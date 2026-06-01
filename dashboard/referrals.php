<?php
/**
 * Tenant Dashboard - Referral & Customer Configuration Center
 * Enhanced with detailed wallet stats, credit adjustment requests, and cashout configuration.
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
$success = "";

// 1. Ensure referral settings row exists
try {
    $stmt = $pdo->prepare("INSERT IGNORE INTO tenant_referral_settings (tenant_id, enabled, reward_type, reward_value, min_withdrawal_amount) VALUES (?, 1, 'fixed', 100.00, 500.00)");
    $stmt->execute([$tenant_id]);
} catch (PDOException $e) {
    error_log("Settings init check failed: " . $e->getMessage());
}

// 2. Load tenant data & settings
try {
    $stmt = $pdo->prepare("SELECT referral_code, referral_wallet, lifetime_referral_earnings FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $tenant_data = $stmt->fetch();
    
    $stmt = $pdo->prepare("SELECT * FROM tenant_referral_settings WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $referral_settings = $stmt->fetch();
} catch (PDOException $e) {
    $errors[] = "Error loading database data: " . $e->getMessage();
}

// 3. Process Customer Referral Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_settings'])) {
    verify_csrf_token();
    
    $enabled = isset($_POST['cust_enabled']) ? 1 : 0;
    $reward_type = clean_input($_POST['reward_type'] ?? 'fixed');
    $reward_val = (double)($_POST['reward_value'] ?? 0.00);
    $min_withdraw = (double)($_POST['min_withdrawal'] ?? 0.00);
    $allow_cashout = isset($_POST['allow_cashout']) ? 1 : 0;
    $max_withdraw = (double)($_POST['max_withdrawal_amount'] ?? 0.00);
    
    if ($reward_val < 0) $errors[] = "Reward value cannot be negative.";
    if ($min_withdraw < 0) $errors[] = "Minimum withdrawal cannot be negative.";
    if ($reward_type === 'percentage' && $reward_val > 100) $errors[] = "Percentage rewards cannot exceed 100%.";
    if ($max_withdraw < 0) $errors[] = "Maximum withdrawal cannot be negative.";
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE tenant_referral_settings SET enabled = ?, reward_type = ?, reward_value = ?, min_withdrawal_amount = ?, allow_cashout = ?, max_withdrawal_amount = ? WHERE tenant_id = ?");
            $stmt->execute([$enabled, $reward_type, $reward_val, $min_withdraw, $allow_cashout, $max_withdraw, $tenant_id]);
            
            set_session_alert("Customer referral program rules saved successfully.", "success");
            header("Location: referrals.php?tab=settings");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Failed to save settings: " . $e->getMessage();
        }
    }
}

// 4. Process Withdrawal Request (Tenant excess earnings cashout to Super Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_withdrawal'])) {
    verify_csrf_token();
    
    $amount = (double)($_POST['amount'] ?? 0.00);
    $method = clean_input($_POST['payment_method'] ?? '');
    $details = clean_input($_POST['payment_details'] ?? '');
    
    // Load global min withdrawal
    $min_payout = 1000.00;
    try {
        $stmt = $pdo->query("SELECT setting_value FROM saas_settings WHERE setting_key = 'min_withdrawal_amount' LIMIT 1");
        $min_val = $stmt->fetchColumn();
        if ($min_val) $min_payout = (double)$min_val;
    } catch (PDOException $e) {}
    
    if ($amount < $min_payout) {
        $errors[] = "Minimum withdrawal amount is Rs. " . number_format($min_payout, 2);
    }
    if ($amount > $tenant_data['referral_wallet']) {
        $errors[] = "Insufficient balance. Available referral wallet: Rs. " . number_format($tenant_data['referral_wallet'], 2);
    }
    if (empty($method)) $errors[] = "Payment gateway method is required.";
    if (empty($details)) $errors[] = "Receiving gateway credentials / details are required.";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Deduct wallet
            $stmt = $pdo->prepare("UPDATE tenants SET referral_wallet = referral_wallet - ? WHERE id = ?");
            $stmt->execute([$amount, $tenant_id]);
            
            // Create withdrawal request
            $stmt = $pdo->prepare("INSERT INTO withdrawal_requests (requester_type, requester_id, amount, payment_method, payment_details, status) VALUES ('tenant', ?, ?, ?, ?, 'pending')");
            $stmt->execute([$tenant_id, $amount, $method, $details]);
            $request_id = $pdo->lastInsertId();
            
            // Ledger entry
            $notes = "ISP Tenant payout request via $method ($details)";
            $stmt = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('tenant', ?, 'withdrawal', ?, ?, 'pending', ?)");
            $stmt->execute([$tenant_id, $amount, $request_id, $notes]);
            
            $pdo->commit();
            
            set_session_alert("Payout request submitted successfully! Locked funds are pending approval by the Super Admin.", "success");
            header("Location: referrals.php?tab=wallet");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Transaction failed: " . $e->getMessage();
        }
    }
}

// 5. Process Credit Adjustment Request (Apply wallet credits to pending invoice)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request_credit_adjustment'])) {
    verify_csrf_token();
    
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $credit_amount = (double)($_POST['credit_amount'] ?? 0.00);
    
    if ($invoice_id <= 0) $errors[] = "Please select a valid pending invoice.";
    if ($credit_amount <= 0) $errors[] = "Credit amount must be greater than Rs. 0.";
    
    // Reload wallet (in case it changed)
    $stmt = $pdo->prepare("SELECT referral_wallet FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $current_wallet = (double)$stmt->fetchColumn();
    
    if ($credit_amount > $current_wallet) {
        $errors[] = "Insufficient wallet balance. Available: Rs. " . number_format($current_wallet, 2);
    }
    
    // Verify the invoice belongs to this tenant and is pending/partial
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT * FROM saas_invoices WHERE id = ? AND tenant_id = ? AND payment_status IN ('pending', 'partial', 'overdue') LIMIT 1");
        $stmt->execute([$invoice_id, $tenant_id]);
        $invoice = $stmt->fetch();
        
        if (!$invoice) {
            $errors[] = "Selected invoice is invalid, already paid, or does not belong to your workspace.";
        } else {
            // Cap credit amount to the invoice remaining
            $remaining = (double)$invoice['remaining_amount'];
            if ($credit_amount > $remaining) {
                $credit_amount = $remaining;
            }
            
            // Check for existing pending request on same invoice
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM credit_adjustment_requests WHERE invoice_id = ? AND tenant_id = ? AND status = 'pending'");
            $stmt->execute([$invoice_id, $tenant_id]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = "A pending credit adjustment request already exists for this invoice. Please wait for it to be processed.";
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Lock the credit amount by deducting from wallet
            $stmt = $pdo->prepare("UPDATE tenants SET referral_wallet = referral_wallet - ? WHERE id = ?");
            $stmt->execute([$credit_amount, $tenant_id]);
            
            // Create credit adjustment request
            $stmt = $pdo->prepare("INSERT INTO credit_adjustment_requests (tenant_id, invoice_id, requested_amount, status) VALUES (?, ?, ?, 'pending')");
            $stmt->execute([$tenant_id, $invoice_id, $credit_amount]);
            
            $pdo->commit();
            
            // Reload wallet balance
            $stmt = $pdo->prepare("SELECT referral_wallet FROM tenants WHERE id = ? LIMIT 1");
            $stmt->execute([$tenant_id]);
            $tenant_data['referral_wallet'] = (double)$stmt->fetchColumn();
            
            set_session_alert("Credit adjustment request of Rs. " . number_format($credit_amount, 2) . " submitted for approval. Funds have been locked until processed.", "success");
            header("Location: referrals.php?tab=wallet");
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Credit request failed: " . $e->getMessage();
        }
    }
}

// 6. Gather statistics
$total_referrals = 0;
$active_referrals = 0;
$pending_referrals = 0;
$credits_used = 0.00;
$total_withdrawals = 0.00;
$pending_earnings = 0.00;
$approved_earnings = 0.00;
$referred_list = [];
$payouts_history = [];
$pending_invoices = [];
$credit_requests_history = [];

try {
    // Referrals stats
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE referred_by_type = 'tenant' AND referred_by_id = ?");
    $stmt->execute([$tenant_id]);
    $total_referrals = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE referred_by_type = 'tenant' AND referred_by_id = ? AND status = 'active'");
    $stmt->execute([$tenant_id]);
    $active_referrals = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE referred_by_type = 'tenant' AND referred_by_id = ? AND status = 'pending'");
    $stmt->execute([$tenant_id]);
    $pending_referrals = (int)$stmt->fetchColumn();
    
    // Credits used (approved invoice_deduction transactions)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_transactions WHERE referrer_type = 'tenant' AND referrer_id = ? AND transaction_type = 'invoice_deduction' AND status = 'approved'");
    $stmt->execute([$tenant_id]);
    $credits_used = (double)$stmt->fetchColumn();
    
    // Total withdrawals (approved/completed)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE requester_type = 'tenant' AND requester_id = ? AND status IN ('approved', 'completed')");
    $stmt->execute([$tenant_id]);
    $total_withdrawals = (double)$stmt->fetchColumn();
    
    // Pending earnings (pending commission credits)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_transactions WHERE referrer_type = 'tenant' AND referrer_id = ? AND transaction_type = 'commission_credit' AND status = 'pending'");
    $stmt->execute([$tenant_id]);
    $pending_earnings = (double)$stmt->fetchColumn();
    
    // Approved earnings (approved commission credits)
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_transactions WHERE referrer_type = 'tenant' AND referrer_id = ? AND transaction_type = 'commission_credit' AND status = 'approved'");
    $stmt->execute([$tenant_id]);
    $approved_earnings = (double)$stmt->fetchColumn();
    
    // Load referred tenants list
    $stmt = $pdo->prepare("SELECT company_name, subdomain, status, created_at, monthly_fee FROM tenants WHERE referred_by_type = 'tenant' AND referred_by_id = ? ORDER BY id DESC");
    $stmt->execute([$tenant_id]);
    $referred_list = $stmt->fetchAll();
    
    // Load cashouts history
    $stmt = $pdo->prepare("SELECT * FROM withdrawal_requests WHERE requester_type = 'tenant' AND requester_id = ? ORDER BY id DESC");
    $stmt->execute([$tenant_id]);
    $payouts_history = $stmt->fetchAll();
    
    // Load pending SaaS invoices for credit adjustment
    $stmt = $pdo->prepare("SELECT * FROM saas_invoices WHERE tenant_id = ? AND payment_status IN ('pending', 'partial', 'overdue') ORDER BY due_date ASC");
    $stmt->execute([$tenant_id]);
    $pending_invoices = $stmt->fetchAll();
    
    // Load credit adjustment request history
    $stmt = $pdo->prepare("SELECT cr.*, i.invoice_number, i.amount as invoice_amount FROM credit_adjustment_requests cr JOIN saas_invoices i ON cr.invoice_id = i.id WHERE cr.tenant_id = ? ORDER BY cr.id DESC LIMIT 20");
    $stmt->execute([$tenant_id]);
    $credit_requests_history = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Referrals stats load error: " . $e->getMessage());
}

$active_tab = clean_input($_GET['tab'] ?? 'dashboard');

// Include header layout now that all action redirects are completed
require_once __DIR__ . '/layouts/header.php';
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-gift text-primary me-2"></i>ISP Referral & Credit Center</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Review tenant-to-tenant SaaS referrals, track bill credits, request payout cashouts, and configure custom reward criteria for your broadband subscribers.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $error): ?>
                <li><?php echo e($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Tabs navigation -->
<ul class="nav nav-tabs border-white border-opacity-5 mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link border-0 text-muted <?php echo ($active_tab === 'dashboard') ? 'active bg-dark text-white fw-bold' : ''; ?>" href="referrals.php?tab=dashboard">
            <i class="bi bi-speedometer2 me-1.5"></i>Referral Stats
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link border-0 text-muted <?php echo ($active_tab === 'wallet') ? 'active bg-dark text-white fw-bold' : ''; ?>" href="referrals.php?tab=wallet">
            <i class="bi bi-wallet2 me-1.5"></i>Credit Wallet & Cashouts
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link border-0 text-muted <?php echo ($active_tab === 'settings') ? 'active bg-dark text-white fw-bold' : ''; ?>" href="referrals.php?tab=settings">
            <i class="bi bi-sliders me-1.5"></i>Subscriber Referral Program
        </a>
    </li>
</ul>

<div class="tab-content">
    
    <!-- Tab 1: Dashboard Stats -->
    <?php if ($active_tab === 'dashboard'): ?>
        <div class="tab-pane fade show active">
            <!-- Enhanced Stats cards -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Total Referrals</span>
                        <strong class="text-white fs-3 font-outfit"><?php echo $total_referrals; ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Registered ISPs</small>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Active Referrals</span>
                        <strong class="text-success fs-3 font-outfit"><?php echo $active_referrals; ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Paying ISPs</small>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Available Wallet Balance</span>
                        <strong class="text-primary fs-3 font-outfit"><?php echo format_currency($tenant_data['referral_wallet']); ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Ready to request credit or cashout</small>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Lifetime Earnings</span>
                        <strong class="text-light fs-3 font-outfit"><?php echo format_currency($tenant_data['lifetime_referral_earnings']); ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Total commissions received</small>
                    </div>
                </div>
            </div>

            <!-- Second row of stats -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Pending Earnings</span>
                        <strong class="text-warning fs-4 font-outfit"><?php echo format_currency($pending_earnings); ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Awaiting approval</small>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Approved Earnings</span>
                        <strong class="text-success fs-4 font-outfit"><?php echo format_currency($approved_earnings); ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Credited commissions</small>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Total Withdrawals</span>
                        <strong class="text-info fs-4 font-outfit"><?php echo format_currency($total_withdrawals); ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Cashouts disbursed</small>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="glass-card p-3.5 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
                        <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Billing Credits Used</span>
                        <strong class="text-light fs-4 font-outfit"><?php echo format_currency($credits_used); ?></strong>
                        <small class="text-muted d-block" style="font-size: 0.68rem;">Applied to invoices</small>
                    </div>
                </div>
            </div>

            <!-- Share Referral link card -->
            <div class="glass-card p-4 mb-4">
                <h5 class="text-white font-outfit mb-3"><i class="bi bi-share text-primary me-2"></i>Your Tenant-to-Tenant Referral URL</h5>
                <p class="text-muted" style="font-size: 0.85rem;">Introduce other ISPs to the <?php echo e(get_platform_name()); ?> SaaS platform. If they sign up and pay their monthly subscription, you earn a recurring commission (auto-applied to your wallet!).</p>
                
                <?php
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $tenant_ref_link = $protocol . $host . "/ISP/register.php?ref=" . e($tenant_data['referral_code']);
                ?>
                <div class="d-flex align-items-center gap-2">
                    <input type="text" class="form-control bg-dark border-0 text-primary fw-bold" value="<?php echo $tenant_ref_link; ?>" readonly id="tenantRefLink">
                    <button class="btn btn-primary" onclick="copyTenantLink()"><i class="bi bi-files me-1"></i>Copy</button>
                </div>
            </div>

            <!-- Referred Tenants List -->
            <div class="glass-card p-4">
                <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                    <i class="bi bi-building-check text-primary me-2"></i>ISP Referral Performance Registry
                </h5>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-muted" style="font-size: 0.75rem;">
                                <th>ISP Company Details</th>
                                <th>Subdomain Workspace</th>
                                <th>Active Plan Value</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Joined Date</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 0.85rem;">
                            <?php if (empty($referred_list)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">You have not referred any ISPs yet. Share your code above to get started!</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($referred_list as $ref): ?>
                                    <tr>
                                        <td><strong class="text-white"><?php echo e($ref['company_name']); ?></strong></td>
                                        <td><code class="text-primary"><?php echo e($ref['subdomain']); ?>.<?php echo e(get_platform_domain()); ?></code></td>
                                        <td><?php echo format_currency($ref['monthly_fee']); ?>/mo</td>
                                        <td class="text-center">
                                            <span class="badge text-uppercase px-2.5 py-0.5 <?php echo ($ref['status'] === 'active') ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'; ?>" style="font-size: 0.68rem; background: rgba(255,255,255,0.02);">
                                                <?php echo e($ref['status']); ?>
                                            </span>
                                        </td>
                                        <td class="text-center text-muted" style="font-size: 0.78rem;"><?php echo format_date($ref['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 2: Wallet & Cashouts -->
    <?php if ($active_tab === 'wallet'): ?>
        <div class="tab-pane fade show active">
            
            <!-- Wallet Overview -->
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-4">
                    <div class="glass-card p-4 text-center" style="border-color: rgba(168, 85, 247, 0.15) !important;">
                        <span class="text-muted d-block mb-1" style="font-size: 0.72rem; text-transform: uppercase;">Available Balance</span>
                        <strong class="text-white font-outfit fs-2">Rs. <?php echo number_format($tenant_data['referral_wallet'], 2); ?></strong>
                        <small class="text-success d-block mt-1" style="font-size: 0.72rem;">Ready for credit or cashout</small>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="glass-card p-4 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
                        <span class="text-muted d-block mb-1" style="font-size: 0.72rem; text-transform: uppercase;">Lifetime Earnings</span>
                        <strong class="text-light font-outfit fs-3"><?php echo format_currency($tenant_data['lifetime_referral_earnings']); ?></strong>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="glass-card p-4 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
                        <span class="text-muted d-block mb-1" style="font-size: 0.72rem; text-transform: uppercase;">Total Cashouts</span>
                        <strong class="text-info font-outfit fs-3"><?php echo format_currency($total_withdrawals); ?></strong>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- Apply Credits to Invoice -->
                <div class="col-lg-6">
                    <div class="glass-card p-4 h-100">
                        <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                            <i class="bi bi-credit-card-2-front text-primary me-2"></i>Apply Credits to Invoice
                        </h5>
                        <p class="text-muted" style="font-size: 0.82rem;">Request to apply your referral wallet credits toward a pending SaaS subscription invoice. The Super Admin will review and approve your request.</p>
                        
                        <?php if (empty($pending_invoices)): ?>
                            <div class="text-center py-4">
                                <i class="bi bi-check2-all text-success fs-2 d-block mb-2"></i>
                                <span class="text-muted" style="font-size: 0.85rem;">No outstanding invoices found. All settled!</span>
                            </div>
                        <?php else: ?>
                            <form action="referrals.php?tab=wallet" method="POST" class="d-flex flex-column gap-3">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action_request_credit_adjustment" value="1">
                                
                                <div>
                                    <label class="form-label text-muted" style="font-size: 0.8rem;">Select Pending Invoice</label>
                                    <select name="invoice_id" class="form-select bg-dark border-0 text-white" required id="creditInvoiceSelect">
                                        <option value="">-- Select Invoice --</option>
                                        <?php foreach ($pending_invoices as $inv): ?>
                                            <option value="<?php echo $inv['id']; ?>" data-remaining="<?php echo $inv['remaining_amount']; ?>">
                                                <?php echo e($inv['invoice_number']); ?> — Outstanding: Rs. <?php echo number_format($inv['remaining_amount'], 2); ?> (Due: <?php echo date('d M, Y', strtotime($inv['due_date'])); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="form-label text-muted" style="font-size: 0.8rem;">Credit Amount (Rs.)</label>
                                    <input type="number" name="credit_amount" class="form-control bg-dark border-0 text-white" id="creditAmountInput" value="<?php echo $tenant_data['referral_wallet']; ?>" required min="0.01" step="0.01">
                                    <small class="text-muted" style="font-size: 0.7rem;">Max available: Rs. <?php echo number_format($tenant_data['referral_wallet'], 2); ?>. Will be capped to invoice outstanding amount.</small>
                                </div>
                                
                                <button type="submit" class="btn btn-primary w-100 py-2.5" <?php echo ($tenant_data['referral_wallet'] <= 0 || empty($pending_invoices)) ? 'disabled' : ''; ?>><i class="bi bi-send-check me-1.5"></i>Submit Credit Request</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Cashout Request -->
                <div class="col-lg-6">
                    <div class="glass-card p-4 h-100">
                        <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                            <i class="bi bi-wallet2 text-primary me-2"></i>Excess Earnings Cashout Request
                        </h5>
                        <p class="text-muted" style="font-size: 0.82rem;">If your referral wallet exceeds your monthly billing invoices, you may request bank or mobile wallet withdrawals from the Super Admin.</p>
                        
                        <form action="referrals.php?tab=wallet" method="POST" class="d-flex flex-column gap-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action_request_withdrawal" value="1">
                            
                            <div>
                                <label class="form-label text-muted" style="font-size: 0.8rem;">Cashout Amount (Rs.)</label>
                                <input type="number" name="amount" class="form-control bg-dark border-0 text-white" value="<?php echo e($tenant_data['referral_wallet']); ?>" required min="100" step="0.01">
                            </div>
                            
                            <div>
                                <label class="form-label text-muted" style="font-size: 0.8rem;">Payout Gateway Method</label>
                                <select name="payment_method" class="form-select bg-dark border-0 text-white" required>
                                    <option value="">-- Select Gateway --</option>
                                    <option value="Bank Transfer">Bank Wire Transfer</option>
                                    <option value="Easypaisa">Easypaisa Mobile Wallet</option>
                                    <option value="JazzCash">JazzCash Mobile Wallet</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="form-label text-muted" style="font-size: 0.8rem;">Gateway Credentials & Account Details</label>
                                <textarea name="payment_details" class="form-control bg-dark border-0 text-white" rows="3" placeholder="Specify wire details (Bank, Account Title, IBAN) or mobile details..." required></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100 py-2.5" <?php echo ($tenant_data['referral_wallet'] <= 0) ? 'disabled' : ''; ?>><i class="bi bi-unlock-fill me-1.5"></i>Submit Payout Slip</button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Credit Adjustment Request History -->
            <div class="glass-card p-4 mb-4">
                <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                    <i class="bi bi-credit-card-2-front text-info me-2"></i>Credit Adjustment History
                </h5>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-muted" style="font-size: 0.75rem;">
                                <th>Invoice</th>
                                <th class="text-end">Requested</th>
                                <th class="text-end">Approved</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Submitted</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 0.85rem;">
                            <?php if (empty($credit_requests_history)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No credit adjustment requests submitted yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($credit_requests_history as $cr):
                                    $cr_status = $cr['status'];
                                    $cr_class = ($cr_status === 'approved') ? 'bg-success bg-opacity-10 text-success border border-success border-opacity-25' : (($cr_status === 'pending') ? 'bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25' : 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25');
                                    $cr_label = ($cr_status === 'approved') ? 'Approved & Applied' : (($cr_status === 'pending') ? 'Pending Review' : 'Rejected & Refunded');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong class="text-primary d-block" style="font-size: 0.82rem;"><?php echo e($cr['invoice_number']); ?></strong>
                                            <small class="text-muted" style="font-size: 0.7rem;">Total: <?php echo format_currency($cr['invoice_amount']); ?></small>
                                        </td>
                                        <td class="text-end text-muted"><?php echo format_currency($cr['requested_amount']); ?></td>
                                        <td class="text-end fw-bold <?php echo ($cr_status === 'approved') ? 'text-success' : 'text-muted'; ?>">
                                            <?php echo ($cr['approved_amount'] !== null) ? format_currency($cr['approved_amount']) : '—'; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge text-uppercase px-2 py-0.5 <?php echo $cr_class; ?>" style="font-size: 0.68rem;"><?php echo $cr_label; ?></span>
                                        </td>
                                        <td class="text-center text-muted" style="font-size: 0.75rem;"><?php echo format_date($cr['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Cashout Request History -->
            <div class="glass-card p-4">
                <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                    <i class="bi bi-clock-history text-success me-2"></i>Cashout Request History
                </h5>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                        <thead>
                            <tr class="text-muted" style="font-size: 0.75rem;">
                                <th>Request ID</th>
                                <th>Gateway</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Status</th>
                                <th class="text-center">Submitted Date</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 0.85rem;">
                            <?php if (empty($payouts_history)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">No cashout request history found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payouts_history as $p): 
                                    $status = $p['status'];
                                    $class = match($status) {
                                        'approved' => 'bg-success-soft text-success',
                                        'completed' => 'bg-success-soft text-success',
                                        'pending' => 'bg-primary-soft text-primary',
                                        default => 'bg-danger-soft text-danger'
                                    };
                                    $label = match($status) {
                                        'approved' => 'Approved',
                                        'completed' => 'Completed & Disbursed',
                                        'pending' => 'Pending',
                                        default => 'Rejected'
                                    };
                                    ?>
                                    <tr>
                                        <td><strong class="text-white">#<?php echo $p['id']; ?></strong></td>
                                        <td>
                                            <span class="text-white d-block"><?php echo e($p['payment_method']); ?></span>
                                            <small class="text-muted" style="font-size: 0.7rem;"><?php echo e($p['payment_details']); ?></small>
                                        </td>
                                        <td class="text-end fw-bold text-white"><?php echo format_currency($p['amount']); ?></td>
                                        <td class="text-center">
                                            <span class="badge text-uppercase px-2 py-0.5 <?php echo $class; ?>" style="font-size: 0.68rem; background: rgba(255,255,255,0.02);">
                                                <?php echo $label; ?>
                                            </span>
                                        </td>
                                        <td class="text-center text-muted" style="font-size: 0.75rem;"><?php echo format_date($p['created_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tab 3: Customer Referral Configuration Settings -->
    <?php if ($active_tab === 'settings'): ?>
        <div class="tab-pane fade show active">
            <div class="glass-card p-4 p-md-5" style="max-width: 700px; margin: 0 auto;">
                <h5 class="text-white font-outfit mb-4 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                    <i class="bi bi-gear-fill text-primary me-2"></i>Configure Customer Referral Rewards
                </h5>
                
                <form action="referrals.php?tab=settings" method="POST" class="d-flex flex-column gap-3.5">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action_update_settings" value="1">
                    
                    <div class="form-check form-switch mb-3 p-3 bg-dark rounded-3 border border-white border-opacity-5 d-flex align-items-center justify-content-between">
                        <div class="ps-2">
                            <label class="form-check-label text-white fw-bold" for="cust_enabled" style="font-size: 0.95rem; cursor: pointer;">Enable Customer Referrals</label>
                            <small class="text-muted d-block" style="font-size: 0.75rem;">Allows your active broadband subscribers to refer their friends/neighbors.</small>
                        </div>
                        <input class="form-check-input ms-0" type="checkbox" name="cust_enabled" id="cust_enabled" value="1" style="width: 2.8rem; height: 1.4rem; cursor: pointer;" <?php echo (($referral_settings['enabled'] ?? 0) == 1) ? 'checked' : ''; ?>>
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Reward Grant Type</label>
                            <select name="reward_type" class="form-select bg-dark border-0 text-white" required>
                                <option value="fixed" <?php echo (($referral_settings['reward_type'] ?? 'fixed') === 'fixed') ? 'selected' : ''; ?>>Fixed Account Credit (Rs.)</option>
                                <option value="percentage" <?php echo (($referral_settings['reward_type'] ?? 'fixed') === 'percentage') ? 'selected' : ''; ?>>Bill Percentage Reward (%)</option>
                            </select>
                            <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Whether rewards are flat rupee credits or a percentage of the referred customer's first bill.</small>
                        </div>
                        
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Reward Value / Quantity</label>
                            <input type="number" name="reward_value" class="form-control bg-dark border-0 text-white" value="<?php echo e($referral_settings['reward_value'] ?? '100.00'); ?>" step="0.01" required min="0">
                            <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Amount in Rs. or % value based on the reward type selected above.</small>
                        </div>
                    </div>
                    
                    <div class="row g-3 mt-1">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Subscriber Minimum Cashout Payout (Rs.)</label>
                            <input type="number" name="min_withdrawal" class="form-control bg-dark border-0 text-white" value="<?php echo e($referral_settings['min_withdrawal_amount'] ?? '500.00'); ?>" step="0.01" required min="0">
                            <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">The threshold balance your customers must accumulate in their wallets to request cash payouts.</small>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Maximum Cashout Amount (Rs.)</label>
                            <input type="number" name="max_withdrawal_amount" class="form-control bg-dark border-0 text-white" value="<?php echo e($referral_settings['max_withdrawal_amount'] ?? '0.00'); ?>" step="0.01" required min="0">
                            <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Max per-request withdrawal amount. Set to 0 for unlimited.</small>
                        </div>
                    </div>
                    
                    <!-- Cashout toggle -->
                    <div class="form-check form-switch p-3 bg-dark rounded-3 border border-white border-opacity-5 d-flex align-items-center justify-content-between mt-2">
                        <div class="ps-2">
                            <label class="form-check-label text-white fw-bold" for="allow_cashout" style="font-size: 0.95rem; cursor: pointer;">Allow Customer Cashouts</label>
                            <small class="text-muted d-block" style="font-size: 0.75rem;">If disabled, customers can only use their wallet credits for billing reductions — no cash withdrawals.</small>
                        </div>
                        <input class="form-check-input ms-0" type="checkbox" name="allow_cashout" id="allow_cashout" value="1" style="width: 2.8rem; height: 1.4rem; cursor: pointer;" <?php echo (($referral_settings['allow_cashout'] ?? 1) == 1) ? 'checked' : ''; ?>>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4 border-top pt-3 border-white border-opacity-5">
                        <button type="submit" class="btn btn-primary px-4 py-2.5">Save Subscriber Rules</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
</div>

<script>
function copyTenantLink() {
    var copyText = document.getElementById("tenantRefLink");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    
    alert("Copied your Tenant Referral Link to clipboard:\n" + copyText.value);
}

// Auto-cap credit amount to invoice remaining
document.addEventListener('DOMContentLoaded', function() {
    var invoiceSelect = document.getElementById('creditInvoiceSelect');
    var creditInput = document.getElementById('creditAmountInput');
    
    if (invoiceSelect && creditInput) {
        invoiceSelect.addEventListener('change', function() {
            var selected = this.options[this.selectedIndex];
            var remaining = parseFloat(selected.getAttribute('data-remaining') || 0);
            var wallet = parseFloat(<?php echo $tenant_data['referral_wallet']; ?>);
            var maxCredit = Math.min(remaining, wallet);
            creditInput.value = maxCredit.toFixed(2);
            creditInput.max = maxCredit;
        });
    }
});
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
