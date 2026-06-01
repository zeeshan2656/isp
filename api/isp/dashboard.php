<?php
/**
 * ISP Owner Mobile Portal Dashboard REST API
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

// Validate JWT and enforce role
$token_payload = JWTHelper::enforceAPIAccess('tenant');
$tenant_id = $token_payload['tenant_id'];

$metrics = [
    'total_customers' => 0,
    'active_customers' => 0,
    'expired_customers' => 0,
    'expiring_soon' => 0,
    'monthly_revenue' => 0.00,
    'collected_payments' => 0.00,
    'pending_payments' => 0.00,
    'bandwidth_purchased' => 0,
    'monthly_internet_cost' => 0.00,
    'net_profit' => 0.00
];

try {
    // 1. Fetch ISP Wholesales Internet costs metadata
    $stmt = $pdo->prepare("SELECT bandwidth_purchased, internet_cost FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $cost_data = $stmt->fetch();
    if ($cost_data) {
        $metrics['bandwidth_purchased'] = (int)$cost_data['bandwidth_purchased'];
        $metrics['monthly_internet_cost'] = (double)$cost_data['internet_cost'];
    }
    
    // 2. Count Customers
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
        FROM customers WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $counts = $stmt->fetch();
    if ($counts) {
        $metrics['total_customers'] = (int)$counts['total'];
        $metrics['active_customers'] = (int)$counts['active'];
        $metrics['expired_customers'] = (int)$counts['expired'];
    }
    
    // 3. Count Expiring Soon (<= 10 days)
    $today = date('Y-m-d');
    $warning_limit = date('Y-m-d', strtotime('+10 days'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND status = 'active' AND expiry_date BETWEEN ? AND ?");
    $stmt->execute([$tenant_id, $today, $warning_limit]);
    $metrics['expiring_soon'] = (int)$stmt->fetchColumn();
    
    // 4. Invoicing for Current Month
    $start_of_month = date('Y-m-01');
    $end_of_month = date('Y-m-t');
    
    $stmt = $pdo->prepare("SELECT 
        SUM(total_amount) as revenue,
        SUM(paid_amount) as collected,
        SUM(remaining_amount) as pending
        FROM invoices 
        WHERE tenant_id = ? AND created_at BETWEEN ? AND ?");
    $stmt->execute([$tenant_id, $start_of_month . ' 00:00:00', $end_of_month . ' 23:59:59']);
    $billing_metrics = $stmt->fetch();
    if ($billing_metrics) {
        $metrics['monthly_revenue'] = (double)$billing_metrics['revenue'];
        $metrics['collected_payments'] = (double)$billing_metrics['collected'];
        $metrics['pending_payments'] = (double)$billing_metrics['pending'];
    }
    
    // Calculate Net Profit
    $metrics['net_profit'] = $metrics['collected_payments'] - $metrics['monthly_internet_cost'];
    
    // Format response JSON maps cleanly
    http_response_code(200);
    echo json_encode([
        'company' => $token_payload['company_name'],
        'kpis' => [
            'total_subscribers' => $metrics['total_customers'],
            'active_subscribers' => $metrics['active_customers'],
            'expired_subscribers' => $metrics['expired_customers'],
            'expiring_soon_count' => $metrics['expiring_soon']
        ],
        'financials' => [
            'monthly_revenue_billed' => format_currency($metrics['monthly_revenue']),
            'collected_payments' => format_currency($metrics['collected_payments']),
            'pending_payments' => format_currency($metrics['pending_payments']),
            'net_profit_loss' => format_currency($metrics['net_profit']),
            'is_profitable' => ($metrics['net_profit'] >= 0)
        ],
        'expenses' => [
            'bandwidth_capacity' => format_bandwidth($metrics['bandwidth_purchased']),
            'monthly_internet_cost' => format_currency($metrics['monthly_internet_cost'])
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Dashboard API query failure: ' . $e->getMessage()]);
}
