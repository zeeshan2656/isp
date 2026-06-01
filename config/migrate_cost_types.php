<?php
/**
 * NetPulse SaaS Platform - Monthly Cost Ledger Categories Upgrade
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/database.php';

try {
    echo "Starting NetPulse Costing Ledger Schema Upgrades for multiple cost categories...\n\n";

    // 1. Alter monthly_costs table: Add 'cost_type' column
    $cols = array_column($pdo->query("SHOW COLUMNS FROM monthly_costs")->fetchAll(), 'Field');
    if (!in_array('cost_type', $cols)) {
        $pdo->exec("ALTER TABLE monthly_costs ADD COLUMN cost_type VARCHAR(100) DEFAULT 'Bandwidth Cost' AFTER year");
        echo "[OK] Added 'cost_type' column to monthly_costs.\n";
    } else {
        echo "[SKIP] 'cost_type' column already exists in monthly_costs.\n";
    }

    // 2. Drop the old unique key 'unique_month_year'
    try {
        $pdo->exec("ALTER TABLE monthly_costs DROP INDEX unique_month_year");
        echo "[OK] Dropped index 'unique_month_year'.\n";
    } catch (PDOException $ex) {
        echo "[INFO] Index 'unique_month_year' already dropped or does not exist.\n";
    }

    // 3. Add the new composite unique key (tenant_id, month, year, cost_type)
    try {
        $pdo->exec("ALTER TABLE monthly_costs ADD UNIQUE KEY unique_month_year_type (tenant_id, month, year, cost_type)");
        echo "[OK] Added composite unique index 'unique_month_year_type'.\n";
    } catch (PDOException $ex) {
        echo "[INFO] Index 'unique_month_year_type' already exists or failed to create: " . $ex->getMessage() . "\n";
    }

    echo "\n[SUCCESS] Costing categories database migration completed successfully!\n";

} catch (PDOException $e) {
    echo "[ERROR] Costing categories migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
