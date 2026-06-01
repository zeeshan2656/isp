<?php
/**
 * NetPulse SaaS Platform - Monthly Costing Ledger & Payment Verification Migration
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/database.php';

try {
    echo "Starting NetPulse Schema Upgrades for Costing & Payment Verification...\n\n";

    // 1. Create monthly_costs table
    $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_costs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        month INT NOT NULL,
        year INT NOT NULL,
        bandwidth_purchased_mbps INT DEFAULT 0,
        total_cost DECIMAL(12,2) DEFAULT 0.00,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_month_year (tenant_id, month, year),
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created 'monthly_costs' table.\n";

    // 2. Create payment_submissions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        payer_type ENUM('tenant', 'customer') NOT NULL,
        payer_id INT NOT NULL,
        invoice_type ENUM('invoice', 'saas_invoice') NOT NULL,
        invoice_id INT NOT NULL,
        payment_method VARCHAR(100) NOT NULL,
        transaction_id VARCHAR(200) DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL,
        proof_image VARCHAR(500) DEFAULT NULL,
        submission_notes TEXT DEFAULT NULL,
        status ENUM('pending', 'approved', 'rejected', 'more_info') DEFAULT 'pending',
        reviewed_by INT DEFAULT NULL,
        review_notes TEXT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_tenant (tenant_id),
        INDEX idx_payer (payer_type, payer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Created 'payment_submissions' table.\n";

    // 3. Add verified_by_admin to invoices
    $invoice_cols = array_column($pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(), 'Field');
    if (!in_array('verified_by_admin', $invoice_cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN verified_by_admin VARCHAR(100) DEFAULT NULL AFTER payment_date");
        echo "[OK] Added 'verified_by_admin' column to invoices.\n";
    } else {
        echo "[SKIP] 'verified_by_admin' already exists in invoices.\n";
    }

    // 4. Add verified_by_admin to saas_invoices
    $saas_cols = array_column($pdo->query("SHOW COLUMNS FROM saas_invoices")->fetchAll(), 'Field');
    if (!in_array('verified_by_admin', $saas_cols)) {
        $pdo->exec("ALTER TABLE saas_invoices ADD COLUMN verified_by_admin VARCHAR(100) DEFAULT NULL AFTER payment_date");
        echo "[OK] Added 'verified_by_admin' column to saas_invoices.\n";
    } else {
        echo "[SKIP] 'verified_by_admin' already exists in saas_invoices.\n";
    }

    echo "\n[SUCCESS] All schema upgrades completed successfully!\n";

} catch (PDOException $e) {
    echo "[ERROR] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
