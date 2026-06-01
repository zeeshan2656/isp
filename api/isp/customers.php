<?php
/**
 * ISP Admin Customer Base Directory REST API
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
    // RETRIEVE SUBSCRIBERS DIRECTORY (LIST & FILTER)
    // --------------------------------------------------------------------------
    $search = trim($_GET['search'] ?? '');
    $filter_zone = (int)($_GET['zone'] ?? 0);
    $filter_status = trim($_GET['status'] ?? '');
    $expiry_filter = (int)($_GET['expiry_filter'] ?? 0);
    
    $query_parts = ["c.tenant_id = :tenant_id"];
    $query_params = [':tenant_id' => $tenant_id];
    
    if (!empty($search)) {
        $query_parts[] = "(c.name LIKE :search OR c.phone LIKE :search OR c.email LIKE :search OR c.cnic LIKE :search)";
        $query_params[':search'] = '%' . $search . '%';
    }
    
    if ($filter_zone > 0) {
        $query_parts[] = "c.zone_id = :zone_id";
        $query_params[':zone_id'] = $filter_zone;
    }
    
    if (!empty($filter_status)) {
        $query_parts[] = "c.status = :status";
        $query_params[':status'] = $filter_status;
    }
    
    if ($expiry_filter > 0) {
        $today = date('Y-m-d');
        $expiry_limit = date('Y-m-d', strtotime("+$expiry_filter days"));
        $query_parts[] = "c.status = 'active' AND c.expiry_date BETWEEN :today AND :expiry_limit";
        $query_params[':today'] = $today;
        $query_params[':expiry_limit'] = $expiry_limit;
    }
    
    $where_sql = implode(" AND ", $query_parts);
    
    // Pagination
    $page = (int)($_GET['page'] ?? 1);
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    try {
        $stmt = $pdo->prepare("SELECT c.*, p.name as package_name, z.name as zone_name 
            FROM customers c 
            LEFT JOIN packages p ON c.assigned_package_id = p.id 
            LEFT JOIN zones z ON c.zone_id = z.id 
            WHERE $where_sql 
            ORDER BY c.id DESC LIMIT $limit OFFSET $offset");
            
        foreach ($query_params as $param => $val) {
            $stmt->bindValue($param, $val);
        }
        $stmt->execute();
        $customers = $stmt->fetchAll();
        
        $formatted_customers = [];
        foreach ($customers as $c) {
            $cfg = get_expiry_alert_config($c['expiry_date']);
            $formatted_customers[] = [
                'id' => $c['id'],
                'name' => $c['name'],
                'cnic' => $c['cnic'],
                'phone' => $c['phone'],
                'email' => $c['email'],
                'address' => $c['address'],
                'area' => $c['area'],
                'zone' => $c['zone_name'],
                'connection_type' => $c['connection_type'],
                'package_name' => $c['package_name'],
                'monthly_fee' => (double)$c['monthly_fee'],
                'monthly_fee_formatted' => format_currency($c['monthly_fee']),
                'activation_date' => $c['activation_date'],
                'expiry_date' => $c['expiry_date'],
                'expiry_date_formatted' => format_date($c['expiry_date']),
                'days_remaining' => max(0, $cfg['days']),
                'expiry_status' => $cfg['label'],
                'status' => $c['status']
            ];
        }
        
        http_response_code(200);
        echo json_encode($formatted_customers);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load subscribers list: ' . $e->getMessage()]);
    }
    
} elseif ($method === 'POST') {
    // --------------------------------------------------------------------------
    // DYNAMIC SUBSCRIBER REGISTRATION (CREATE)
    // --------------------------------------------------------------------------
    $data = json_decode(file_get_contents("php://input"), true);
    
    $name = trim($data['name'] ?? '');
    $cnic = trim($data['cnic'] ?? '');
    $phone = trim($data['phone'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $address = trim($data['address'] ?? '');
    $area = trim($data['area'] ?? '');
    $zone_id = (int)($data['zone_id'] ?? 0);
    $conn_type = trim($data['connection_type'] ?? 'Fiber');
    $pkg_id = (int)($data['assigned_package_id'] ?? 0);
    $fee = (double)($data['monthly_fee'] ?? 0.00);
    $install = (double)($data['installation_fee'] ?? 0.00);
    
    if (empty($name) || empty($cnic) || empty($phone) || empty($email) || empty($password) || $zone_id <= 0 || $pkg_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Required parameters are missing. Complete name, cnic, phone, email, password, zone and packages.']);
        exit;
    }
    
    try {
        // Email check
        $chk = $pdo->prepare("SELECT id FROM customers WHERE email = ? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetch()) {
            http_response_code(400);
            echo json_encode(['error' => 'This email address is already registered inside another customer account.']);
            exit;
        }
        
        $password_hash = password_hash($password, PASSWORD_BCRYPT);
        $active_date = date('Y-m-d');
        $expiry_date = date('Y-m-d', strtotime('+30 days'));
        
        $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, name, cnic, phone, email, password_hash, address, area, zone_id, connection_type, assigned_package_id, monthly_fee, installation_fee, activation_date, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $tenant_id, $name, $cnic, $phone, $email, $password_hash, $address, $area, 
            $zone_id, $conn_type, $pkg_id, $fee, $install, $active_date, $expiry_date
        ]);
        
        $new_cust_id = $pdo->lastInsertId();
        
        // Dynamic welcome notification
        $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Welcome to " . get_platform_name() . "', 'Your service is activated.', 'system')");
        $notif->execute([$tenant_id, $new_cust_id]);
        
        log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Registered subscriber $name via Mobile API.");
        
        http_response_code(201);
        echo json_encode(['success' => 'Subscriber registered successfully.', 'customer_id' => $new_cust_id]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save customer: ' . $e->getMessage()]);
    }
}
