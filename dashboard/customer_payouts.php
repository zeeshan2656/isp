<?php
/**
 * Tenant Dashboard - Customer Referral Payout Settle Desk
 */
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce guard
require_tenant_login();

$tenant_id = $_SESSION['tenant_id'];

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$request_id = (int)($_GET['id'] ?? 0);

// Actions Processing
if ($request_id > 0 && ($action === 'approve' || $action === 'reject')) {
    try {
        $pdo->beginTransaction();
        
        // Fetch request and ensure it belongs to a customer of this tenant
        $stmt = $pdo->prepare("SELECT w.*, c.tenant_id, c.id as customer_id 
            FROM withdrawal_requests w
            INNER JOIN customers c ON w.requester_type = 'customer' AND w.requester_id = c.id
            WHERE w.id = ? AND c.tenant_id = ? LIMIT 1");
        $stmt->execute([$request_id, $tenant_id]);
        $req = $stmt->fetch();
        
        if ($req && $req['status'] === 'pending') {
            $new_status = ($action === 'approve') ? 'approved' : 'rejected';
            
            // 1. Update withdrawal request status
            $stmt = $pdo->prepare("UPDATE withdrawal_requests SET status = ?, processed_at = NOW(), processed_by = ? WHERE id = ?");
            $stmt->execute([$new_status, $tenant_id, $request_id]);
            
            // 2. Update corresponding ledger referral transaction status
            $stmt = $pdo->prepare("UPDATE referral_transactions SET status = ? WHERE referrer_type = 'customer' AND transaction_type = 'withdrawal' AND reference_id = ?");
            $stmt->execute([$new_status, $request_id]);
            
            if ($action === 'approve') {
                // Settle and increment lifetime customer referral earnings
                $stmt = $pdo->prepare("UPDATE customers SET lifetime_referral_earnings = lifetime_referral_earnings + ? WHERE id = ?");
                $stmt->execute([$req['amount'], $req['customer_id']]);
                
                log_audit_activity($pdo, $tenant_id, 'customer_payout', $request_id, "ISP approved Rs. {$req['amount']} payout to customer ID: {$req['customer_id']}.");
                set_session_alert("Customer cashout request marked as paid and settled successfully.", "success");
            } else {
                // Rejection: refund money to customer referral wallet
                $stmt = $pdo->prepare("UPDATE customers SET referral_wallet = referral_wallet + ? WHERE id = ?");
                $stmt->execute([$req['amount'], $req['customer_id']]);
                
                // Log refund entry in referral transactions ledger
                $refund_notes = "Refunded from rejected cashout slip #$request_id";
                $stmt = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('customer', ?, 'commission_credit', ?, ?, 'approved', ?)");
                $stmt->execute([$req['customer_id'], $req['amount'], $request_id, $refund_notes]);
                
                log_audit_activity($pdo, $tenant_id, 'customer_payout', $request_id, "ISP rejected Rs. {$req['amount']} payout request to customer ID: {$req['customer_id']}. Refunded to wallet.");
                set_session_alert("Customer cashout request rejected and credits returned to their reward wallet.", "success");
            }
            
            $pdo->commit();
        } else {
            $pdo->rollBack();
            set_session_alert("Invalid or already processed payout request.", "error");
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_session_alert("Customer payout operation failed: " . $e->getMessage(), "error");
    }
    header("Location: customer_payouts.php");
    exit;
}

// Load pending and recent customer payout lists for this tenant
$pending_list = [];
$completed_list = [];

try {
    // Pending
    $stmt = $pdo->prepare("SELECT w.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone
        FROM withdrawal_requests w
        INNER JOIN customers c ON w.requester_type = 'customer' AND w.requester_id = c.id
        WHERE c.tenant_id = ? AND w.status = 'pending'
        ORDER BY w.id DESC");
    $stmt->execute([$tenant_id]);
    $pending_list = $stmt->fetchAll();
    
    // Processed
    $stmt = $pdo->prepare("SELECT w.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone
        FROM withdrawal_requests w
        INNER JOIN customers c ON w.requester_type = 'customer' AND w.requester_id = c.id
        WHERE c.tenant_id = ? AND w.status != 'pending'
        ORDER BY w.processed_at DESC LIMIT 50");
    $stmt->execute([$tenant_id]);
    $completed_list = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Customer payouts fetch failure: " . $e->getMessage());
}
// Include header layout now that all action redirects are completed
require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-cash-coin text-primary me-2"></i>Customer Cashout Center</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Review, authorize, and disburse cash rewards requested by your referred subscribers from their available wallets.</p>
    </div>
</div>

<!-- Pending Slips Panel -->
<div class="glass-card p-4 mb-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-clock text-warning me-2"></i>Awaiting Cash Disbursement (Pending Customer Slips)
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>Subscriber Name</th>
                    <th>Contact Phone</th>
                    <th>Payment Method</th>
                    <th>Payout Gateway Details</th>
                    <th class="text-end">Requested Amount</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($pending_list)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No pending customer cashouts require processing.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pending_list as $p): ?>
                        <tr>
                            <td>
                                <strong class="text-white d-block"><?php echo e($p['customer_name']); ?></strong>
                                <span class="text-muted" style="font-size: 0.75rem;"><?php echo e($p['customer_email']); ?></span>
                            </td>
                            <td class="text-white"><?php echo e($p['customer_phone']); ?></td>
                            <td class="text-primary fw-bold"><?php echo e($p['payment_method']); ?></td>
                            <td>
                                <code class="text-light" style="font-size: 0.78rem; word-break: break-all;"><?php echo e($p['payment_details']); ?></code>
                            </td>
                            <td class="text-end fw-bold text-white fs-6">
                                <?php echo format_currency($p['amount']); ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1.5">
                                    <a href="customer_payouts.php?action=approve&id=<?php echo $p['id']; ?>" class="btn btn-success btn-sm px-2.5" onclick="return confirm('Verify that you have transferred <?php echo format_currency($p['amount']); ?> to <?php echo e($p['customer_name']); ?> before approving.')">
                                        <i class="bi bi-check-circle me-1"></i>Approve & Pay
                                    </a>
                                    <a href="customer_payouts.php?action=reject&id=<?php echo $p['id']; ?>" class="btn btn-dark-glass btn-sm text-danger border-danger border-opacity-15" onclick="return confirm('Are you sure you want to reject this request and refund the credits?')">
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

<!-- Processed/Completed Customer Payout History -->
<div class="glass-card p-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-check2-all text-success me-2"></i>Recently Settle Logs
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>Slip ID</th>
                    <th>Customer Name</th>
                    <th>Gateway Method</th>
                    <th class="text-end">Amount</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Processed On</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($completed_list)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">No customer payout slips have been settled yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($completed_list as $p): 
                        $status = $p['status'];
                        $badge = ($status === 'approved') ? '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-0.5">Paid & Settled</span>' : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2.5 py-0.5">Rejected & Refunded</span>';
                        ?>
                        <tr>
                            <td><strong class="text-white">#<?php echo $p['id']; ?></strong></td>
                            <td>
                                <strong class="text-white d-block"><?php echo e($p['customer_name']); ?></strong>
                                <small class="text-muted"><?php echo e($p['customer_email']); ?></small>
                            </td>
                            <td class="text-muted" style="font-size: 0.8rem;"><?php echo e($p['payment_method']); ?></td>
                            <td class="text-end fw-bold text-white"><?php echo format_currency($p['amount']); ?></td>
                            <td class="text-center"><?php echo $badge; ?></td>
                            <td class="text-center text-muted" style="font-size: 0.75rem;">
                                <?php echo date('d M, Y H:i', strtotime($p['processed_at'])); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
