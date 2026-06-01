<?php
/**
 * NetPulse SaaS Platform - Credit Adjustment Requests Approval Desk
 * Super Admin reviews ISP requests to apply wallet credits to pending invoices.
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

// ACTION: Approve credit adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_credit_adjustment'])) {
    verify_csrf_token();
    
    $req_id = (int)($_POST['request_id'] ?? 0);
    $approved_amount = (double)($_POST['approved_amount'] ?? 0.00);
    
    if ($req_id <= 0) $errors[] = "Invalid request ID.";
    if ($approved_amount <= 0) $errors[] = "Approved credit amount must be greater than Rs. 0.";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Fetch the credit adjustment request
            $stmt = $pdo->prepare("SELECT cr.*, t.company_name, t.referral_wallet, i.invoice_number, i.amount as invoice_amount, i.paid_amount, i.remaining_amount, i.payment_status
                FROM credit_adjustment_requests cr
                JOIN tenants t ON cr.tenant_id = t.id
                JOIN saas_invoices i ON cr.invoice_id = i.id
                WHERE cr.id = ? AND cr.status = 'pending' LIMIT 1");
            $stmt->execute([$req_id]);
            $req = $stmt->fetch();
            
            if ($req) {
                $remaining = (double)$req['remaining_amount'];
                
                // Cap the approved amount to the invoice remaining amount
                if ($approved_amount > $remaining) {
                    $approved_amount = $remaining;
                }
                
                // If admin approved less than requested, refund the difference to wallet
                $requested = (double)$req['requested_amount'];
                $refund_diff = $requested - $approved_amount;
                
                if ($refund_diff > 0) {
                    $stmt = $pdo->prepare("UPDATE tenants SET referral_wallet = referral_wallet + ? WHERE id = ?");
                    $stmt->execute([$refund_diff, $req['tenant_id']]);
                }
                
                // Apply the credit to the invoice
                $new_paid = (double)$req['paid_amount'] + $approved_amount;
                $new_remaining = (double)$req['invoice_amount'] - $new_paid;
                
                // Determine payment status
                $pay_status = 'pending';
                if ($new_remaining <= 0) {
                    $pay_status = 'paid';
                    $new_remaining = 0;
                } elseif ($new_paid > 0) {
                    $pay_status = 'partial';
                }
                
                $pay_date = ($pay_status === 'paid' || $pay_status === 'partial') ? date('Y-m-d H:i:s') : null;
                
                $stmt = $pdo->prepare("UPDATE saas_invoices SET paid_amount = ?, remaining_amount = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                $stmt->execute([$new_paid, $new_remaining, $pay_status, $pay_date, $req['invoice_id']]);
                
                // If fully paid, extend tenant subscription
                if ($pay_status === 'paid') {
                    $new_end = date('Y-m-d', strtotime('+30 days'));
                    $stmt = $pdo->prepare("UPDATE tenants SET subscription_end = ?, status = 'active' WHERE id = ?");
                    $stmt->execute([$new_end, $req['tenant_id']]);
                }
                
                // Update credit adjustment request
                $stmt = $pdo->prepare("UPDATE credit_adjustment_requests SET status = 'approved', approved_amount = ?, processed_by = 1, processed_at = NOW(), notes = ? WHERE id = ?");
                $stmt->execute([$approved_amount, "Approved by Super Admin", $req_id]);
                
                // Log to referral_transactions ledger
                $notes = "Credit adjustment approved: " . format_currency($approved_amount) . " applied to Invoice #" . $req['invoice_number'];
                $stmt = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('tenant', ?, 'invoice_deduction', ?, ?, 'approved', ?)");
                $stmt->execute([$req['tenant_id'], $approved_amount, $req['invoice_id'], $notes]);
                
                $pdo->commit();
                
                log_audit_activity($pdo, 1, 'credit_adjustment', $req_id, "Approved credit adjustment of " . format_currency($approved_amount) . " for tenant: " . $req['company_name'] . " on invoice: " . $req['invoice_number']);
                set_session_alert("Credit adjustment of " . format_currency($approved_amount) . " approved and applied to Invoice #{$req['invoice_number']}.", "success");
            } else {
                $pdo->rollBack();
                set_session_alert("Invalid or already processed credit adjustment request.", "error");
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            set_session_alert("Credit adjustment processing failed: " . $e->getMessage(), "error");
        }
        header("Location: credit_adjustments.php");
        exit;
    }
}

// ACTION: Reject credit adjustment
if ($request_id > 0 && $action === 'reject') {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT cr.*, t.company_name FROM credit_adjustment_requests cr JOIN tenants t ON cr.tenant_id = t.id WHERE cr.id = ? AND cr.status = 'pending' LIMIT 1");
        $stmt->execute([$request_id]);
        $req = $stmt->fetch();
        
        if ($req) {
            // Refund the locked amount back to tenant wallet
            $stmt = $pdo->prepare("UPDATE tenants SET referral_wallet = referral_wallet + ? WHERE id = ?");
            $stmt->execute([$req['requested_amount'], $req['tenant_id']]);
            
            // Update request status
            $stmt = $pdo->prepare("UPDATE credit_adjustment_requests SET status = 'rejected', approved_amount = 0.00, processed_by = 1, processed_at = NOW(), notes = 'Rejected by Super Admin' WHERE id = ?");
            $stmt->execute([$request_id]);
            
            // Log refund to ledger
            $notes = "Credit adjustment rejected. Rs. " . number_format($req['requested_amount'], 2) . " refunded to wallet.";
            $stmt = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('tenant', ?, 'commission_credit', ?, ?, 'approved', ?)");
            $stmt->execute([$req['tenant_id'], $req['requested_amount'], $request_id, $notes]);
            
            $pdo->commit();
            
            log_audit_activity($pdo, 1, 'credit_adjustment', $request_id, "Rejected credit adjustment of " . format_currency($req['requested_amount']) . " for tenant: " . $req['company_name'] . ". Funds refunded.");
            set_session_alert("Credit adjustment rejected. Rs. " . number_format($req['requested_amount'], 2) . " refunded to tenant wallet.", "success");
        } else {
            $pdo->rollBack();
            set_session_alert("Invalid or already processed credit adjustment request.", "error");
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        set_session_alert("Credit adjustment rejection failed: " . $e->getMessage(), "error");
    }
    header("Location: credit_adjustments.php");
    exit;
}

// Load pending and processed credit adjustment requests
$pending_requests = [];
$processed_requests = [];

try {
    $stmt = $pdo->query("SELECT cr.*, t.company_name, t.referral_wallet, i.invoice_number, i.amount as invoice_amount, i.remaining_amount, i.payment_status
        FROM credit_adjustment_requests cr
        JOIN tenants t ON cr.tenant_id = t.id
        JOIN saas_invoices i ON cr.invoice_id = i.id
        WHERE cr.status = 'pending'
        ORDER BY cr.id DESC");
    $pending_requests = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT cr.*, t.company_name, i.invoice_number, i.amount as invoice_amount
        FROM credit_adjustment_requests cr
        JOIN tenants t ON cr.tenant_id = t.id
        JOIN saas_invoices i ON cr.invoice_id = i.id
        WHERE cr.status != 'pending'
        ORDER BY cr.processed_at DESC LIMIT 50");
    $processed_requests = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Credit adjustments load error: " . $e->getMessage());
}

require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-credit-card-2-front text-primary me-2"></i>Credit Adjustment Requests</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Review and approve ISP tenant requests to apply their referral wallet credits toward outstanding SaaS subscription invoices.</p>
    </div>
</div>

<!-- Pending Requests Panel -->
<div class="glass-card p-4 mb-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-clock-history text-warning me-2"></i>Pending Credit Adjustment Requests
        <?php if (!empty($pending_requests)): ?>
            <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 ms-2" style="font-size: 0.72rem;"><?php echo count($pending_requests); ?> Pending</span>
        <?php endif; ?>
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>ISP Tenant</th>
                    <th>Invoice Details</th>
                    <th class="text-end">Invoice Amount</th>
                    <th class="text-end">Outstanding</th>
                    <th class="text-end">Requested Credit</th>
                    <th class="text-end">Wallet Balance</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($pending_requests)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-check2-all fs-3 d-block mb-2 opacity-50"></i>
                            No pending credit adjustment requests. All clear!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pending_requests as $r): ?>
                        <tr>
                            <td>
                                <strong class="text-white d-block"><?php echo e($r['company_name']); ?></strong>
                                <small class="text-muted" style="font-size: 0.72rem;">Tenant ID: <?php echo $r['tenant_id']; ?></small>
                            </td>
                            <td>
                                <strong class="text-primary d-block" style="font-size: 0.82rem;"><?php echo e($r['invoice_number']); ?></strong>
                                <span class="badge text-uppercase px-1.5 py-0.5 <?php echo ($r['payment_status'] === 'paid') ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'; ?>" style="font-size: 0.65rem; background: rgba(255,255,255,0.02);">
                                    <?php echo e($r['payment_status']); ?>
                                </span>
                            </td>
                            <td class="text-end text-white"><?php echo format_currency($r['invoice_amount']); ?></td>
                            <td class="text-end text-danger fw-bold"><?php echo format_currency($r['remaining_amount']); ?></td>
                            <td class="text-end">
                                <strong class="text-warning fs-6"><?php echo format_currency($r['requested_amount']); ?></strong>
                            </td>
                            <td class="text-end text-muted"><?php echo format_currency($r['referral_wallet']); ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1.5">
                                    <button type="button" class="btn btn-success btn-sm px-2.5" data-bs-toggle="modal" data-bs-target="#approveModal-<?php echo $r['id']; ?>">
                                        <i class="bi bi-check-circle me-1"></i>Approve
                                    </button>
                                    <a href="credit_adjustments.php?action=reject&id=<?php echo $r['id']; ?>" class="btn btn-dark-glass btn-sm text-danger border-danger border-opacity-15" onclick="return confirm('Reject this credit request and refund Rs. <?php echo number_format($r['requested_amount'], 2); ?> back to tenant wallet?')">
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

<!-- Processed History Panel -->
<div class="glass-card p-4">
    <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
        <i class="bi bi-check2-all text-success me-2"></i>Recently Processed Credit Adjustments
    </h5>
    
    <div class="table-responsive">
        <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
            <thead>
                <tr class="text-muted" style="font-size: 0.75rem;">
                    <th>Request ID</th>
                    <th>ISP Tenant</th>
                    <th>Invoice</th>
                    <th class="text-end">Requested</th>
                    <th class="text-end">Approved</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Processed On</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.85rem;">
                <?php if (empty($processed_requests)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No credit adjustments have been processed yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($processed_requests as $r):
                        $status = $r['status'];
                        $badge = ($status === 'approved')
                            ? '<span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-0.5">Approved & Applied</span>'
                            : '<span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2.5 py-0.5">Rejected & Refunded</span>';
                        ?>
                        <tr>
                            <td><strong class="text-white">#<?php echo $r['id']; ?></strong></td>
                            <td><strong class="text-white"><?php echo e($r['company_name']); ?></strong></td>
                            <td>
                                <span class="text-primary" style="font-size: 0.82rem;"><?php echo e($r['invoice_number']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.7rem;">Total: <?php echo format_currency($r['invoice_amount']); ?></small>
                            </td>
                            <td class="text-end text-muted"><?php echo format_currency($r['requested_amount']); ?></td>
                            <td class="text-end fw-bold <?php echo ($status === 'approved') ? 'text-success' : 'text-danger'; ?>"><?php echo format_currency($r['approved_amount'] ?? 0); ?></td>
                            <td class="text-center"><?php echo $badge; ?></td>
                            <td class="text-center text-muted" style="font-size: 0.75rem;">
                                <?php echo $r['processed_at'] ? date('d M, Y H:i', strtotime($r['processed_at'])) : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Approval Modals (rendered outside table-responsive to avoid clipping) -->
<?php if (!empty($pending_requests)): ?>
    <?php foreach ($pending_requests as $r): ?>
        <div class="modal fade" id="approveModal-<?php echo $r['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px); z-index: 1060;">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                    <div class="modal-header border-bottom border-white border-opacity-5">
                        <h5 class="modal-title text-white"><i class="bi bi-credit-card-2-front text-primary me-2"></i>Approve Credit Adjustment</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <form action="credit_adjustments.php" method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="request_id" value="<?php echo $r['id']; ?>">
                        <input type="hidden" name="approve_credit_adjustment" value="1">
                        
                        <div class="modal-body p-4 d-flex flex-column gap-3">
                            <div>
                                <span class="text-muted" style="font-size: 0.8rem;">ISP Tenant Workspace</span>
                                <strong class="text-white d-block"><?php echo e($r['company_name']); ?></strong>
                            </div>
                            <div class="row text-center bg-dark rounded-3 p-3 my-2" style="border: 1px solid var(--border-color);">
                                <div class="col-4">
                                    <span class="text-muted d-block" style="font-size: 0.72rem;">Invoice Total</span>
                                    <strong class="text-white"><?php echo format_currency($r['invoice_amount']); ?></strong>
                                </div>
                                <div class="col-4 border-start border-white border-opacity-5">
                                    <span class="text-muted d-block" style="font-size: 0.72rem;">Outstanding</span>
                                    <strong class="text-danger"><?php echo format_currency($r['remaining_amount']); ?></strong>
                                </div>
                                <div class="col-4 border-start border-white border-opacity-5">
                                    <span class="text-muted d-block" style="font-size: 0.72rem;">Requested</span>
                                    <strong class="text-warning"><?php echo format_currency($r['requested_amount']); ?></strong>
                                </div>
                            </div>
                            <div>
                                <label class="form-label text-muted" style="font-size: 0.8rem;">Approved Credit Amount (Rs.)</label>
                                <input type="number" name="approved_amount" class="form-control" value="<?php echo $r['requested_amount']; ?>" required min="0.01" max="<?php echo $r['remaining_amount']; ?>" step="0.01">
                                <small class="text-muted" style="font-size: 0.7rem;">You may modify the approved amount. Max: <?php echo format_currency($r['remaining_amount']); ?> (invoice outstanding).</small>
                            </div>
                        </div>
                        <div class="modal-footer border-top border-white border-opacity-5">
                            <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-circle me-1.5"></i>Approve & Apply Credit</button>
                            <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
