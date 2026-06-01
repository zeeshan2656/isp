<?php
/**
 * Public Marketing Landing Page - NetPulse
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

// Fetch dynamic SaaS plans
$saas_plans = [];
try {
    $saas_plans = $pdo->query("SELECT * FROM saas_plans ORDER BY monthly_fee ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Landing page plans fetch failed: " . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<!-- 1. Hero Section -->
<section id="home" class="py-5 d-flex align-items-center" style="min-height: 85vh; background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.08) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-5">
        <div class="row align-items-center gy-5">
            <div class="col-lg-6 text-center text-lg-start">
                <div class="badge bg-primary-gradient px-3 py-1.5 rounded-pill mb-3" style="font-size: 0.85rem; letter-spacing: 0.05em; font-family: 'Outfit';">ALL-IN-ONE ISP SAAS</div>
                <h1 class="display-3 fw-bold text-white lh-sm mb-3" style="letter-spacing: -0.04em;">
                    Scale Your ISP Operations <span class="text-gradient-purple">Effortlessly</span>
                </h1>
                <p class="lead text-muted mb-4" style="font-size: 1.15rem; max-width: 520px; margin: 0 auto 2rem auto; margin-lg-left: 0;">
                    Manage thousands of subscribers, customizable bandwidth packages, automated invoices, zone reporting, and visual expiration alerts in one single premium platform.
                </p>
                <div class="d-flex flex-column flex-sm-row justify-content-center justify-content-lg-start gap-3">
                    <a href="register.php" class="btn btn-primary-gradient px-4 py-3" style="font-size: 1rem; border-radius: 8px;">Start 14-Day Free Trial</a>
                    <a href="#features" class="btn btn-dark-glass px-4 py-3" style="font-size: 1rem; border-radius: 8px;">Explore Features <i class="bi bi-arrow-right ms-2"></i></a>
                </div>
            </div>
            
            <div class="col-lg-6">
                <!-- Visual Display Mockup -->
                <div class="p-2 glass-panel" style="border-radius: 16px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);">
                    <div class="bg-dark rounded-3 overflow-hidden border border-secondary border-opacity-10">
                        <div class="d-flex align-items-center gap-1.5 px-3 py-2" style="background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.06);">
                            <span class="rounded-circle bg-danger bg-opacity-70" style="width: 10px; height: 10px; display: inline-block;"></span>
                            <span class="rounded-circle bg-warning bg-opacity-70" style="width: 10px; height: 10px; display: inline-block;"></span>
                            <span class="rounded-circle bg-success bg-opacity-70" style="width: 10px; height: 10px; display: inline-block;"></span>
                            <span class="text-muted ms-2" style="font-size: 0.75rem; font-family: monospace;"><?php echo e(strtolower(preg_replace('/[^A-Za-z0-9-]/', '', get_platform_name()))); ?>-saas-dashboard.net</span>
                        </div>
                        <div class="p-4" style="background: #0E1320; min-height: 280px;">
                            <div class="row g-3">
                                <div class="col-6 col-md-4">
                                    <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04);">
                                        <div style="font-size: 0.75rem; color: #9CA3AF;">Total Customers</div>
                                        <div style="font-size: 1.5rem; font-weight: 700; color: #FFFFFF;" class="font-outfit mt-1">1,248</div>
                                        <div style="font-size: 0.7rem; color: #10B981;" class="mt-1"><i class="bi bi-arrow-up-short"></i> +12% this month</div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04);">
                                        <div style="font-size: 0.75rem; color: #9CA3AF;">Monthly Revenue</div>
                                        <div style="font-size: 1.5rem; font-weight: 700; color: #6366F1;" class="font-outfit mt-1">Rs. 185k</div>
                                        <div style="font-size: 0.7rem; color: #10B981;" class="mt-1"><i class="bi bi-arrow-up-short"></i> +8.2% this month</div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-4">
                                    <div class="p-3 rounded-3" style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.15);">
                                        <div style="font-size: 0.75rem; color: #EF4444;">Expired Accounts</div>
                                        <div style="font-size: 1.5rem; font-weight: 700; color: #EF4444;" class="font-outfit mt-1">14</div>
                                        <div style="font-size: 0.7rem; color: #9CA3AF;" class="mt-1">Requires renewal push</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.04);">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span style="font-size: 0.8rem; color: #FFFFFF; font-weight: 600;">Expiring Soon Warnings</span>
                                            <span class="badge bg-danger bg-opacity-20 text-danger" style="font-size: 0.7rem;">3 Overdue</span>
                                        </div>
                                        <div class="d-flex flex-column gap-2">
                                            <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background: rgba(255, 255, 255, 0.01); border-left: 3px solid #EF4444;">
                                                <span style="font-size: 0.75rem; color: #E2E8F0;">M. Hashim (Area 3)</span>
                                                <span class="badge" style="background: #EF4444; color: #FFF; font-size: 0.65rem;">Expired Today</span>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center p-2 rounded" style="background: rgba(255, 255, 255, 0.01); border-left: 3px solid #EA580C;">
                                                <span style="font-size: 0.75rem; color: #E2E8F0;">Asad Raza (Zone B)</span>
                                                <span class="badge" style="background: #EA580C; color: #FFF; font-size: 0.65rem;">2 Days Remaining</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 2. Features Section -->
<section id="features" class="py-5">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="display-5 fw-bold text-white">Full-Featured Core Engine</h2>
            <p class="text-muted" style="max-width: 540px; margin: 0.5rem auto 0 auto;">Everything you need to automate billing and monitor subscriptions on Hostinger Shared Hosting.</p>
        </div>
        
        <div class="row g-4">
            <!-- Feature 1 -->
            <div class="col-md-6 col-lg-4">
                <div class="p-4 glass-card h-100">
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-3 d-inline-block mb-3">
                        <i class="bi bi-shield-lock" style="font-size: 1.5rem;"></i>
                    </div>
                    <h4>Multi-Tenant Separation</h4>
                    <p class="text-muted mb-0" style="font-size: 0.92rem;">
                        Isolated data isolation using advanced relational filtering. No ISP can ever view another's billing, logs, or subscribers.
                    </p>
                </div>
            </div>
            <!-- Feature 2 -->
            <div class="col-md-6 col-lg-4">
                <div class="p-4 glass-card h-100">
                    <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-3 d-inline-block mb-3">
                        <i class="bi bi-bell" style="font-size: 1.5rem;"></i>
                    </div>
                    <h4>Subscription Alert Center</h4>
                    <p class="text-muted mb-0" style="font-size: 0.92rem;">
                        Color-coded indicators at 10, 7, 5, 3, and 1 days before expiry. Never miss a billing milestone again.
                    </p>
                </div>
            </div>
            <!-- Feature 3 -->
            <div class="col-md-6 col-lg-4">
                <div class="p-4 glass-card h-100">
                    <div class="p-3 bg-success bg-opacity-10 text-success rounded-3 d-inline-block mb-3">
                        <i class="bi bi-wallet2" style="font-size: 1.5rem;"></i>
                    </div>
                    <h4>Internet Costs & Profit Tracking</h4>
                    <p class="text-muted mb-0" style="font-size: 0.92rem;">
                        Input your purchase details (Mbps & Monthly Price) to automatically compute real-time gross/net profit.
                    </p>
                </div>
            </div>
            <!-- Feature 4 -->
            <div class="col-md-6 col-lg-4">
                <div class="p-4 glass-card h-100">
                    <div class="p-3 bg-info bg-opacity-10 text-info rounded-3 d-inline-block mb-3">
                        <i class="bi bi-diagram-3" style="font-size: 1.5rem;"></i>
                    </div>
                    <h4>Zone & Area Filters</h4>
                    <p class="text-muted mb-0" style="font-size: 0.92rem;">
                        Organize customer grids into zones (e.g. Zone A, Zone B) to query analytical updates dynamically based on area filters.
                    </p>
                </div>
            </div>
            <!-- Feature 5 -->
            <div class="col-md-6 col-lg-4">
                <div class="p-4 glass-card h-100">
                    <div class="p-3 bg-secondary bg-opacity-10 text-secondary rounded-3 d-inline-block mb-3">
                        <i class="bi bi-person-badge" style="font-size: 1.5rem;"></i>
                    </div>
                    <h4>Customer Self-Service</h4>
                    <p class="text-muted mb-0" style="font-size: 0.92rem;">
                        Subscribers receive custom login credentials to view active packages, expiry dates, remaining days, and invoice logs.
                    </p>
                </div>
            </div>
            <!-- Feature 6 -->
            <div class="col-md-6 col-lg-4">
                <div class="p-4 glass-card h-100">
                    <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-3 d-inline-block mb-3">
                        <i class="bi bi-lightning" style="font-size: 1.5rem;"></i>
                    </div>
                    <h4>Shared Hosting Optimized</h4>
                    <p class="text-muted mb-0" style="font-size: 0.92rem;">
                        100% PHP and MySQL-native. Requires zero Node.js or Docker setup. Guaranteed fast TTFB and excellent PageSpeed metrics.
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 3. Pricing Plan Section -->
<section id="pricing" class="py-5" style="background: rgba(255,255,255,0.01);">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="display-5 fw-bold text-white">Simple, Scalable Plans</h2>
            <p class="text-muted" style="max-width: 500px; margin: 0.5rem auto 0 auto;">Choose the level that matches your network coverage. Free trial available.</p>
        </div>
        
        <div class="row justify-content-center g-4">
            <?php if (empty($saas_plans)): ?>
                <div class="col-12 text-center text-muted py-5">
                    <p>Subscription plans limits configuration is currently unavailable. Please check back later.</p>
                </div>
            <?php else: ?>
                <?php foreach ($saas_plans as $index => $p): 
                    // Make the second or middle plan highly styled as "popular" if there are multiple plans
                    $is_popular = ($index === 1 || (count($saas_plans) === 1 && $index === 0));
                    $card_class = $is_popular 
                        ? 'border-color: var(--primary); box-shadow: 0 10px 30px rgba(99, 102, 241, 0.15);' 
                        : '';
                    $btn_class = $is_popular ? 'btn-primary-gradient' : 'btn-dark-glass';
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="p-4 glass-card h-100 d-flex flex-column" style="<?php echo $card_class; ?>">
                            <div class="mb-4">
                                <?php if ($is_popular): ?>
                                    <div class="badge bg-primary-gradient px-3 py-1 rounded-pill mb-2.5" style="font-size: 0.75rem; letter-spacing: 0.05em;">POPULAR PLAN</div>
                                <?php endif; ?>
                                <h4 class="text-white"><?php echo e($p['name']); ?></h4>
                                <p class="text-muted" style="font-size: 0.85rem;"><?php echo e($p['features_list']); ?></p>
                                <h2 class="fw-bold text-white mt-3 mb-0">Rs. <?php echo number_format($p['monthly_fee'], 0); ?><span class="text-muted" style="font-size: 1rem; font-weight: normal;"> /mo</span></h2>
                            </div>
                            <ul class="list-unstyled d-flex flex-column gap-3 mb-5" style="font-size: 0.92rem; color: #D1D5DB;">
                                <li><i class="bi bi-check2 text-primary me-2"></i> Up to <?php echo $p['max_customers'] > 50000 ? '<strong>Unlimited</strong>' : number_format($p['max_customers']) . ' Active'; ?> Customers</li>
                                <li><i class="bi bi-check2 text-primary me-2"></i> Up to <?php echo e($p['max_packages']); ?> Bandwidth Packages</li>
                                <li><i class="bi bi-check2 text-primary me-2"></i> Up to <?php echo e($p['max_zones']); ?> Coverage Zones</li>
                                <li><i class="bi bi-check2 text-primary me-2"></i> Dynamic analytical dashboard</li>
                                <li><i class="bi bi-check2 text-primary me-2"></i> Automated customer invoicing logs</li>
                            </ul>
                            <a href="register.php" class="btn <?php echo $btn_class; ?> w-100 mt-auto py-2.5"><?php echo $is_popular ? 'Start Free Trial' : 'Get Started'; ?></a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- 4. FAQ Section -->
<section id="faq" class="py-5">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="display-5 fw-bold text-white">Frequently Asked Questions</h2>
            <p class="text-muted" style="max-width: 500px; margin: 0.5rem auto 0 auto;">Everything you need to know about setting up and running <?php echo e(get_platform_name()); ?>.</p>
        </div>
        
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="accordion accordion-flush" id="faqAccordion">
                    
                    <div class="accordion-item mb-3 rounded border" style="background: rgba(14, 19, 31, 0.7); border-color: rgba(255, 255, 255, 0.08) !important;">
                        <h2 class="accordion-header" id="headingOne">
                            <button class="accordion-button collapsed bg-transparent text-white border-0 shadow-none py-3.5" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="false" aria-controls="collapseOne" style="font-family: 'Outfit';">
                                Can this really run on basic Hostinger Shared Hosting?
                            </button>
                        </h2>
                        <div id="collapseOne" class="accordion-collapse collapse" aria-labelledby="headingOne" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-muted border-top border-white border-opacity-10 py-3" style="font-size: 0.92rem;">
                                Yes, absolutely! We designed this SaaS platform using native PHP 8+ and optimized, fully indexed MySQL tables. It requires no server-side compilation, Node.js, Docker, or command line access, meaning it runs blazingly fast even on the most entry-level shared hosting plans.
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item mb-3 rounded border" style="background: rgba(14, 19, 31, 0.7); border-color: rgba(255, 255, 255, 0.08) !important;">
                        <h2 class="accordion-header" id="headingTwo">
                            <button class="accordion-button collapsed bg-transparent text-white border-0 shadow-none py-3.5" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo" style="font-family: 'Outfit';">
                                How is data isolated between ISPs (Tenants)?
                            </button>
                        </h2>
                        <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-muted border-top border-white border-opacity-10 py-3" style="font-size: 0.92rem;">
                                We enforce strict database-level separation. Every entity (packages, customers, invoices) is tagged with a `tenant_id`. Every SQL lookup checks this ID derived securely from the authenticated session, which makes it programmatically impossible for one ISP to access another's data.
                            </div>
                        </div>
                    </div>
                    
                    <div class="accordion-item mb-3 rounded border" style="background: rgba(14, 19, 31, 0.7); border-color: rgba(255, 255, 255, 0.08) !important;">
                        <h2 class="accordion-header" id="headingThree">
                            <button class="accordion-button collapsed bg-transparent text-white border-0 shadow-none py-3.5" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree" style="font-family: 'Outfit';">
                                How does the customer self-service portal work?
                            </button>
                        </h2>
                        <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#faqAccordion">
                            <div class="accordion-body text-muted border-top border-white border-opacity-10 py-3" style="font-size: 0.92rem;">
                                When you add a new subscriber to your dashboard, the system requires an email address and sets up their portal credentials. The customer can log in to a dedicated portal using their email/password and immediately review their bandwidth, activation details, days until expiry, and billing logs.
                            </div>
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
</section>

<!-- 5. Contact Section -->
<section id="contact" class="py-5">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px;">
                    <div class="row gy-4 align-items-center">
                        <div class="col-md-5 text-center text-md-start">
                            <h2 class="fw-bold text-white mb-3">Get in Touch</h2>
                            <p class="text-muted" style="font-size: 0.95rem;">
                                Have questions about platform capabilities or pricing? Contact our customer support team.
                            </p>
                            <div class="d-flex flex-column gap-3 mt-4 text-start" style="font-size: 0.9rem;">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-envelope text-primary" style="font-size: 1.2rem;"></i>
                                    <span><?php echo e(get_platform_email('support')); ?></span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="bi bi-phone text-primary" style="font-size: 1.2rem;"></i>
                                    <span>+92 300 1234567</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-7">
                            <form action="#" method="POST" class="d-flex flex-column gap-3">
                                <?php csrf_field(); ?>
                                <div class="row g-2">
                                    <div class="col">
                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Name</label>
                                        <input type="text" class="form-control" placeholder="John" required>
                                    </div>
                                    <div class="col">
                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Email</label>
                                        <input type="email" class="form-control" placeholder="john@email.com" required>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label text-muted" style="font-size: 0.8rem;">Message</label>
                                    <textarea class="form-control" rows="4" placeholder="Your inquiry..." required></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="font-family: 'Outfit';">Send Inquiry</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
