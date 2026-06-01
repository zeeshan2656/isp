<?php
/**
 * Public Affiliate Workspace Header Layout
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Enforce Affiliate Login
require_affiliate_login();

$affiliate_id = $_SESSION['affiliate_id'];
$affiliate_name = $_SESSION['affiliate_name'];
$affiliate_email = $_SESSION['affiliate_email'];

// Highlight active sidebar links
$current_page = basename($_SERVER['PHP_SELF']);

// Load affiliate data
$aff_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE id = ? LIMIT 1");
    $stmt->execute([$affiliate_id]);
    $aff_data = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Affiliate header load error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_platform_name()); ?> Partner Portal</title>
    
    <!-- Fonts and Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        :root {
            --primary: #A855F7; /* Purple Theme for Affiliate partners */
            --primary-rgb: 168, 85, 247;
            --accent: #E9D5FF;
        }
        
        body {
            background-color: #080B11 !important;
        }
        
        /* Override sidebar-nav-item hover/active with affiliate purple theme */
        .sidebar-nav-item:hover, .sidebar-nav-item.active {
            background: rgba(168, 85, 247, 0.08) !important;
            border-color: rgba(168, 85, 247, 0.2) !important;
        }
        .sidebar-nav-item:hover i, .sidebar-nav-item.active i {
            color: var(--primary) !important;
        }
    </style>

    <script>
        // Apply sidebar collapsed state instantly to avoid layout flash
        (function() {
            const collapsed = localStorage.getItem('sidebar-collapsed-affiliate') === 'true';
            if (collapsed && window.innerWidth >= 992) {
                document.documentElement.classList.add('sidebar-init-collapsed');
            }
        })();
    </script>
</head>
<body class="bg-dark text-white">

    <!-- Mobile Sidebar Backdrop overlay -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

    <!-- Sidebar Navigation -->
    <aside class="sidebar-panel" id="sidebarMenu">
        <!-- SaaS Platform Branding -->
        <div class="px-3 py-3 d-flex align-items-center justify-content-between border-bottom" style="border-color: rgba(255,255,255,0.05) !important;">
            <a href="index.php" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
                <span class="p-1 px-2 rounded bg-primary-gradient" style="background: linear-gradient(135deg, var(--primary), var(--accent)); font-size: 0.95rem;">PN</span>
                <span class="text-white fw-bold brand-text" style="font-size: 1.15rem;"><?php echo e(get_platform_name()); ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <button type="button" class="toggle-sidebar-btn d-none d-lg-block" onclick="toggleSidebarCollapse()" title="Collapse Sidebar"><i class="bi bi-chevron-left"></i></button>
                <button type="button" class="btn-close btn-close-white d-lg-none" onclick="toggleSidebar()" aria-label="Close"></button>
            </div>
        </div>
        
        <div class="py-2 mt-2 d-flex flex-column justify-content-between" style="min-height: calc(100vh - 100px);">
            <div class="d-flex flex-column gap-1">
                <a href="index.php" class="sidebar-nav-item <?php echo ($current_page === 'index.php') ? 'active' : ''; ?>">
                    <i class="bi bi-speedometer2"></i>
                    <span class="nav-text">Earnings & Stats</span>
                </a>
                
                <a href="withdraw.php" class="sidebar-nav-item <?php echo ($current_page === 'withdraw.php') ? 'active' : ''; ?>">
                    <i class="bi bi-wallet2"></i>
                    <span class="nav-text">Request Payout</span>
                </a>
                
                <a href="../index.php" class="sidebar-nav-item">
                    <i class="bi bi-box-arrow-up-right"></i>
                    <span class="nav-text">Public Website</span>
                </a>
            </div>
            
            <!-- Bottom profile info and logout link -->
            <div class="d-flex flex-column gap-1">
                <div class="px-3 py-2 border-top border-opacity-5 border-white sidebar-workspace-label" style="font-size: 0.72rem; color: var(--text-dim);">
                    PARTNER: <strong class="text-white"><?php echo strtoupper(e($affiliate_name)); ?></strong>
                </div>
                
                <a href="profile.php" class="sidebar-nav-item <?php echo ($current_page === 'profile.php') ? 'active' : ''; ?>">
                    <i class="bi bi-person-badge"></i>
                    <span class="nav-text">My Profile</span>
                </a>
                
                <a href="../logout.php" class="sidebar-nav-item text-danger">
                    <i class="bi bi-box-arrow-left text-danger"></i> <span class="nav-text text-danger">Log Out</span>
                </a>
            </div>
        </div>
    </aside>

    <!-- Top bar header for mobile screens -->
    <header class="d-lg-none d-flex align-items-center justify-content-between p-3 border-bottom sticky-top" style="background: rgba(11, 15, 25, 0.9); border-color: rgba(255,255,255,0.05) !important; backdrop-filter: blur(12px); z-index: 998;">
        <button class="btn btn-dark-glass p-2 px-2.5 rounded border border-white border-opacity-10" onclick="toggleSidebar()">
            <i class="bi bi-list fs-5"></i>
        </button>
        
        <a href="index.php" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
            <span class="p-1 px-2 rounded" style="background: linear-gradient(135deg, var(--primary), var(--accent)); font-size: 0.85rem;">PN</span>
            <span class="text-white fw-bold" style="font-size: 1rem;"><?php echo e(get_platform_name()); ?></span>
        </a>
        
        <div style="width: 38px;"></div> <!-- Spacer -->
    </header>

    <!-- Main Content Workspace -->
    <main class="dashboard-content-wrapper">
        <div class="container-fluid px-0">
        
        <!-- Flash Alerts Box -->
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
        localStorage.setItem('sidebar-collapsed-affiliate', 'false');
    } else {
        sidebar.classList.add('collapsed');
        wrapper.classList.add('expanded');
        localStorage.setItem('sidebar-collapsed-affiliate', 'true');
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
    const collapsed = localStorage.getItem('sidebar-collapsed-affiliate') === 'true';
    const sidebar = document.getElementById('sidebarMenu');
    const wrapper = document.querySelector('.dashboard-content-wrapper');
    
    if (collapsed && window.innerWidth >= 992) {
        sidebar.classList.add('collapsed');
        wrapper.classList.add('expanded');
    }
});
</script>
