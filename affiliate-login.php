<?php
/**
 * Public Affiliate Login Secure Authentication Portal
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

if (is_affiliate_logged_in()) {
    header("Location: affiliates/index.php");
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid partner email address.";
    }
    if (empty($password)) {
        $errors[] = "Please enter your password.";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM affiliates WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $aff = $stmt->fetch();
            
            if ($aff && password_verify($password, $aff['password_hash'])) {
                if ($aff['status'] === 'suspended') {
                    $errors[] = "Your affiliate account is currently suspended. Please contact platform support.";
                } else {
                    session_regenerate_id(true);
                    $_SESSION['affiliate_id'] = $aff['id'];
                    $_SESSION['affiliate_name'] = $aff['name'];
                    $_SESSION['affiliate_email'] = $aff['email'];
                    $_SESSION['role'] = 'affiliate';
                    $_SESSION['initiated_at'] = time();
                    $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                    
                    header("Location: affiliates/index.php");
                    exit;
                }
            } else {
                $errors[] = "Invalid partner email or password combination.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database operation failure: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="py-5" style="min-height: 80vh; background: radial-gradient(circle at top left, rgba(168, 85, 247, 0.05) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-5">
                
                <div class="text-center mb-4">
                    <h2 class="display-6 fw-bold text-white mb-2">Partner Sign In</h2>
                    <p class="text-muted">Access your <?php echo e(get_platform_name()); ?> earnings and referral tracking statistics.</p>
                </div>
                
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); border-color: rgba(168, 85, 247, 0.15) !important;">
                    
                    <?php display_session_alerts(); ?>
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo e($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form action="affiliate-login.php" method="POST" class="d-flex flex-column gap-3">
                        <?php csrf_field(); ?>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Affiliate Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="partner@email.com" value="<?php echo e($email); ?>" required autocomplete="email">
                        </div>
                        
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label text-muted mb-0" style="font-size: 0.8rem; font-weight: 500;">Password</label>
                            </div>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                        </div>
                        
                        <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="background: linear-gradient(135deg, #A855F7, #6366F1); font-family: 'Outfit'; font-size: 1rem;">Authenticate Session</button>
                    </form>
                    
                    <div class="text-center mt-4 border-top pt-3" style="border-color: rgba(255,255,255,0.06) !important;">
                        <span class="text-muted" style="font-size: 0.85rem;">New to our partner network?</span>
                        <a href="affiliate-register.php" class="text-primary ms-1 text-decoration-none" style="font-size: 0.85rem; font-weight: 600;">Create Affiliate Account</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
