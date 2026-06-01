<?php
/**
 * ISP Admin Packages list REST API
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

// Validate JWT
$token_payload = JWTHelper::enforceAPIAccess('tenant');
$tenant_id = $token_payload['tenant_id'];

try {
    $stmt = $pdo->prepare("SELECT id, name, speed_mbps, monthly_price, description, status FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$tenant_id]);
    $packages = $stmt->fetchAll();
    
    http_response_code(200);
    echo json_encode($packages);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'API Database query failure: ' . $e->getMessage()]);
}
