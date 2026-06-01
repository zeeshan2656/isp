<?php
/**
 * Customer Self-Service Portal Dashboard REST API
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
    // 1. Fetch Profile & Package info
    $stmt = $pdo->prepare("SELECT c.*, p.name as package_name, p.speed_mbps, p.monthly_price as package_base_price, z.name as zone_name 
        FROM customers c 
        LEFT JOIN packages p ON c.assigned_package_id = p.id 
        LEFT JOIN zones z ON c.zone_id = z.id
        WHERE c.id = ? AND c.tenant_id = ? LIMIT 1");
    $stmt->execute([$customer_id, $tenant_id]);
    $cust = $stmt->fetch();
    
    if (!$cust) {
        http_response_code(404);
        echo json_encode(['error' => 'Subscriber profile not found.']);
        exit;
    }
    
    // Calculate Expiry Details
    $days = calculate_days_remaining($cust['expiry_date']);
    $cfg = get_expiry_alert_config($cust['expiry_date']);
    
    // 2. Fetch ISP Contact Meta
    $stmt = $pdo->prepare("SELECT company_name, email, phone FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $isp = $stmt->fetch();
    
    // 3. Fetch Notifications (limit 5)
    $stmt = $pdo->prepare("SELECT title, message, type, created_at FROM notifications WHERE customer_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$customer_id, $tenant_id]);
    $notifications = $stmt->fetchAll();
    
    http_response_code(200);
    echo json_encode([
        'customer' => [
            'name' => $cust['name'],
            'email' => $cust['email'],
            'phone' => $cust['phone'],
            'cnic' => $cust['cnic'],
            'address' => $cust['address'],
            'area' => $cust['area'],
            'zone' => $cust['zone_name'],
            'status' => $cust['status'],
            'connection_type' => $cust['connection_type'],
            'activation_date' => $cust['activation_date'],
            'expiry_date' => $cust['expiry_date'],
            'days_remaining' => max(0, $days),
            'expiry_status' => $cfg['label']
        ],
        'package' => [
            'name' => $cust['package_name'],
            'speed' => format_bandwidth($cust['speed_mbps']),
            'monthly_fee' => format_currency($cust['monthly_fee'])
        ],
        'isp' => [
            'company_name' => $isp['company_name'] ?? 'My ISP',
            'support_email' => $isp['email'] ?? 'support@isp.net',
            'support_phone' => $isp['phone'] ?? 'N/A'
        ],
        'notifications' => $notifications
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'API database transaction failure: ' . $e->getMessage()]);
}
