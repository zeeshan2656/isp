<?php
/**
 * Secure Database Connection Configuration
 * Optimized for Hostinger Shared Hosting (PHP 8+)
 */

// Define safe secure access constant
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

// Database Credentials
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'u434697879_isp');
define('DB_USER', 'u434697879_isp');
define('DB_PASS', '03061881882Star2656');
define('DB_CHARSET', 'utf8mb4');

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // Enforce native prepared statements for SQLi protection
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
} catch (PDOException $e) {
    // Hide raw database exception details from end users in production
    error_log("Database Connection Failure: " . $e->getMessage());
    die("<div style='font-family: sans-serif; padding: 2rem; text-align: center; background: #0B0F19; color: #EF4444; height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center;'>
            <h2 style='margin-bottom: 0.5rem;'>Database Connection Error</h2>
            <p style='color: #9CA3AF; max-width: 500px;'>The platform is temporarily unable to connect to the database. If this is a new installation, please verify your settings in <code>config/database.php</code>.</p>
         </div>");
}
