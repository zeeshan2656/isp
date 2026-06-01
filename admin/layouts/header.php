<?php
ob_start();
/**
 * NetPulse Super Admin Workspace Header Layout
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

// Enforce Super Admin Guard
require_super_admin_login();

$admin_id = $_SESSION['super_admin_id'];
$admin_name = $_SESSION['super_admin_name'];
$admin_email = $_SESSION['super_admin_email'];

// Highlight current active sidebar option
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch Pending Payment Reviews Count
$pending_payments_count = 0;
try {
    $pending_payments_count = (int)$pdo->query("SELECT COUNT(*) FROM payment_submissions WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) {
    error_log("Pending payments count query fail: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_platform_name()); ?> Super Admin Workspace</title>
    
    <!-- Google Fonts Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Platform Premium Global Theme Style -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        /* Custom enhancements specifically for super-admin dashboard workspace color coordination */
        :root {
            --primary: #A855F7; /* Override main brand with Purple for Super Admin */
            --primary-rgb: 168, 85, 247;
            --accent: #E9D5FF;
        }
        
        .super-admin-sidebar {
            width: 280px;
            background: #0B0F19;
            border-right: 1px solid rgba(255,255,255,0.06);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .super-admin-content {
            margin-left: 280px;
            padding: 2.5rem;
            min-height: 100vh;
            background: #080B11;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .nav-link-saas {
            display: flex;
            align-items: center;
            padding: 0.48rem 0.85rem; /* Reduced from 0.85rem 1.25rem for Notion compactness */
            color: var(--text-dim) !important;
            font-size: 0.88rem; /* Slightly smaller typography */
            font-weight: 500;
            border-radius: 8px;
            margin-bottom: 0.25rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            border: 1px solid transparent;
        }
        
        .nav-link-saas i {
            font-size: 1.1rem;
            margin-right: 0.75rem;
            transition: transform 0.25s;
        }
        
        .nav-link-saas:hover {
            color: #ffffff !important;
            background: rgba(255,255,255,0.03);
        }
        
        .nav-link-saas.active {
            color: #ffffff !important;
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.15) 0%, rgba(168, 85, 247, 0.05) 100%) !important;
            border: 1px solid rgba(168, 85, 247, 0.25) !important;
            box-shadow: 0 4px 20px rgba(168, 85, 247, 0.08);
        }
        
        .nav-link-saas.active i {
            color: var(--primary) !important;
            transform: scale(1.1);
        }
        
        /* Collapsed super admin sidebar */
        .super-admin-sidebar.collapsed {
            width: 70px !important;
            padding: 1.5rem 0.5rem !important;
            align-items: center;
        }
        .super-admin-sidebar.collapsed .sidebar-logo-text,
        .super-admin-sidebar.collapsed .sidebar-text,
        .super-admin-sidebar.collapsed .border-top {
            display: none !important;
        }
        .super-admin-sidebar.collapsed .nav-link-saas {
            justify-content: center !important;
            padding: 0.65rem 0 !important;
            margin-bottom: 0.3rem !important;
        }
        .super-admin-sidebar.collapsed .nav-link-saas i {
            margin-right: 0 !important;
            font-size: 1.3rem !important;
        }
        .super-admin-sidebar.collapsed .navbar-brand {
            justify-content: center !important;
        }
        .super-admin-content.expanded {
            margin-left: 70px !important;
        }
        
        /* Instant anti-flicker classes */
        html.sidebar-init-collapsed .super-admin-sidebar {
            width: 70px !important;
            padding: 1.5rem 0.5rem !important;
            align-items: center;
        }
        html.sidebar-init-collapsed .sidebar-logo-text,
        html.sidebar-init-collapsed .sidebar-text,
        html.sidebar-init-collapsed .border-top {
            display: none !important;
        }
        html.sidebar-init-collapsed .nav-link-saas {
            justify-content: center !important;
            padding: 0.65rem 0 !important;
            margin-bottom: 0.3rem !important;
        }
        html.sidebar-init-collapsed .nav-link-saas i {
            margin-right: 0 !important;
            font-size: 1.3rem !important;
        }
        html.sidebar-init-collapsed .super-admin-content {
            margin-left: 70px !important;
        }
        
        @media (max-width: 991.98px) {
            .super-admin-sidebar {
                left: -280px;
            }
            .super-admin-sidebar.show {
                left: 0;
                box-shadow: 10px 0 30px rgba(0,0,0,0.5);
            }
            .super-admin-content {
                margin-left: 0;
                padding: 1.5rem;
            }
        }
    </style>

    <script>
        // Apply sidebar collapsed state instantly to avoid layout flash
        (function() {
            const collapsed = localStorage.getItem('sidebar-collapsed-admin') === 'true';
            if (collapsed && window.innerWidth >= 992) {
                document.documentElement.classList.add('sidebar-init-collapsed');
            }
        })();
    </script>
