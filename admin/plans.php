<?php
/**
 * NetPulse SaaS Platform Owner - Subscription Plans Limits Configuration
 */
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce Super Admin Guard
require_super_admin_login();

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$edit_id = (int)($_GET['id'] ?? 0);

$name = '';
$max_customers = '';
$max_zones = '';
$max_packages = '';
$monthly_fee = '';
$features_list = '';

// Load Plan for Editing
if ($action === 'edit' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM saas_plans WHERE id = ? LIMIT 1");
        $stmt->execute([$edit_id]);
        $plan = $stmt->fetch();
        
        if ($plan) {
            $name = $plan['name'];
            $max_customers = $plan['max_customers'];
            $max_zones = $plan['max_zones'];
            $max_packages = $plan['max_packages'];
            $monthly_fee = $plan['monthly_fee'];
            $features_list = $plan['features_list'];
        } else {
            set_session_alert("Plan not found.", "error");
            header("Location: plans.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Load plan fail: " . $e->getMessage());
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $edit_id > 0) {
    verify_csrf_token();
    
    $max_customers = (int)($_POST['max_customers'] ?? 0);
    $max_zones = (int)($_POST['max_zones'] ?? 0);
    $max_packages = (int)($_POST['max_packages'] ?? 0);
    $monthly_fee = (double)($_POST['monthly_fee'] ?? 0.00);
    $features_list = clean_input($_POST['features_list'] ?? '');
    
    if ($max_customers <= 0) $errors[] = "Subscriber limit must be greater than 0.";
    if ($max_zones <= 0) $errors[] = "Zones limit must be greater than 0.";
    if ($max_packages <= 0) $errors[] = "Packages limit must be greater than 0.";
    if ($monthly_fee < 0) $errors[] = "Monthly subscription fee cannot be negative.";
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE saas_plans SET max_customers = ?, max_zones = ?, max_packages = ?, monthly_fee = ?, features_list = ? WHERE id = ?");
            $stmt->execute([$max_customers, $max_zones, $max_packages, $monthly_fee, $features_list, $edit_id]);
            
            log_audit_activity($pdo, 1, 'tenant', 1, "Modified SaaS Subscription Plan parameters for: $name (ID: $edit_id)");
            set_session_alert("SaaS plan parameters adjusted successfully.", "success");
            header("Location: plans.php");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Plan save failed: " . $e->getMessage();
        }
    }
}

// Load all plans
$plans = [];
try {
    $plans = $pdo->query("SELECT * FROM saas_plans ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Plans select fail: " . $e->getMessage());
}

// Include header layout now that all action redirects are completed
require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-sliders2 text-primary me-2"></i>SaaS Subscription Limits</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Configure maximum operational limits (subscriber counts, zone partitions, internet bandwidth tiers) and monthly leasing rates for workspace tiers.</p>
    </div>
</div>

<?php if ($action === 'list'): ?>
    
    <!-- Subscription Plans grid -->
    <div class="row g-4">
        <?php foreach ($plans as $p): ?>
            <div class="col-md-4">
                <div class="glass-card p-4 h-100 d-flex flex-column border-primary border-opacity-10">
                    <div class="d-flex justify-content-between align-items-start mb-3 pb-2 border-bottom border-white border-opacity-5">
                        <div>
                            <h4 class="text-white font-outfit fw-bold mb-0"><?php echo e($p['name']); ?></h4>
                            <span class="text-muted" style="font-size: 0.72rem;">Configure parameters override</span>
                        </div>
                        <span class="badge bg-primary bg-opacity-10 text-primary px-2.5 py-1" style="font-size: 0.72rem; border: 1px solid rgba(168,85,247,0.2);">Rs. <?php echo e($p['monthly_fee']); ?>/mo</span>
                    </div>
                    
                    <p class="text-muted" style="font-size: 0.85rem; line-height: 1.6;"><?php echo e($p['features_list']); ?></p>
                    
                    <div class="p-3 bg-dark rounded-3 border border-white border-opacity-5 mb-4 mt-auto text-start" style="font-size: 0.82rem;">
                        <div class="row g-2">
                            <div class="col-7">
                                <span class="text-muted d-block" style="font-size: 0.7rem;">Subscriber Capacity:</span>
                                <strong class="text-white"><?php echo $p['max_customers'] > 50000 ? 'Unlimited' : $p['max_customers'] . ' Customers'; ?></strong>
                            </div>
                            <div class="col-5 border-start border-white border-opacity-5 ps-3">
                                <span class="text-muted d-block" style="font-size: 0.7rem;">Zone Limits:</span>
                                <strong class="text-white"><?php echo e($p['max_zones']); ?> Coverage Areas</strong>
                            </div>
                            <div class="col-7 mt-2 pt-2 border-top border-white border-opacity-5">
                                <span class="text-muted d-block" style="font-size: 0.7rem;">Rate Plans Limits:</span>
                                <strong class="text-white"><?php echo e($p['max_packages']); ?> Packages</strong>
                            </div>
                        </div>
                    </div>
                    
                    <a href="plans.php?action=edit&id=<?php echo $p['id']; ?>" class="btn btn-dark-glass w-100 py-2 mt-auto" style="font-size: 0.88rem; font-weight: 500;"><i class="bi bi-pencil-square me-1.5"></i>Edit Limits & Rates</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    
<?php elseif ($action === 'edit' && $edit_id > 0): ?>
    
    <!-- Edit Plan Form -->
    <div class="p-4 p-md-5 glass-panel" style="max-width: 600px; margin: 0 auto; border-radius: 16px;">
        <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;">
            <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Limits Configuration: <?php echo e($name); ?>
        </h5>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form action="plans.php?action=edit&id=<?php echo $edit_id; ?>" method="POST" class="d-flex flex-column gap-3.5">
            <?php csrf_field(); ?>
            
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Max Customer Capacity</label>
                    <input type="number" name="max_customers" class="form-control" value="<?php echo e($max_customers); ?>" required min="1">
                    <small class="text-muted" style="font-size: 0.7rem;">Enter 99999 for unlimited capacity</small>
                </div>
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Monthly SaaS Fee (Rs.)</label>
                    <input type="number" name="monthly_fee" class="form-control" value="<?php echo e($monthly_fee); ?>" required min="0" step="0.01">
                </div>
            </div>
            
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Max Coverage Zones Allowed</label>
                    <input type="number" name="max_zones" class="form-control" value="<?php echo e($max_zones); ?>" required min="1">
                </div>
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Max Internet Packages Allowed</label>
                    <input type="number" name="max_packages" class="form-control" value="<?php echo e($max_packages); ?>" required min="1">
                </div>
            </div>
            
            <div>
                <label class="form-label text-muted" style="font-size: 0.8rem;">Plan Description & Feature Tags</label>
                <textarea name="features_list" class="form-control" rows="3" placeholder="SaaS plan highlights..." required><?php echo e($features_list); ?></textarea>
            </div>
            
            <div class="d-flex gap-2 justify-content-end mt-3 border-top pt-3 border-white border-opacity-5">
                <button type="submit" class="btn btn-primary px-4 py-2.5">Apply Plan Parameters</button>
                <a href="plans.php" class="btn btn-dark-glass px-4 py-2.5">Cancel</a>
            </div>
        </form>
    </div>
    
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
