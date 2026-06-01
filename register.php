<?php
/**
 * ISP Registration Portal
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Verify CSRF Token
    verify_csrf_token();
    
    // 2. Clean and capture input
    $company_name = clean_input($_POST['company_name'] ?? '');
    $subdomain = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '', clean_input($_POST['subdomain'] ?? ''))); // safe URL slug
    $email = clean_input($_POST['email'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $address = clean_input($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    // 3. Simple validation
    if (empty($company_name)) $errors[] = "Company name is required.";
    if (empty($subdomain)) $errors[] = "Dynamic subdomain/slug is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
    if (empty($phone)) $errors[] = "Phone number is required.";
    if (empty($address)) $errors[] = "Company business address is required.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters long.";
    if ($password !== $password_confirm) $errors[] = "Passwords do not match.";
    
    // 4. Database Check for unique credentials
    if (empty($errors)) {
        try {
            // Check email uniqueness
            $check_email = $pdo->prepare("SELECT id FROM tenants WHERE email = ?");
            $check_email->execute([$email]);
            if ($check_email->fetch()) {
                $errors[] = "This email address is already registered.";
            }
            
            // Check subdomain uniqueness
            $check_slug = $pdo->prepare("SELECT id FROM tenants WHERE subdomain = ?");
            $check_slug->execute([$subdomain]);
            if ($check_slug->fetch()) {
                $errors[] = "This portal slug/subdomain is already taken.";
            }
            
            // 5. Insert new tenant
            if (empty($errors)) {
                $password_hash = password_hash($password, PASSWORD_BCRYPT);
                
                // Track Referrer
                $referred_by_type = 'none';
                $referred_by_id = null;
                $ref = $_SESSION['netpulse_ref'] ?? $_COOKIE['netpulse_ref'] ?? '';
                
                if (!empty($ref)) {
                    // Check Affiliate
                    $stmt_aff = $pdo->prepare("SELECT id, email FROM affiliates WHERE referral_code = ? LIMIT 1");
                    $stmt_aff->execute([$ref]);
                    $aff = $stmt_aff->fetch();
                    if ($aff) {
                        if (strtolower($aff['email']) !== strtolower($email)) {
                            $referred_by_type = 'affiliate';
                            $referred_by_id = $aff['id'];
                        }
                    } else {
                        // Check Tenant
                        $stmt_ten = $pdo->prepare("SELECT id, email FROM tenants WHERE referral_code = ? LIMIT 1");
                        $stmt_ten->execute([$ref]);
                        $ten = $stmt_ten->fetch();
                        if ($ten) {
                            if (strtolower($ten['email']) !== strtolower($email)) {
                                $referred_by_type = 'tenant';
                                $referred_by_id = $ten['id'];
                            }
                        }
                    }
                }
                
                // Generate a unique referral code for the new tenant
                $tenant_ref_code = 'TEN-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                
                $insert = $pdo->prepare("INSERT INTO tenants (company_name, subdomain, email, phone, address, password_hash, status, referred_by_type, referred_by_id, referral_code, joining_date) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, CURDATE())");
                $insert->execute([$company_name, $subdomain, $email, $phone, $address, $password_hash, $referred_by_type, $referred_by_id, $tenant_ref_code]);
                
                $tenant_id = $pdo->lastInsertId();
                
                // Initialize default tenant referral settings
                $init_settings = $pdo->prepare("INSERT IGNORE INTO tenant_referral_settings (tenant_id, enabled, reward_type, reward_value, min_withdrawal_amount) VALUES (?, 1, 'fixed', 100.00, 500.00)");
                $init_settings->execute([$tenant_id]);
                
                // Set audit log
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $log = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_type, user_id, action, ip_address) VALUES (?, 'tenant', ?, 'Registered new pending ISP workspace request', ?)");
                $log->execute([$tenant_id, $tenant_id, $ip]);
                
                // Clean up cookies/session
                setcookie('netpulse_ref', '', time() - 3600, "/");
                unset($_SESSION['netpulse_ref']);
                
                // Set flash alert and redirect
                set_session_alert("ISP Registration submitted successfully! Your workspace application is pending review and approval by the Super Admin.", "success");
                header("Location: login.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "Registration database failure: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="py-5" style="min-height: 80vh; background: radial-gradient(circle at bottom left, rgba(99, 102, 241, 0.05) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                
                <div class="text-center mb-4">
                    <h2 class="display-6 fw-bold text-white mb-2">Create Your Portal</h2>
                    <p class="text-muted">Register your ISP and setup isolated workspaces in minutes.</p>
                </div>
                
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo e($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form action="register.php" method="POST" class="d-flex flex-column gap-3">
                        <?php csrf_field(); ?>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">ISP Company Name</label>
                            <input type="text" name="company_name" class="form-control" placeholder="SpeedNet Broadband" value="<?php echo e($company_name ?? ''); ?>" required autocomplete="off">
                        </div>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Workspace Portal Slug</label>
                            <div class="input-group">
                                <span class="input-group-text border-0" style="background: rgba(255,255,255,0.03); color: var(--text-dim); font-size: 0.85rem;"><?php echo e(get_platform_domain()); ?>/</span>
                                <input type="text" name="subdomain" class="form-control" placeholder="speednet" value="<?php echo e($subdomain ?? ''); ?>" required autocomplete="off" style="font-size: 0.9rem;">
                            </div>
                            <small class="text-muted mt-1 d-block" style="font-size: 0.72rem;">Lowercase letters and numbers only. This defines your local ISP link.</small>
                        </div>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Company Business Address</label>
                            <textarea name="address" class="form-control" rows="2" placeholder="123 Main Street, Sector 4..." required><?php echo e($address ?? ''); ?></textarea>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Email Address</label>
                                <input type="email" name="email" class="form-control" placeholder="owner@speednet.com" value="<?php echo e($email ?? ''); ?>" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Phone Number</label>
                                <input type="tel" name="phone" class="form-control" placeholder="03001234567" value="<?php echo e($phone ?? ''); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-sm-6">
                                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Confirm Password</label>
                                <input type="password" name="password_confirm" class="form-control" placeholder="••••••••" required>
                            </div>
                        </div>
                        
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="terms" required style="background-color: rgba(8,11,17,0.6); border-color: var(--border-color);">
                            <label class="form-check-label text-muted" for="terms" style="font-size: 0.8rem;">
                                I agree to the <?php echo e(get_platform_name()); ?> SaaS terms & privacy policy.
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="font-family: 'Outfit'; font-size: 1rem;">Create My Workspace</button>
                    </form>
                    
                    <div class="text-center mt-4 border-top pt-3" style="border-color: rgba(255,255,255,0.06) !important;">
                        <span class="text-muted" style="font-size: 0.85rem;">Already have an ISP workspace?</span>
                        <a href="login.php" class="text-primary ms-1 text-decoration-none" style="font-size: 0.85rem; font-weight: 600;">Log In</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
