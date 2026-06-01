<?php
/**
 * Public Affiliate Partner Portal - Analytics Dashboard & Earnings Ledger
 */
require_once __DIR__ . '/layouts/header.php';

// Fetch dynamic affiliate settings & commission
$comm_pct = 20.00;
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key = 'affiliate_commission_percentage' LIMIT 1");
    $stmt->execute();
    $pct_val = $stmt->fetchColumn();
    if ($pct_val) $comm_pct = (double)$pct_val;
} catch (PDOException $e) {
    error_log("SaaS settings load fail: " . $e->getMessage());
}

// 1. Referred Tenants Counts
$total_referrals = 0;
$active_referrals = 0;
$projected_mrr = 0.00;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE referred_by_type = 'affiliate' AND referred_by_id = ?");
    $stmt->execute([$affiliate_id]);
    $total_referrals = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tenants WHERE referred_by_type = 'affiliate' AND referred_by_id = ? AND status = 'active'");
    $stmt->execute([$affiliate_id]);
    $active_referrals = (int)$stmt->fetchColumn();

    // Projected Monthly Commission MRR: Sum of Monthly SaaS Fee * commission rate for active referrers
    $stmt = $pdo->prepare("SELECT SUM(monthly_fee) FROM tenants WHERE referred_by_type = 'affiliate' AND referred_by_id = ? AND status = 'active'");
    $stmt->execute([$affiliate_id]);
    $total_fee = $stmt->fetchColumn();
    $projected_mrr = $total_fee ? (double)$total_fee * ($comm_pct / 100) : 0.00;

} catch (PDOException $e) {
    error_log("Referred counts load failed: " . $e->getMessage());
}

// 2. Fetch list of referred tenants
$referred_tenants = [];
try {
    $stmt = $pdo->prepare("SELECT t.*, p.name as plan_name FROM tenants t LEFT JOIN saas_plans p ON t.plan_id = p.id WHERE t.referred_by_type = 'affiliate' AND t.referred_by_id = ? ORDER BY t.id DESC");
    $stmt->execute([$affiliate_id]);
    $referred_tenants = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Referred list load failed: " . $e->getMessage());
}

// 3. Fetch recent earnings & payout transactions ledger
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM referral_transactions WHERE referrer_type = 'affiliate' AND referrer_id = ? ORDER BY id DESC LIMIT 10");
    $stmt->execute([$affiliate_id]);
    $transactions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Referral ledger load failed: " . $e->getMessage());
}

// Dynamic Affiliate Referral Link calculation
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
// Get the directory containing the project
$script = $_SERVER['SCRIPT_NAME'];
$projectDir = substr($script, 0, strpos($script, '/affiliates/'));
$referral_url = $protocol . $domainName . $projectDir . '/register.php?ref=' . $aff_data['referral_code'];
?>

<div class="row align-items-center mb-4">
    <div class="col-sm-8">
        <h2 class="text-white mb-1"><i class="bi bi-speedometer2 text-primary me-2"></i>Affiliate Dashboard</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Track clicks, monitor active SaaS client conversions, and review your recurring monthly earnings ledger.</p>
    </div>
    <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
        <a href="withdraw.php" class="btn btn-primary-gradient py-2.5 px-4" style="font-size: 0.9rem;"><i class="bi bi-cash-stack me-1.5"></i>Request Payout</a>
    </div>
</div>

<!-- Referral Link Card -->
<div class="glass-card p-4 mb-4 border-primary border-opacity-10">
    <h5 class="text-white font-outfit mb-2"><i class="bi bi-link-45deg text-primary me-1.5"></i>Your Partner Referral Assets</h5>
    <p class="text-muted mb-3" style="font-size: 0.85rem;">Share your custom referral link. When an ISP registers and completes their first payment, your commissions start automatically.</p>
    <div class="row g-2">
        <div class="col-md-9">
            <div class="input-group">
                <span class="input-group-text border-0 text-muted" style="background: rgba(255,255,255,0.03);"><i class="bi bi-globe"></i></span>
                <input type="text" class="form-control bg-dark border-0 text-white font-monospace" style="font-size: 0.85rem;" value="<?php echo e($referral_url); ?>" id="refLink" readonly>
                <button class="btn btn-primary px-3" onclick="copyRefLink()"><i class="bi bi-clipboard me-1"></i>Copy Link</button>
            </div>
        </div>
        <div class="col-md-3">
            <div class="p-2 text-center rounded bg-dark border border-white border-opacity-5">
                <span class="text-muted d-block" style="font-size: 0.65rem; text-transform: uppercase;">Partner Code</span>
                <strong class="text-primary" style="font-size: 0.95rem; font-family: monospace;"><?php echo e($aff_data['referral_code']); ?></strong>
            </div>
        </div>
    </div>
