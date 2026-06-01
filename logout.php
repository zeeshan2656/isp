<?php
/**
 * Global Secure Logout Handler
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';

// Unset all session variables
$_SESSION = [];

// Destroy session cookie if set
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();

// Start a brief new session for flash notification
session_start();
set_session_alert("You have been securely logged out.", "success");

header("Location: index.php");
exit;
