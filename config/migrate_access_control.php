<?php
/**
 * NetPulse SaaS Platform - Approval & Access Control Schema Upgrades
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/database.php';

try {
    echo "Starting NetPulse Access Control Database Migrations...\n";

    // 1. Alter customers status ENUM and default value
    try {
        $pdo->exec("ALTER TABLE customers MODIFY COLUMN status ENUM('active', 'expired', 'suspended', 'pending') DEFAULT 'pending'");
        echo "[OK] Modified customers.status ENUM to include 'pending'.\n";
    } catch (PDOException $e) {
        echo "[ERROR] customers.status ENUM modify failed: " . $e->getMessage() . "\n";
    }

    // 2. Add customer payment proof columns to invoices table
    $inv_columns = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
    
    $proof_fields = [
        'payment_method' => "VARCHAR(100) DEFAULT NULL",
        'transaction_id' => "VARCHAR(100) DEFAULT NULL",
        'proof_submitted' => "TINYINT(1) DEFAULT 0",
        'submission_notes' => "TEXT DEFAULT NULL",
        'submission_date' => "DATETIME DEFAULT NULL"
    ];

    foreach ($proof_fields as $col => $definition) {
        if (!in_array($col, $inv_columns)) {
            $pdo->exec("ALTER TABLE invoices ADD COLUMN $col $definition");
            echo "[OK] Added Column '$col' to invoices.\n";
        }
    }

    // 3. Add tenant payment proof columns and duration_days to saas_invoices table
    $saas_inv_columns = $pdo->query("SHOW COLUMNS FROM saas_invoices")->fetchAll(PDO::FETCH_COLUMN);

    $saas_proof_fields = [
        'payment_method' => "VARCHAR(100) DEFAULT NULL",
        'transaction_id' => "VARCHAR(100) DEFAULT NULL",
        'proof_submitted' => "TINYINT(1) DEFAULT 0",
        'submission_notes' => "TEXT DEFAULT NULL",
        'submission_date' => "DATETIME DEFAULT NULL",
        'duration_days' => "INT DEFAULT 30"
    ];

    foreach ($saas_proof_fields as $col => $definition) {
        if (!in_array($col, $saas_inv_columns)) {
            $pdo->exec("ALTER TABLE saas_invoices ADD COLUMN $col $definition");
            echo "[OK] Added Column '$col' to saas_invoices.\n";
        }
    }

    // 4. Add subscription_duration to tenants table
    $tenant_columns = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('subscription_duration', $tenant_columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN subscription_duration INT DEFAULT 30 AFTER subscription_end");
        echo "[OK] Added Column 'subscription_duration' to tenants.\n";
    }

    // 5. Update existing tenants to active/Enterprise limits to protect active seeds
    $pdo->exec("UPDATE tenants SET plan_id = 3, status = 'active', subscription_duration = 365 WHERE id = 1");
    echo "[OK] Default tenant upgraded and secured successfully.\n";

    echo "\nDatabase Access Control Migrations Completed Successfully!\n";

} catch (PDOException $e) {
    echo "[FATAL ERROR] Database access control migration failed: " . $e->getMessage() . "\n";
}
