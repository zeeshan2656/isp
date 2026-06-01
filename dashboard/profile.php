<?php
/**
 * ISP Settings & Profile Management
 * Allows editing purchased bandwidth, monthly cost, and secure password updates.
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$success = false;

// Fetch active profile data
$tenant = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $tenant = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Profile load failure: " . $e->getMessage());
}

if (!$tenant) {
    set_session_alert("Unable to load profile data.", "error");
    header("Location: index.php");
    exit;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    if (isset($_POST['update_profile'])) {
        // PROFILE UPDATE
        $comp_name = clean_input($_POST['company_name'] ?? '');
        $phone = clean_input($_POST['phone'] ?? '');
        $bandwidth = (int)($_POST['bandwidth_purchased'] ?? 0);
        $cost = (double)($_POST['internet_cost'] ?? 0.00);
        
        if (empty($comp_name)) $errors[] = "Company name is required.";
        if (empty($phone)) $errors[] = "Phone number is required.";
        if ($bandwidth < 0) $errors[] = "Bandwidth must be positive.";
        if ($cost < 0) $errors[] = "Monthly cost must be positive.";
        
        if (empty($errors)) {
            try {
                $stmt = $pdo->prepare("UPDATE tenants SET company_name = ?, phone = ?, bandwidth_purchased = ?, internet_cost = ? WHERE id = ?");
                $stmt->execute([$comp_name, $phone, $bandwidth, $cost, $tenant_id]);
                
                // Update session variable
                $_SESSION['tenant_name'] = $comp_name;
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Updated ISP profile settings & wholesale internet costs.");
                set_session_alert("ISP profile settings updated successfully.", "success");
                header("Location: profile.php");
                exit;
            } catch (PDOException $e) {
                $errors[] = "Database save error: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['change_password'])) {
        // PASSWORD UPDATE
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
                // Verify old password
                if (password_verify($old_pass, $tenant['password_hash'])) {
                    $new_hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("UPDATE tenants SET password_hash = ? WHERE id = ?");
                    $stmt->execute([$new_hash, $tenant_id]);
                    
                    log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Changed account administrator password.");
                    set_session_alert("Password updated successfully.", "success");
                    header("Location: profile.php");
                    exit;
                } else {
                    $errors[] = "The old password you entered is incorrect.";
                }
            } catch (PDOException $e) {
                $errors[] = "Database password save error: " . $e->getMessage();
            }
        }
    }
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-gear text-primary me-2"></i>ISP Profile & Settings</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Manage company metadata, wholesale bandwidth limits, and authentication credentials.</p>
    </div>
</div>

<div class="row g-4">
    <!-- Profile & Bandwidth Cost Settings Card -->
    <div class="col-lg-7">
        <div class="p-4 p-md-5 glass-panel h-100">
            <h5 class="text-white mb-4 border-bottom pb-2 font-outfit"><i class="bi bi-sliders text-primary me-2"></i>Company & Wholesales Bandwidth</h5>
            
            <?php if (!empty($errors) && isset($_POST['update_profile'])): ?>
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
                <input type="hidden" name="update_profile" value="1">
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">ISP Company Name</label>
                    <input type="text" name="company_name" class="form-control" value="<?php echo e($tenant['company_name']); ?>" required>
                </div>
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Contact Email Address</label>
                        <input type="email" class="form-control" value="<?php echo e($tenant['email']); ?>" disabled style="opacity: 0.5; background: rgba(0,0,0,0.2);">
                        <small class="text-muted" style="font-size: 0.7rem;">Email address cannot be changed</small>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Contact Phone Number</label>
                        <input type="tel" name="phone" class="form-control" value="<?php echo e($tenant['phone']); ?>" required>
                    </div>
                </div>
                
                <hr class="border-white border-opacity-10 my-3">
                
                <h6 class="text-primary font-outfit mb-0"><i class="bi bi-wallet2 me-1.5"></i>Wholesale Internet Costs Tracker</h6>
                <p class="text-muted mb-1" style="font-size: 0.82rem;">Enter your monthly wholesale purchase limits. <?php echo e(get_platform_name()); ?> uses these configurations to automatically calculate net profitability margins.</p>
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Total Bandwidth Purchased (Mbps)</label>
                        <input type="number" name="bandwidth_purchased" class="form-control" placeholder="500" value="<?php echo e($tenant['bandwidth_purchased']); ?>" min="0">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Monthly Purchase Cost (Rs.)</label>
                        <input type="number" name="internet_cost" class="form-control" placeholder="50000" value="<?php echo e($tenant['internet_cost']); ?>" min="0" step="0.01">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary-gradient py-2.5 mt-3" style="font-family: 'Outfit'; font-size: 0.95rem;">Save Metadata Settings</button>
            </form>
        </div>
    </div>
    
    <!-- Security / Password Update Panel Card -->
    <div class="col-lg-5">
        <div class="p-4 p-md-5 glass-panel h-100 border-danger border-opacity-5">
            <h5 class="text-white mb-4 border-bottom pb-2 font-outfit"><i class="bi bi-shield-lock text-danger me-2"></i>Security & Credentials</h5>
            
            <?php if (!empty($errors) && isset($_POST['change_password'])): ?>
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
                <input type="hidden" name="change_password" value="1">
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Current Active Password</label>
                    <input type="password" name="old_password" class="form-control" placeholder="••••••••" required>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">New Security Password</label>
                    <input type="password" name="new_password" class="form-control" placeholder="••••••••" required>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" required>
                </div>
                
                <button type="submit" class="btn btn-dark-glass py-2.5 mt-3 border-danger border-opacity-10 text-danger" style="font-family: 'Outfit'; font-size: 0.95rem;">Update Password</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
