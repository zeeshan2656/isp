<?php
/**
 * Mobile Applications Downloads Portal for ISP Operator
 */
require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-phone text-primary me-2"></i>Mobile APK Center</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Download and deploy native Android applications for your operators and subscribers.</p>
    </div>
</div>

<!-- SaaS Tenant Integration Status Alert -->
<div class="p-4 rounded-3 border mb-4 text-start glass-panel" style="border-color: rgba(99, 102, 241, 0.15) !important; background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.05) 0%, rgba(8, 11, 17, 0.2) 100%);">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
        <div>
            <h5 class="text-white font-outfit mb-1.5"><i class="bi bi-shield-check text-primary me-2"></i>Multi-Tenant Intelligent Routing Active</h5>
            <p class="text-muted mb-0 style-dim font-size-sm" style="max-width: 820px; font-size: 0.88rem;">
                Your ISP Workspace is identified globally as <strong class="text-white"><?php echo strtoupper(e($tenant_subdomain)); ?></strong>. Both mobile apps utilize safe JWT token handshakes. Once downloaded, when your customers log in using their credentials, they are automatically placed into your workspace context. No manual server configurations are required!
            </p>
        </div>
        <div class="badge bg-primary-gradient px-3 py-1.5 rounded-pill text-nowrap" style="font-size: 0.78rem;">WORKSPACE SECURED</div>
    </div>
</div>

<div class="row g-4 mb-5">
    <!-- App Card 1: Provider App -->
    <div class="col-md-6">
        <div class="p-4 p-md-5 glass-card h-100 d-flex flex-column border-indigo border-opacity-10" style="border-color: rgba(99, 102, 241, 0.12) !important; min-height: 520px;">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="p-3 rounded-3 bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-headset" style="font-size: 2.2rem;"></i>
                </div>
                <span class="badge bg-primary-soft text-light rounded-pill px-2.5 py-1" style="background: rgba(99,102,241,0.1); border: 1px solid rgba(99,102,241,0.2); font-size: 0.72rem;">V1.0.0 - OPERATOR EDITION</span>
            </div>
            
            <h3 class="text-white fw-bold font-outfit mb-2"><?php echo e(get_platform_name()); ?> ISP App</h3>
            <p class="text-muted mb-4" style="font-size: 0.9rem; line-height: 1.6;">
                Advanced administrative workspace designed for your ISP team. Direct mobile login allows you or your staff to manage invoices, add subscribers, check expiry schedules, and monitor profitability on the go.
            </p>
            
            <div class="p-3 bg-dark rounded-3 border mb-4 text-start" style="border-color: var(--border-color); font-size: 0.82rem;">
                <div class="row g-2">
                    <div class="col-6">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">Target Audience:</span>
                        <strong class="text-white"><?php echo e($tenant_name); ?> Team</strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">Authorized Logins:</span>
                        <strong class="text-white">ISP Operators Only</strong>
                    </div>
                    <div class="col-6 mt-2">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">File Format:</span>
                        <strong class="text-white">Android APK Package</strong>
                    </div>
                    <div class="col-6 mt-2">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">Download Size:</span>
                        <strong class="text-white">14.8 MB</strong>
                    </div>
                </div>
            </div>
            
            <div class="mb-4">
                <h6 class="text-white font-outfit mb-2" style="font-size: 0.88rem;"><i class="bi bi-check2-circle text-primary me-1.5"></i>Operator Capabilities:</h6>
                <ul class="list-unstyled d-flex flex-column gap-2 text-muted" style="font-size: 0.82rem; padding-left: 0.2rem;">
                    <li><i class="bi bi-circle-fill text-primary me-2" style="font-size: 0.35rem;"></i> Direct subscriber registration via phone</li>
                    <li><i class="bi bi-circle-fill text-primary me-2" style="font-size: 0.35rem;"></i> Log cash payments on live customer invoices</li>
                    <li><i class="bi bi-circle-fill text-primary me-2" style="font-size: 0.35rem;"></i> System logs and real-time billing indicators</li>
                </ul>
            </div>
            
            <a href="../uploads/apks/netpulse_isp.apk" class="btn btn-primary-gradient w-100 py-2.5 mt-auto" style="font-family: 'Outfit'; font-size: 0.95rem;">
                <i class="bi bi-download me-1.5"></i>Download ISP Operator APK
            </a>
        </div>
    </div>

    <!-- App Card 2: Customer App -->
    <div class="col-md-6">
        <div class="p-4 p-md-5 glass-card h-100 d-flex flex-column border-purple border-opacity-10" style="border-color: rgba(168, 85, 247, 0.12) !important; min-height: 520px;">
            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="p-3 rounded-3 bg-secondary bg-opacity-10 text-secondary" style="color: var(--secondary) !important;">
                    <i class="bi bi-person-workspace" style="font-size: 2.2rem;"></i>
                </div>
                <span class="badge bg-secondary-soft text-light rounded-pill px-2.5 py-1" style="background: rgba(168,85,247,0.1); border: 1px solid rgba(168,85,247,0.2); font-size: 0.72rem; color: var(--secondary) !important;">V1.0.0 - SUBSCRIBER EDITION</span>
            </div>
            
            <h3 class="text-white fw-bold font-outfit mb-2"><?php echo e(get_platform_name()); ?> Customer App</h3>
            <p class="text-muted mb-4" style="font-size: 0.9rem; line-height: 1.6;">
                Provide this APK directly to your subscribers. When they download and open the application, they can easily verify their remaining subscription days, read your announcements, and submit billing logs.
            </p>
            
            <div class="p-3 bg-dark rounded-3 border mb-4 text-start" style="border-color: var(--border-color); font-size: 0.82rem;">
                <div class="row g-2">
                    <div class="col-6">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">Target Audience:</span>
                        <strong class="text-white">Your Subscribers</strong>
                    </div>
                    <div class="col-6">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">Authorized Logins:</span>
                        <strong class="text-white"><?php echo e($tenant_name); ?> Clients</strong>
                    </div>
                    <div class="col-6 mt-2">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">File Format:</span>
                        <strong class="text-white">Android APK Package</strong>
                    </div>
                    <div class="col-6 mt-2">
                        <span class="text-muted d-block" style="font-size: 0.72rem;">Download Size:</span>
                        <strong class="text-white">12.4 MB</strong>
                    </div>
                </div>
            </div>
            
            <div class="mb-4">
                <h6 class="text-white font-outfit mb-2" style="font-size: 0.88rem;"><i class="bi bi-check2-circle text-secondary me-1.5"></i>Subscriber Capabilities:</h6>
                <ul class="list-unstyled d-flex flex-column gap-2 text-muted" style="font-size: 0.82rem; padding-left: 0.2rem;">
                    <li><i class="bi bi-circle-fill text-secondary me-2" style="font-size: 0.35rem;"></i> Circular Expiry Countdown widget</li>
                    <li><i class="bi bi-circle-fill text-secondary me-2" style="font-size: 0.35rem;"></i> Complete Payments Receipt Viewer</li>
                    <li><i class="bi bi-circle-fill text-secondary me-2" style="font-size: 0.35rem;"></i> Offline Caching for subscriber profiles</li>
                </ul>
            </div>
            
            <a href="../uploads/apks/netpulse_customer.apk" class="btn btn-primary-gradient w-100 py-2.5 mt-auto" style="background: linear-gradient(135deg, var(--secondary), var(--accent)) !important; box-shadow: 0 4px 14px rgba(168, 85, 247, 0.25) !important; font-family: 'Outfit'; font-size: 0.95rem;">
                <i class="bi bi-download me-1.5"></i>Download Customer App APK
            </a>
        </div>
    </div>
