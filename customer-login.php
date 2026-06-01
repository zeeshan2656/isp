<?php
/**
 * Customer Self-Service Portal Login
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

// Redirect if already logged in
if (is_customer_logged_in()) {
    header("Location: customer/index.php");
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
        $errors[] = "Please enter your portal password.";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $customer = $stmt->fetch();
            
            if ($customer && password_verify($password, $customer['password_hash'])) {
                if ($customer['status'] === 'suspended') {
                    $errors[] = "Your account is currently suspended. Please contact your ISP support.";
                } else {
                    // Log session in
                    login_customer($customer);
                    
                    // Set audit log
                    log_audit_activity($pdo, $customer['tenant_id'], 'customer', $customer['id'], 'Customer logged in to Self-Service Portal');
                    
                    header("Location: customer/index.php");
                    exit;
                }
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

<section class="py-5" style="min-height: 80vh; background: radial-gradient(circle at top right, rgba(168, 85, 247, 0.05) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-7 col-lg-5">
                
                <div class="text-center mb-4">
                    <h2 class="display-6 fw-bold text-white mb-2">Customer Self-Service</h2>
                    <p class="text-muted">Log in to view your package, speed, and invoice payments history.</p>
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
                    
                    <form action="customer-login.php" method="POST" class="d-flex flex-column gap-3">
                        <?php csrf_field(); ?>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Registered Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="client@email.com" value="<?php echo e($email); ?>" required autocomplete="email">
                        </div>
                        
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label text-muted mb-0" style="font-size: 0.8rem; font-weight: 500;">Password</label>
                            </div>
                            <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
                        </div>
                        
                        <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); box-shadow: 0 4px 14px rgba(168, 85, 247, 0.3); font-family: 'Outfit'; font-size: 1rem;">Log In as Customer</button>
                    </form>
                    
                    <div class="text-center mt-4 border-top pt-3" style="border-color: rgba(255,255,255,0.06) !important;">
                        <span class="text-muted" style="font-size: 0.82rem;">Need portal login credentials? Contact your local internet service provider.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