</div>

<!-- Metrics Overview -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="glass-card p-4 d-flex flex-column h-100 border-primary border-opacity-10">
            <span class="text-muted mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Available Balance</span>
            <h3 class="fw-bold text-white mb-2">Rs. <?php echo number_format($aff_data['wallet_balance'], 2); ?></h3>
            <span class="text-success" style="font-size: 0.78rem; font-weight: 500;"><i class="bi bi-piggy-bank me-1"></i>Ready for withdrawal</span>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="glass-card p-4 d-flex flex-column h-100 border-indigo border-opacity-10">
            <span class="text-muted mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Lifetime Earnings</span>
            <h3 class="fw-bold text-white mb-2">Rs. <?php echo number_format($aff_data['lifetime_earnings'], 2); ?></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Cumulative payout commissions</span>
        </div>
    </div>
    
    <div class="col-md-3">
        <div class="glass-card p-4 d-flex flex-column h-100 border-accent border-opacity-10">
            <span class="text-muted mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Projected MRR Earnings</span>
            <h3 class="fw-bold text-white mb-2">Rs. <?php echo number_format($projected_mrr, 2); ?></h3>
            <span class="text-primary" style="font-size: 0.78rem; font-weight: 500;"><i class="bi bi-arrow-repeat me-1"></i><?php echo e($comm_pct); ?>% recurring commission</span>
        </div>
    </div>

    <div class="col-md-3">
        <div class="glass-card p-4 d-flex flex-column h-100 border-white border-opacity-10">
            <span class="text-muted mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Active Clients</span>
            <h3 class="fw-bold text-white mb-2"><?php echo $active_referrals; ?> <span class="text-muted" style="font-size: 1rem; font-weight: normal;">/ <?php echo $total_referrals; ?></span></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Referredpaying ISP accounts</span>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Referred Tenants list -->
    <div class="col-lg-7">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-people text-primary me-2"></i>Referred ISP Workspaces List
            </h5>
            
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.75rem;">
                            <th>ISP Workspace</th>
                            <th>Dynamic Slug</th>
                            <th>Active Plan</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php if (empty($referred_tenants)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No ISP workspaces have registered through your partner link yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($referred_tenants as $t): ?>
                                <tr>
                                    <td>
                                        <strong class="text-white"><?php echo e($t['company_name']); ?></strong>
                                    </td>
                                    <td>
                                        <code class="text-primary"><?php echo e($t['subdomain']); ?></code>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary" style="font-size: 0.72rem; border: 1px solid rgba(168,85,247,0.2);"><?php echo e($t['plan_name']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge text-uppercase <?php echo ($t['status'] === 'active') ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'; ?>" style="font-size: 0.7rem; background: rgba(255,255,255,0.02);">
                                            <?php echo e($t['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Earnings Ledger -->
    <div class="col-lg-5">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-journal-text text-success me-2"></i>Recent Earnings & Payouts Ledger
            </h5>
            
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.75rem;">
                            <th>Transaction</th>
                            <th class="text-end">Amount</th>
                            <th class="text-center">Date</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="3" class="text-center text-muted py-4">No commissions or payouts logged on your account yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): 
                                $type_label = ($tx['transaction_type'] === 'commission_credit') ? 'Commission Credit' : 'Withdrawal Request';
                                $type_color = ($tx['transaction_type'] === 'commission_credit') ? 'text-success' : 'text-danger';
                                $amount_prefix = ($tx['transaction_type'] === 'commission_credit') ? '+' : '-';
                                ?>
                                <tr>
                                    <td>
                                        <strong class="text-white d-block" style="font-size: 0.82rem;"><?php echo $type_label; ?></strong>
                                        <small class="text-muted" style="font-size: 0.7rem;"><?php echo e($tx['notes']); ?></small>
                                    </td>
                                    <td class="text-end fw-bold <?php echo $type_color; ?>">
                                        <?php echo $amount_prefix; ?> Rs. <?php echo number_format($tx['amount'], 2); ?>
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
    alert("Referred Partner Link Copied: " + copyText.value);
}
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
