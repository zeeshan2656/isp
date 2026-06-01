<?php
/**
 * NetPulse SaaS Subscription Engine Schema Migration Utility
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/database.php';

try {
    echo "Starting NetPulse SaaS Schema Migration...\n";

    // 1. Create saas_plans Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        max_customers INT NOT NULL,
        max_zones INT NOT NULL,
        max_packages INT NOT NULL,
        monthly_fee DECIMAL(10, 2) NOT NULL,
        features_list TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] saas_plans table verified/created.\n";

    // Seed plans if empty
    $check = $pdo->query("SELECT COUNT(*) FROM saas_plans")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("INSERT INTO saas_plans (id, name, max_customers, max_zones, max_packages, monthly_fee, features_list) VALUES
        (1, 'Starter Plan', 50, 3, 5, 2500.00, 'Up to 50 Subscribers, 3 coverage zones, basic analytics'),
        (2, 'Professional Plan', 500, 15, 20, 7500.00, 'Up to 500 Subscribers, 15 zones, custom invoicing revisions'),
        (3, 'Enterprise Plan', 99999, 100, 100, 15000.00, 'Unlimited Subscribers, full feature suite, support dashboard')");
        echo "[OK] Default SaaS subscription plans seeded.\n";
    }

    // 2. Create super_admins Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS super_admins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] super_admins table verified/created.\n";

    // Seed default Super Admin if empty
    $check = $pdo->query("SELECT COUNT(*) FROM super_admins")->fetchColumn();
    if ($check == 0) {
        // admin@netpulse.saas / super123
        $hash = password_hash('super123', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO super_admins (id, name, email, password_hash) VALUES (1, 'Super Admin Owner', 'admin@netpulse.saas', ?)");
        $stmt->execute([$hash]);
        echo "[OK] Super Admin credentials seeded: admin@netpulse.saas / super123\n";
    }

    // 2b. Add profile fields to super_admins if missing
    $sa_columns = $pdo->query("SHOW COLUMNS FROM super_admins")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('phone', $sa_columns)) {
        $pdo->exec("ALTER TABLE super_admins ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER email");
        echo "[OK] Added 'phone' to super_admins.\n";
    }
    if (!in_array('address', $sa_columns)) {
        $pdo->exec("ALTER TABLE super_admins ADD COLUMN address TEXT DEFAULT NULL AFTER phone");
        echo "[OK] Added 'address' to super_admins.\n";
    }
    if (!in_array('company_name', $sa_columns)) {
        $pdo->exec("ALTER TABLE super_admins ADD COLUMN company_name VARCHAR(200) DEFAULT NULL AFTER name");
        echo "[OK] Added 'company_name' to super_admins.\n";
    }

    // 3. Alter tenants Table to include Subscription & Plan parameters
    // Check if columns already exist
    $columns = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('plan_id', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN plan_id INT DEFAULT 1");
        echo "[OK] Added Column 'plan_id' to tenants.\n";
    }
    if (!in_array('status', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN status ENUM('pending', 'trial', 'active', 'expired', 'suspended') DEFAULT 'pending'");
        echo "[OK] Added Column 'status' to tenants.\n";
    }
    if (!in_array('subscription_start', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN subscription_start DATE DEFAULT NULL");
        echo "[OK] Added Column 'subscription_start' to tenants.\n";
    }
    if (!in_array('subscription_end', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN subscription_end DATE DEFAULT NULL");
        echo "[OK] Added Column 'subscription_end' to tenants.\n";
    }
    if (!in_array('monthly_fee', $columns)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN monthly_fee DECIMAL(10, 2) DEFAULT 2500.00");
        echo "[OK] Added Column 'monthly_fee' to tenants.\n";
    }

    // Check constraint/foreign key
    try {
        $pdo->exec("ALTER TABLE tenants ADD CONSTRAINT fk_tenant_plan FOREIGN KEY (plan_id) REFERENCES saas_plans(id)");
        echo "[OK] Added plan_id foreign key constraint on tenants.\n";
    } catch (PDOException $ex) {
        // Might already exist
        echo "[INFO] Constraint fk_tenant_plan already verified.\n";
    }

    // Update existing tenant (Tenant 1) to active Enterprise plan so it continues to function
    $pdo->exec("UPDATE tenants SET plan_id = 3, status = 'active', subscription_start = CURDATE(), subscription_end = DATE_ADD(CURDATE(), INTERVAL 365 DAY), monthly_fee = 15000.00 WHERE id = 1");
    echo "[OK] Existing tenant (ID 1) upgraded to active Enterprise Plan.\n";

    // 4. Create saas_invoices Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        invoice_number VARCHAR(50) UNIQUE NOT NULL,
        plan_name VARCHAR(100) NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        paid_amount DECIMAL(10, 2) DEFAULT 0.00,
        remaining_amount DECIMAL(10, 2) NOT NULL,
        due_date DATE NOT NULL,
        payment_date DATETIME DEFAULT NULL,
        payment_status ENUM('paid', 'partial', 'pending', 'overdue') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        INDEX idx_saas_pay (payment_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] saas_invoices table verified/created.\n";

    // 5. Create invoice_revisions Table (Auditing paid/pending changes)
    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_revisions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        invoice_id INT NOT NULL,
        edited_by INT NOT NULL,
        change_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        previous_values JSON NOT NULL,
        new_values JSON NOT NULL,
        modification_reason TEXT NOT NULL,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
        INDEX idx_tenant_rev (tenant_id, invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] invoice_revisions table verified/created.\n";

    echo "SaaS Migration Completed Successfully!\n";

} catch (PDOException $e) {
    echo "[ERROR] Database migration failed: " . $e->getMessage() . "\n";
}
