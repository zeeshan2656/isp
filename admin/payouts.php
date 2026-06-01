<?php
/**
 * NetPulse SaaS Platform - Centralized Withdrawal Payouts Processing Desk
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
$request_id = (int)($_GET['id'] ?? 0);

if ($request_id > 0 && ($action === 'approve' || $action === 'reject')) {
    try {
        $pdo->beginTransaction();
        
        // Fetch request details
        $stmt = $pdo->prepare("SELECT * FROM withdrawal_requests WHERE id = ? LIMIT 1");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        
        if ($req && $req['status'] === 'pending') {
            $new_status = ($action === 'approve') ? 'approved' : 'rejected';
            
            // Update withdrawal request
            $stmt = $pdo->prepare("UPDATE withdrawal_requests SET status = ?, processed_at = NOW(), processed_by = 1 WHERE id = ?");
            $stmt->execute([$new_status, $request_id]);
            
            // Update corresponding referral transaction status
            $stmt = $pdo->prepare("UPDATE referral_transactions SET status = ? WHERE transaction_type = 'withdrawal' AND reference_id = ?");
            $stmt->execute([$new_status, $request_id]);
            
            if ($action === 'approve') {
                log_audit_activity($pdo, 1, 'payout', $request_id, "Approved Rs. {$req['amount']} payout to {$req['requester_type']} ID: {$req['requester_id']}. Awaiting physical disbursement.");
                set_session_alert("Payout request approved. Please disburse the payment and mark as 'Completed'.", "success");
            } else {
                // Rejection: refund the money to their wallet
                if ($req['requester_type'] === 'affiliate') {
                    $stmt = $pdo->prepare("UPDATE affiliates SET wallet_balance = wallet_balance + ? WHERE id = ?");
                    $stmt->execute([$req['amount'], $req['requester_id']]);
                } elseif ($req['requester_type'] === 'tenant') {
                    $stmt = $pdo->prepare("UPDATE tenants SET referral_wallet = referral_wallet + ? WHERE id = ?");
                    $stmt->execute([$req['amount'], $req['requester_id']]);
                } elseif ($req['requester_type'] === 'customer') {
                    $stmt = $pdo->prepare("UPDATE customers SET referral_wallet = referral_wallet + ? WHERE id = ?");
                    $stmt->execute([$req['amount'], $req['requester_id']]);
                }
                
                // Add refund transaction logs to ledger
                $refund_notes = "Refunded from rejected payout slip #$request_id";
                $stmt = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES (?, ?, 'commission_credit', ?, ?, 'approved', ?)");
                $stmt->execute([$req['requester_type'], $req['requester_id'], $req['amount'], $request_id, $refund_notes]);
                
                log_audit_activity($pdo, 1, 'payout', $request_id, "Rejected Rs. {$req['amount']} payout request to {$req['requester_type']} ID: {$req['requester_id']}. Funds refunded to wallet.");
                set_session_alert("Payout request rejected. Wallet funds have been successfully refunded.", "success");
            }
            
            $pdo->commit();
        } else {
            $pdo->rollBack();
            set_session_alert("Invalid or already processed payout request.", "error");
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_session_alert("Payout operations failure: " . $e->getMessage(), "error");
    }
    header("Location: payouts.php");
    exit;
}

// ACTION: Mark as Completed (after physical payment disbursement)
if ($request_id > 0 && $action === 'complete') {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM withdrawal_requests WHERE id = ? AND status = 'approved' LIMIT 1");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        
        if ($req) {
            // Update status to completed
            $stmt = $pdo->prepare("UPDATE withdrawal_requests SET status = 'completed', processed_at = NOW() WHERE id = ?");
            $stmt->execute([$request_id]);
            
            // Update referral transaction status
            $stmt = $pdo->prepare("UPDATE referral_transactions SET status = 'approved' WHERE transaction_type = 'withdrawal' AND reference_id = ?");
            $stmt->execute([$request_id]);
            
            // Credit lifetime earnings
            if ($req['requester_type'] === 'affiliate') {
                $stmt = $pdo->prepare("UPDATE affiliates SET lifetime_earnings = lifetime_earnings + ? WHERE id = ?");
                $stmt->execute([$req['amount'], $req['requester_id']]);
            } elseif ($req['requester_type'] === 'tenant') {
                $stmt = $pdo->prepare("UPDATE tenants SET lifetime_referral_earnings = lifetime_referral_earnings + ? WHERE id = ?");
                $stmt->execute([$req['amount'], $req['requester_id']]);
            } elseif ($req['requester_type'] === 'customer') {
                $stmt = $pdo->prepare("UPDATE customers SET lifetime_referral_earnings = lifetime_referral_earnings + ? WHERE id = ?");
                $stmt->execute([$req['amount'], $req['requester_id']]);
            }
            
            $pdo->commit();
            log_audit_activity($pdo, 1, 'payout', $request_id, "Completed disbursement of Rs. {$req['amount']} to {$req['requester_type']} ID: {$req['requester_id']}.");
            set_session_alert("Payout marked as completed and disbursed successfully.", "success");
        } else {
            $pdo->rollBack();
            set_session_alert("Invalid request or not yet approved.", "error");
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_session_alert("Completion operation failed: " . $e->getMessage(), "error");
    }
    header("Location: payouts.php");
    exit;
}

// Load pending and recent payout history
$pending_payouts = [];
$completed_payouts = [];

try {
    // 1. Fetch pending
    $stmt = $pdo->query("SELECT w.*, 
           COALESCE(a.name, t.company_name, c.name) as requester_name,
           COALESCE(a.email, t.email, c.email) as requester_email
        FROM withdrawal_requests w
        LEFT JOIN affiliates a ON w.requester_type = 'affiliate' AND w.requester_id = a.id
        LEFT JOIN tenants t ON w.requester_type = 'tenant' AND w.requester_id = t.id
        LEFT JOIN customers c ON w.requester_type = 'customer' AND w.requester_id = c.id
        WHERE w.status = 'pending'
        ORDER BY w.id DESC");
    $pending_payouts = $stmt->fetchAll();
    
    // 2. Fetch approved (awaiting disbursement)
    $stmt = $pdo->query("SELECT w.*, 
           COALESCE(a.name, t.company_name, c.name) as requester_name,
           COALESCE(a.email, t.email, c.email) as requester_email
        FROM withdrawal_requests w
        LEFT JOIN affiliates a ON w.requester_type = 'affiliate' AND w.requester_id = a.id
        LEFT JOIN tenants t ON w.requester_type = 'tenant' AND w.requester_id = t.id
        LEFT JOIN customers c ON w.requester_type = 'customer' AND w.requester_id = c.id
        WHERE w.status = 'approved'
        ORDER BY w.processed_at DESC");
    $approved_payouts = $stmt->fetchAll();
    
    // 3. Fetch completed/rejected (history)
    $stmt = $pdo->query("SELECT w.*, 
           COALESCE(a.name, t.company_name, c.name) as requester_name,
           COALESCE(a.email, t.email, c.email) as requester_email
        FROM withdrawal_requests w
        LEFT JOIN affiliates a ON w.requester_type = 'affiliate' AND w.requester_id = a.id
        LEFT JOIN tenants t ON w.requester_type = 'tenant' AND w.requester_id = t.id
        LEFT JOIN customers c ON w.requester_type = 'customer' AND w.requester_id = c.id
        WHERE w.status IN ('completed', 'rejected')
        ORDER BY w.processed_at DESC LIMIT 50");
    $completed_payouts = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Payouts lists lookup failure: " . $e->getMessage());
}

require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-cash-stack text-primary me-2"></i>Payouts & Cashouts Desk</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Approve and process cash withdrawal slips from Public Affiliates, SaaS Tenants (ISPs), or customer referral wallets.</p>
    </div>
</div>

<!-- Pending Slips Panel -->
<div class="glass-card p-4 mb-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-clock-history text-warning me-2"></i>Pending Withdrawal Slips (Awaiting Cash Disbursement)
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>Requester Profile</th>
                    <th>Requester Role</th>
                    <th>Disbursement Method</th>
                    <th>Payment Gateway Credentials</th>
                    <th class="text-end">Cash Amount</th>
                    <th class="text-end">Settlement Controls</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($pending_payouts)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No pending cash withdrawal requests currently require processing. Excellent!</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pending_payouts as $p): ?>
                        <tr>
                            <td>
                                <strong class="text-white d-block"><?php echo e($p['requester_name'] ?? 'Unknown Requester'); ?></strong>
                                <span class="text-muted" style="font-size: 0.75rem;"><?php echo e($p['requester_email'] ?? 'No email logged'); ?></span>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-0.5 text-uppercase" style="font-size: 0.7rem;">
                                    <?php echo e($p['requester_type']); ?>
                                </span>
                            </td>
                            <td class="text-white"><?php echo e($p['payment_method']); ?></td>
                            <td>
                                <code class="text-light" style="font-size: 0.78rem; word-break: break-all;"><?php echo e($p['payment_details']); ?></code>
                            </td>
                            <td class="text-end fw-bold text-white fs-6">
                                <?php echo format_currency($p['amount']); ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1.5">
                                    <a href="payouts.php?action=approve&id=<?php echo $p['id']; ?>" class="btn btn-success btn-sm px-2.5" onclick="return confirm('Confirm disbursement: Have you sent the payment of <?php echo format_currency($p['amount']); ?> to this recipient?')">
                                        <i class="bi bi-check-circle me-1"></i>Approve & Pay
                                    </a>
                                    <a href="payouts.php?action=reject&id=<?php echo $p['id']; ?>" class="btn btn-dark-glass btn-sm text-danger border-danger border-opacity-15" onclick="return confirm('Reject request and return Rs. <?php echo e($p['amount']); ?> back to the wallet?')">
                                        <i class="bi bi-x-circle me-1"></i>Reject
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Approved Payouts (Awaiting Disbursement) -->
<div class="glass-card p-4 mb-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-send-check text-info me-2"></i>Approved — Awaiting Physical Disbursement
        <?php if (!empty($approved_payouts)): ?>
            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 ms-2" style="font-size: 0.72rem;"><?php echo count($approved_payouts); ?> Pending</span>
        <?php endif; ?>
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>Requester Profile</th>
                    <th>Requester Role</th>
                    <th>Gateway Details</th>
                    <th class="text-end">Cash Amount</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($approved_payouts)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">No approved payouts pending disbursement.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($approved_payouts as $p): ?>
                        <tr>
                            <td>
                                <strong class="text-white d-block"><?php echo e($p['requester_name'] ?? 'Unknown'); ?></strong>
                                <small class="text-muted" style="font-size: 0.75rem;"><?php echo e($p['requester_email'] ?? ''); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-0.5 text-uppercase" style="font-size: 0.7rem;">
                                    <?php echo e($p['requester_type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-white d-block" style="font-size: 0.82rem;"><?php echo e($p['payment_method']); ?></span>
                                <code class="text-light" style="font-size: 0.75rem; word-break: break-all;"><?php echo e($p['payment_details']); ?></code>
                            </td>
                            <td class="text-end fw-bold text-white fs-6"><?php echo format_currency($p['amount']); ?></td>
                            <td class="text-end">
                                <a href="payouts.php?action=complete&id=<?php echo $p['id']; ?>" class="btn btn-success btn-sm px-3" onclick="return confirm('Confirm: You have physically sent <?php echo format_currency($p['amount']); ?> to this recipient?')">
                                    <i class="bi bi-check2-all me-1"></i>Mark Completed
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Completed/Rejected Payout History Desk -->
<div class="glass-card p-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-check2-all text-success me-2"></i>Settled & Rejected Withdrawal Logs
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>Request ID</th>
                    <th>Requester Details</th>
                    <th>Role</th>
                    <th>Gateway Method</th>
                    <th class="text-end">Amount</th>
                    <th class="text-center">Processing Status</th>
                    <th class="text-center">Handled Date</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($completed_payouts)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No payout requests have been settled or rejected on the platform yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($completed_payouts as $p): 
                        $status = $p['status'];
                        $badge = '';
                        if ($status === 'completed') {
                            $badge = '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-0.5">Completed & Disbursed</span>';
                        } else {
                            $badge = '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2.5 py-0.5">Rejected & Refunded</span>';
                        }
                        ?>
                        <tr>
                            <td><strong class="text-white">#<?php echo $p['id']; ?></strong></td>
                            <td>
                                <strong class="text-white d-block"><?php echo e($p['requester_name'] ?? 'Unknown'); ?></strong>
                                <small class="text-muted"><?php echo e($p['requester_email']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-dark text-muted px-2 py-0.5 text-uppercase" style="font-size: 0.68rem; border: 1px solid rgba(255,255,255,0.05);">
                                    <?php echo e($p['requester_type']); ?>
                                </span>
                            </td>
                            <td class="text-muted" style="font-size: 0.8rem;"><?php echo e($p['payment_method']); ?></td>
                            <td class="text-end fw-bold text-white"><?php echo format_currency($p['amount']); ?></td>
                            <td class="text-center"><?php echo $badge; ?></td>
                            <td class="text-center text-muted" style="font-size: 0.75rem;">
                                <?php echo $p['processed_at'] ? date('d M, Y H:i', strtotime($p['processed_at'])) : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
