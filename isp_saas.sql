-- ==========================================================================
-- Multi-Tenant ISP Management Platform (SaaS) - Database Seed & Install Schema
-- Optimized for Hostinger Shared Hosting (PHP 8+ & MySQL)
-- ==========================================================================

CREATE DATABASE IF NOT EXISTS isp_saas DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE isp_saas;

-- --------------------------------------------------------------------------
-- TABLE STRUCTURES
-- --------------------------------------------------------------------------

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


-- --------------------------------------------------------------------------
-- SEED DATA FOR DEMO & TESTING
-- --------------------------------------------------------------------------

-- 1. Insert Sample Tenant (ISP Owner)
-- Company Name: NetSpeed Broadband, Slug: netspeed
-- Login Email: admin@netspeed.com
-- Password: admin123 (hashed: $2y$10$8C3YQ.1oO5xK7Yg/R5G6EuA/1UuC.7P0vW9pXjO2zB1uI.2y8uD2i)
-- Bandwidth Purchased: 500 Mbps, Cost: Rs. 50,000.00
INSERT INTO tenants (id, company_name, subdomain, email, phone, password_hash, bandwidth_purchased, internet_cost) VALUES 
(1, 'NetSpeed Broadband', 'netspeed', 'admin@netspeed.com', '03001234567', '$2y$10$8C3YQ.1oO5xK7Yg/R5G6EuA/1UuC.7P0vW9pXjO2zB1uI.2y8uD2i', 500, 50000.00);

-- 2. Insert Zone Divisions
INSERT INTO zones (id, tenant_id, name, description) VALUES 
(1, 1, 'Zone Alpha', 'Sector 4, Blocks A to D - Fiber Layout'),
(2, 1, 'Zone Beta', 'Sector 9, Main Commercial Area - GPON FTTH'),
(3, 1, 'Zone Gamma', 'Sector 12, Residential Division - Cable & Wireless');

-- 3. Insert Packages
INSERT INTO packages (id, tenant_id, name, speed_mbps, monthly_price, description, status) VALUES 
(1, 1, '10 Mbps Basic', 10, 1500.00, 'Unlimited downloads, single device optimal', 'active'),
(2, 1, '25 Mbps Standard', 25, 2500.00, 'Dual-band router, high definition streaming optimal', 'active'),
(3, 1, '50 Mbps Premium', 50, 4000.00, 'Dedicated IP, lag-free gaming & backup optimal', 'active');

-- 4. Insert Test Customers
-- Passwords: password123 (hashed: $2y$10$k1w.3Fz/9j2zL6m47u7gOe/1UuC.7P0vW9pXjO2zB1uI.2y8uD2i)
-- Customer A: Muhammad Hashim - Active (Expiry in 25 Days)
-- Customer B: Asad Raza - Expiring Soon (Expiry in 3 Days)
-- Customer C: Zainab Bibi - Expired (Expiry yesterday)
-- Customer D: Hamza Khan - Suspended
INSERT INTO customers (id, tenant_id, name, cnic, phone, email, password_hash, address, area, zone_id, connection_type, assigned_package_id, monthly_fee, installation_fee, activation_date, expiry_date, status, notes) VALUES 
(1, 1, 'Muhammad Hashim', '42101-1234567-1', '03211234567', 'hashim@email.com', '$2y$10$k1w.3Fz/9j2zL6m47u7gOe/1UuC.7P0vW9pXjO2zB1uI.2y8uD2i', 'House 142, Street 3, Block B', 'Sector 4', 1, 'Fiber', 2, 2500.00, 3000.00, DATE_SUB(CURDATE(), INTERVAL 5 DAY), DATE_ADD(CURDATE(), INTERVAL 25 DAY), 'active', 'Fiber optical power level is stable at -21dBm.'),
(2, 1, 'Asad Raza', '42101-7654321-3', '03331234567', 'asad@email.com', '$2y$10$k1w.3Fz/9j2zL6m47u7gOe/1UuC.7P0vW9pXjO2zB1uI.2y8uD2i', 'Apartment 4B, Sector 9 Plaza', 'Commercial Hub', 2, 'GPON', 3, 4000.00, 0.00, DATE_SUB(CURDATE(), INTERVAL 27 DAY), DATE_ADD(CURDATE(), INTERVAL 3 DAY), 'active', 'Corporate client - priority gaming setup.'),
(3, 1, 'Zainab Bibi', '42101-9876543-5', '03451234567', 'zainab@email.com', '$2y$10$k1w.3Fz/9j2zL6m47u7gOe/1UuC.7P0vW9pXjO2zB1uI.2y8uD2i', 'House 92, Road 4, Sector 12', 'Residential Block', 3, 'Cable', 1, 1500.00, 2000.00, DATE_SUB(CURDATE(), INTERVAL 31 DAY), DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'expired', 'Needs to pay dues for reactivation.'),
(4, 1, 'Hamza Khan', '42101-4567890-7', '03009876543', 'hamza@email.com', '$2y$10$k1w.3Fz/9j2zL6m47u7gOe/1UuC.7P0vW9pXjO2zB1uI.2y8uD2i', 'Plot 10, Sector 12, P2P Wireless', 'Residential Block', 3, 'Wireless', 2, 2500.00, 4000.00, DATE_SUB(CURDATE(), INTERVAL 60 DAY), DATE_SUB(CURDATE(), INTERVAL 30 DAY), 'suspended', 'Suspended per customer holiday request.');

