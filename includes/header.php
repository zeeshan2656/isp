<?php
/**
 * Frontend Layout Header
 */
defined('SECURE_ACCESS') or die('Direct access denied');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(get_platform_name()); ?> - Multi-Tenant ISP Management SaaS Platform</title>
    <meta name="description" content="Ultra-fast, scalable, and secure SaaS platform for internet service providers. Manage billing, zones, packages, and custom portals easily.">
    
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.2/font/bootstrap-icons.min.css">
    <!-- Custom Style -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
 
<nav class="navbar navbar-expand-lg navbar-dark bg-transparent border-bottom py-3" style="border-color: rgba(255,255,255,0.06) !important;">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <span class="p-1 px-2 rounded-3 bg-primary-gradient" style="font-size: 1.1rem; box-shadow: 0 4px 10px rgba(99, 102, 241, 0.25);"><?php echo e(get_platform_logo()); ?></span>
            <span class="text-gradient fw-bold"><?php echo e(get_platform_name()); ?></span>
        </a>
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent" aria-controls="navbarContent" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarContent">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-1 gap-lg-3 mt-3 mt-lg-0">
                <li class="nav-item"><a class="nav-link" href="index.php#home">Home</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php#features">Features</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php#pricing">Pricing</a></li>
                <li class="nav-item"><a class="nav-link" href="downloads.php">Downloads</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php#faq">FAQ</a></li>
                <li class="nav-item"><a class="nav-link" href="index.php#contact">Contact</a></li>
            </ul>

            
            <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2 mt-3 mt-lg-0">
                <a href="customer-login.php" class="btn btn-dark-glass px-4 py-2 text-center" style="font-size: 0.9rem;">Customer Login</a>
                <a href="login.php" class="btn btn-dark-glass px-4 py-2 text-center" style="font-size: 0.9rem;">ISP Login</a>
                <a href="register.php" class="btn btn-primary-gradient px-4 py-2 text-center" style="font-size: 0.9rem;">Register ISP</a>
            </div>
        </div>
    </div>
</nav>
