<?php
/**
 * Public Mobile Applications Downloads Portal
 * Obsidian Dark Mode style page listing APK files parameters, guides, and download logs.
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';
?>

<section class="py-5" style="min-height: 80vh; background: radial-gradient(circle at top left, rgba(99, 102, 241, 0.04) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-4">
        
        <div class="text-center mb-5">
            <div class="badge bg-primary-gradient px-3 py-1.5 rounded-pill mb-3" style="font-size: 0.8rem; letter-spacing: 0.05em; font-family: 'Outfit';">MOBILE APPLICATIONS SYSTEM</div>
            <h2 class="display-5 fw-bold text-white mb-2"><?php echo e(get_platform_name()); ?> On The Go</h2>
            <p class="text-muted" style="max-width: 550px; margin: 0.5rem auto 0 auto;">Download our secure, lightweight native mobile applications designed to run seamlessly on any Android device.</p>
        </div>
        
        <div class="row justify-content-center g-4 mb-5">
            <!-- App Card 1: Customer App -->
            <div class="col-md-6 col-lg-5">
                <div class="p-4 p-md-5 glass-card h-100 d-flex flex-column border-purple border-opacity-10" style="border-color: rgba(168, 85, 247, 0.12) !important;">
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <div class="p-3 rounded-3 bg-secondary bg-opacity-10 text-secondary">
                            <i class="bi bi-person-workspace" style="font-size: 2.2rem;"></i>
                        </div>
                        <span class="badge bg-secondary-soft text-light rounded-pill px-2.5 py-1" style="background: rgba(168,85,247,0.1); border: 1px solid rgba(168,85,247,0.2); font-size: 0.72rem;">V1.0.0 - PRODUCTION</span>
                    </div>
                    
                    <h3 class="text-white fw-bold font-outfit mb-2"><?php echo e(get_platform_name()); ?> Client App</h3>
                    <p class="text-muted mb-4" style="font-size: 0.9rem; line-height: 1.6;">
                        Dedicated portal designed solely for subscribers. Review active broadband packages, download rates, remaining subscription days, notifications, and clear invoices safely.
                    </p>
                    
                    <div class="p-3 bg-dark rounded-3 border mb-4 text-start" style="border-color: var(--border-color); font-size: 0.82rem;">
                        <div class="row g-2">
                            <div class="col-6">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">File Format:</span>
                                <strong class="text-white">Android APK Package</strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">Download Size:</span>
                                <strong class="text-white">12.4 MB</strong>
                            </div>
                            <div class="col-6 mt-2">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">Requirements:</span>
                                <strong class="text-white">Android 8.0 or newer</strong>
                            </div>
                            <div class="col-6 mt-2">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">Interface Protocol:</span>
                                <strong class="text-accent" style="color: var(--accent);">JWT REST API (HTTPS)</strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="text-white font-outfit mb-2" style="font-size: 0.88rem;"><i class="bi bi-check2-circle text-secondary me-1.5"></i>Key Capabilities:</h6>
                        <ul class="list-unstyled d-flex flex-column gap-2 text-muted" style="font-size: 0.82rem; padding-left: 0.2rem;">
                            <li><i class="bi bi-circle-fill text-secondary me-2" style="font-size: 0.35rem;"></i> Circular Expiry Countdown widget</li>
                            <li><i class="bi bi-circle-fill text-secondary me-2" style="font-size: 0.35rem;"></i> Complete Payments Receipt Viewer</li>
                            <li><i class="bi bi-circle-fill text-secondary me-2" style="font-size: 0.35rem;"></i> Offline Caching for subscriber profiles</li>
                        </ul>
                    </div>
                    
                    <a href="#" class="btn btn-primary-gradient w-100 py-2.5 mt-auto" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); box-shadow: 0 4px 14px rgba(168, 85, 247, 0.3); font-family: 'Outfit'; font-size: 0.95rem;">
                        <i class="bi bi-download me-1.5"></i>Download Customer App APK
                    </a>
                </div>
            </div>
            
            <!-- App Card 2: Provider App -->
            <div class="col-md-6 col-lg-5">
                <div class="p-4 p-md-5 glass-card h-100 d-flex flex-column border-indigo border-opacity-10" style="border-color: rgba(99, 102, 241, 0.12) !important;">
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <div class="p-3 rounded-3 bg-primary bg-opacity-10 text-primary">
                            <i class="bi bi-headset" style="font-size: 2.2rem;"></i>
                        </div>
                        <span class="badge bg-primary-soft text-light rounded-pill px-2.5 py-1" style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.2); font-size: 0.72rem;">V1.0.0 - PRODUCTION</span>
                    </div>
                    
                    <h3 class="text-white fw-bold font-outfit mb-2"><?php echo e(get_platform_name()); ?> Provider App</h3>
                    <p class="text-muted mb-4" style="font-size: 0.9rem; line-height: 1.6;">
                        Advanced administrative workspace designed for ISP Owners, Managers, and workspace admins. Add new customers, monitor total net profits, edit invoice logs, and configure coverage zones.
                    </p>
                    
                    <div class="p-3 bg-dark rounded-3 border mb-4 text-start" style="border-color: var(--border-color); font-size: 0.82rem;">
                        <div class="row g-2">
                            <div class="col-6">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">File Format:</span>
                                <strong class="text-white">Android APK Package</strong>
                            </div>
                            <div class="col-6">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">Download Size:</span>
                                <strong class="text-white">14.8 MB</strong>
                            </div>
                            <div class="col-6 mt-2">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">Requirements:</span>
                                <strong class="text-white">Android 8.0 or newer</strong>
                            </div>
                            <div class="col-6 mt-2">
                                <span class="text-muted d-block" style="font-size: 0.72rem;">Interface Protocol:</span>
                                <strong class="text-primary" style="color: var(--primary);">JWT REST API (HTTPS)</strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="text-white font-outfit mb-2" style="font-size: 0.88rem;"><i class="bi bi-check2-circle text-primary me-1.5"></i>Key Capabilities:</h6>
                        <ul class="list-unstyled d-flex flex-column gap-2 text-muted" style="font-size: 0.82rem; padding-left: 0.2rem;">
                            <li><i class="bi bi-circle-fill text-primary me-2" style="font-size: 0.35rem;"></i> Complete Customer Directory & zone CRUDs</li>
                            <li><i class="bi bi-circle-fill text-primary me-2" style="font-size: 0.35rem;"></i> Advanced Audited Invoice editor tools</li>
                            <li><i class="bi bi-circle-fill text-primary me-2" style="font-size: 0.35rem;"></i> Real-time Revenue & Profit monitoring graphs</li>
                        </ul>
                    </div>
                    
                    <a href="#" class="btn btn-primary-gradient w-100 py-2.5 mt-auto" style="font-family: 'Outfit'; font-size: 0.95rem;">
                        <i class="bi bi-download me-1.5"></i>Download ISP Provider APK
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Installation Guide Section -->
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px;">
                    <h5 class="text-white mb-4 border-bottom pb-2 font-outfit"><i class="bi bi-info-circle text-secondary me-2"></i>Android APK Installation Guide</h5>
                    
                    <div class="row g-4 text-muted" style="font-size: 0.88rem;">
                        <div class="col-md-4">
                            <h6 class="text-white font-outfit mb-2">1. Download the APK</h6>
                            <p class="mb-0">Choose either the Client or Provider app package using the buttons above and wait for the direct APK transfer to complete.</p>
                        </div>
                        <div class="col-md-4 border-md-start">
                            <h6 class="text-white font-outfit mb-2">2. Allow Unknown Sources</h6>
                            <p class="mb-0">Navigate to your device <code>Settings &rarr; Security</code> and toggle "Allow Installation of Apps from Unknown Sources" or authorize your browser permission.</p>
                        </div>
                        <div class="col-md-4 border-md-start">
                            <h6 class="text-white font-outfit mb-2">3. Install & Authenticate</h6>
                            <p class="mb-0">Open your File Manager, tap on the downloaded APK file, click Install, and log in securely with your <?php echo e(get_platform_name()); ?> account credentials!</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
