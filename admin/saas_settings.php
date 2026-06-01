<?php
/**
 * Super Admin SaaS Settings & Commission Rules Dashboard
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

$errors = [];
$success = "";

// Load existing settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM saas_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("SaaS settings load fail: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $org_name = clean_input($_POST['organization_name'] ?? 'NetPulse');
    $aff_pct = (double)($_POST['affiliate_commission_percentage'] ?? 0.00);
    $tenant_pct = (double)($_POST['tenant_referral_percentage'] ?? 0.00);
    $min_withdraw = (double)($_POST['min_withdrawal_amount'] ?? 0.00);
    $approval_req = (int)($_POST['withdrawal_approval_required'] ?? 0);
    
    if (empty($org_name)) $errors[] = "Platform/Organization name cannot be empty.";
    if ($aff_pct < 0 || $aff_pct > 100) $errors[] = "Affiliate recurring commission must be between 0% and 100%.";
    if ($tenant_pct < 0 || $tenant_pct > 100) $errors[] = "Tenant referral recurring credit must be between 0% and 100%.";
    if ($min_withdraw < 0) $errors[] = "Minimum withdrawal payout limit cannot be negative.";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            $updates = [
                'organization_name' => $org_name,
                'affiliate_commission_percentage' => $aff_pct,
                'tenant_referral_percentage' => $tenant_pct,
                'min_withdrawal_amount' => $min_withdraw,
                'withdrawal_approval_required' => $approval_req
            ];
            
            foreach ($updates as $key => $val) {
                // Ensure key exists first
                $stmt = $pdo->prepare("INSERT IGNORE INTO saas_settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->execute([$key, $val]);
                // Update
                $stmt = $pdo->prepare("UPDATE saas_settings SET setting_value = ? WHERE setting_key = ?");
                $stmt->execute([$val, $key]);
            }
            
            $pdo->commit();
            
            // Reload settings
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM saas_settings");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            set_session_alert("SaaS configuration and platform name modified successfully.", "success");
            header("Location: saas_settings.php");
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Settings update failed: " . $e->getMessage();
        }
    }
}
// Include header layout now that all action redirects are completed
require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-gear text-primary me-2"></i>SaaS Referral & Brand Configuration</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Configure platform name branding, recurring partner commission percentages, tenant referral credits, and payout approval threshold guidelines.</p>
    </div>
</div>

<div class="glass-card p-4 p-md-5" style="max-width: 650px; margin: 0 auto; border-radius: 16px;">
    <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-sliders text-primary me-2"></i>Referral Commission & Brand Adjuster
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
    
    <form action="saas_settings.php" method="POST" class="d-flex flex-column gap-3.5">
        <?php csrf_field(); ?>
        
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label text-muted" style="font-size: 0.8rem;">Platform / Organization Name</label>
                <input type="text" name="organization_name" class="form-control" value="<?php echo e($settings['organization_name'] ?? 'NetPulse'); ?>" required placeholder="e.g. NetPulse, NayeNet">
                <small class="text-muted" style="font-size: 0.7rem;">This updates the platform name all over the website and dashboards. Logo initials (e.g. NP, NN) are automatically derived from capital letters.</small>
            </div>
        </div>
        
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label text-muted" style="font-size: 0.8rem;">Public Affiliate Commission (%)</label>
                <div class="input-group">
                    <input type="number" name="affiliate_commission_percentage" class="form-control" value="<?php echo e($settings['affiliate_commission_percentage'] ?? '20.00'); ?>" step="0.01" required min="0" max="100">
                    <span class="input-group-text bg-dark border-0 text-muted">%</span>
                </div>
                <small class="text-muted" style="font-size: 0.7rem;">Recurring payout from paid ISP SaaS invoices</small>
            </div>
            
            <div class="col-sm-6">
                <label class="form-label text-muted" style="font-size: 0.8rem;">Tenant-to-Tenant Referral (%)</label>
                <div class="input-group">
                    <input type="number" name="tenant_referral_percentage" class="form-control" value="<?php echo e($settings['tenant_referral_percentage'] ?? '10.00'); ?>" step="0.01" required min="0" max="100">
                    <span class="input-group-text bg-dark border-0 text-muted">%</span>
                </div>
                <small class="text-muted" style="font-size: 0.7rem;">Recurring credits auto-applied to referrer ISP invoice</small>
            </div>
        </div>
        
        <div class="row g-3">
            <div class="col-sm-6">
                <label class="form-label text-muted" style="font-size: 0.8rem;">Minimum Withdrawal Payout (Rs.)</label>
                <input type="number" name="min_withdrawal_amount" class="form-control" value="<?php echo e($settings['min_withdrawal_amount'] ?? '1000.00'); ?>" step="0.01" required min="0">
                <small class="text-muted" style="font-size: 0.7rem;">Minimum threshold required for cashout requests</small>
            </div>
            
            <div class="col-sm-6">
                <label class="form-label text-muted" style="font-size: 0.8rem;">Withdrawal Payout Approval Mode</label>
                <select name="withdrawal_approval_required" class="form-select" required>
                    <option value="1" <?php echo (($settings['withdrawal_approval_required'] ?? '1') === '1') ? 'selected' : ''; ?>>Require Manual Payout Review</option>
                    <option value="0" <?php echo (($settings['withdrawal_approval_required'] ?? '1') === '0') ? 'selected' : ''; ?>>Auto-Approve Payouts</option>
                </select>
                <small class="text-muted" style="font-size: 0.7rem;">Whether cashouts must undergo manual validation</small>
            </div>
        </div>
        
        <div class="d-flex gap-2 justify-content-end mt-3 border-top pt-3 border-white border-opacity-5">
            <button type="submit" class="btn btn-primary px-4 py-2.5">Save Global Rules</button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
