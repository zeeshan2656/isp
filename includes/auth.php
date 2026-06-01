<?php
/**
 * Authentication and Access Control Guards
 */

defined('SECURE_ACCESS') or die('Direct access denied');

require_once __DIR__ . '/security.php';

/**
 * Check if Tenant (ISP) is logged in
 */
function is_tenant_logged_in() {
    return isset($_SESSION['tenant_id']) && isset($_SESSION['tenant_email']) && $_SESSION['role'] === 'tenant';
}

/**
 * Require Tenant Login Guard
 */
function require_tenant_login() {
    if (!is_tenant_logged_in()) {
        header("Location: ../login.php?err=login_required");
        exit;
    }
}

/**
 * Check if Customer is logged in
 */
function is_customer_logged_in() {
    return isset($_SESSION['customer_id']) && isset($_SESSION['customer_email']) && $_SESSION['role'] === 'customer';
}

/**
 * Require Customer Login Guard
 */
function require_customer_login() {
    if (!is_customer_logged_in()) {
        header("Location: ../customer-login.php?err=login_required");
        exit;
    }
}

/**
 * Log in Tenant session
 */
function login_tenant($tenant) {
    session_regenerate_id(true);
    $_SESSION['tenant_id'] = $tenant['id'];
    $_SESSION['tenant_name'] = $tenant['company_name'];
    $_SESSION['tenant_email'] = $tenant['email'];
    $_SESSION['tenant_subdomain'] = $tenant['subdomain'];
    $_SESSION['role'] = 'tenant';
    $_SESSION['initiated_at'] = time();
    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Log in Customer session
 */
function login_customer($customer) {
    session_regenerate_id(true);
    $_SESSION['customer_id'] = $customer['id'];
    $_SESSION['customer_name'] = $customer['name'];
    $_SESSION['customer_email'] = $customer['email'];
    $_SESSION['customer_tenant_id'] = $customer['tenant_id'];
    $_SESSION['role'] = 'customer';
    $_SESSION['initiated_at'] = time();
    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
}

/**
 * Check if Super Admin is logged in
 */
function is_super_admin_logged_in() {
    return isset($_SESSION['super_admin_id']) && isset($_SESSION['super_admin_email']) && $_SESSION['role'] === 'super_admin';
}

/**
 * Require Super Admin Login Guard
 */
function require_super_admin_login() {
    if (!is_super_admin_logged_in()) {
        header("Location: login.php?err=login_required");
        exit;
    }
}

/**
 * Check if Public Affiliate is logged in
 */
function is_affiliate_logged_in() {
    return isset($_SESSION['affiliate_id']) && isset($_SESSION['affiliate_email']) && $_SESSION['role'] === 'affiliate';
}

/**
 * Require Affiliate Login Guard
 */
function require_affiliate_login() {
    if (!is_affiliate_logged_in()) {
        header("Location: affiliate-login.php?err=login_required");
        exit;
    }
}

/**
 * Log Audit Activity
 */
function log_audit_activity($pdo, $tenant_id, $user_type, $user_id, $action) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_type, user_id, action, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $user_type, $user_id, $action, $ip]);
    } catch (PDOException $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}

/**
 * Check Tenant Workspace Lock State
 */
function check_tenant_lock_state($pdo, $tenant_id) {
    try {
        $stmt = $pdo->prepare("SELECT status, subscription_end FROM tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch();
        
        if (!$tenant) {
            return 'not_found';
        }
        
        if ($tenant['status'] === 'pending') {
            return 'pending_approval';
        }
        
        if ($tenant['status'] === 'suspended') {
            return 'suspended';
        }
        
        // Expiry check
        $today = date('Y-m-d');
        if ($tenant['status'] === 'expired' || ($tenant['subscription_end'] && $tenant['subscription_end'] < $today)) {
            return 'expired';
        }
        
        // Payment check: Check if there is an unpaid SaaS invoice
        $stmt_inv = $pdo->prepare("SELECT COUNT(*) FROM saas_invoices WHERE tenant_id = ? AND payment_status != 'paid'");
        $stmt_inv->execute([$tenant_id]);
        $unpaid_count = (int)$stmt_inv->fetchColumn();
        
        if ($unpaid_count > 0) {
            return 'awaiting_payment';
        }
        
        return 'active';
    } catch (PDOException $e) {
        error_log("check_tenant_lock_state fail: " . $e->getMessage());
        return 'active'; // Fallback
    }
}

/**
 * Check Customer Workspace Lock State
 */
function check_customer_lock_state($pdo, $customer_id) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM customers WHERE id = ? LIMIT 1");
        $stmt->execute([$customer_id]);
        $cust = $stmt->fetch();
        
        if (!$cust) {
            return 'not_found';
        }
        
        if ($cust['status'] === 'pending') {
            return 'pending_approval';
        }
        
        if ($cust['status'] === 'suspended') {
            return 'suspended';
        }
        
        $today = date('Y-m-d');
        
        // 1. Check if there is an active paid subscription covering today
        $stmt_paid = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND payment_status = 'paid' AND ? BETWEEN billing_start_date AND billing_end_date");
        $stmt_paid->execute([$customer_id, $today]);
        $has_active_paid = (int)$stmt_paid->fetchColumn();
        
        if ($has_active_paid > 0) {
            return 'active';
        }
        
        // 2. Check if they have ANY paid invoice in the past (so they did have service, but it has now expired)
        $stmt_any_paid = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND payment_status = 'paid'");
        $stmt_any_paid->execute([$customer_id]);
        $has_any_paid = (int)$stmt_any_paid->fetchColumn();
        
        if ($has_any_paid > 0) {
            return 'expired';
        }
        
        // 3. Otherwise, they have never paid (e.g. brand new customer with a pending first invoice)
        return 'awaiting_payment';
    } catch (PDOException $e) {
        error_log("check_customer_lock_state fail: " . $e->getMessage());
        return 'active'; // Fallback
    }
}
