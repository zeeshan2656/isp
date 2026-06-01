<?php
/**
 * NetPulse Super Admin - Profile & Account Settings
 * Allows the Super Admin to update name, company, email, phone, address, and password.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce Super Admin Guard
require_super_admin_login();

$admin_id = $_SESSION['super_admin_id'];
$errors = [];

// Load current admin data
$admin = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE id = ? LIMIT 1");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
} catch (PDOException $e) {
    $errors[] = "Failed to load admin profile: " . $e->getMessage();
}

// Handle Profile Details Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    verify_csrf_token();
    
    $name = clean_input($_POST['name'] ?? '');
    $company_name = clean_input($_POST['company_name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $address = clean_input($_POST['address'] ?? '');
    
    if (empty($name)) $errors[] = "Full name is required.";
    if (empty($email)) $errors[] = "Email address is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Please enter a valid email address.";
    
    // Check email uniqueness (exclude self)
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM super_admins WHERE email = ? AND id != ?");
        $stmt->execute([$email, $admin_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = "This email address is already in use by another admin account.";
        }
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE super_admins SET name = ?, company_name = ?, email = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $company_name, $email, $phone, $address, $admin_id]);
            
            // Update session variables
            $_SESSION['super_admin_name'] = $name;
            $_SESSION['super_admin_email'] = $email;
            
            log_audit_activity($pdo, $admin_id, 'profile', $admin_id, "Super Admin updated profile details.");
            set_session_alert("Profile details updated successfully.", "success");
            header("Location: profile.php");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Profile update failed: " . $e->getMessage();
        }
    }
}

// Handle Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    verify_csrf_token();
    
    $current_pw = $_POST['current_password'] ?? '';
    $new_pw = $_POST['new_password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';
    
    if (empty($current_pw)) $errors[] = "Current password is required.";
    if (empty($new_pw)) $errors[] = "New password is required.";
    if (strlen($new_pw) < 6) $errors[] = "New password must be at least 6 characters long.";
    if ($new_pw !== $confirm_pw) $errors[] = "New password and confirmation do not match.";
    
    // Verify current password
    if (empty($errors)) {
        if (!password_verify($current_pw, $admin['password_hash'])) {
            $errors[] = "Current password is incorrect.";
        }
    }
    
    if (empty($errors)) {
        try {
            $new_hash = password_hash($new_pw, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE super_admins SET password_hash = ? WHERE id = ?");
            $stmt->execute([$new_hash, $admin_id]);
            
            log_audit_activity($pdo, $admin_id, 'profile', $admin_id, "Super Admin changed account password.");
            set_session_alert("Password changed successfully.", "success");
            header("Location: profile.php");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Password change failed: " . $e->getMessage();
        }
    }
}

// Reload admin data after potential update
if (empty($errors) || $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE id = ? LIMIT 1");
        $stmt->execute([$admin_id]);
        $admin = $stmt->fetch();
    } catch (PDOException $e) {}
}

// Include header layout now that all action redirects are completed
require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-person-badge text-primary me-2"></i>My Profile & Account</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Update your personal contact details, company information, and secure your account with a new password.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $error): ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Profile Details Card -->
    <div class="col-lg-7">
        <div class="glass-card p-4 p-md-5" style="border-radius: 16px;">
            <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-person-lines-fill text-primary me-2"></i>Personal & Contact Details
            </h5>
            
            <form action="profile.php" method="POST" class="d-flex flex-column gap-3.5">
                <?php csrf_field(); ?>
                <input type="hidden" name="update_profile" value="1">
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?php echo e($admin['name'] ?? ''); ?>" required placeholder="Your full name">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Company / Organization</label>
                        <input type="text" name="company_name" class="form-control" value="<?php echo e($admin['company_name'] ?? ''); ?>" placeholder="<?php echo e(get_platform_name()); ?> Inc.">
                    </div>
                </div>
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo e($admin['email'] ?? ''); ?>" required placeholder="admin@example.com">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Contact Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?php echo e($admin['phone'] ?? ''); ?>" placeholder="+92 300 1234567">
                    </div>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Office / Mailing Address</label>
                    <textarea name="address" class="form-control" rows="3" placeholder="Street, City, Province, Zip Code"><?php echo e($admin['address'] ?? ''); ?></textarea>
                </div>
                
                <div class="d-flex gap-2 justify-content-end mt-3 border-top pt-3 border-white border-opacity-5">
                    <button type="submit" class="btn btn-primary px-4 py-2.5"><i class="bi bi-check-lg me-1.5"></i>Save Profile Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Password & Account Card -->
    <div class="col-lg-5">
        <div class="glass-card p-4 p-md-5 mb-4" style="border-radius: 16px;">
            <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-shield-lock text-primary me-2"></i>Change Password
            </h5>
            
            <form action="profile.php" method="POST" class="d-flex flex-column gap-3">
                <?php csrf_field(); ?>
                <input type="hidden" name="change_password" value="1">
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Current Password</label>
                    <input type="password" name="current_password" class="form-control" required placeholder="Enter current password">
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem;">New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6" placeholder="Minimum 6 characters">
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" required placeholder="Retype new password">
                </div>
                
                <div class="d-flex gap-2 justify-content-end mt-3 border-top pt-3 border-white border-opacity-5">
                    <button type="submit" class="btn btn-warning px-4 py-2.5 text-dark"><i class="bi bi-key me-1.5"></i>Update Password</button>
                </div>
            </form>
        </div>
        
        <!-- Account Info Card -->
        <div class="glass-card p-4" style="border-radius: 16px; border-color: rgba(168, 85, 247, 0.1) !important;">
            <h5 class="text-white mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-info-circle text-primary me-2"></i>Account Information
            </h5>
            <div class="d-flex flex-column gap-2.5">
                <div class="d-flex justify-content-between align-items-center py-2 px-3 bg-dark rounded-3 border border-white border-opacity-5">
                    <span class="text-muted" style="font-size: 0.8rem;">Account ID</span>
                    <strong class="text-primary font-monospace">#<?php echo $admin['id']; ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2 px-3 bg-dark rounded-3 border border-white border-opacity-5">
                    <span class="text-muted" style="font-size: 0.8rem;">Role</span>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2.5">Super Administrator</span>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2 px-3 bg-dark rounded-3 border border-white border-opacity-5">
                    <span class="text-muted" style="font-size: 0.8rem;">Account Created</span>
                    <strong class="text-white" style="font-size: 0.85rem;"><?php echo date('d M, Y', strtotime($admin['created_at'])); ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center py-2 px-3 bg-dark rounded-3 border border-white border-opacity-5">
                    <span class="text-muted" style="font-size: 0.8rem;">Login Email</span>
                    <strong class="text-white" style="font-size: 0.82rem;"><?php echo e($admin['email']); ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
