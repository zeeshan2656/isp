<?php
/**
 * NetPulse SaaS Platform - Joining Dates, Billing Cycles & Dynamic Connection Interfaces Migration Utility
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/database.php';

try {
    echo "Starting NetPulse Schema Upgrades for Joining Dates, Billing Cycles & Connection Interfaces...\n";

    // 1. Alter tenants table
    $tenant_cols = $pdo->query("SHOW COLUMNS FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('joining_date', $tenant_cols)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN joining_date DATE DEFAULT NULL AFTER created_at");
        echo "[OK] Added 'joining_date' column to tenants.\n";
        // Populate existing tenants
        $pdo->exec("UPDATE tenants SET joining_date = DATE(created_at) WHERE joining_date IS NULL");
    }
    if (!in_array('address', $tenant_cols)) {
        $pdo->exec("ALTER TABLE tenants ADD COLUMN address TEXT DEFAULT NULL AFTER phone");
        echo "[OK] Added 'address' column to tenants.\n";
    }

    // 2. Create connection_interfaces table
    $pdo->exec("CREATE TABLE IF NOT EXISTS connection_interfaces (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        type_category VARCHAR(100) NOT NULL,
        description TEXT DEFAULT NULL,
        speed_capacity VARCHAR(100) DEFAULT NULL,
        status ENUM('active', 'inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
        INDEX idx_tenant_conn (tenant_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "[OK] connection_interfaces table verified/created.\n";

    // 3. Alter customers table
    $customer_cols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('joining_date', $customer_cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN joining_date DATE DEFAULT NULL AFTER created_at");
        echo "[OK] Added 'joining_date' column to customers.\n";
        // Populate existing customers
        $pdo->exec("UPDATE customers SET joining_date = activation_date WHERE joining_date IS NULL");
    }
    if (!in_array('connection_interface_id', $customer_cols)) {
        $pdo->exec("ALTER TABLE customers ADD COLUMN connection_interface_id INT DEFAULT NULL AFTER zone_id");
        echo "[OK] Added 'connection_interface_id' column to customers.\n";
        try {
            $pdo->exec("ALTER TABLE customers ADD CONSTRAINT fk_customer_conn_interface FOREIGN KEY (connection_interface_id) REFERENCES connection_interfaces(id) ON DELETE SET NULL");
            echo "[OK] Added foreign key constraint fk_customer_conn_interface on customers.\n";
        } catch (PDOException $ex) {
            echo "[INFO] Constraint fk_customer_conn_interface already exists or failed to create: " . $ex->getMessage() . "\n";
        }
    }

    // 4. Alter invoices table to store billing period
    $invoice_cols = $pdo->query("SHOW COLUMNS FROM invoices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('billing_start_date', $invoice_cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN billing_start_date DATE DEFAULT NULL AFTER total_amount");
        echo "[OK] Added 'billing_start_date' column to invoices.\n";
    }
    if (!in_array('billing_end_date', $invoice_cols)) {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN billing_end_date DATE DEFAULT NULL AFTER billing_start_date");
        echo "[OK] Added 'billing_end_date' column to invoices.\n";
    }

    // Seed billing start/end dates for existing invoices if empty
    $pdo->exec("UPDATE invoices SET billing_start_date = DATE(created_at), billing_end_date = due_date WHERE billing_start_date IS NULL");

    // 5. Alter saas_invoices table to store billing period
    $saas_invoice_cols = $pdo->query("SHOW COLUMNS FROM saas_invoices")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('billing_start_date', $saas_invoice_cols)) {
        $pdo->exec("ALTER TABLE saas_invoices ADD COLUMN billing_start_date DATE DEFAULT NULL AFTER amount");
        echo "[OK] Added 'billing_start_date' column to saas_invoices.\n";
    }
    if (!in_array('billing_end_date', $saas_invoice_cols)) {
        $pdo->exec("ALTER TABLE saas_invoices ADD COLUMN billing_end_date DATE DEFAULT NULL AFTER billing_start_date");
        echo "[OK] Added 'billing_end_date' column to saas_invoices.\n";
    }
    
    // Seed saas_invoices billing dates
    $pdo->exec("UPDATE saas_invoices SET billing_start_date = DATE(created_at), billing_end_date = due_date WHERE billing_start_date IS NULL");

    // 6. Seed default connection interfaces for existing tenants
    $tenants = $pdo->query("SELECT id FROM tenants")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tenants as $t_id) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM connection_interfaces WHERE tenant_id = ?");
        $check->execute([$t_id]);
        if ((int)$check->fetchColumn() === 0) {
            $defaults = [
                ['Optical Fiber', 'Fiber', 'High-speed single-core/dual-core fiber optics connection', '100 Mbps'],
                ['Strong Fiber', 'Fiber', 'Premium high-durability fiber drop wire connection', '200 Mbps'],
                ['GPON', 'GPON', 'Gigabit Passive Optical Network connection', '1000 Mbps'],
                ['FTTH', 'Fiber', 'Fiber To The Home residential broadband', '100 Mbps'],
                ['Wireless Link', 'Wireless', 'Point-to-point wireless client antenna link', '50 Mbps'],
                ['Radio Connection', 'Wireless', 'High-frequency outdoor radio link', '30 Mbps'],
                ['Dedicated Line', 'Corporate', 'Symmetric dedicated leased line connection', '1 Gbps'],
                ['Corporate Fiber', 'Corporate', 'Enterprise fiber ring connectivity', '10 Gbps'],
                ['Home Broadband', 'Cable', 'Coaxial hybrid copper-fiber home connection', '25 Mbps']
            ];
            
            $ins = $pdo->prepare("INSERT INTO connection_interfaces (tenant_id, name, type_category, description, speed_capacity, status) VALUES (?, ?, ?, ?, ?, 'active')");
            foreach ($defaults as $d) {
                $ins->execute([$t_id, $d[0], $d[1], $d[2], $d[3]]);
            }
            echo "[OK] Seeded default connection interfaces for Tenant ID: $t_id\n";
        }
    }

    // 7. Map existing customer static connection types to seeded connection interfaces
    $customers = $pdo->query("SELECT id, tenant_id, connection_type FROM customers WHERE connection_interface_id IS NULL")->fetchAll();
    foreach ($customers as $c) {
        $mapped_name = 'Optical Fiber';
        if (stripos($c['connection_type'], 'gpon') !== false) {
            $mapped_name = 'GPON';
        } elseif (stripos($c['connection_type'], 'cable') !== false) {
            $mapped_name = 'Home Broadband';
        } elseif (stripos($c['connection_type'], 'wireless') !== false) {
            $mapped_name = 'Wireless Link';
        }
        
        $stmt = $pdo->prepare("SELECT id FROM connection_interfaces WHERE tenant_id = ? AND name = ? LIMIT 1");
        $stmt->execute([$c['tenant_id'], $mapped_name]);
        $conn_id = $stmt->fetchColumn();
        
        if ($conn_id) {
            $upd = $pdo->prepare("UPDATE customers SET connection_interface_id = ? WHERE id = ?");
            $upd->execute([$conn_id, $c['id']]);
        }
    }
    echo "[OK] Mapped existing customers to default connection interfaces.\n";

    echo "\nDatabase Migrations Completed Successfully!\n";

} catch (PDOException $e) {
    echo "[FATAL ERROR] Migration failed: " . $e->getMessage() . "\n";
}
