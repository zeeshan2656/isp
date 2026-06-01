<?php
/**
 * NetPulse SaaS Platform - Affiliate & Referral System Schema Upgrades
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/database.php';

try {
    echo "Starting NetPulse Affiliate & Referral System Database Migration...\n";

    // 1. Alter tenants Table to add Referral fields
    $columns = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('referred_by_type', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN referred_by_type ENUM('affiliate', 'tenant', 'none') DEFAULT 'none'");
        echo "[OK] Added Column 'referred_by_type' to tenants.\n";
    }
    if (!in_array('referred_by_id', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN referred_by_id INT DEFAULT NULL");
        echo "[OK] Added Column 'referred_by_id' to tenants.\n";
    }
    if (!in_array('referral_code', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN referral_code VARCHAR(50) UNIQUE DEFAULT NULL");
        echo "[OK] Added Column 'referral_code' to tenants.\n";
    }
    if (!in_array('referral_wallet', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN referral_wallet DECIMAL(10, 2) DEFAULT 0.00");
        echo "[OK] Added Column 'referral_wallet' to tenants.\n";
    }
    if (!in_array('lifetime_referral_earnings', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN lifetime_referral_earnings DECIMAL(10, 2) DEFAULT 0.00");
        echo "[OK] Added Column 'lifetime_referral_earnings' to tenants.\n";
    }

    // 2. Alter customers Table to add Referral fields
    $cust_columns = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('referred_by_id', $cust_columns)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN referred_by_id INT DEFAULT NULL");
        echo "[OK] Added Column 'referred_by_id' to customers.\n";
    }
    if (!in_array('referral_code', $cust_columns)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN referral_code VARCHAR(50) UNIQUE DEFAULT NULL");
        echo "[OK] Added Column 'referral_code' to customers.\n";
    }
    if (!in_array('referral_wallet', $cust_columns)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN referral_wallet DECIMAL(10, 2) DEFAULT 0.00");
        echo "[OK] Added Column 'referral_wallet' to customers.\n";
    }
    if (!in_array('lifetime_referral_earnings', $cust_columns)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN lifetime_referral_earnings DECIMAL(10, 2) DEFAULT 0.00");
        echo "[OK] Added Column 'lifetime_referral_earnings' to customers.\n";
    }

    // 3. Create saas_settings Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] saas_settings table verified/created.\n";

    // Seed platform settings
    $seeds = [
        'organization_name' => 'NetPulse',
        'affiliate_commission_percentage' => '20.00',
        'tenant_referral_percentage' => '10.00',
        'min_withdrawal_amount' => '1000.00',
        'withdrawal_approval_required' => '1'
    ];
    foreach ($seeds as $key => $val) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO saas_settings (setting_key, setting_value) VALUES (?, ?)");
        $stmt->execute([$key, $val]);
    }
    echo "[OK] Platform settings seeded successfully.\n";

    // 4. Create affiliates Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        referral_code VARCHAR(50) UNIQUE NOT NULL,
        wallet_balance DECIMAL(10, 2) DEFAULT 0.00,
        lifetime_earnings DECIMAL(10, 2) DEFAULT 0.00,
        status ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] affiliates table verified/created.\n";

    // 5. Create referral_transactions Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS referral_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referrer_type ENUM('affiliate', 'tenant', 'customer') NOT NULL,
        referrer_id INT NOT NULL,
        transaction_type ENUM('commission_credit', 'invoice_deduction', 'withdrawal') NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        reference_id INT DEFAULT NULL, -- SaaS invoice ID, Broadband invoice ID, or Withdrawal Request ID
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] referral_transactions table verified/created.\n";

    // 6. Create withdrawal_requests Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS withdrawal_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requester_type ENUM('affiliate', 'tenant', 'customer') NOT NULL,
        requester_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        payment_method VARCHAR(100) NOT NULL,
        payment_details TEXT NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        processed_at DATETIME DEFAULT NULL,
        processed_by INT DEFAULT NULL, -- Admin or Tenant ID who processed it
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] withdrawal_requests table verified/created.\n";

    // 7. Create tenant_referral_settings Table (Configurations per tenant)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tenant_referral_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNIQUE NOT NULL,
        enabled TINYINT(1) DEFAULT 0,
        reward_type ENUM('fixed', 'percentage') DEFAULT 'fixed',
        reward_value DECIMAL(10, 2) DEFAULT 100.00,
        min_withdrawal_amount DECIMAL(10, 2) DEFAULT 500.00,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] tenant_referral_settings table verified/created.\n";

    // 8. Auto-populate referral codes for existing tenants & customers
    $tenants = $pdo->query("SELECT id FROM tenants WHERE referral_code IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tenants as $id) {
        $code = 'TEN-' . strtoupper(substr(md5($id . time() . rand()), 0, 8));
        $upd = $pdo->prepare("UPDATE tenants SET referral_code = ? WHERE id = ?");
        $upd->execute([$code, $id]);
    }
    
    $customers = $pdo->query("SELECT id FROM customers WHERE referral_code IS NULL")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($customers as $id) {
        $code = 'CUS-' . strtoupper(substr(md5($id . time() . rand()), 0, 8));
        $upd = $pdo->prepare("UPDATE customers SET referral_code = ? WHERE id = ?");
        $upd->execute([$code, $id]);
    }
    echo "[OK] Seeded missing unique referral codes for all active tenants and customer rows.\n";

    // Ensure all existing tenants have customer referral settings initialized
    $all_tenants = $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($all_tenants as $tid) {
        $pdo->exec("INSERT IGNORE INTO tenant_referral_settings (tenant_id, enabled, reward_type, reward_value, min_withdrawal_amount) VALUES ($tid, 1, 'fixed', 100.00, 500.00)");
    }
    echo "[OK] Initialized default tenant referral configurations for active workspaces.\n";

    // 9. Create credit_adjustment_requests Table (ISP requests to apply wallet credits to invoices)
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_adjustment_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        invoice_id INT NOT NULL,
        requested_amount DECIMAL(10, 2) NOT NULL,
        approved_amount DECIMAL(10, 2) DEFAULT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        processed_by INT DEFAULT NULL,
        processed_at DATETIME DEFAULT NULL,
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES saas_invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] credit_adjustment_requests table verified/created.\n";

    // 10. Extend withdrawal_requests status ENUM to include 'completed'
    try {
        $pdo->exec("ALTER TABLE withdrawal_requests MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'completed') DEFAULT 'pending'");
        echo "[OK] withdrawal_requests status ENUM extended with 'completed'.\n";
    } catch (PDOException $e) {
        echo "[SKIP] withdrawal_requests ENUM already updated or error: " . $e->getMessage() . "\n";
    }

    // 11. Add allow_cashout and max_withdrawal_amount columns to tenant_referral_settings
    $trs_columns = $pdo->query("SHOW COLUMNS FROM tenant_referral_settings")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('allow_cashout', $trs_columns)) {
        $pdo->exec("ALTER TABLE tenant_referral_settings ADD COLUMN allow_cashout TINYINT(1) DEFAULT 1");
        echo "[OK] Added 'allow_cashout' to tenant_referral_settings.\n";
    }
    if (!in_array('max_withdrawal_amount', $trs_columns)) {
        $pdo->exec("ALTER TABLE tenant_referral_settings ADD COLUMN max_withdrawal_amount DECIMAL(10, 2) DEFAULT 0.00");
        echo "[OK] Added 'max_withdrawal_amount' to tenant_referral_settings.\n";
    }

    echo "\nAffiliate & Referral Schema Migrations Completed Successfully!\n";

} catch (PDOException $e) {
    echo "[ERROR] Database migration failed: " . $e->getMessage() . "\n";
}
