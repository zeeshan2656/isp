<?php
/**
 * Customer Self-Service Portal - Referral Rewards & Credits Hub
 * Enhanced with detailed wallet stats, allow_cashout, and max_withdrawal support.
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

// Ensure customer is logged in (header.php may also do this, but we need data before UI)
if (!isset($_SESSION['customer_id'])) {
    header("Location: ../customer-login.php");
    exit;
}
$customer_id = $_SESSION['customer_id'];

// Load Customer Referral Data
$cust_data = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? LIMIT 1");
    $stmt->execute([$customer_id]);
    $cust_data = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Load customer referrals fail: " . $e->getMessage());
}

// Load ISP Referral Settings
$ref_settings = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tenant_referral_settings WHERE tenant_id = ? LIMIT 1");
    $stmt->execute([$cust_data['tenant_id']]);
    $ref_settings = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Load tenant settings fail: " . $e->getMessage());
}

$errors = [];
$success = "";

// Handle Customer Excess Cashout Payout Request (only if cashouts are allowed)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_customer_cashout'])) {
    verify_csrf_token();
    
    // Check if cashout is allowed
    $allow_cashout = (int)($ref_settings['allow_cashout'] ?? 1);
    if (!$allow_cashout) {
        $errors[] = "Cash withdrawals are currently disabled by your ISP provider. Your wallet credits will be auto-applied to your next bill.";
    }
    
    $amount = (double)($_POST['amount'] ?? 0.00);
    $method = clean_input($_POST['payment_method'] ?? '');
    $details = clean_input($_POST['payment_details'] ?? '');
    $min_withdraw = (double)$ref_settings['min_withdrawal_amount'];
    $max_withdraw = (double)($ref_settings['max_withdrawal_amount'] ?? 0.00);
    
    if ($amount < $min_withdraw) {
        $errors[] = "Minimum withdrawal request amount is Rs. " . number_format($min_withdraw, 2);
    }
    if ($max_withdraw > 0 && $amount > $max_withdraw) {
        $errors[] = "Maximum withdrawal limit is Rs. " . number_format($max_withdraw, 2) . " per request.";
    }
    if ($amount > $cust_data['referral_wallet']) {
        $errors[] = "Insufficient balance. Available wallet credits: Rs. " . number_format($cust_data['referral_wallet'], 2);
    }
    if (empty($method)) $errors[] = "Please specify a payment withdrawal gateway.";
    if (empty($details)) $errors[] = "Please provide your payout account credentials.";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 1. Deduct from customer wallet
            $stmt = $pdo->prepare("UPDATE customers SET referral_wallet = referral_wallet - ? WHERE id = ?");
            $stmt->execute([$amount, $customer_id]);
            
            // 2. Insert into withdrawal requests
            $stmt = $pdo->prepare("INSERT INTO withdrawal_requests (requester_type, requester_id, amount, payment_method, payment_details, status) VALUES ('customer', ?, ?, ?, ?, 'pending')");
            $stmt->execute([$customer_id, $amount, $method, $details]);
            $request_id = $pdo->lastInsertId();
            
            // 3. Insert transaction log
            $notes = "Broadband credit cashout requested via $method ($details)";
            $stmt = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('customer', ?, 'withdrawal', ?, ?, 'pending', ?)");
            $stmt->execute([$customer_id, $amount, $request_id, $notes]);
            
            $pdo->commit();
            
            set_session_alert("Payout request submitted! Your ISP Administrator will review and settle the credit payout shortly.", "success");
            header("Location: referrals.php");
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Payout transaction failed: " . $e->getMessage();
        }
    }
}

// Referred customer counts
$total_friends = 0;
$active_friends = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE referred_by_id = ?");
    $stmt->execute([$customer_id]);
    $total_friends = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE referred_by_id = ? AND status = 'active'");
    $stmt->execute([$customer_id]);
    $active_friends = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Subscriber referrers load fail: " . $e->getMessage());
}

// Enhanced stats
$total_withdrawals = 0.00;
$credits_used = 0.00;
$pending_earnings = 0.00;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM withdrawal_requests WHERE requester_type = 'customer' AND requester_id = ? AND status IN ('approved', 'completed')");
    $stmt->execute([$customer_id]);
    $total_withdrawals = (double)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_transactions WHERE referrer_type = 'customer' AND referrer_id = ? AND transaction_type = 'invoice_deduction' AND status = 'approved'");
    $stmt->execute([$customer_id]);
    $credits_used = (double)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_transactions WHERE referrer_type = 'customer' AND referrer_id = ? AND transaction_type = 'commission_credit' AND status = 'pending'");
    $stmt->execute([$customer_id]);
    $pending_earnings = (double)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Customer enhanced stats fail: " . $e->getMessage());
}

// Load billing transactions history
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM referral_transactions WHERE referrer_type = 'customer' AND referrer_id = ? ORDER BY id DESC LIMIT 15");
    $stmt->execute([$customer_id]);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Referral transaction select fail: " . $e->getMessage());
}

// Generate referral link
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
$script = $_SERVER['SCRIPT_NAME'];
$projectDir = substr($script, 0, strpos($script, '/customer/'));
$referral_url = $protocol . $domainName . $projectDir . '/customer-register.php?ref=' . $cust_data['referral_code'];

// Check if cashouts are allowed
$allow_cashout = (int)($ref_settings['allow_cashout'] ?? 1);
$max_withdraw = (double)($ref_settings['max_withdrawal_amount'] ?? 0.00);

// Now include header — all redirects are complete
require_once __DIR__ . '/layouts/header.php';

// Block access if referrals are disabled by the ISP
if (!$ref_settings || (int)$ref_settings['enabled'] !== 1) {
    echo "<div class='alert alert-danger text-center p-5'><i class='bi bi-shield-slash fs-2 d-block mb-3'></i>The Referral Program has been disabled by your ISP provider.</div>";
    require_once __DIR__ . '/layouts/footer.php';
    exit;
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-gift text-primary me-2"></i>Referral Rewards Program</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Invite your friends to register. Earn credits in your reward wallet that automatically reduce your monthly internet bill!</p>
    </div>
</div>

<!-- Referral Link Sharing Card -->
<div class="glass-card p-4 mb-4" style="border-color: rgba(168, 85, 247, 0.15) !important;">
    <h5 class="text-white font-outfit mb-2"><i class="bi bi-share text-primary me-1.5"></i>Invite Friends & Neighbors</h5>
    <p class="text-muted mb-3" style="font-size: 0.85rem;">
        When a friend signs up using your unique link and settles their first broadband invoice, you will automatically receive a 
        <strong>
            <?php 
            if ($ref_settings['reward_type'] === 'fixed') {
                echo "Rs. " . number_format($ref_settings['reward_value'], 0) . " cash credit";
            } else {
                echo $ref_settings['reward_value'] . "% package billing reward";
            }
            ?>
        </strong> in your reward wallet!
    </p>
    <div class="row g-2">
        <div class="col-md-9">
            <div class="input-group">
                <span class="input-group-text border-0 text-muted" style="background: rgba(255,255,255,0.03);"><i class="bi bi-link-45deg"></i></span>
                <input type="text" class="form-control bg-dark border-0 text-white font-monospace" style="font-size: 0.85rem;" value="<?php echo e($referral_url); ?>" id="refLink" readonly>
                <button class="btn btn-primary px-3" onclick="copyRefLink()" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); border: 0;"><i class="bi bi-clipboard me-1"></i>Copy Invite Link</button>
            </div>
        </div>
        <div class="col-md-3">
            <div class="p-2 text-center rounded bg-dark border border-white border-opacity-5">
                <span class="text-muted d-block" style="font-size: 0.65rem; text-transform: uppercase;">Referral Code</span>
                <strong class="text-primary" style="font-size: 0.95rem; font-family: monospace;"><?php echo e($cust_data['referral_code']); ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Metrics Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="glass-card p-3.5 d-flex flex-column h-100" style="border-color: rgba(168, 85, 247, 0.1) !important;">
            <span class="text-muted mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Reward Wallet Balance</span>
            <h3 class="fw-bold text-white mb-2">Rs. <?php echo number_format($cust_data['referral_wallet'], 2); ?></h3>
            <span class="text-success" style="font-size: 0.78rem; font-weight: 500;"><i class="bi bi-info-circle me-1"></i>Auto-deducts from next bill</span>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="glass-card p-3.5 d-flex flex-column h-100" style="border-color: rgba(168, 85, 247, 0.1) !important;">
            <span class="text-muted mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Lifetime Referral Rewards</span>
            <h3 class="fw-bold text-white mb-2">Rs. <?php echo number_format($cust_data['lifetime_referral_earnings'], 2); ?></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Cumulative credits earned</span>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="glass-card p-3.5 d-flex flex-column h-100" style="border-color: rgba(168, 85, 247, 0.1) !important;">
            <span class="text-muted mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Referred Friends</span>
            <h3 class="fw-bold text-white mb-2"><?php echo $active_friends; ?> <span class="text-muted" style="font-size: 1.1rem; font-weight: normal;">/ <?php echo $total_friends; ?> active</span></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Joined through your invite link</span>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="glass-card p-3.5 d-flex flex-column h-100" style="border-color: rgba(168, 85, 247, 0.1) !important;">
            <span class="text-muted mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Pending Earnings</span>
            <h3 class="fw-bold text-warning mb-2">Rs. <?php echo number_format($pending_earnings, 2); ?></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Awaiting processing</span>
        </div>
    </div>
</div>

<!-- Second stats row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-4">
        <div class="glass-card p-3 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
            <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Billing Credits Used</span>
            <strong class="text-info fs-5 font-outfit"><?php echo format_currency($credits_used); ?></strong>
            <small class="text-muted d-block" style="font-size: 0.68rem;">Applied to invoices</small>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="glass-card p-3 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
            <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Total Withdrawals</span>
            <strong class="text-light fs-5 font-outfit"><?php echo format_currency($total_withdrawals); ?></strong>
            <small class="text-muted d-block" style="font-size: 0.68rem;">Cashouts disbursed</small>
        </div>
    </div>
    <div class="col-sm-6 col-lg-4">
        <div class="glass-card p-3 text-center" style="border-color: rgba(168, 85, 247, 0.1) !important;">
            <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Available for Cashout</span>
            <strong class="<?php echo $allow_cashout ? 'text-success' : 'text-danger'; ?> fs-5 font-outfit">
                <?php echo $allow_cashout ? format_currency($cust_data['referral_wallet']) : 'Disabled'; ?>
            </strong>
            <small class="text-muted d-block" style="font-size: 0.68rem;"><?php echo $allow_cashout ? 'Ready to withdraw' : 'Cashouts disabled by ISP'; ?></small>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Payout Request or Details -->
    <div class="col-lg-5">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-wallet2 text-primary me-2"></i>Request Credit Cashout
            </h5>
            
            <?php if (!$allow_cashout): ?>
                <div class="text-center py-4">
                    <i class="bi bi-shield-slash text-danger fs-2 d-block mb-2"></i>
                    <p class="text-muted" style="font-size: 0.85rem;">Cash withdrawals are currently <strong class="text-danger">disabled</strong> by your ISP provider. Your wallet credits will automatically reduce your monthly broadband bill.</p>
                </div>
            <?php else: ?>
                <p class="text-muted" style="font-size: 0.82rem; line-height: 1.6;">
                    If your reward wallet exceeds your monthly package price, you may optionally request to withdraw available excess cash directly to Easypaisa, JazzCash, or bank wire options.
                </p>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger border-0 rounded-lg p-3 mb-3" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.88rem;">
                        <ul class="mb-0 ps-3">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo e($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <form action="referrals.php" method="POST" class="d-flex flex-column gap-3 mt-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="request_customer_cashout" value="1">
                    
                    <div>
                        <label class="form-label text-muted" style="font-size: 0.78rem;">Withdrawal Payout Amount (Rs.)</label>
                        <input type="number" name="amount" class="form-control" value="<?php echo e($cust_data['referral_wallet']); ?>" required min="<?php echo $ref_settings['min_withdrawal_amount']; ?>" <?php echo ($max_withdraw > 0) ? 'max="' . $max_withdraw . '"' : ''; ?> step="0.01">
                        <small class="text-muted" style="font-size: 0.7rem;">
                            Min: Rs. <?php echo number_format($ref_settings['min_withdrawal_amount'], 2); ?>
                            <?php if ($max_withdraw > 0): ?>
                                | Max: Rs. <?php echo number_format($max_withdraw, 2); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    
                    <div>
                        <label class="form-label text-muted" style="font-size: 0.78rem;">Cashout Gateway</label>
                        <select name="payment_method" class="form-select" required>
                            <option value="">-- Select Gateway --</option>
                            <option value="Easypaisa">Easypaisa</option>
                            <option value="JazzCash">JazzCash</option>
                            <option value="Bank Account">Bank Wire Transfer</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="form-label text-muted" style="font-size: 0.78rem;">Gateway Credentials & Name</label>
                        <textarea name="payment_details" class="form-control" rows="3" placeholder="Provide Account number/IBAN & title title..." required></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); border: 0;" <?php echo ($cust_data['referral_wallet'] < (double)$ref_settings['min_withdrawal_amount']) ? 'disabled' : ''; ?>><i class="bi bi-wallet2 me-1.5"></i>Submit Cashout Slips</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Ledger -->
    <div class="col-lg-7">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-clock-history text-success me-2"></i>Rewards & Credit Adjustments Ledger
            </h5>
            
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.75rem;">
                            <th>Transaction</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Applied On</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No reward credits logged on your account statements yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): 
                                $type_label = ($tx['transaction_type'] === 'commission_credit') ? 'Referral Credit Received' : (($tx['transaction_type'] === 'invoice_deduction') ? 'Invoice Billing Applied' : 'Wallet Withdrawal Payout');
                                $type_color = ($tx['transaction_type'] === 'commission_credit') ? 'text-success' : (($tx['transaction_type'] === 'invoice_deduction') ? 'text-info' : 'text-danger');
                                $prefix = ($tx['transaction_type'] === 'commission_credit') ? '+' : '-';
                                ?>
                                <tr>
                                    <td>
                                        <strong class="text-white d-block" style="font-size: 0.82rem;"><?php echo $type_label; ?></strong>
                                        <small class="text-muted" style="font-size: 0.7rem;"><?php echo e($tx['notes']); ?></small>
                                    </td>
                                    <td class="text-end fw-bold <?php echo $type_color; ?>">
                                        <?php echo $prefix; ?> Rs. <?php echo number_format($tx['amount'], 2); ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge text-uppercase" style="font-size: 0.68rem; background: rgba(255,255,255,0.02);">
                                            <?php echo e($tx['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-muted" style="font-size: 0.75rem;">
                                        <?php echo date('d M, Y', strtotime($tx['created_at'])); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function copyRefLink() {
    var copyText = document.getElementById("refLink");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);
    alert("Invite Link Copied Successfully: " + copyText.value);
}
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
