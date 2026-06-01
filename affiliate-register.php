<?php
/**
 * Public Affiliate Registration Gateway
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$errors = [];
$name = "";
$email = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $name = clean_input($_POST['name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    if (empty($name)) $errors[] = "Full Name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters long.";
    if ($password !== $password_confirm) $errors[] = "Passwords do not match.";
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "This email is already registered as an affiliate.";
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                // Unique referral code e.g. AFF-A1B2C3D4
                $ref_code = 'AFF-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                
                $insert = $pdo->prepare("INSERT INTO affiliates (name, email, password_hash, referral_code, status) VALUES (?, ?, ?, ?, 'active')");
                $insert->execute([$name, $email, $hash, $ref_code]);
                
                set_session_alert("Affiliate registration completed! Please log in to access your partner dashboard.", "success");
                header("Location: affiliate-login.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "Affiliate sign up error: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="py-5" style="min-height: 80vh; background: radial-gradient(circle at bottom right, rgba(168, 85, 247, 0.05) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-5">
                
                <div class="text-center mb-4">
                    <h2 class="display-6 fw-bold text-white mb-2">Join the Affiliate Program</h2>
                    <p class="text-muted">Earn 20% recurring commissions referring ISPs to <?php echo e(get_platform_name()); ?>.</p>
                </div>
                
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.4); border-color: rgba(168, 85, 247, 0.15) !important;">
                    
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
                            <ul class="mb-0 ps-3">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo e($error); ?></li>
                                	<?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <form action="affiliate-register.php" method="POST" class="d-flex flex-column gap-3">
                        <?php csrf_field(); ?>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Full Name</label>
                            <input type="text" name="name" class="form-control" placeholder="John Doe" value="<?php echo e($name); ?>" required>
                        </div>
                        
                        <div>
                            <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="partner@email.com" value="<?php echo e($email); ?>" required autocomplete="email">
                        </div>
                        
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Password</label>
                                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Confirm Password</label>
                                <input type="password" name="password_confirm" class="form-control" placeholder="••••••••" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="background: linear-gradient(135deg, #A855F7, #6366F1); font-family: 'Outfit'; font-size: 1rem;">Join Partner Network</button>
                    </form>
                    
                    <div class="text-center mt-4 border-top pt-3" style="border-color: rgba(255,255,255,0.06) !important;">
                        <span class="text-muted" style="font-size: 0.85rem;">Already an affiliate partner?</span>
                        <a href="affiliate-login.php" class="text-primary ms-1 text-decoration-none" style="font-size: 0.85rem; font-weight: 600;">Sign In Here</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
