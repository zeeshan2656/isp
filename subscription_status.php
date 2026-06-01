<?php
/**
 * NetPulse SaaS Platform - Subscription Lock Status Screen
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

// Enforce basic tenant session guard
if (!is_tenant_logged_in()) {
    header("Location: login.php");
    exit;
}

$tenant_id = $_SESSION['tenant_id'];

// Process payment proof submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_proof'])) {
    verify_csrf_token();
    
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $method = clean_input($_POST['payment_method'] ?? '');
    $trans_id = clean_input($_POST['transaction_id'] ?? '');
    $notes = clean_input($_POST['submission_notes'] ?? '');
    
    if ($invoice_id <= 0) $errors[] = "Please select a valid invoice.";
    if (empty($method)) $errors[] = "Payment method is required.";
    if (empty($trans_id)) $errors[] = "Transaction Reference / ID is required.";
    
    if (empty($errors)) {
        try {
            $stmt_inv = $pdo->prepare("SELECT amount, remaining_amount FROM saas_invoices WHERE id = ? AND tenant_id = ?");
            $stmt_inv->execute([$invoice_id, $tenant_id]);
            $inv_data = $stmt_inv->fetch();
            
            if ($inv_data) {
                $amount_submitted = (double)$inv_data['remaining_amount'];
                
                $pdo->beginTransaction();
                
                // Update the SaaS invoice status to pending review
                $stmt = $pdo->prepare("UPDATE saas_invoices SET payment_method = ?, transaction_id = ?, proof_submitted = 1, submission_notes = ?, submission_date = NOW() WHERE id = ? AND tenant_id = ? AND payment_status != 'paid'");
                $stmt->execute([$method, $trans_id, $notes, $invoice_id, $tenant_id]);
                
                // Insert into payment_submissions central table
                $stmt_sub = $pdo->prepare("INSERT INTO payment_submissions (tenant_id, payer_type, payer_id, invoice_type, invoice_id, payment_method, transaction_id, amount, submission_notes, status) 
                    VALUES (?, 'tenant', ?, 'saas_invoice', ?, ?, ?, ?, ?, 'pending')");
                $stmt_sub->execute([$tenant_id, $tenant_id, $invoice_id, $method, $trans_id, $amount_submitted, $notes]);
                
                $pdo->commit();
                
                // Log Audit
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Submitted payment proof for SaaS Invoice ID: $invoice_id (Trans: $trans_id)");
                
                set_session_alert("Payment proof submitted successfully! Super Admin will review and activate your subscription shortly.", "success");
            } else {
                $errors[] = "SaaS invoice not found or unauthorized access.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_session_alert("Database error during submission: " . $e->getMessage(), "error");
        }
        header("Location: subscription_status.php");
        exit;
    }
}

// Load dynamic lock state
$lock_state = check_tenant_lock_state($pdo, $tenant_id);

// Load tenant details
$company_name = '';
$subscription_end = '';
try {
    $stmt = $pdo->prepare("SELECT company_name, subscription_end FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $res = $stmt->fetch();
    if ($res) {
        $company_name = $res['company_name'];
        $subscription_end = $res['subscription_end'];
    }
} catch (PDOException $e) {
    error_log("Status screen lookup error: " . $e->getMessage());
}

// Redirect back if active
if ($lock_state === 'active') {
    header("Location: dashboard/index.php");
    exit;
}

// Load outstanding SaaS Invoices
$outstanding_invoices = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM saas_invoices WHERE tenant_id = ? AND payment_status != 'paid' ORDER BY id DESC");
    $stmt->execute([$tenant_id]);
    $outstanding_invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Outstanding SaaS invoices fetch fail: " . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="py-5" style="min-height: 85vh; background: radial-gradient(circle at top left, rgba(168, 85, 247, 0.04) 0%, rgba(8, 11, 17, 0) 70%); display: flex; flex-direction: column; justify-content: center;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-9 col-lg-7">
                
                <div class="text-center mb-4">
                    <div class="d-inline-flex p-3 bg-danger bg-opacity-10 rounded-4 text-danger border border-danger border-opacity-10 mb-3 animate-pulse">
                        <i class="bi bi-shield-lock-fill fs-2"></i>
                    </div>
                    <h3 class="fw-bold text-white font-outfit mb-1"><?php echo e($company_name); ?></h3>
                    <p class="text-muted" style="font-size: 0.88rem;"><?php echo e(get_platform_name()); ?> Workspace Guard Security</p>
                </div>

                <?php display_session_alerts(); ?>
                
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px; border-color: rgba(168, 85, 247, 0.15) !important;">
                    
                    <?php if ($lock_state === 'pending_approval'): ?>
                        <!-- 1. PENDING APPROVAL -->
                        <div class="text-center">
                            <h5 class="text-warning fw-bold mb-3 font-outfit"><i class="bi bi-clock-history me-1.5"></i>Workspace Awaiting Approval</h5>
                            <p class="text-muted" style="font-size: 0.92rem; line-height: 1.7; text-align: left;">
                                Thank you for registering your Internet Service Provider on **<?php echo e(get_platform_name()); ?>**! 
                                <br><br>
                                Your application has been logged and is currently **Pending Approval**. Your account is awaiting Super Admin approval. Once approved, your tenant desk and database limits will be automatically enabled.
                            </p>
                            <div class="alert alert-warning border-0 rounded-lg p-3 mt-4 text-start" style="background: rgba(245, 158, 11, 0.08); color: #FBBF24; font-size: 0.85rem;">
                                <i class="bi bi-info-circle me-1.5"></i>Status: <strong>Awaiting Super Admin Verification</strong>
                            </div>
                        </div>

                    <?php elseif ($lock_state === 'suspended'): ?>
                        <!-- 2. LEASE SUSPENDED -->
                        <div class="text-center">
                            <h5 class="text-danger fw-bold mb-3 font-outfit"><i class="bi bi-slash-circle me-1.5"></i>Workspace Lease Suspended</h5>
                            <p class="text-muted" style="font-size: 0.92rem; line-height: 1.7; text-align: left;">
                                Access to your multi-tenant broadband panel has been **Administratively Suspended**.
                                <br><br>
                                This lock is generally enforced due to an outstanding subscription fee invoice or a policy compliance violation. Please contact our system administrators to resolve this suspension.
                            </p>
                            <div class="alert alert-danger border-0 rounded-lg p-3 mt-4 text-start" style="background: rgba(239, 68, 68, 0.08); color: #F87171; font-size: 0.85rem;">
                                <i class="bi bi-exclamation-triangle me-1.5"></i>Contact support at: <code><?php echo e(get_platform_email('admin')); ?></code>
                            </div>
                        </div>

                    <?php elseif ($lock_state === 'expired'): ?>
                        <!-- 3. SUBSCRIPTION EXPIRED -->
                        <div class="text-center">
                            <h5 class="text-danger fw-bold mb-3 font-outfit"><i class="bi bi-calendar-x me-1.5"></i>SaaS Subscription Lease Expired</h5>
                            <p class="text-muted" style="font-size: 0.92rem; line-height: 1.7; text-align: left;">
                                Your active SaaS subscription lease has **Expired** (Expiry Date: **<?php echo format_date($subscription_end); ?>**).
                                <br><br>
                                Access to your subscribers list, coverage area tools, billing desks, and financial reporting metrics has been locked automatically. Settle your pending renewal dues to reactivate workspace parameters.
                            </p>
                            <div class="alert alert-danger border-0 rounded-lg p-3 mt-4 text-start" style="background: rgba(239, 68, 68, 0.08); color: #F87171; font-size: 0.85rem;">
                                <i class="bi bi-credit-card-2-front me-1.5"></i>Outstanding dues required. Settle below to restore operations immediately.
                            </div>
                        </div>

                    <?php elseif ($lock_state === 'awaiting_payment'): ?>
                        <!-- 4. AWAITING PAYMENT (APPROVED BUT UNPAID) -->
                        <div class="text-center">
                            <h5 class="text-info fw-bold mb-3 font-outfit"><i class="bi bi-credit-card me-1.5"></i>Subscription Payment Required</h5>
                            <p class="text-muted" style="font-size: 0.92rem; line-height: 1.7; text-align: left;">
                                Your workspace registration has been **Approved**, but is currently awaiting payment activation.
                                <br><br>
                                Please pay the initial SaaS subscription invoice to unlock your broadband panel dashboard, manage customers, and provision connection packages.
                            </p>
                            <div class="alert alert-info border-0 rounded-lg p-3 mt-4 text-start" style="background: rgba(6, 182, 212, 0.08); color: #22D3EE; font-size: 0.85rem;">
                                <i class="bi bi-hourglass-split me-1.5"></i>Status: <strong>Awaiting Subscription Payment</strong>. Restricted Dashboard & Locked Features.
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- RENEWAL AND OUTSTANDING INVOICES AREA -->
                    <?php if (($lock_state === 'expired' || $lock_state === 'awaiting_payment') && !empty($outstanding_invoices)): ?>
                        <div class="mt-4 pt-4 border-top text-start" style="border-color: rgba(255, 255, 255, 0.06) !important;">
                            <h6 class="text-white font-outfit mb-3"><i class="bi bi-file-earmark-text text-primary me-1.5"></i>Outstanding Subscription Invoices</h6>
                            
                            <div class="table-responsive mb-4">
                                <table class="table table-dark table-hover mb-0" style="background: rgba(0,0,0,0.2); border-radius: 8px; overflow: hidden; font-size: 0.85rem;">
                                    <thead>
                                        <tr class="text-muted">
                                            <th class="p-3">Invoice Number</th>
                                            <th class="p-3">Plan Package</th>
                                            <th class="p-3 text-end">Amount Due</th>
                                            <th class="p-3 text-center">Due Date</th>
                                            <th class="p-3 text-center">Proof State</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($outstanding_invoices as $inv): ?>
                                            <tr>
                                                <td class="p-3 fw-bold text-white"><?php echo e($inv['invoice_number']); ?></td>
                                                <td class="p-3"><?php echo e($inv['plan_name']); ?></td>
                                                <td class="p-3 text-end fw-bold text-white"><?php echo format_currency($inv['amount']); ?></td>
                                                <td class="p-3 text-center text-muted"><?php echo format_date($inv['due_date']); ?></td>
                                                <td class="p-3 text-center">
                                                    <?php if ($inv['proof_submitted'] == 1): ?>
                                                        <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Reviewing</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1">Unpaid</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Payment Proof Submission Card -->
                            <div class="p-4 bg-dark bg-opacity-40 rounded-3 border" style="border-color: rgba(255,255,255,0.04);">
                                <h6 class="text-white font-outfit mb-2"><i class="bi bi-send text-secondary me-1.5"></i>Submit Renewal / Payment Proof</h6>
                                <p class="text-muted mb-3" style="font-size: 0.8rem;">Submit your billing details after transferring funds outside the system (Bank/EasyPaisa/JazzCash).</p>
                                
                                <form action="subscription_status.php" method="POST" class="d-flex flex-column gap-3">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="submit_proof" value="1">
                                    
                                    <div>
                                        <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Select Outstanding Invoice</label>
                                        <select name="invoice_id" class="form-select" style="font-size: 0.85rem;" required>
                                            <?php foreach ($outstanding_invoices as $inv): ?>
                                                <option value="<?php echo $inv['id']; ?>" <?php echo ($inv['proof_submitted'] == 1) ? 'disabled' : ''; ?>>
                                                    <?php echo e($inv['invoice_number']); ?> (Rs. <?php echo number_format($inv['amount'], 2); ?> - Plan: <?php echo e($inv['plan_name']); ?>) <?php echo ($inv['proof_submitted'] == 1) ? '[Proof Submitted]' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="row g-2">
                                        <div class="col-sm-6">
                                            <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Payment Channel / Method</label>
                                            <select name="payment_method" class="form-select" style="font-size: 0.85rem;" required>
                                                <option value="HBL Bank Transfer">HBL Bank Transfer</option>
                                                <option value="EasyPaisa Mobile Wallet">EasyPaisa Mobile Wallet</option>
                                                <option value="JazzCash Mobile Wallet">JazzCash Mobile Wallet</option>
                                                <option value="Alfalah Cash Deposit">Alfalah Cash Deposit</option>
                                                <option value="Other Method">Other Method</option>
                                            </select>
                                        </div>
                                        <div class="col-sm-6">
                                            <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Transaction ID / Reference Number</label>
                                            <input type="text" name="transaction_id" class="form-control" placeholder="TXN99881234" style="font-size: 0.85rem;" required>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Submission Remarks / Notes</label>
                                        <textarea name="submission_notes" class="form-control" rows="2" placeholder="Enter transfer date, account details, or specific notes..." style="font-size: 0.85rem;"></textarea>
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary-gradient w-100 py-2.5 mt-2" style="font-family: 'Outfit'; font-size: 0.95rem;">
                                        <i class="bi bi-check2-circle me-1.5"></i>Submit Payment Verification Proof
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-4 border-top pt-3 text-center d-flex justify-content-center gap-3" style="border-color: rgba(255,255,255,0.06) !important;">
                        <a href="logout.php" class="btn btn-dark-glass py-2 px-4" style="font-size: 0.9rem;"><i class="bi bi-box-arrow-left me-1.5"></i>Exit Session</a>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
