<?php
/**
 * ISP Admin Billing, Payments, and Invoice Revision REST API
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('SECURE_ACCESS', true);
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

// Validate JWT
$token_payload = JWTHelper::enforceAPIAccess('tenant');
$tenant_id = $token_payload['tenant_id'];

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // --------------------------------------------------------------------------
    // RETRIEVE INVOICES (LIST & FILTER)
    // --------------------------------------------------------------------------
    $search = trim($_GET['search'] ?? '');
    $filter_status = trim($_GET['status'] ?? '');
    
    $query_parts = ["i.tenant_id = :tenant_id"];
    $query_params = [':tenant_id' => $tenant_id];
    
    if (!empty($search)) {
        $query_parts[] = "(c.name LIKE :search OR i.invoice_number LIKE :search)";
        $query_params[':search'] = '%' . $search . '%';
    }
    
    if (!empty($filter_status)) {
        $query_parts[] = "i.payment_status = :status";
        $query_params[':status'] = $filter_status;
    }
    
    $where_sql = implode(" AND ", $query_parts);
    
    $page = (int)($_GET['page'] ?? 1);
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    try {
        $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            WHERE $where_sql 
            ORDER BY i.id DESC LIMIT $limit OFFSET $offset");
            
        foreach ($query_params as $param => $val) {
            $stmt->bindValue($param, $val);
        }
        $stmt->execute();
        $invoices = $stmt->fetchAll();
        
        $formatted_invoices = [];
        foreach ($invoices as $inv) {
            $formatted_invoices[] = [
                'id' => $inv['id'],
                'invoice_number' => $inv['invoice_number'],
                'subscriber' => $inv['customer_name'],
                'package' => $inv['package_name'],
                'total_amount' => (double)$inv['total_amount'],
                'total_amount_formatted' => format_currency($inv['total_amount']),
                'paid_amount' => (double)$inv['paid_amount'],
                'paid_amount_formatted' => format_currency($inv['paid_amount']),
                'remaining_amount' => (double)$inv['remaining_amount'],
                'remaining_amount_formatted' => format_currency($inv['remaining_amount']),
                'due_date' => $inv['due_date'],
                'payment_status' => $inv['payment_status']
            ];
        }
        
        http_response_code(200);
        echo json_encode($formatted_invoices);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load invoices: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    // --------------------------------------------------------------------------
    // ACTION CONTROLLER: COLLECT PAYMENT OR EDIT INVOICE
    // --------------------------------------------------------------------------
    $data = json_decode(file_get_contents("php://input"), true);
    $action = trim($data['action'] ?? 'collect');
    $invoice_id = (int)($data['invoice_id'] ?? 0);
    
    if ($invoice_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid invoice selection.']);
        exit;
    }
    
    try {
        // Load original invoice
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$invoice_id, $tenant_id]);
        $inv = $stmt->fetch();
        
        if (!$inv) {
            http_response_code(404);
            echo json_encode(['error' => 'Invoice not found or unauthorized.']);
            exit;
        }
        
        if ($action === 'collect') {
            // COLLECT PAYMENT
            $pay_amt = (double)($data['paid_amount'] ?? 0.00);
            if ($pay_amt <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Payment amount must be greater than Rs. 0.']);
                exit;
            }
            
            $new_paid = (double)$inv['paid_amount'] + $pay_amt;
            if ($new_paid > (double)$inv['total_amount']) {
                http_response_code(400);
                echo json_encode(['error' => 'Recorded payments cannot exceed total invoice.']);
                exit;
            }
            
            $remaining = (double)$inv['total_amount'] - $new_paid;
            $status = 'pending';
            if ($new_paid == (double)$inv['total_amount']) $status = 'paid';
            elseif ($new_paid > 0) $status = 'partial';
            elseif ($inv['due_date'] < date('Y-m-d')) $status = 'overdue';
            
            $pay_date = ($status === 'paid' || $status === 'partial') ? date('Y-m-d H:i:s') : null;
            
            $update = $pdo->prepare("UPDATE invoices SET paid_amount = ?, remaining_amount = ?, payment_status = ?, payment_date = ? WHERE id = ? AND tenant_id = ?");
            $update->execute([$new_paid, $remaining, $status, $pay_date, $invoice_id, $tenant_id]);
            
            if ($pay_amt > 0) {
                trigger_customer_referral_reward($pdo, $tenant_id, $inv['customer_id'], $pay_amt, $invoice_id);
            }
            
            if ($status === 'paid') {
                $new_expiry = date('Y-m-d', strtotime('+30 days'));
                $renew = $pdo->prepare("UPDATE customers SET expiry_date = ?, status = 'active' WHERE id = ? AND tenant_id = ?");
                $renew->execute([$new_expiry, $inv['customer_id'], $tenant_id]);
            }
            
            log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Collected Rs. $pay_amt on invoice {$inv['invoice_number']} via Mobile.");
            
            http_response_code(200);
            echo json_encode(['success' => 'Payment recorded successfully.', 'payment_status' => $status]);
            exit;
            
        } elseif ($action === 'edit') {
            // EDIT INVOICE (With revisions audit log)
            $pkg_name = trim($data['package_name'] ?? '');
            $total_amt = (double)($data['total_amount'] ?? 0.00);
            $paid_amt = (double)($data['paid_amount'] ?? 0.00);
            $due_date = trim($data['due_date'] ?? '');
            $reason = trim($data['modification_reason'] ?? '');
            
            if (empty($pkg_name) || $total_amt <= 0 || empty($due_date) || empty($reason)) {
                http_response_code(400);
                echo json_encode(['error' => 'Required parameter fields are missing or empty. Package, total bill, due date and reasoning are required.']);
                exit;
            }
            
            if ($paid_amt > $total_amt) {
                http_response_code(400);
                echo json_encode(['error' => 'Paid collected amount cannot exceed total bill amount.']);
                exit;
            }
            
            $remaining = $total_amt - $paid_amt;
            $status = 'pending';
            if ($remaining == 0) $status = 'paid';
            elseif ($paid_amt > 0) $status = 'partial';
            elseif ($due_date < date('Y-m-d')) $status = 'overdue';
            
            $pay_date = ($status === 'paid' || $status === 'partial') ? date('Y-m-d H:i:s') : null;
            
            // Execute update
            $stmt = $pdo->prepare("UPDATE invoices SET package_name = ?, total_amount = ?, paid_amount = ?, remaining_amount = ?, due_date = ?, payment_status = ?, payment_date = ? WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$pkg_name, $total_amt, $paid_amt, $remaining, $due_date, $status, $pay_date, $invoice_id, $tenant_id]);
            
            // Log revision
            $prev_json = json_encode([
                'package_name' => $inv['package_name'],
                'total_amount' => $inv['total_amount'],
                'paid_amount' => $inv['paid_amount'],
                'remaining_amount' => $inv['remaining_amount'],
                'due_date' => $inv['due_date'],
                'payment_status' => $inv['payment_status']
            ]);
            $new_json = json_encode([
                'package_name' => $pkg_name,
                'total_amount' => $total_amt,
                'paid_amount' => $paid_amt,
                'remaining_amount' => $remaining,
                'due_date' => $due_date,
                'payment_status' => $status
            ]);
            
            $ins = $pdo->prepare("INSERT INTO invoice_revisions (tenant_id, invoice_id, edited_by, previous_values, new_values, modification_reason) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$tenant_id, $invoice_id, $tenant_id, $prev_json, $new_json, $reason]);
            
            log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Edited invoice {$inv['invoice_number']} via Mobile. revision recorded.");
            
            http_response_code(200);
            echo json_encode(['success' => 'Invoice modified successfully and audited.', 'invoice_number' => $inv['invoice_number']]);
            exit;
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'API Database transaction failure: ' . $e->getMessage()]);
    }
}