</div>

<!-- Deployment & Installation Guides -->
<div class="row justify-content-center">
    <div class="col-12">
        <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px;">
            <h5 class="text-white mb-4 border-bottom pb-2 font-outfit"><i class="bi bi-info-circle text-secondary me-2"></i>ISP Operator APK Installation & Setup Guide</h5>
            
            <div class="row g-4 text-muted" style="font-size: 0.88rem;">
                <div class="col-md-4">
                    <h6 class="text-white font-outfit mb-2"><i class="bi bi-1-circle text-primary me-2"></i>1. Distribute APKs</h6>
                    <p class="mb-0">You can share the downloaded Customer APK with your subscribers by hosting it on your custom domain, sending via WhatsApp, or sending email notices.</p>
                </div>
                <div class="col-md-4 border-md-start">
                    <h6 class="text-white font-outfit mb-2"><i class="bi bi-2-circle text-primary me-2"></i>2. Enable Unknown Sources</h6>
                    <p class="mb-0">On the Android device, go to <code>Settings &rarr; Security</code> and toggle "Allow Installation of Apps from Unknown Sources" or grant permission to your installer/browser.</p>
                </div>
                <div class="col-md-4 border-md-start">
                    <h6 class="text-white font-outfit mb-2"><i class="bi bi-3-circle text-primary me-2"></i>3. Secure Authentication</h6>
                    <p class="mb-0">Launch the app and log in using your registered credentials. The application dynamically synchronizes profile data and configures dashboard analytics instantly.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
