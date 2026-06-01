<?php
/**
 * Public Partner Portal - Withdrawal Request Desk
 */
require_once __DIR__ . '/layouts/header.php';

// Fetch min payout limit
$min_payout = 1000.00;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key = 'min_withdrawal_amount' LIMIT 1");
    $stmt->execute();
    $min_val = $stmt->fetchColumn();
    if ($min_val) $min_payout = (double)$min_val;
} catch (PDOException $e) {
    error_log("Min payout setting load fail: " . $e->getMessage());
}

$errors = [];
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $amount = (double)($_POST['amount'] ?? 0.00);
    $method = clean_input($_POST['payment_method'] ?? '');
    $details = clean_input($_POST['payment_details'] ?? '');
    
    if ($amount < $min_payout) {
        $errors[] = "Minimum payout amount is Rs. " . number_format($min_payout, 2);
    }
    if ($amount > $aff_data['wallet_balance']) {
        $errors[] = "Insufficient balance. Available wallet credits: Rs. " . number_format($aff_data['wallet_balance'], 2);
    }
    if (empty($method)) $errors[] = "Please select a payment method.";
    if (empty($details)) $errors[] = "Please enter your payment credentials/details.";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 1. Deduct from affiliate wallet balance
            $stmt = $pdo->prepare("UPDATE affiliates SET wallet_balance = wallet_balance - ? WHERE id = ?");
            $stmt->execute([$amount, $affiliate_id]);
            
            // 2. Create withdrawal request
            $stmt = $pdo->prepare("INSERT INTO withdrawal_requests (requester_type, requester_id, amount, payment_method, payment_details, status) VALUES ('affiliate', ?, ?, ?, ?, 'pending')");
            $stmt->execute([$affiliate_id, $amount, $method, $details]);
            $request_id = $pdo->lastInsertId();
            
            // 3. Create referral transaction entry
            $notes = "Payout requested via $method ($details)";
            $stmt = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('affiliate', ?, 'withdrawal', ?, ?, 'pending', ?)");
            $stmt->execute([$affiliate_id, $amount, $request_id, $notes]);
            
            $pdo->commit();
            
            set_session_alert("Payout request submitted successfully! Your funds are locked and pending review by the platform administrator.", "success");
            header("Location: withdraw.php");
            exit;
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Transaction failed: " . $e->getMessage();
        }
    }
}

// Load payout request history
$requests = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM withdrawal_requests WHERE requester_type = 'affiliate' AND requester_id = ? ORDER BY id DESC");
    $stmt->execute([$affiliate_id]);
    $requests = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Withdraw requests fetch failed: " . $e->getMessage());
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-wallet2 text-primary me-2"></i>Request Commission Payout</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Request a secure cash transfer from your available credit wallet directly into your banking or mobile wallet account.</p>
    </div>
</div>

<div class="row g-4">
    <!-- Payout Form Card -->
    <div class="col-lg-5">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-cash-stack text-primary me-2"></i>Initiate Payout request
            </h5>
            
            <div class="p-3 bg-dark rounded-3 border border-white border-opacity-5 mb-4 text-center">
                <span class="text-muted d-block" style="font-size: 0.72rem; text-transform: uppercase;">Available Cash Wallet</span>
                <strong class="text-white font-outfit fs-2">Rs. <?php echo number_format($aff_data['wallet_balance'], 2); ?></strong>
                <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">Minimum Withdrawal Threshold: Rs. <?php echo number_format($min_payout, 2); ?></small>
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
            
            <form action="withdraw.php" method="POST" class="d-flex flex-column gap-3">
                <?php csrf_field(); ?>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Payout Amount (Rs.)</label>
                    <input type="number" name="amount" class="form-control bg-dark border-0 text-white" value="<?php echo e($aff_data['wallet_balance']); ?>" required min="<?php echo $min_payout; ?>" step="0.01">
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Select Payout Gateway</label>
                    <select name="payment_method" class="form-select bg-dark border-0 text-white" required>
                        <option value="">-- Select Gateway --</option>
                        <option value="Bank Transfer">Bank Wire Transfer</option>
                        <option value="Easypaisa">Easypaisa Mobile Wallet</option>
                        <option value="JazzCash">JazzCash Mobile Wallet</option>
                    </select>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Payment Details & Credentials</label>
                    <textarea name="payment_details" class="form-control bg-dark border-0 text-white" rows="4" placeholder="Enter bank IBAN & Title, or mobile account number & account name..." required></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" <?php echo ($aff_data['wallet_balance'] < $min_payout) ? 'disabled' : ''; ?>><i class="bi bi-unlock-fill me-1.5"></i>Submit Cashout Request</button>
            </form>
        </div>
    </div>
    
    <!-- Payout Log History -->
    <div class="col-lg-7">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-journal-text text-success me-2"></i>Withdrawal & Payout History
            </h5>
            
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.75rem;">
                            <th>Request ID</th>
                            <th>Gateway</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Submitted On</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No withdrawal request slips generated yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($requests as $r): 
                                $status = $r['status'];
                                $badge_class = ($status === 'approved') ? 'bg-success-soft text-success' : (($status === 'pending') ? 'bg-primary-soft text-primary' : 'bg-danger-soft text-danger');
                                ?>
                                <tr>
                                    <td>
                                        <strong class="text-white">#<?php echo e($r['id']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="text-white d-block" style="font-size: 0.82rem;"><?php echo e($r['payment_method']); ?></span>
                                        <small class="text-muted" style="font-size: 0.7rem;"><?php echo e($r['payment_details']); ?></small>
                                    </td>
                                    <td class="text-end fw-bold text-white">
                                        Rs. <?php echo number_format($r['amount'], 2); ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge text-uppercase px-2.5 py-1 <?php echo $badge_class; ?>" style="font-size: 0.7rem; background: rgba(255,255,255,0.02);">
                                            <?php echo e($status); ?>
                                        </span>
                                    </td>
                                    <td class="text-center text-muted" style="font-size: 0.75rem;">
                                        <?php echo date('d M, Y', strtotime($r['created_at'])); ?>
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

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
