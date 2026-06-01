<?php
/**
 * Shared API REST Authentication Endpoint
 * Supports CORS, JWT generation, and separate login routing.
 */

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Handle pre-flight options query
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('SECURE_ACCESS', true);
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

// Capture raw body inputs
$data = json_decode(file_get_contents("php://input"), true);

$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';
$role = trim($data['role'] ?? 'customer'); // 'customer' or 'tenant'

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email address and password are required.']);
    exit;
}

try {
    if ($role === 'tenant') {
        // ISP OWNER LOGIN
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $tenant = $stmt->fetch();
        
        if ($tenant && password_verify($password, $tenant['password_hash'])) {
            // Generate Tenant Token
            $payload = [
                'tenant_id' => $tenant['id'],
                'role' => 'tenant',
                'company_name' => $tenant['company_name'],
                'email' => $tenant['email']
            ];
            
            $jwt = JWTHelper::encode($payload);
            
            log_audit_activity($pdo, $tenant['id'], 'tenant', $tenant['id'], 'Mobile App REST API logged in.');
            
            http_response_code(200);
            echo json_encode([
                'token' => $jwt,
                'role' => 'tenant',
                'company_name' => $tenant['company_name'],
                'email' => $tenant['email']
            ]);
            exit;
        }
    } else {
        // SUBSCRIBER LOGIN
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $customer = $stmt->fetch();
        
        if ($customer && password_verify($password, $customer['password_hash'])) {
            if ($customer['status'] === 'suspended') {
                http_response_code(403);
                echo json_encode(['error' => 'Your subscriber account is currently suspended.']);
                exit;
            }
            
            // Generate Customer Token
            $payload = [
                'customer_id' => $customer['id'],
                'tenant_id' => $customer['tenant_id'],
                'role' => 'customer',
                'name' => $customer['name'],
                'email' => $customer['email']
            ];
            
            $jwt = JWTHelper::encode($payload);
            
            log_audit_activity($pdo, $customer['tenant_id'], 'customer', $customer['id'], 'Mobile Client App REST API logged in.');
            
            http_response_code(200);
            echo json_encode([
                'token' => $jwt,
                'role' => 'customer',
                'name' => $customer['name'],
                'email' => $customer['email']
            ]);
            exit;
        }
    }
    
    // Auth Failed
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email address or password combination.']);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Authentication database transaction failure.']);
}
