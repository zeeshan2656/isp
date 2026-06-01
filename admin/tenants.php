<?php
/**
 * NetPulse SaaS Platform Owner - Tenant Operations Manager (Approvals, Suspensions, Renewals)
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

// Load dynamic plans for plan assignment drop down
$plans_opt = [];
try {
    $plans_opt = $pdo->query("SELECT id, name, monthly_fee FROM saas_plans ORDER BY id ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Plans load failed: " . $e->getMessage());
}

// 0. RESET TENANT PASSWORD ACTION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_tenant_password'])) {
    verify_csrf_token();
    
    $tenant_id = (int)($_POST['tenant_id'] ?? 0);
    $new_password = clean_input($_POST['new_password'] ?? '');
    
    if ($tenant_id <= 0) $errors[] = "Invalid tenant selection.";
    if (empty($new_password)) $errors[] = "New password cannot be empty.";
    elseif (strlen($new_password) < 6) $errors[] = "New password must be at least 6 characters.";
    
    if (empty($errors)) {
        try {
            $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE tenants SET password_hash = ? WHERE id = ?");
            $stmt->execute([$password_hash, $tenant_id]);
            
            log_audit_activity($pdo, 1, 'tenant', 1, "Reset password for tenant workspace ID: $tenant_id");
            set_session_alert("Password updated successfully for tenant workspace.", "success");
        } catch (PDOException $e) {
            set_session_alert("Password reset failed: " . $e->getMessage(), "error");
        }
    } else {
        set_session_alert(implode(" ", $errors), "error");
    }
    header("Location: tenants.php");
    exit;
}

// 1. APPROVE TENANT ACTION
if ($action === 'approve' && $edit_id > 0) {
    try {
        // Fetch Tenant
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$edit_id]);
        $tenant = $stmt->fetch();
        
        if ($tenant) {
            $plan_id = (int)($_POST['plan_id'] ?? 1);
            
            // Get Plan monthly price
            $stmt = $pdo->prepare("SELECT name, monthly_fee FROM saas_plans WHERE id = ? LIMIT 1");
            $stmt->execute([$plan_id]);
            $plan = $stmt->fetch();
            $fee = $plan ? (double)$plan['monthly_fee'] : 2500.00;
            $plan_name = $plan ? $plan['name'] : 'Starter Plan';
            
            $start = date('Y-m-d');
            $end = date('Y-m-d', strtotime('+30 days'));
            
            // 1. Activate Tenant Subscription
            $upd = $pdo->prepare("UPDATE tenants SET plan_id = ?, status = 'active', subscription_start = ?, subscription_end = ?, monthly_fee = ? WHERE id = ?");
            $upd->execute([$plan_id, $start, $end, $fee, $edit_id]);
            
            // 2. Generate First SaaS Invoice automatically
            $inv_num = 'SAAS-INV-' . date('Ymd') . '-' . sprintf('%04d', $edit_id) . '-' . rand(100, 999);
            $ins = $pdo->prepare("INSERT INTO saas_invoices (tenant_id, invoice_number, plan_name, amount, remaining_amount, due_date, payment_status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $ins->execute([$edit_id, $inv_num, $plan_name, $fee, $fee, date('Y-m-d', strtotime('+7 days'))]);
            
            // 3. Log audit activity
            log_audit_activity($pdo, 1, 'tenant', 1, "Approved ISP registration request for {$tenant['company_name']}. Plan: $plan_name assigned.");
            set_session_alert("Tenant workspace '{$tenant['company_name']}' has been approved and initialized under $plan_name successfully.", "success");
            
        } else {
            set_session_alert("Invalid tenant selection.", "error");
        }
    } catch (PDOException $e) {
        set_session_alert("Approve action failed: " . $e->getMessage(), "error");
    }
    header("Location: tenants.php");
    exit;
}

// 2. SUSPEND TENANT ACTION
if ($action === 'suspend' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE tenants SET status = 'suspended' WHERE id = ?");
        $stmt->execute([$edit_id]);
        
        log_audit_activity($pdo, 1, 'tenant', 1, "Suspended tenant workspace lease ID: $edit_id due to administrative/non-payment reason.");
        set_session_alert("Tenant workspace account suspended successfully.", "success");
    } catch (PDOException $e) {
        set_session_alert("Suspend execution failed: " . $e->getMessage(), "error");
    }
    header("Location: tenants.php");
    exit;
}

// 3. REACTIVATE TENANT ACTION
if ($action === 'activate' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("UPDATE tenants SET status = 'active' WHERE id = ?");
        $stmt->execute([$edit_id]);
        
        log_audit_activity($pdo, 1, 'tenant', 1, "Reactivated tenant workspace lease ID: $edit_id.");
        set_session_alert("Tenant workspace reactivated successfully.", "success");
    } catch (PDOException $e) {
        set_session_alert("Reactivate execution failed: " . $e->getMessage(), "error");
    }
    header("Location: tenants.php");
    exit;
}

// 4. REJECT / DELETE TENANT REQUEST
if ($action === 'reject' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("DELETE FROM tenants WHERE id = ? AND status = 'pending'");
        $stmt->execute([$edit_id]);
        
        log_audit_activity($pdo, 1, 'tenant', 1, "Rejected and deleted pending tenant workspace application ID: $edit_id.");
        set_session_alert("Pending tenant request rejected and removed.", "success");
    } catch (PDOException $e) {
        set_session_alert("Reject execution failed: " . $e->getMessage(), "error");
    }
    header("Location: tenants.php");
    exit;
}

// Include header layout now that all action redirects are completed
require_once __DIR__ . '/layouts/header.php';

// Fetch all tenants
$tenants = [];
try {
    $tenants = $pdo->query("SELECT t.*, p.name as plan_name 
        FROM tenants t 
        LEFT JOIN saas_plans p ON t.plan_id = p.id 
        ORDER BY t.status DESC, t.id DESC")->fetchAll();
} catch (PDOException $e) {
    error_log("Tenants lookup failed: " . $e->getMessage());
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-building text-primary me-2"></i>Tenant Workspace Manager</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Review registered ISP applicants, configure active subscriptions, manage plan overrides, or suspend default workspaces.</p>
    </div>
</div>

<!-- Pending Registration Requests Box -->
<div class="glass-card p-4 mb-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-shield-exclamation text-warning me-2"></i>ISP Registration Applications (Pending Review)
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>ISP Company Details</th>
                    <th>Subdomain Workspace</th>
                    <th>Contact Phone</th>
                    <th>Subscribed Email</th>
                    <th class="text-end">Actions Workflow</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php
                $pending_list = array_filter($tenants, function($t) { return $t['status'] === 'pending'; });
                if (empty($pending_list)):
                ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No pending registered ISP workspace applications currently require review.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pending_list as $t): ?>
                        <tr>
                            <td>
                                <strong class="text-white d-block"><?php echo e($t['company_name']); ?></strong>
                                <small class="text-muted">Registered: <?php echo date('d M, Y H:i', strtotime($t['created_at'])); ?></small>
                            </td>
                            <td>
                                <code class="text-primary"><?php echo e($t['subdomain']); ?>.<?php echo e(get_platform_domain()); ?></code>
                            </td>
                            <td class="text-white"><?php echo e($t['phone']); ?></td>
                            <td><?php echo e($t['email']); ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#approveModal-<?php echo $t['id']; ?>"><i class="bi bi-check-circle me-1"></i>Approve</button>
                                    <a href="tenants.php?action=reject&id=<?php echo $t['id']; ?>" class="btn btn-dark-glass btn-sm text-danger border-danger border-opacity-10" onclick="return confirm('Are you sure you want to reject this request?')"><i class="bi bi-x-circle me-1"></i>Reject</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Active and Suspended Tenants Directory -->
<div class="glass-card p-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-building-check text-primary me-2"></i>Workspace Directory (Approved Platforms)
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>ISP Platform Company</th>
                    <th>Subdomain Address</th>
                    <th>Active Plan</th>
                    <th>Lease Dates</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php
                $approved_list = array_filter($tenants, function($t) { return $t['status'] !== 'pending'; });
                if (empty($approved_list)):
                ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No active or suspended tenant workspaces registered yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($approved_list as $t): 
                        $status = $t['status'];
                        $status_color = 'text-success';
                        if ($status === 'suspended') $status_color = 'text-warning';
                        elseif ($status === 'expired') $status_color = 'text-danger';
                        ?>
                        <tr>
                            <td>
                                <strong class="text-white d-block"><?php echo e($t['company_name']); ?></strong>
                                <small class="text-muted">Registered: <?php echo date('d M, Y', strtotime($t['created_at'])); ?></small>
                            </td>
                            <td>
                                <code class="text-primary"><?php echo e($t['subdomain']); ?>.<?php echo e(get_platform_domain()); ?></code>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary px-2.5 py-1" style="font-size: 0.72rem; border: 1px solid rgba(168,85,247,0.2);"><?php echo e($t['plan_name']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.7rem;">Fee: <?php echo format_currency($t['monthly_fee']); ?>/mo</small>
                            </td>
                            <td>
                                <span class="text-white d-block" style="font-size: 0.8rem;">Start: <?php echo format_date($t['subscription_start']); ?></span>
                                <small class="text-muted" style="font-size: 0.72rem;">End: <?php echo format_date($t['subscription_end']); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="badge text-uppercase px-2.5 py-1 <?php echo ($status === 'active') ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'; ?>" style="font-size: 0.7rem; background: rgba(255,255,255,0.02);">
                                    <?php echo e($status); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <?php if ($status === 'active'): ?>
                                        <a href="tenants.php?action=suspend&id=<?php echo $t['id']; ?>" class="btn btn-dark-glass btn-sm text-warning" title="Suspend Platform Workspace Access"><i class="bi bi-slash-circle"></i></a>
                                    <?php else: ?>
                                        <a href="tenants.php?action=activate&id=<?php echo $t['id']; ?>" class="btn btn-dark-glass btn-sm text-success" title="Reactivate Platform Workspace Access"><i class="bi bi-check-circle"></i></a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-dark-glass btn-sm text-info" data-bs-toggle="modal" data-bs-target="#resetTenantPasswordModal-<?php echo $t['id']; ?>" title="Reset Workspace Password"><i class="bi bi-key"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Render Modals outside of the table-responsive and nested grid contexts to prevent backdrop/rendering clipping bugs -->
<?php if (!empty($pending_list)): ?>
    <?php foreach ($pending_list as $t): ?>
        <!-- Plan Assignment Modal -->
        <div class="modal fade saas-approve-modal" id="approveModal-<?php echo $t['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                    <div class="modal-header border-bottom border-white border-opacity-5">
                        <h5 class="modal-title text-white">Approve Workspace Registration</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="tenants.php?action=approve&id=<?php echo $t['id']; ?>" method="POST">
                        <?php csrf_field(); ?>
                        <div class="modal-body p-4">
                            <p class="text-muted" style="font-size: 0.85rem;">Assign a SaaS subscription plan limits configuration package for <strong><?php echo e($t['company_name']); ?></strong>.</p>
                            <div class="mb-3">
                                <label class="form-label text-muted" style="font-size: 0.8rem;">SaaS Subscription Plan</label>
                                <select name="plan_id" class="form-select" required>
                                    <?php foreach ($plans_opt as $p): ?>
                                        <option value="<?php echo $p['id']; ?>"><?php echo e($p['name']); ?> (Monthly Fee: Rs. <?php echo e($p['monthly_fee']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer border-top border-white border-opacity-5">
                            <button type="submit" class="btn btn-primary px-4">Activate Workspace</button>
                            <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($approved_list)): ?>
    <?php foreach ($approved_list as $t): ?>
        <!-- Reset Tenant Password Modal -->
        <div class="modal fade tenant-reset-modal" id="resetTenantPasswordModal-<?php echo $t['id']; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                    <div class="modal-header border-bottom border-white border-opacity-5">
                        <h5 class="modal-title text-white"><i class="bi bi-key me-2 text-info"></i>Reset Workspace Password</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="tenants.php" method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="reset_tenant_password" value="1">
                        <input type="hidden" name="tenant_id" value="<?php echo $t['id']; ?>">
                        
                        <div class="modal-body p-4">
                            <p class="text-muted" style="font-size: 0.85rem;">Set a new administrative access password for <strong><?php echo e($t['company_name']); ?></strong> (Workspace subdomain: <code><?php echo e($t['subdomain']); ?></code>).</p>
                            <div class="mb-3">
                                <label class="form-label text-muted" style="font-size: 0.8rem;">New Password</label>
                                <input type="password" name="new_password" class="form-control text-white" placeholder="Minimum 6 characters" required minlength="6">
                            </div>
                        </div>
                        <div class="modal-footer border-top border-white border-opacity-5">
                            <button type="submit" class="btn btn-info px-4 text-white" style="background: var(--info) !important; border-color: var(--info) !important;">Update Password</button>
                            <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
// Fix: Move modals to body level so they aren't clipped by the sidebar/content offset layout.
// This is the standard Bootstrap pattern for modals rendered inside offset wrappers.
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.saas-approve-modal, .tenant-reset-modal').forEach(function(modal) {
        document.body.appendChild(modal);
    });
});
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
