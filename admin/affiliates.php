<?php
/**
 * NetPulse SaaS Platform - Public Affiliate Registry Manager
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

// Actions Processing
if ($edit_id > 0) {
    if ($action === 'approve') {
        try {
            $stmt = $pdo->prepare("UPDATE affiliates SET status = 'active' WHERE id = ?");
            $stmt->execute([$edit_id]);
            
            log_audit_activity($pdo, 1, 'affiliate', $edit_id, "Approved public affiliate ID: $edit_id.");
            set_session_alert("Affiliate account activated successfully.", "success");
        } catch (PDOException $e) {
            set_session_alert("Approve action failed: " . $e->getMessage(), "error");
        }
        header("Location: affiliates.php");
        exit;
    }
    
    if ($action === 'suspend') {
        try {
            $stmt = $pdo->prepare("UPDATE affiliates SET status = 'suspended' WHERE id = ?");
            $stmt->execute([$edit_id]);
            
            log_audit_activity($pdo, 1, 'affiliate', $edit_id, "Suspended public affiliate ID: $edit_id.");
            set_session_alert("Affiliate account suspended successfully.", "success");
        } catch (PDOException $e) {
            set_session_alert("Suspend action failed: " . $e->getMessage(), "error");
        }
        header("Location: affiliates.php");
        exit;
    }
    
    if ($action === 'delete') {
        try {
            // Delete affiliate only if pending/suspended with no transactions
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral_transactions WHERE referrer_type = 'affiliate' AND referrer_id = ?");
            $stmt->execute([$edit_id]);
            if ($stmt->fetchColumn() > 0) {
                set_session_alert("Cannot delete affiliate with active transaction history. Suspend them instead.", "error");
            } else {
                $stmt = $pdo->prepare("DELETE FROM affiliates WHERE id = ?");
                $stmt->execute([$edit_id]);
                log_audit_activity($pdo, 1, 'affiliate', $edit_id, "Deleted public affiliate registration request ID: $edit_id.");
                set_session_alert("Affiliate registration request removed.", "success");
            }
        } catch (PDOException $e) {
            set_session_alert("Delete action failed: " . $e->getMessage(), "error");
        }
        header("Location: affiliates.php");
        exit;
    }
}

// Load Affiliates List
$affiliates = [];
try {
    $stmt = $pdo->query("SELECT a.*, 
        (SELECT COUNT(*) FROM tenants t WHERE t.referred_by_type = 'affiliate' AND t.referred_by_id = a.id) as referred_tenants_count
        FROM affiliates a 
        ORDER BY a.status ASC, a.created_at DESC");
    $affiliates = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Affiliates registry fetch error: " . $e->getMessage());
}

require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-people text-primary me-2"></i>Affiliate Registry Desk</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Manage external growth partners, review registration applications, check referred tenant counts, and track wallet balances.</p>
    </div>
</div>

<div class="glass-card p-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-person-lines-fill text-primary me-2"></i>Registered Affiliates & Growth Partners
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>Partner Details</th>
                    <th>Referral Code & Link</th>
                    <th>Referred Tenants</th>
                    <th>Wallet Balance</th>
                    <th>Lifetime Earnings</th>
                    <th class="text-center">Status</th>
                    <th class="text-end">Actions Workflow</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($affiliates)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No affiliates are currently registered in the database.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($affiliates as $a): 
                        $status = $a['status'];
                        $status_badge = '';
                        if ($status === 'pending') {
                            $status_badge = '<span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-20 px-2 py-1">Pending Review</span>';
                        } elseif ($status === 'active') {
                            $status_badge = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-20 px-2 py-1">Active</span>';
                        } else {
                            $status_badge = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-20 px-2 py-1">Suspended</span>';
                        }
                        
                        // Construct the full registration URL
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                        $host = $_SERVER['HTTP_HOST'];
                        $ref_link = $protocol . $host . "/ISP/register.php?ref=" . e($a['referral_code']);
                        ?>
                        <tr>
                            <td>
                                <strong class="text-white d-block"><?php echo e($a['name']); ?></strong>
                                <span class="text-muted d-block" style="font-size: 0.75rem;"><?php echo e($a['email']); ?></span>
                                <small class="text-muted" style="font-size: 0.7rem;">Registered: <?php echo format_date($a['created_at']); ?></small>
                            </td>
                            <td>
                                <code class="text-primary d-block mb-1"><?php echo e($a['referral_code']); ?></code>
                                <div class="d-flex align-items-center gap-1.5">
                                    <input type="text" class="form-control form-control-sm bg-dark border-0 text-muted" value="<?php echo $ref_link; ?>" readonly id="lnk-<?php echo $a['id']; ?>" style="font-size: 0.72rem; width: 200px;">
                                    <button class="btn btn-dark-glass btn-sm px-2 text-primary" onclick="copyLink(<?php echo $a['id']; ?>)" title="Copy Link"><i class="bi bi-files"></i></button>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary bg-opacity-15 text-primary fs-7 border border-primary border-opacity-25 px-2.5 py-1">
                                    <?php echo (int)$a['referred_tenants_count']; ?>
                                </span>
                            </td>
                            <td class="text-white fw-bold"><?php echo format_currency($a['wallet_balance']); ?></td>
                            <td><?php echo format_currency($a['lifetime_earnings']); ?></td>
                            <td class="text-center"><?php echo $status_badge; ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <?php if ($status === 'pending'): ?>
                                        <a href="affiliates.php?action=approve&id=<?php echo $a['id']; ?>" class="btn btn-success btn-sm px-2.5" title="Approve Affiliate Application"><i class="bi bi-check-circle me-1"></i>Approve</a>
                                        <a href="affiliates.php?action=delete&id=<?php echo $a['id']; ?>" class="btn btn-dark-glass btn-sm text-danger border-danger border-opacity-10" onclick="return confirm('Are you sure you want to reject and delete this request?')" title="Reject Application"><i class="bi bi-x-circle"></i></a>
                                    <?php elseif ($status === 'active'): ?>
                                        <a href="affiliates.php?action=suspend&id=<?php echo $a['id']; ?>" class="btn btn-dark-glass btn-sm text-warning" title="Suspend Partner Accounts"><i class="bi bi-slash-circle me-1"></i>Suspend</a>
                                    <?php else: ?>
                                        <a href="affiliates.php?action=approve&id=<?php echo $a['id']; ?>" class="btn btn-dark-glass btn-sm text-success" title="Unsuspend/Activate Partner Accounts"><i class="bi bi-check-circle me-1"></i>Activate</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function copyLink(id) {
    var copyText = document.getElementById("lnk-" + id);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    
    alert("Copied Referral Link to Clipboard: " + copyText.value);
}
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
