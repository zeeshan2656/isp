<?php
/**
 * Customer Self-Service Portal Invoices list REST API
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS");
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
$token_payload = JWTHelper::enforceAPIAccess('customer');
$customer_id = $token_payload['customer_id'];
$tenant_id = $token_payload['tenant_id'];

try {
    // Fetch all invoices generated for the customer
    $stmt = $pdo->prepare("SELECT id, invoice_number, package_name, total_amount, paid_amount, remaining_amount, due_date, payment_date, payment_status, created_at 
        FROM invoices 
        WHERE customer_id = ? AND tenant_id = ? 
        ORDER BY id DESC");
    $stmt->execute([$customer_id, $tenant_id]);
    $invoices = $stmt->fetchAll();
    
    // Format invoice numbers & currency values cleanly for API consumption
    $formatted_invoices = [];
    foreach ($invoices as $inv) {
        $formatted_invoices[] = [
            'id' => $inv['id'],
            'invoice_number' => $inv['invoice_number'],
            'package_name' => $inv['package_name'],
            'total_amount' => (double)$inv['total_amount'],
            'total_amount_formatted' => format_currency($inv['total_amount']),
            'paid_amount' => (double)$inv['paid_amount'],
            'paid_amount_formatted' => format_currency($inv['paid_amount']),
            'remaining_amount' => (double)$inv['remaining_amount'],
            'remaining_amount_formatted' => format_currency($inv['remaining_amount']),
            'due_date' => $inv['due_date'],
            'due_date_formatted' => format_date($inv['due_date']),
            'payment_date' => $inv['payment_date'],
            'payment_date_formatted' => $inv['payment_date'] ? date('d M, Y H:i', strtotime($inv['payment_date'])) : 'N/A',
            'payment_status' => $inv['payment_status']
        ];
    }
    
    http_response_code(200);
    echo json_encode($formatted_invoices);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'API database transaction failure: ' . $e->getMessage()]);
}
