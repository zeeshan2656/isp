<?php
/**
 * Frontend Layout Footer
 */
defined('SECURE_ACCESS') or die('Direct access denied');
?>

<footer class="border-top py-5 mt-5" style="border-color: rgba(255,255,255,0.06) !important; background: rgba(8, 11, 17, 0.95);">
    <div class="container text-center text-lg-start">
        <div class="row gy-4">
            <div class="col-lg-4 text-center text-lg-start">
                <a class="navbar-brand d-flex align-items-center justify-content-center justify-content-lg-start gap-2 mb-3" href="index.php">
                    <span class="p-1 px-2 rounded-3 bg-primary-gradient" style="font-size: 1.1rem;"><?php echo e(get_platform_logo()); ?></span>
                    <span class="text-white fw-bold"><?php echo e(get_platform_name()); ?></span>
                </a>
                <p class="text-muted" style="max-width: 320px; font-size: 0.9rem; margin: 0 auto 1.5rem auto; margin-lg-bottom: 0;">
                    Modern, ultra-fast, and scalable ISP SaaS management dashboard designed for next-generation network providers.
                </p>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="text-white mb-3" style="font-size: 0.95rem;">Product</h6>
                <ul class="list-unstyled d-flex flex-column gap-2" style="font-size: 0.85rem;">
                    <li><a href="index.php#home" class="text-muted text-decoration-none hover-white">Home</a></li>
                    <li><a href="index.php#features" class="text-muted text-decoration-none hover-white">Features</a></li>
                    <li><a href="index.php#pricing" class="text-muted text-decoration-none hover-white">Pricing Plans</a></li>
                </ul>
            </div>
            <div class="col-6 col-lg-2">
                <h6 class="text-white mb-3" style="font-size: 0.95rem;">Portals</h6>
                <ul class="list-unstyled d-flex flex-column gap-2" style="font-size: 0.85rem;">
                    <li><a href="register.php" class="text-muted text-decoration-none hover-white">Register ISP</a></li>
                    <li><a href="login.php" class="text-muted text-decoration-none hover-white">ISP Login</a></li>
                    <li><a href="customer-login.php" class="text-muted text-decoration-none hover-white">Customer Login</a></li>
                </ul>
            </div>
            <div class="col-lg-4 text-center text-lg-start">
                <h6 class="text-white mb-3" style="font-size: 0.95rem;">Join the <?php echo e(get_platform_name()); ?> Newsletter</h6>
                <p class="text-muted" style="font-size: 0.85rem;">Get updates on speed optimizations and ISP industry tech.</p>
                <div class="input-group mt-2" style="max-width: 360px; margin: 0 auto; margin-lg: 0;">
                    <input type="email" class="form-control" placeholder="Your email address" aria-label="Email" style="font-size: 0.85rem;">
                    <button class="btn btn-primary-gradient px-3" type="button" style="font-size: 0.85rem;">Subscribe</button>
                </div>
            </div>
        </div>
        
        <hr class="my-4" style="border-color: rgba(255,255,255,0.06);">
        
        <div class="d-flex flex-column flex-lg-row align-items-center justify-content-between gap-3 text-muted" style="font-size: 0.85rem;">
            <div>
                &copy; <?php echo date('Y'); ?> <?php echo e(get_platform_name()); ?> SaaS. All rights reserved. Made for premium scale.
            </div>
            <div class="d-flex gap-3">
                <a href="#" class="text-muted text-decoration-none">Privacy Policy</a>
                <span>&bull;</span>
                <a href="#" class="text-muted text-decoration-none">Terms of Service</a>
            </div>
        </div>
    </div>
</footer>

<style>
.hover-white:hover {
    color: #FFFFFF !important;
    transition: color 0.2s ease;
}
</style>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