</head>
<body class="bg-dark text-white">

    <!-- Mobile Sidebar Backdrop overlay -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

    <!-- Super Admin Fixed Left Sidebar Navigation -->
    <aside class="super-admin-sidebar" id="sidebarMenu">
        <!-- SaaS Platform Branding Title -->
        <div class="d-flex align-items-center mb-4 gap-2 px-2 justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <div class="p-2 bg-primary bg-opacity-10 rounded-3 text-primary border border-primary border-opacity-25">
                    <i class="bi bi-clouds fs-4"></i>
                </div>
                <div class="sidebar-logo-text">
                    <h4 class="fw-bold text-white mb-0 font-outfit"><?php echo e(get_platform_name()); ?></h4>
                    <small class="text-primary fw-bold" style="font-size: 0.68rem; letter-spacing: 0.05em; text-transform: uppercase;">Super Admin</small>
                </div>
            </div>
            <button type="button" class="toggle-sidebar-btn d-none d-lg-block ms-auto" onclick="toggleSidebarCollapse()" title="Collapse Sidebar"><i class="bi bi-chevron-left"></i></button>
        </div>
        
        <!-- Navigation Menu Items -->
        <nav class="d-flex flex-column flex-grow-1">
            <a href="index.php" class="nav-link-saas <?php echo ($current_page === 'index.php') ? 'active' : ''; ?>">
                <i class="bi bi-speedometer2"></i>
                <span class="sidebar-text">SaaS Analytics</span>
            </a>
            
            <a href="tenants.php" class="nav-link-saas <?php echo ($current_page === 'tenants.php') ? 'active' : ''; ?>">
                <i class="bi bi-building"></i>
                <span class="sidebar-text">Tenant Approvals</span>
            </a>
            
            <a href="plans.php" class="nav-link-saas <?php echo ($current_page === 'plans.php') ? 'active' : ''; ?>">
                <i class="bi bi-sliders2"></i>
                <span class="sidebar-text">Subscription Plans</span>
            </a>
            
            <a href="billing.php" class="nav-link-saas <?php echo ($current_page === 'billing.php') ? 'active' : ''; ?>">
                <i class="bi bi-wallet2"></i>
                <span class="sidebar-text">SaaS Billing Desk</span>
            </a>
            
            <a href="payment_verifications.php" class="nav-link-saas <?php echo ($current_page === 'payment_verifications.php') ? 'active' : ''; ?>">
                <i class="bi bi-shield-check"></i>
                <span class="sidebar-text">Payment Reviews</span>
                <?php if ($pending_payments_count > 0): ?>
                    <span class="badge bg-danger ms-auto rounded-pill" style="font-size: 0.72rem; padding: 0.25em 0.6em; animation: pulse-danger 2s infinite;"><?php echo $pending_payments_count; ?></span>
                <?php endif; ?>
            </a>
            
            <a href="credit_adjustments.php" class="nav-link-saas <?php echo ($current_page === 'credit_adjustments.php') ? 'active' : ''; ?>">
                <i class="bi bi-credit-card-2-front"></i>
                <span class="sidebar-text">Credit Adjustments</span>
            </a>
            
            <a href="affiliates.php" class="nav-link-saas <?php echo ($current_page === 'affiliates.php') ? 'active' : ''; ?>">
                <i class="bi bi-people"></i>
                <span class="sidebar-text">Affiliate Registry</span>
            </a>

            <a href="payouts.php" class="nav-link-saas <?php echo ($current_page === 'payouts.php') ? 'active' : ''; ?>">
                <i class="bi bi-cash-stack"></i>
                <span class="sidebar-text">Payouts Desk</span>
            </a>

            <a href="saas_settings.php" class="nav-link-saas <?php echo ($current_page === 'saas_settings.php') ? 'active' : ''; ?>">
                <i class="bi bi-gear"></i>
                <span class="sidebar-text">Referral Settings</span>
            </a>

            <a href="profile.php" class="nav-link-saas <?php echo ($current_page === 'profile.php') ? 'active' : ''; ?>">
                <i class="bi bi-person-badge"></i>
                <span class="sidebar-text">My Profile</span>
            </a>
        </nav>
        
        <!-- Bottom Super Admin profile info and logout link -->
        <div class="border-top border-white border-opacity-5 pt-4 mt-auto">
            <div class="d-flex align-items-center justify-content-between gap-2 px-2">
                <div class="sidebar-logo-text overflow-hidden">
                    <span class="fw-bold text-white d-block text-truncate" style="font-size: 0.85rem;"><?php echo e($admin_name); ?></span>
                    <span class="text-muted d-block text-truncate" style="font-size: 0.72rem;"><?php echo e($admin_email); ?></span>
                </div>
                <a href="logout.php" class="btn btn-dark-glass p-2 px-2.5 text-danger" title="Secure Exit Platform"><i class="bi bi-power"></i></a>
            </div>
        </div>
    </aside>

    <!-- Mobile header for Super Admin -->
    <header class="d-lg-none d-flex align-items-center justify-content-between p-3 border-bottom sticky-top" style="background: rgba(11, 15, 25, 0.9); border-color: rgba(255,255,255,0.05) !important; backdrop-filter: blur(12px); z-index: 998;">
        <button class="btn btn-dark-glass p-2 px-2.5 rounded border border-white border-opacity-10" onclick="toggleSidebar()">
            <i class="bi bi-list fs-5"></i>
        </button>
        
        <a href="index.php" class="navbar-brand d-flex align-items-center gap-2 text-decoration-none">
            <span class="text-white fw-bold" style="font-size: 1rem;"><?php echo e(get_platform_name()); ?></span>
        </a>
        
        <div style="width: 38px;"></div> <!-- Spacer -->
    </header>

    <!-- Super Admin Main Workspace Content area wrapper -->
    <main class="super-admin-content">
        
        <!-- Flash Alert Message Display Box -->
        <?php display_session_alerts(); ?>

<script>
function toggleSidebarCollapse() {
    const sidebar = document.getElementById('sidebarMenu');
    const wrapper = document.querySelector('.super-admin-content');
    const isCollapsed = sidebar.classList.contains('collapsed');
    
    document.documentElement.classList.remove('sidebar-init-collapsed');
    
    if (isCollapsed) {
        sidebar.classList.remove('collapsed');
        wrapper.classList.remove('expanded');
        localStorage.setItem('sidebar-collapsed-admin', 'false');
    } else {
        sidebar.classList.add('collapsed');
        wrapper.classList.add('expanded');
        localStorage.setItem('sidebar-collapsed-admin', 'true');
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

document.addEventListener('DOMContentLoaded', function() {
    const collapsed = localStorage.getItem('sidebar-collapsed-admin') === 'true';
    const sidebar = document.getElementById('sidebarMenu');
    const wrapper = document.querySelector('.super-admin-content');
    
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
            
            // Extract file target (e.g. "tenants.php?action=approve" -> "tenants.php")
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
