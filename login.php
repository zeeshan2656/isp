<?php
/**
 * ISP Portal Login
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

// Redirect if already logged in
if (is_tenant_logged_in()) {
    header("Location: dashboard/index.php");
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (empty($password)) {
        $errors[] = "Please enter your password.";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM tenants WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $tenant = $stmt->fetch();
            
            if ($tenant && password_verify($password, $tenant['password_hash'])) {
                // Log session in
                login_tenant($tenant);
                
                // Set audit log
                log_audit_activity($pdo, $tenant['id'], 'tenant', $tenant['id'], 'Logged in to ISP Dashboard');
                
                header("Location: dashboard/index.php");
                exit;
            } else {
                $errors[] = "Invalid email or password combination.";
            }
        } catch (PDOException $e) {
            $errors[] = "Login connection failure: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="py-5" style="min-height: 80vh; background: radial-gradient(circle at top left, rgba(99, 102, 241, 0.05) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-5">
                
                <div class="text-center mb-4">
                    <h2 class="display-6 fw-bold text-white mb-2">ISP Owner Login</h2>
                    <p class="text-muted">Access your private multi-tenant ISP workspace.</p>
                </div>
                
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
                    
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
                    
                    <form action="login.php" method="POST" class="d-flex flex-column gap-3">
                        <?php csrf_field(); ?>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="name@ispcompany.com" value="<?php echo e($email); ?>" required autocomplete="email">
                        </div>
                        
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label text-muted mb-0" style="font-size: 0.8rem; font-weight: 500;">Password</label>
                                <a href="#" class="text-primary text-decoration-none" style="font-size: 0.75rem;">Forgot?</a>
                            </div>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                        </div>
                        
                        <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="font-family: 'Outfit'; font-size: 1rem;">Log In to Dashboard</button>
                    </form>
                    
                    <div class="text-center mt-4 border-top pt-3" style="border-color: rgba(255,255,255,0.06) !important;">
                        <span class="text-muted" style="font-size: 0.85rem;">New to <?php echo e(get_platform_name()); ?>?</span>
                        <a href="register.php" class="text-primary ms-1 text-decoration-none" style="font-size: 0.85rem; font-weight: 600;">Create Workspace</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
