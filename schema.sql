-- Multi-Tenant ISP Management Platform (SaaS) - Database Schema
-- Optimized for Hostinger Shared Hosting (PHP 8+ & MySQL)

CREATE DATABASE IF NOT EXISTS isp_saas DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE isp_saas;

-- 1. Tenants (ISP Entities)
CREATE TABLE IF NOT EXISTS tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    subdomain VARCHAR(100) UNIQUE NOT NULL, -- Custom URL slug/identifier
    email VARCHAR(100) UNIQUE NOT NULL,
    phone VARCHAR(20) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    bandwidth_purchased INT DEFAULT 0, -- in Mbps
    internet_cost DECIMAL(10, 2) DEFAULT 0.00, -- monthly purchase expense
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_subdomain (subdomain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Zones (ISP Areas per Tenant)
CREATE TABLE IF NOT EXISTS zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_zone (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Custom Internet Packages per Tenant
CREATE TABLE IF NOT EXISTS packages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    speed_mbps INT NOT NULL,
    monthly_price DECIMAL(10, 2) NOT NULL,
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_package (tenant_id, id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Customers per Tenant
CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    cnic VARCHAR(30) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL, -- For customer portal access
    address TEXT NOT NULL,
    area VARCHAR(100),
    zone_id INT NOT NULL,
    connection_type VARCHAR(50) DEFAULT 'Fiber', -- Fiber, GPON, Cable, Wireless, etc.
    assigned_package_id INT NOT NULL,
    monthly_fee DECIMAL(10, 2) NOT NULL, -- Overridable package cost
    installation_fee DECIMAL(10, 2) DEFAULT 0.00,
    activation_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    status ENUM('active', 'expired', 'suspended') DEFAULT 'active',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE RESTRICT,
    FOREIGN KEY (assigned_package_id) REFERENCES packages(id) ON DELETE RESTRICT,
    INDEX idx_tenant_customer (tenant_id, id),
    INDEX idx_expiry (expiry_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Invoices (Billing & Payments)
CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL,
    package_name VARCHAR(100) NOT NULL, -- snapshotted package name
    total_amount DECIMAL(10, 2) NOT NULL,
    paid_amount DECIMAL(10, 2) DEFAULT 0.00,
    remaining_amount DECIMAL(10, 2) NOT NULL,
    due_date DATE NOT NULL,
    payment_date DATETIME DEFAULT NULL,
    payment_status ENUM('paid', 'partial', 'pending', 'overdue') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    INDEX idx_tenant_invoice (tenant_id, id),
    INDEX idx_invoice_num (invoice_number),
    INDEX idx_pay_status (payment_status),
    INDEX idx_due_date (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    customer_id INT DEFAULT NULL, -- NULL if global tenant/system alert, INT if for customer
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'info', -- 'expiry', 'payment', 'system'
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_notif (tenant_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Audit Logs
CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    user_type ENUM('tenant', 'customer') NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    INDEX idx_tenant_logs (tenant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Invoice Revisions (Auditing paid/pending changes)
CREATE TABLE IF NOT EXISTS invoice_revisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    invoice_id INT NOT NULL,
    edited_by INT NOT NULL, -- Tenant admin ID who executed the edit
    change_timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    previous_values JSON NOT NULL, -- Snapshot of changed parameters
    new_values JSON NOT NULL,      -- Snapshot of updated parameters
    modification_reason TEXT NOT NULL, -- Reason required for audit trails
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
    INDEX idx_tenant_rev (tenant_id, invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

