<?php
/**
 * Security & Protection Utilities
 * Enforces XSS, CSRF, and Session Hijacking defenses.
 */

defined('SECURE_ACCESS') or die('Direct access denied');

// Start secure session if not already started
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    
    // Enforce Secure Cookie only over HTTPS if available
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    ini_set('session.cookie_secure', $isSecure ? 1 : 0);
    
    // SameSite Cookie Restriction
    if (PHP_VERSION_ID >= 70300) {
        session_start([
            'cookie_samesite' => 'Strict'
        ]);
    } else {
        session_start();
    }
}

// Capture and track referral code if present in GET parameters
if (isset($_GET['ref']) && !empty($_GET['ref'])) {
    $ref_code = preg_replace('/[^a-zA-Z0-9-]/', '', $_GET['ref']);
    $_SESSION['netpulse_ref'] = $ref_code;
    setcookie('netpulse_ref', $ref_code, time() + (86400 * 30), "/"); // 30-day cookie
}

/**
 * XSS Clean Escape Output Utility
 * Shortcut for htmlspecialchars
 */
function e($value) {
    if ($value === null) return '';
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Generate CSRF Token for Forms
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render CSRF Hidden Input Field
 */
function csrf_field() {
    $token = generate_csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Verify CSRF Token from POST Requests
 */
function verify_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            // Log CSRF failure
            error_log("CSRF verification failed.");
            
            // Set 403 Forbidden
            http_response_code(403);
            die("<div style='font-family: sans-serif; padding: 2rem; text-align: center; background: #0B0F19; color: #EF4444; height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center;'>
                    <h2>Security Warning</h2>
                    <p style='color: #9CA3AF;'>Invalid or expired security token (CSRF). Please go back and submit the form again.</p>
                    <a href='javascript:history.back()' style='color: #6366F1; text-decoration: none; font-weight: bold; margin-top: 1rem; border: 1px solid #6366F1; padding: 0.5rem 1.5rem; border-radius: 4px; transition: all 0.3s;'>Go Back</a>
                 </div>");
        }
    }
}

/**
 * Dynamically calculates the correct login page path based on active workspace URL
 */
function get_dynamic_login_redirect($error_type) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    
    if (strpos($script, '/admin/') !== false) {
        $pos = strpos($script, '/admin/');
        $base = substr($script, 0, $pos);
        return $base . '/admin/login.php?err=' . $error_type;
    } elseif (strpos($script, '/dashboard/') !== false) {
        $pos = strpos($script, '/dashboard/');
        $base = substr($script, 0, $pos);
        return $base . '/login.php?err=' . $error_type;
    } elseif (strpos($script, '/customer/') !== false) {
        $pos = strpos($script, '/customer/');
        $base = substr($script, 0, $pos);
        return $base . '/customer-login.php?err=' . $error_type;
    } else {
        return 'login.php?err=' . $error_type;
    }
}

/**
 * Session Hijacking Defense
 * Regenerate session and validate client identifiers
 */
function enforce_session_security() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Prevent Session Fixation / Hijacking
    if (!isset($_SESSION['initiated_at'])) {
        $_SESSION['initiated_at'] = time();
        $_SESSION['user_ip'] = $ip;
        $_SESSION['user_agent'] = $ua;
    } else {
        // Enforce recovery/initialization if user_ip or user_agent got cleared/not set during login
        if (!isset($_SESSION['user_ip'])) {
            $_SESSION['user_ip'] = $ip;
        }
        if (!isset($_SESSION['user_agent'])) {
            $_SESSION['user_agent'] = $ua;
        }

        // If IP or User Agent changes, destroy session immediately
        if ($_SESSION['user_ip'] !== $ip || $_SESSION['user_agent'] !== $ua) {
            session_unset();
            session_destroy();
            
            $redirect = get_dynamic_login_redirect('session_compromised');
            header("Location: " . $redirect);
            exit;
        }
        
        // Periodically regenerate session ID (every 15 mins)
        if (time() - $_SESSION['initiated_at'] > 900) {
            session_regenerate_id(true);
            $_SESSION['initiated_at'] = time();
        }
    }
}

// Call session security enforcement
enforce_session_security();

/**
 * Filter User Inputs (Trim & Clean)
 */
function clean_input($data) {
    if (is_array($data)) {
        return array_map('clean_input', $data);
    }
    return trim((string)$data);
}
