<?php
/**
 * Customer Profile Settings & Credentials Rotation
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$success = false;

// Fetch active customer data
$cust = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$customer_id]);
    $cust = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Customer profile load fail: " . $e->getMessage());
}

if (!$cust) {
    set_session_alert("Unable to load profile data.", "error");
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $old_pass = $_POST['old_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $conf_pass = $_POST['confirm_password'] ?? '';
    
    if (empty($old_pass) || empty($new_pass) || empty($conf_pass)) {
        $errors[] = "All password fields are required.";
    }
    if (strlen($new_pass) < 6) {
        $errors[] = "New password must be at least 6 characters long.";
    }
    if ($new_pass !== $conf_pass) {
        $errors[] = "New passwords do not match.";
    }
    
    if (empty($errors)) {
        try {
            // Verify old password hash
            if (password_verify($old_pass, $cust['password_hash'])) {
                $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE customers SET password_hash = ? WHERE id = ?");
                $stmt->execute([$new_hash, $customer_id]);
                
                log_audit_activity($pdo, $customer_tenant_id, 'customer', $customer_id, "Customer updated self-service portal password.");
                set_session_alert("Portal password updated successfully.", "success");
                header("Location: profile.php");
                exit;
            } else {
                $errors[] = "The current password you entered is incorrect.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database password save error: " . $e->getMessage();
        }
    }
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-shield-check text-secondary me-2"></i>Account Security Settings</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Manage self-service credentials, secure login pins, and update session parameters.</p>
    </div>
</div>

<div class="row g-4">
    <!-- Contact Info summary -->
    <div class="col-lg-6">
        <div class="p-4 p-md-5 glass-panel h-100" style="border-color: rgba(168, 85, 247, 0.12) !important;">
            <h5 class="text-white mb-4 border-bottom pb-2 font-outfit"><i class="bi bi-person-badge text-secondary me-2"></i>Subscriber Credentials</h5>
            
            <div class="d-flex flex-column gap-3" style="font-size: 0.92rem;">
                <div>
                    <span class="text-muted d-block" style="font-size: 0.75rem;">Full Registered Name</span>
                    <strong class="text-white"><?php echo e($cust['name']); ?></strong>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.75rem;">CNIC / National Identity ID</span>
                    <strong class="text-white"><?php echo e($cust['cnic']); ?></strong>
                </div>
                <div class="row">
                    <div class="col">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Active Phone</span>
                        <strong class="text-white"><?php echo e($cust['phone']); ?></strong>
                    </div>
                    <div class="col">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Portal Login Email</span>
                        <strong class="text-white"><?php echo e($cust['email']); ?></strong>
                    </div>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.75rem;">Home Area & Address</span>
                    <span class="text-white"><?php echo e($cust['address']); ?> (Area: <?php echo e($cust['area']); ?>)</span>
                </div>
            </div>
            
            <div class="p-3 border rounded-3 mt-4 text-muted bg-dark" style="border-color: var(--border-color); font-size: 0.8rem;">
                <i class="bi bi-info-circle text-secondary me-1.5 fs-5 align-middle"></i>
                Contact details are locked for billing integrity. Contact your service provider to change your registered email, contact numbers, or home address.
            </div>
        </div>
    </div>
    
    <!-- Password modification card -->
    <div class="col-lg-6">
        <div class="p-4 p-md-5 glass-panel h-100 border-danger border-opacity-5">
            <h5 class="text-white mb-4 border-bottom pb-2 font-outfit"><i class="bi bi-key text-danger me-2"></i>Update Portal Password</h5>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.85rem;">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form action="profile.php" method="POST" class="d-flex flex-column gap-3">
                <?php csrf_field(); ?>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Current Active Password</label>
                    <input type="password" name="old_password" class="form-control" placeholder="••••••••" required>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">New Security Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="••••••••" required autocomplete="new-password">
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required autocomplete="new-password">
                </div>
                
                <button type="submit" class="btn btn-dark-glass py-2.5 mt-3 border-danger border-opacity-10 text-danger" style="font-family: 'Outfit'; font-size: 0.95rem;">Save Password changes</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
