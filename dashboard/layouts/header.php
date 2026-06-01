<?php
ob_start();
/**
 * Tenant Dashboard Layout Header
 */
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

// Enforce guard
require_tenant_login();

$tenant_id = $_SESSION['tenant_id'];
$tenant_name = $_SESSION['tenant_name'];
$tenant_subdomain = $_SESSION['tenant_subdomain'];

// SaaS Subscription status lock guard
try {
    $lock_state = check_tenant_lock_state($pdo, $tenant_id);
    if ($lock_state !== 'active') {
        header("Location: ../subscription_status.php");
        exit;
    }
} catch (PDOException $e) {
    error_log("SaaS Subscription guard error: " . $e->getMessage());
}

// Fetch Expiring Customer Alert Counter for Sidebar indicator (customers expiring in <= 5 days)
$notif_count = 0;
$proof_count = 0;
try {
    $today = date('Y-m-d');
    $warning_date = date('Y-m-d', strtotime('+5 days'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND status = 'active' AND expiry_date BETWEEN ? AND ?");
    $stmt->execute([$tenant_id, $today, $warning_date]);
    $notif_count = (int)$stmt->fetchColumn();
    
    // Fetch count of subscriber submitted payment proofs that are pending verification
    $stmt_p = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE tenant_id = ? AND proof_submitted = 1 AND payment_status != 'paid'");
    $stmt_p->execute([$tenant_id]);
    $proof_count = (int)$stmt_p->fetchColumn();
} catch (PDOException $e) {
    error_log("Sidebar indicator lookup fail: " . $e->getMessage());
}

// Helper to check active navigation item
function nav_active($page) {
    $current = basename($_SERVER['PHP_SELF']);
    return ($current === $page) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($tenant_name); ?> - <?php echo e(get_platform_name()); ?> Admin Panel</title>
    
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <!-- Main Style -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        body {
            background-color: #07090E !important;
        }
    </style>
    
    <script>
        // Apply sidebar collapsed state instantly to avoid layout flash
        (function() {
            const collapsed = localStorage.getItem('sidebar-collapsed') === 'true';
            if (collapsed && window.innerWidth >= 992) {
                document.documentElement.classList.add('sidebar-init-collapsed');
            }
        })();
    </script>
</head>
<body>

<!-- Mobile Sidebar Backdrop overlay -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<!-- Sidebar navigation panel -->
<aside class="sidebar-panel" id="sidebarMenu">
    <div class="px-3 py-3 d-flex align-items-center justify-content-between border-bottom" style="border-color: rgba(255,255,255,0.05) !important;">
        <a href="index.php" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
            <span class="p-1 px-2 rounded bg-primary-gradient" style="font-size: 0.95rem;"><?php echo e(get_platform_logo()); ?></span>
            <span class="text-white fw-bold brand-text" style="font-size: 1.15rem;"><?php echo e(get_platform_name()); ?></span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="toggle-sidebar-btn d-none d-lg-block" onclick="toggleSidebarCollapse()" title="Collapse Sidebar"><i class="bi bi-chevron-left"></i></button>
            <button type="button" class="btn-close btn-close-white d-lg-none" onclick="toggleSidebar()" aria-label="Close"></button>
        </div>
    </div>
    
    <div class="py-2 mt-2 d-flex flex-column justify-content-between" style="min-height: calc(100vh - 100px);">
        <div class="d-flex flex-column gap-1">
            <a href="index.php" class="sidebar-nav-item <?php echo nav_active('index.php'); ?>">
                <i class="bi bi-grid"></i> <span class="nav-text">Dashboard</span>
            </a>
            
            <a href="zones.php" class="sidebar-nav-item <?php echo nav_active('zones.php'); ?>">
                <i class="bi bi-geo"></i> <span class="nav-text">Zone Divisions</span>
            </a>
            
            <a href="packages.php" class="sidebar-nav-item <?php echo nav_active('packages.php'); ?>">
                <i class="bi bi-router"></i> <span class="nav-text">Internet Packages</span>
            </a>
            
            <a href="connection_interfaces.php" class="sidebar-nav-item <?php echo nav_active('connection_interfaces.php'); ?>">
                <i class="bi bi-hdd-network"></i> <span class="nav-text">Connection Interfaces</span>
            </a>
            
            <a href="customers.php" class="sidebar-nav-item <?php echo nav_active('customers.php'); ?>">
                <i class="bi bi-people"></i> <span class="nav-text">Customer Base</span>
                <?php if ($notif_count > 0): ?>
                    <span class="badge bg-danger ms-auto rounded-pill sidebar-header-badge" style="font-size: 0.72rem; padding: 0.25em 0.6em; animation: pulse-danger 2s infinite;"><?php echo $notif_count; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="billing.php" class="sidebar-nav-item <?php echo nav_active('billing.php'); ?>">
                <i class="bi bi-receipt"></i> <span class="nav-text">Billing & Invoices</span>
                <?php if ($proof_count > 0): ?>
                    <span class="badge bg-warning ms-auto rounded-pill sidebar-header-badge" style="font-size: 0.72rem; padding: 0.25em 0.6em; animation: pulse-warning 2s infinite; background-color: var(--warning) !important; color: #000000 !important; font-weight: 700;"><?php echo $proof_count; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="reports.php" class="sidebar-nav-item <?php echo nav_active('reports.php'); ?>">
                <i class="bi bi-bar-chart"></i> <span class="nav-text">Financial Reports</span>
            </a>

            <a href="costing_ledger.php" class="sidebar-nav-item <?php echo nav_active('costing_ledger.php'); ?>">
                <i class="bi bi-journal-text"></i> <span class="nav-text">Costing Ledger</span>
            </a>

            <a href="referrals.php" class="sidebar-nav-item <?php echo nav_active('referrals.php'); ?>">
                <i class="bi bi-gift"></i> <span class="nav-text">Referral Center</span>
            </a>

            <a href="customer_payouts.php" class="sidebar-nav-item <?php echo nav_active('customer_payouts.php'); ?>">
                <i class="bi bi-cash-coin"></i> <span class="nav-text">Customer Cashouts</span>
            </a>

            <a href="downloads.php" class="sidebar-nav-item <?php echo nav_active('downloads.php'); ?>">
                <i class="bi bi-phone"></i> <span class="nav-text">Mobile APK Center</span>
            </a>
        </div>
        
        <div class="d-flex flex-column gap-1">
            <div class="px-3 py-2 border-top border-opacity-5 border-white sidebar-workspace-label" style="font-size: 0.72rem; color: var(--text-dim);">
                ISP WORKSPACE: <strong class="text-white"><?php echo strtoupper(e($tenant_subdomain)); ?></strong>
            </div>
            
            <a href="profile.php" class="sidebar-nav-item <?php echo nav_active('profile.php'); ?>">
                <i class="bi bi-gear"></i> <span class="nav-text">ISP Profile</span>
            </a>
            
            <a href="../logout.php" class="sidebar-nav-item text-danger border border-transparent hover-border-danger">
                <i class="bi bi-box-arrow-left text-danger"></i> <span class="nav-text text-danger">Log Out</span>
            </a>
        </div>
    </div>
</aside>

<!-- Top bar header for mobile screens -->
<header class="d-lg-none d-flex align-items-center justify-content-between p-3 border-bottom sticky-top" style="background: rgba(14, 19, 31, 0.9); border-color: rgba(255,255,255,0.05) !important; backdrop-filter: blur(12px); z-index: 98;">
    <button class="btn btn-dark-glass p-2 px-2.5 rounded border border-white border-opacity-10" onclick="toggleSidebar()">
        <i class="bi bi-list fs-5"></i>
    </button>
    
    <a href="index.php" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
        <span class="p-1 px-2 rounded bg-primary-gradient" style="font-size: 0.85rem;"><?php echo e(get_platform_logo()); ?></span>
        <span class="text-white fw-bold" style="font-size: 1rem;"><?php echo e(get_platform_name()); ?></span>
    </a>
    
    <div style="width: 38px;"></div> <!-- Spacer -->
</header>

<main class="dashboard-content-wrapper">
    <div class="container-fluid px-0">
        <!-- Render alerts globally across views -->
        <?php display_session_alerts(); ?>

<script>
function toggleSidebarCollapse() {
    const sidebar = document.getElementById('sidebarMenu');
    const wrapper = document.querySelector('.dashboard-content-wrapper');
    const isCollapsed = sidebar.classList.contains('collapsed');
    
    // Remove init collapsed state to handle transitions smoothly via body classes
    document.documentElement.classList.remove('sidebar-init-collapsed');
    
    if (isCollapsed) {
        sidebar.classList.remove('collapsed');
        wrapper.classList.remove('expanded');
        localStorage.setItem('sidebar-collapsed', 'false');
    } else {
        sidebar.classList.add('collapsed');
        wrapper.classList.add('expanded');
        localStorage.setItem('sidebar-collapsed', 'true');
    }
}

function toggleSidebar() {
    const sidebar = document.getElementById('sidebarMenu');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (sidebar.classList.contains('show')) {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
    } else {
        sidebar.classList.add('show');
        backdrop.classList.add('show');
    }
}

// Restore collapsed classes on wrapper on document ready
document.addEventListener('DOMContentLoaded', function() {
    const collapsed = localStorage.getItem('sidebar-collapsed') === 'true';
    const sidebar = document.getElementById('sidebarMenu');
    const wrapper = document.querySelector('.dashboard-content-wrapper');
    
    if (collapsed && window.innerWidth >= 992) {
        sidebar.classList.add('collapsed');
        wrapper.classList.add('expanded');
    }

    // Instantly transition sidebar active styling when clicking any page-navigation links
    const allLinks = document.querySelectorAll('a');
    const sidebarItems = document.querySelectorAll('.sidebar-nav-item, .nav-link-saas');
    
    allLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
            
            // Extract file target (e.g. "billing.php?action=add" -> "billing.php")
            const targetPage = href.split('?')[0].split('/').pop();
            if (!targetPage) return;
            
            sidebarItems.forEach(item => {
                const itemHref = item.getAttribute('href');
                if (itemHref) {
                    const itemPage = itemHref.split('?')[0].split('/').pop();
                    if (itemPage === targetPage) {
                        // Remove active class from all other menu links
                        sidebarItems.forEach(i => i.classList.remove('active'));
                        // Instantly add active class to matching left side menu item
                        item.classList.add('active');
                    }
                }
            });
        });
    });

    // Track original page active menu item to restore on popup close
    let originalActiveItem = null;
    sidebarItems.forEach(item => {
        if (item.classList.contains('active')) {
            originalActiveItem = item;
        }
    });

    // When the Create Bill popup opens, transition left sidebar active class to Billing & Invoices
    document.addEventListener('show.bs.modal', function (event) {
        const modal = event.target;
        if (modal.id && modal.id.startsWith('createBillModal')) {
            sidebarItems.forEach(item => {
                const href = item.getAttribute('href');
                if (href && href.startsWith('billing.php')) {
                    sidebarItems.forEach(i => i.classList.remove('active'));
                    item.classList.add('active');
                }
            });
        }
    });

    // When the Create Bill popup closes, restore original left sidebar active item
    document.addEventListener('hide.bs.modal', function (event) {
        const modal = event.target;
        if (modal.id && modal.id.startsWith('createBillModal')) {
            sidebarItems.forEach(i => i.classList.remove('active'));
            if (originalActiveItem) {
                originalActiveItem.classList.add('active');
            }
        }
    });
});
</script>
