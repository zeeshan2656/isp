<?php
ob_start();
/**
 * Customer Self-Service Portal Header Layout
 */
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

// Enforce customer guard
require_customer_login();

$customer_id = $_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'];
$customer_email = $_SESSION['customer_email'];
$customer_tenant_id = $_SESSION['customer_tenant_id'];

// Check dynamic customer lock state
$customer_status_lock = check_customer_lock_state($pdo, $customer_id);

if ($customer_status_lock === 'pending_approval' && basename($_SERVER['PHP_SELF']) !== 'pending.php') {
    header("Location: pending.php");
    exit;
}

// Fetch ISP name for context header
$isp_name = 'My Service Provider';
try {
    $stmt = $pdo->prepare("SELECT company_name FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$customer_tenant_id]);
    $isp_name = (string)$stmt->fetchColumn() ?: 'My Service Provider';
} catch (PDOException $e) {
    error_log("ISP lookup inside customer panel fail: " . $e->getMessage());
}

// Helper to check active navigation item
function cust_nav_active($page) {
    $current = basename($_SERVER['PHP_SELF']);
    return ($current === $page) ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_platform_name()); ?> Customer Self-Service Desk</title>
    
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <!-- Main Style -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        body {
            background-color: #07080D !important;
        }
        .sidebar-panel {
            border-right: 1px solid rgba(168, 85, 247, 0.1) !important;
        }
        .sidebar-nav-item:hover, .sidebar-nav-item.active {
            background: rgba(168, 85, 247, 0.08) !important;
            border-color: rgba(168, 85, 247, 0.2) !important;
        }
        .sidebar-nav-item:hover i, .sidebar-nav-item.active i {
            color: var(--secondary) !important;
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

<!-- Sidebar navigation panel for Customers -->
<aside class="sidebar-panel" id="sidebarMenu">
    <div class="px-3 py-3 d-flex align-items-center justify-content-between border-bottom" style="border-color: rgba(255,255,255,0.05) !important;">
        <a href="index.php" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
            <span class="p-1 px-2 rounded bg-primary-gradient" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); font-size: 0.95rem;">CS</span>
            <span class="text-white fw-bold brand-text" style="font-size: 1.15rem;">Self Service</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="toggle-sidebar-btn d-none d-lg-block" onclick="toggleSidebarCollapse()" title="Collapse Sidebar"><i class="bi bi-chevron-left"></i></button>
            <button type="button" class="btn-close btn-close-white d-lg-none" onclick="toggleSidebar()" aria-label="Close"></button>
        </div>
    </div>
    
    <div class="py-2 mt-2 d-flex flex-column justify-content-between" style="min-height: calc(100vh - 100px);">
        <div class="d-flex flex-column gap-1">
            <a href="index.php" class="sidebar-nav-item <?php echo cust_nav_active('index.php'); ?>">
                <i class="bi bi-person-workspace"></i> <span class="nav-text">My Overview</span>
            </a>
            
            <a href="payments.php" class="sidebar-nav-item <?php echo cust_nav_active('payments.php'); ?>">
                <i class="bi bi-wallet2"></i> <span class="nav-text">Payment History</span>
            </a>

            <?php 
            $show_ref_link = false;
            try {
                $stmt = $pdo->prepare("SELECT enabled FROM tenant_referral_settings WHERE tenant_id = ? LIMIT 1");
                $stmt->execute([$_SESSION['customer_tenant_id']]);
                $show_ref_link = ((int)$stmt->fetchColumn() === 1);
            } catch (Exception $ex) {}
            if ($show_ref_link && $customer_status_lock === 'active'):
            ?>
                <a href="referrals.php" class="sidebar-nav-item <?php echo cust_nav_active('referrals.php'); ?>">
                    <i class="bi bi-gift"></i> <span class="nav-text">Referral Rewards</span>
                </a>
            <?php endif; ?>
        </div>
        
        <div class="d-flex flex-column gap-1">
            <div class="px-3 py-2 border-top border-opacity-5 border-white sidebar-workspace-label" style="font-size: 0.72rem; color: var(--text-dim);">
                ISP: <strong class="text-white"><?php echo strtoupper(e($isp_name)); ?></strong>
            </div>
            
            <?php if ($customer_status_lock === 'active'): ?>
                <a href="profile.php" class="sidebar-nav-item <?php echo cust_nav_active('profile.php'); ?>">
                    <i class="bi bi-shield-check"></i> <span class="nav-text">Account Settings</span>
                </a>
            <?php endif; ?>
            
            <a href="../logout.php" class="sidebar-nav-item text-danger">
                <i class="bi bi-box-arrow-left text-danger"></i> <span class="nav-text text-danger">Log Out</span>
            </a>
        </div>
    </div>
</aside>

<!-- Top bar for mobile screens -->
<header class="d-lg-none d-flex align-items-center justify-content-between p-3 border-bottom sticky-top" style="background: rgba(14, 19, 31, 0.9); border-color: rgba(255,255,255,0.05) !important; backdrop-filter: blur(12px); z-index: 98;">
    <button class="btn btn-dark-glass p-2 px-2.5 rounded border border-white border-opacity-10" onclick="toggleSidebar()">
        <i class="bi bi-list fs-5"></i>
    </button>
    
    <a href="index.php" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
        <span class="p-1 px-2 rounded bg-primary-gradient" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); font-size: 0.85rem;">CS</span>
        <span class="text-white fw-bold" style="font-size: 1rem;">Self Service</span>
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
            
            // Extract file target (e.g. "payments.php?action=view" -> "payments.php")
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
});
</script>
