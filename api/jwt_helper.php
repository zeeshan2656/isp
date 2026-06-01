<?php
/**
 * Native, Lightweight JSON Web Token (JWT) Helper
 * 100% PHP-native with zero external dependencies.
 * Designed specifically for high performance on Hostinger Shared Hosting.
 */

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

class JWTHelper {
    // Unique cryptographically secure key signature
    private static $secret = 'NetPulseSaaS_JWT_SuperSecretKey_2026_!@#';
    
    // Encodes data to Base64Url
    private static function base64UrlEncode($data) {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }
    
    // Decodes data from Base64Url
    private static function base64UrlDecode($data) {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }
    
    /**
     * Generate a signed JWT
     */
    public static function encode($payload, $expiry_seconds = 86400) {
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]);
        
        // Append expiry claim
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiry_seconds;
        
        $header_b64 = self::base64UrlEncode($header);
        $payload_b64 = self::base64UrlEncode(json_encode($payload));
        
        // Sign token
        $signature = hash_hmac('sha256', "$header_b64.$payload_b64", self::$secret, true);
        $signature_b64 = self::base64UrlEncode($signature);
        
        return "$header_b64.$payload_b64.$signature_b64";
    }
    
    /**
     * Decode and Validate a JWT
     */
    public static function decode($token) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false; // Invalid token structure
        }
        
        list($header_b64, $payload_b64, $signature_b64) = $parts;
        
        // Re-generate signature to verify
        $expected_sig = hash_hmac('sha256', "$header_b64.$payload_b64", self::$secret, true);
        $expected_sig_b64 = self::base64UrlEncode($expected_sig);
        
        if (!hash_equals($expected_sig_b64, $signature_b64)) {
            return false; // Signature compromise
        }
        
        $payload = json_decode(self::base64UrlDecode($payload_b64), true);
        
        // Check Expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false; // Token expired
        }
        
        return $payload;
    }
    
    /**
     * Helper to read Authorization Bearer header directly
     */
    public static function getBearerTokenFromHeader() {
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        
        // Extract Bearer value
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
                return $matches[1];
            }
        }
        return null;
    }
    
    /**
     * API Authorization Guard
     * Validates JWT and enforces role check
     */
    public static function enforceAPIAccess($role_required = null) {
        $token = self::getBearerTokenFromHeader();
        if (!$token) {
            http_response_code(401);
            echo json_encode(['error' => 'Authentication token required. Bearer format.']);
            exit;
        }
        
        $payload = self::decode($token);
        if (!$payload) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or expired session token. Please log in again.']);
            exit;
        }
        
        if ($role_required && (!isset($payload['role']) || $payload['role'] !== $role_required)) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied. Unauthorized role level.']);
            exit;
        }
        
        return $payload;
    }
}
