<?php
/**
 * NetPulse SaaS Platform Owner - Tenant Subscription Billing & Payment Records desk
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

// Load options (approved tenants) for manual invoicing
$tenants_opt = [];
try {
    $tenants_opt = $pdo->query("SELECT id, company_name, monthly_fee FROM tenants WHERE status != 'pending' ORDER BY company_name ASC")->fetchAll();
} catch (PDOException $e) {
    error_log("Tenants option fetch failed: " . $e->getMessage());
}

// 1. MANUALLY GENERATE SUBSCRIPTION INVOICE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_saas_invoice'])) {
    verify_csrf_token();
    
    $tenant_id = (int)($_POST['tenant_id'] ?? 0);
    $due_date = clean_input($_POST['due_date'] ?? '');
    $duration_days = (int)($_POST['duration_days'] ?? 30);
    
    if ($tenant_id <= 0) $errors[] = "Please select a platform tenant.";
    if (empty($due_date)) $errors[] = "Please specify an invoice due date.";
    
    if (empty($errors)) {
        try {
            // Load tenant and their assigned plan metadata
            $stmt = $pdo->prepare("SELECT t.*, p.name as plan_name FROM tenants t JOIN saas_plans p ON t.plan_id = p.id WHERE t.id = ?");
            $stmt->execute([$tenant_id]);
            $tenant = $stmt->fetch();
            
            if ($tenant) {
                $inv_num = 'SAAS-INV-' . date('Ymd') . '-' . sprintf('%04d', $tenant_id) . '-' . rand(100, 999);
                $monthly_fee = (double)$tenant['monthly_fee'];
                $fee = ($monthly_fee * $duration_days) / 30; // Pro-rated fee
                
                // Invoices are generated at calculated plan price for dynamic duration.
                $stmt = $pdo->prepare("INSERT INTO saas_invoices (tenant_id, invoice_number, plan_name, amount, paid_amount, remaining_amount, due_date, payment_status, payment_date, duration_days) VALUES (?, ?, ?, ?, 0.00, ?, ?, 'pending', NULL, ?)");
                $stmt->execute([$tenant_id, $inv_num, $tenant['plan_name'] . " ($duration_days Days)", $fee, $fee, $due_date, $duration_days]);
                
                log_audit_activity($pdo, 1, 'tenant', 1, "Generated custom SaaS invoice $inv_num for tenant: " . $tenant['company_name']);
                set_session_alert("SaaS invoice generated successfully. The tenant may request credit adjustments from their referral wallet.", "success");
                header("Location: billing.php");
                exit;
            } else {
                $errors[] = "Selected tenant workspace details are invalid.";
            }
        } catch (PDOException $e) {
            $errors[] = "Billing generation error: " . $e->getMessage();
        }
    }
}

// 2. RECORD TENANT PAYMENT SUBMIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_saas_payment'])) {
    verify_csrf_token();
    
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $paid_amt = (double)($_POST['paid_amount'] ?? 0.00);
    
    if ($invoice_id <= 0) $errors[] = "Invalid invoice selected.";
    if ($paid_amt <= 0) $errors[] = "Collect amount must be greater than Rs. 0.";
    
    if (empty($errors)) {
        try {
            // Fetch SaaS Invoice details
            $stmt = $pdo->prepare("SELECT i.*, t.company_name FROM saas_invoices i JOIN tenants t ON i.tenant_id = t.id WHERE i.id = ?");
            $stmt->execute([$invoice_id]);
            $inv = $stmt->fetch();
            
            if ($inv) {
                $total = (double)$inv['amount'];
                $new_paid = (double)$inv['paid_amount'] + $paid_amt;
                
                if ($new_paid > $total) {
                    $errors[] = "Recorded payment cannot exceed total billed subscription amount: " . format_currency($total);
                }
                
                if (empty($errors)) {
                    $remaining = $total - $new_paid;
                    
                    // Status
                    $status = 'pending';
                    if ($remaining == 0) $status = 'paid';
                    elseif ($new_paid > 0) $status = 'partial';
                    elseif ($inv['due_date'] < date('Y-m-d')) $status = 'overdue';
                    
                    $pay_date = ($status === 'paid' || $status === 'partial') ? date('Y-m-d H:i:s') : null;
                    
                    $upd = $pdo->prepare("UPDATE saas_invoices SET paid_amount = ?, remaining_amount = ?, payment_status = ?, payment_date = ? WHERE id = ?");
                    $upd->execute([$new_paid, $remaining, $status, $pay_date, $invoice_id]);
                    
                    // Trigger Commission/Referral rewards on paid SaaS amount
                    $stmt_ref = $pdo->prepare("SELECT referred_by_type, referred_by_id FROM tenants WHERE id = ?");
                    $stmt_ref->execute([$inv['tenant_id']]);
                    $ref_data = $stmt_ref->fetch();
                    
                    if ($ref_data && $ref_data['referred_by_type'] !== 'none' && $ref_data['referred_by_id'] > 0) {
                        $ref_type = $ref_data['referred_by_type'];
                        $ref_id = $ref_data['referred_by_id'];
                        
                        if ($ref_type === 'affiliate') {
                            // Fetch affiliate commission percentage
                            $stmt_pct = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key = 'affiliate_commission_percentage' LIMIT 1");
                            $stmt_pct->execute();
                            $aff_pct = (double)($stmt_pct->fetchColumn() ?? 20.00);
                            
                            $comm_amt = ($paid_amt * $aff_pct) / 100;
                            
                            if ($comm_amt > 0) {
                                // Credit affiliate wallet
                                $stmt_c = $pdo->prepare("UPDATE affiliates SET wallet_balance = wallet_balance + ? WHERE id = ?");
                                $stmt_c->execute([$comm_amt, $ref_id]);
                                
                                // Ledger log
                                $notes = "Recurring affiliate commission on SaaS invoice paid: " . $inv['invoice_number'];
                                $stmt_l = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('affiliate', ?, 'commission_credit', ?, ?, 'approved', ?)");
                                $stmt_l->execute([$ref_id, $comm_amt, $invoice_id, $notes]);
                            }
                        } elseif ($ref_type === 'tenant') {
                            // Fetch tenant referral percentage
                            $stmt_pct = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key = 'tenant_referral_percentage' LIMIT 1");
                            $stmt_pct->execute();
                            $tenant_pct = (double)($stmt_pct->fetchColumn() ?? 10.00);
                            
                            $comm_amt = ($paid_amt * $tenant_pct) / 100;
                            
                            if ($comm_amt > 0) {
                                // Credit referring tenant's wallet
                                $stmt_c = $pdo->prepare("UPDATE tenants SET referral_wallet = referral_wallet + ? WHERE id = ?");
                                $stmt_c->execute([$comm_amt, $ref_id]);
                                
                                // Ledger log
                                $notes = "Recurring tenant referral commission on SaaS invoice paid: " . $inv['invoice_number'];
                                $stmt_l = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('tenant', ?, 'commission_credit', ?, ?, 'approved', ?)");
                                $stmt_l->execute([$ref_id, $comm_amt, $invoice_id, $notes]);
                            }
                        }
                    }
                    
                    // If fully paid, extend tenant's subscription dynamically by the invoice's duration!
                    if ($status === 'paid') {
                        $days = (int)($inv['duration_days'] ?? 30);
                        
                        // Check if subscription has expired. If so, start extension from today. Otherwise, extend from current end date!
                        $stmt_t = $pdo->prepare("SELECT subscription_end FROM tenants WHERE id = ? LIMIT 1");
                        $stmt_t->execute([$inv['tenant_id']]);
                        $current_end = $stmt_t->fetchColumn();
                        
                        $today = date('Y-m-d');
                        if (empty($current_end) || $current_end < $today) {
                            $start_date = $today;
                        } else {
                            $start_date = $current_end;
                        }
                        
                        $new_end = date('Y-m-d', strtotime($start_date . " + $days days"));
                        
                        $upd_t = $pdo->prepare("UPDATE tenants SET subscription_start = ?, subscription_end = ?, subscription_duration = ?, status = 'active' WHERE id = ?");
                        $upd_t->execute([$today, $new_end, $days, $inv['tenant_id']]);
                    }
                    
                    log_audit_activity($pdo, 1, 'tenant', 1, "Recorded tenant subscription payment of Rs. $paid_amt on invoice: " . $inv['invoice_number']);
                    set_session_alert("Tenant payment of Rs. $paid_amt successfully logged on invoice '{$inv['invoice_number']}'.", "success");
                    header("Location: billing.php");
                    exit;
                }
            } else {
                $errors[] = "SaaS invoice not found.";
            }
        } catch (PDOException $e) {
            $errors[] = "Payment transaction error: " . $e->getMessage();
        }
    }
}

// Fetch all SaaS Invoices
$invoices = [];
try {
    $invoices = $pdo->query("SELECT i.*, t.company_name FROM saas_invoices i JOIN tenants t ON i.tenant_id = t.id ORDER BY i.id DESC")->fetchAll();
} catch (PDOException $e) {
    error_log("SaaS invoices load failed: " . $e->getMessage());
}

// Include header layout now that all action redirects are completed
require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col-sm-8">
        <h2 class="text-white mb-1"><i class="bi bi-wallet2 text-primary me-2"></i>SaaS Platform Billing</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Track outstanding tenant balances, log monthly subscription payments, or generate manual workspace invoices.</p>
    </div>
    <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
        <?php if ($action === 'list'): ?>
            <a href="billing.php?action=add" class="btn btn-primary-gradient px-4 py-2.5" style="font-size: 0.9rem;"><i class="bi bi-file-earmark-plus me-1.5"></i>Generate SaaS Invoice</a>
        <?php else: ?>
            <a href="billing.php" class="btn btn-dark-glass px-4 py-2.5" style="font-size: 0.9rem;"><i class="bi bi-arrow-left me-1.5"></i>Back to Billing Desk</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'list'): ?>
    
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo e($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    
    <!-- Invoices ledger -->
    <div class="table-responsive-glass mb-4">
        <table class="table table-glass align-middle">
            <thead>
                <tr>
                    <th style="width: 20%;">Invoice Number</th>
                    <th style="width: 25%;">ISP Workspace Tenant</th>
                    <th style="width: 15%; text-align: right;">Billed Amount</th>
                    <th style="width: 15%; text-align: right;">Paid So Far</th>
                    <th style="width: 15%; text-align: center;">Due Date</th>
                    <th style="width: 10%; text-align: center;">Status</th>
                    <th style="width: 15%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted" style="font-size: 0.9rem;">
                            <i class="bi bi-receipt fs-2 mb-2 d-block opacity-40"></i>
                            No tenant subscription invoices recorded in platform database.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): 
                        $status = $inv['payment_status'];
                        $badge_class = ($status === 'paid') ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger';
                        ?>
                        <tr>
                            <td class="fw-bold text-white"><?php echo e($inv['invoice_number']); ?></td>
                            <td>
                                <span class="fw-bold text-white d-block" style="font-size: 0.88rem;"><?php echo e($inv['company_name']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.72rem;">SaaS Plan: <?php echo e($inv['plan_name']); ?></small>
                                <?php if ($inv['proof_submitted'] == 1): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1 mt-1 d-inline-block" style="font-size: 0.68rem;" title="Method: <?php echo e($inv['payment_method']); ?> | Trans: <?php echo e($inv['transaction_id']); ?>">
                                        <i class="bi bi-info-circle me-1"></i>Proof Submitted
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold text-white"><?php echo format_currency($inv['amount']); ?></td>
                            <td class="text-end">
                                <span class="text-success d-block" style="font-size: 0.82rem;">Paid: <?php echo format_currency($inv['paid_amount']); ?></span>
                                <span class="text-danger d-block" style="font-size: 0.75rem;">Due: <?php echo format_currency($inv['remaining_amount']); ?></span>
                            </td>
                            <td class="text-center">
                                <span class="text-white d-block fw-bold" style="font-size: 0.85rem;"><?php echo format_date($inv['due_date']); ?></span>
                                <?php if ($inv['payment_date']): ?>
                                    <small class="text-muted" style="font-size: 0.7rem;">Paid: <?php echo date('d M, Y', strtotime($inv['payment_date'])); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge text-uppercase px-2.5 py-1 <?php echo $badge_class; ?>" style="font-size: 0.7rem; background: rgba(255,255,255,0.02);">
                                    <?php echo e($status); ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php if ($status !== 'paid'): ?>
                                    <button type="button" class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#payModal-<?php echo $inv['id']; ?>"><i class="bi bi-wallet2 me-1"></i>Collect Payment</button>
                                <?php else: ?>
                                    <span class="text-success" style="font-size: 0.85rem;"><i class="bi bi-check2-all me-1"></i>Settled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
<?php elseif ($action === 'add'): ?>
    
    <!-- Custom manual invoice generation -->
    <div class="p-4 p-md-5 glass-panel" style="max-width: 600px; margin: 0 auto; border-radius: 16px;">
        <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Generate Tenant Subscription Invoice</h5>
        
        <form action="billing.php?action=add" method="POST" class="d-flex flex-column gap-3.5">
            <?php csrf_field(); ?>
            <input type="hidden" name="generate_saas_invoice" value="1">
            
            <div>
                <label class="form-label text-muted" style="font-size: 0.8rem;">Select Active Tenant Workspace</label>
                <select name="tenant_id" class="form-select" required>
                    <option value="">-- Select Tenant --</option>
                    <?php foreach ($tenants_opt as $t): ?>
                        <option value="<?php echo $t['id']; ?>">
                            <?php echo e($t['company_name']); ?> (Default Monthly Fee: Rs. <?php echo e($t['monthly_fee']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="form-label text-muted" style="font-size: 0.8rem;">Plan Duration</label>
                <select name="duration_days" class="form-select" required>
                    <option value="14">14 Days</option>
                    <option value="30" selected>30 Days</option>
                    <option value="90">90 Days</option>
                    <option value="180">180 Days</option>
                    <option value="365">365 Days</option>
                </select>
            </div>
            
            <div>
                <label class="form-label text-muted" style="font-size: 0.8rem;">Due Date</label>
                <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
            </div>
            
            <div class="d-flex gap-2 justify-content-end mt-3 border-top pt-3 border-white border-opacity-5">
                <button type="submit" class="btn btn-primary px-4 py-2.5">Generate SaaS Invoice</button>
                <a href="billing.php" class="btn btn-dark-glass px-4 py-2.5">Cancel</a>
            </div>
        </form>
    </div>
    
<?php endif; ?>

<!-- Render Modals outside of the table-responsive and nested grid contexts to prevent backdrop/rendering clipping bugs -->
<?php if ($action === 'list' && !empty($invoices)): ?>
    <?php foreach ($invoices as $inv): 
        if ($inv['payment_status'] !== 'paid'): ?>
            <!-- Record Payment Modal -->
            <div class="modal fade" id="payModal-<?php echo $inv['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px); z-index: 1060;">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                        <div class="modal-header border-bottom border-white border-opacity-5">
                            <h5 class="modal-title text-white">Record Tenant Payment</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="billing.php" method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                            <input type="hidden" name="record_saas_payment" value="1">
                            
                            <div class="modal-body p-4 d-flex flex-column gap-3">
                                <div>
                                    <span class="text-muted" style="font-size: 0.8rem;">ISP Tenant Workspace</span>
                                    <strong class="text-white d-block"><?php echo e($inv['company_name']); ?></strong>
                                </div>
                                
                                <?php if ($inv['proof_submitted'] == 1): ?>
                                    <div class="p-3 bg-info bg-opacity-5 border border-info border-opacity-15 rounded-3 my-1" style="font-size: 0.82rem; color: #22D3EE;">
                                        <strong class="d-block mb-1 text-white"><i class="bi bi-info-circle me-1"></i>Tenant Submitted Payment Proof</strong>
                                        Channel: <strong><?php echo e($inv['payment_method']); ?></strong><br>
                                        Transaction Ref: <strong><?php echo e($inv['transaction_id']); ?></strong><br>
                                        Submitted Date: <strong><?php echo format_date($inv['submission_date']); ?></strong><br>
                                        Notes: <em><?php echo e($inv['submission_notes'] ?: 'None'); ?></em>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="row text-center bg-dark rounded-3 p-3 my-2" style="border: 1px solid var(--border-color);">
                                    <div class="col">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">Billed Subscription</span>
                                        <strong class="text-white"><?php echo format_currency($inv['amount']); ?></strong>
                                    </div>
                                    <div class="col border-start border-white border-opacity-5">
                                        <span class="text-muted d-block" style="font-size: 0.72rem;">Outstanding</span>
                                        <strong class="text-danger"><?php echo format_currency($inv['remaining_amount']); ?></strong>
                                    </div>
                                </div>
                                <div>
                                    <label class="form-label text-muted" style="font-size: 0.8rem;">Collect Paid Amount (Rs.)</label>
                                    <input type="number" name="paid_amount" class="form-control" value="<?php echo $inv['remaining_amount']; ?>" required min="0.01" max="<?php echo $inv['remaining_amount']; ?>" step="0.01">
                                </div>
                            </div>
                            <div class="modal-footer border-top border-white border-opacity-5">
                                <button type="submit" class="btn btn-primary px-4">Log Payment</button>
                                <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