-- 5. Insert Sample Invoices
-- Invoice 1: Muhammad Hashim - PAID
INSERT INTO invoices (id, tenant_id, customer_id, invoice_number, package_name, total_amount, paid_amount, remaining_amount, due_date, payment_date, payment_status, created_at) VALUES 
(1, 1, 1, 'INV-20260601-001-0001-492', '25 Mbps Standard', 2500.00, 2500.00, 0.00, DATE_ADD(CURDATE(), INTERVAL 2 DAY), NOW(), 'paid', DATE_SUB(NOW(), INTERVAL 5 DAY));

-- Invoice 2: Asad Raza - PENDING (Unpaid, due in 3 days)
INSERT INTO invoices (id, tenant_id, customer_id, invoice_number, package_name, total_amount, paid_amount, remaining_amount, due_date, payment_date, payment_status, created_at) VALUES 
(2, 1, 2, 'INV-20260601-001-0002-835', '50 Mbps Premium', 4000.00, 0.00, 4000.00, DATE_ADD(CURDATE(), INTERVAL 3 DAY), NULL, 'pending', DATE_SUB(NOW(), INTERVAL 27 DAY));

-- Invoice 3: Zainab Bibi - OVERDUE (Unpaid, due yesterday)
INSERT INTO invoices (id, tenant_id, customer_id, invoice_number, package_name, total_amount, paid_amount, remaining_amount, due_date, payment_date, payment_status, created_at) VALUES 
(3, 1, 3, 'INV-20260601-001-0003-128', '10 Mbps Basic', 1500.00, 0.00, 1500.00, DATE_SUB(CURDATE(), INTERVAL 1 DAY), NULL, 'overdue', DATE_SUB(NOW(), INTERVAL 31 DAY));

-- 6. Insert System Notifications
INSERT INTO notifications (id, tenant_id, customer_id, title, message, type, is_read) VALUES 
(1, 1, 1, 'Payment Confirmed', 'Thank you! Your payment of Rs. 2,500.00 has been logged. Your subscription is extended.', 'payment', 1),
(2, 1, 2, 'Subscription Expiring Soon', 'Attention: Your internet package will expire in 3 days. Please renew to avoid service loss.', 'expiry', 0),
(3, 1, 3, 'Service Deactivation Alert', 'Notice: Your package expired yesterday. Clear your invoice INV-20260601-001-0003-128 to reactivate.', 'expiry', 0);

-- 7. Insert Initial Audit Logs
INSERT INTO audit_logs (id, tenant_id, user_type, user_id, action, ip_address) VALUES 
(1, 1, 'tenant', 1, 'Initialized new tenant platform: NetSpeed Broadband', '127.0.0.1'),
(2, 1, 'tenant', 1, 'Created default network packages and coverage zones.', '127.0.0.1'),
(3, 1, 'customer', 1, 'Muhammad Hashim logged in to Client Dashboard.', '127.0.0.1');
