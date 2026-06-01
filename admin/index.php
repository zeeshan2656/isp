<?php
/**
 * NetPulse Platform Owner Super Admin Dashboard Index
 * SaaS Analytics, Platform revenue MRR/ARR, Expiring tenant subscriptions
 */
require_once __DIR__ . '/layouts/header.php';

// Fetch Platform Metrics
$total_tenants = 0;
$active_tenants = 0;
$suspended_tenants = 0;
$pending_tenants = 0;
$total_platform_customers = 0;
$mrr = 0.00;

try {
    $total_tenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants")->fetchColumn();
    $active_tenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'active'")->fetchColumn();
    $suspended_tenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'")->fetchColumn();
    $pending_tenants = (int)$pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'pending'")->fetchColumn();
    
    $total_platform_customers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
    
    $mrr_val = $pdo->query("SELECT SUM(monthly_fee) FROM tenants WHERE status = 'active'")->fetchColumn();
    $mrr = $mrr_val ? (double)$mrr_val : 0.00;
    
} catch (PDOException $e) {
    error_log("Super admin dashboard metrics loading failed: " . $e->getMessage());
}

$arr = $mrr * 12;

// Load Expiring Tenant Accounts (<= 30 Days)
$expiring_tenants = [];
try {
    $stmt = $pdo->prepare("SELECT id, company_name, subdomain, subscription_end, status, monthly_fee FROM tenants WHERE status = 'active' AND subscription_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY subscription_end ASC");
    $stmt->execute();
    $expiring_tenants = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Expiring tenants load failed: " . $e->getMessage());
}

// Load Platform Revenue Invoices Log
$recent_invoices = [];
try {
    $stmt = $pdo->prepare("SELECT i.*, t.company_name FROM saas_invoices i JOIN tenants t ON i.tenant_id = t.id ORDER BY i.id DESC LIMIT 5");
    $stmt->execute();
    $recent_invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("SaaS recent invoices fetch failed: " . $e->getMessage());
}

// Load Recent Payment Proof Submissions (Top 5)
$recent_submissions = [];
try {
    $stmt = $pdo->prepare("SELECT ps.*, 
        CASE 
            WHEN ps.payer_type = 'tenant' THEN t.company_name
            WHEN ps.payer_type = 'customer' THEN c.name
        END as payer_name
    FROM payment_submissions ps
    LEFT JOIN tenants t ON ps.payer_type = 'tenant' AND ps.payer_id = t.id
    LEFT JOIN customers c ON ps.payer_type = 'customer' AND ps.payer_id = c.id
    ORDER BY ps.id DESC LIMIT 5");
    $stmt->execute();
    $recent_submissions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Recent submissions load failed: " . $e->getMessage());
}
?>

<div class="row align-items-center mb-4">
    <div class="col-sm-8">
        <h2 class="text-white mb-1"><i class="bi bi-clouds-fill text-primary me-2"></i>Supervisor Dashboard</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Monitor multi-tenant platform allocations, monthly recurring subscription revenue, and review ISP registrations.</p>
    </div>
    <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
        <a href="tenants.php" class="btn btn-primary-gradient py-2.5 px-4" style="font-size: 0.9rem;"><i class="bi bi-shield-check me-1.5"></i>Review Pending (<?php echo $pending_tenants; ?>)</a>
    </div>
</div>

<!-- Payment Verification Quick Action Alert Panel -->
<?php if ($pending_payments_count > 0): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="glass-card p-4 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3 border-warning border-opacity-25" style="border: 1px solid rgba(255, 193, 7, 0.15);">
            <div class="d-flex align-items-center gap-3">
                <div class="p-3 rounded-3 bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25">
                    <i class="bi bi-shield-exclamation fs-3"></i>
                </div>
                <div>
                    <h5 class="text-white mb-1 font-outfit">Payment Review Approvals Pending</h5>
                    <p class="text-muted mb-0" style="font-size: 0.88rem;">There are currently <strong class="text-warning"><?php echo $pending_payments_count; ?></strong> invoice payment verification proofs submitted by subscribers and tenants awaiting super admin audit.</p>
                </div>
            </div>
            <div>
                <a href="payment_verifications.php" class="btn btn-warning text-dark fw-bold px-4 py-2.5 font-outfit" style="font-size: 0.88rem;"><i class="bi bi-shield-check me-1.5"></i>Open Reviews Desk</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- SaaS Revenue Overview KPI Cards Row -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="glass-card p-4 d-flex flex-column h-100 border-primary border-opacity-10">
            <span class="text-muted mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Monthly Recurring Revenue (MRR)</span>
            <h3 class="fw-bold text-white mb-2"><?php echo format_currency($mrr); ?></h3>
            <span class="text-success" style="font-size: 0.8rem; font-weight: 500;"><i class="bi bi-graph-up-arrow me-1"></i>Active platform MRR</span>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="glass-card p-4 d-flex flex-column h-100 border-indigo border-opacity-10">
            <span class="text-muted mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Annual Recurring Revenue (ARR)</span>
            <h3 class="fw-bold text-white mb-2"><?php echo format_currency($arr); ?></h3>
            <span class="text-muted" style="font-size: 0.8rem;">Projected platform capacity basis</span>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="glass-card p-4 d-flex flex-column h-100 border-accent border-opacity-10">
            <span class="text-muted mb-1" style="font-size: 0.72rem; letter-spacing: 0.05em; font-weight: bold; text-transform: uppercase;">Subscriber Multiplier Effect</span>
            <h3 class="fw-bold text-white mb-2"><?php echo $total_platform_customers; ?></h3>
            <span class="text-muted" style="font-size: 0.8rem;">Active clients across all workspaces</span>
        </div>
    </div>
</div>

<!-- Platform Operational KPI Indicators Grid -->
<div class="row g-4 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="glass-panel p-3 d-flex align-items-center gap-3">
            <div class="p-2.5 rounded-3 bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-10">
                <i class="bi bi-building fs-4"></i>
            </div>
            <div>
                <span class="text-muted d-block" style="font-size: 0.72rem;">Total ISP Tenants</span>
                <strong class="text-white fs-5"><?php echo $total_tenants; ?></strong>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6">
        <div class="glass-panel p-3 d-flex align-items-center gap-3">
            <div class="p-2.5 rounded-3 bg-success bg-opacity-10 text-success border border-success border-opacity-10">
                <i class="bi bi-check-circle fs-4"></i>
            </div>
            <div>
                <span class="text-muted d-block" style="font-size: 0.72rem;">Active Workspace Leases</span>
                <strong class="text-white fs-5"><?php echo $active_tenants; ?></strong>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6">
        <div class="glass-panel p-3 d-flex align-items-center gap-3">
            <div class="p-2.5 rounded-3 bg-danger bg-opacity-10 text-danger border border-danger border-opacity-10">
                <i class="bi bi-exclamation-triangle fs-4"></i>
            </div>
            <div>
                <span class="text-muted d-block" style="font-size: 0.72rem;">Suspended Workspaces</span>
                <strong class="text-white fs-5"><?php echo $suspended_tenants; ?></strong>
            </div>
        </div>
    </div>
    
    <div class="col-md-3 col-sm-6">
        <div class="glass-panel p-3 d-flex align-items-center gap-3">
            <div class="p-2.5 rounded-3 bg-warning bg-opacity-10 text-warning border border-warning border-opacity-10">
                <i class="bi bi-clock-history fs-4"></i>
            </div>
            <div>
                <span class="text-muted d-block" style="font-size: 0.72rem;">Pending Requests</span>
                <strong class="text-white fs-5"><?php echo $pending_tenants; ?></strong>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Column Left: Expiring Tenant Leases Warnings -->
    <div class="col-lg-6">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-clock-history text-warning me-2"></i>Expiring Tenant Subscriptions (<= 30 Days)
            </h5>
            
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.75rem;">
                            <th>ISP Tenant</th>
                            <th>Workspace</th>
                            <th class="text-center">Due Expiry</th>
                            <th class="text-end">Monthly Fee</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php if (empty($expiring_tenants)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">All workspace subscription leases are currently safe and active.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($expiring_tenants as $t): 
                                $days = calculate_days_remaining($t['subscription_end']);
                                ?>
                                <tr>
                                    <td>
                                        <strong class="text-white"><?php echo e($t['company_name']); ?></strong>
                                    </td>
                                    <td>
                                        <code class="text-primary"><?php echo e($t['subdomain']); ?></code>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger-soft text-danger px-2.5 py-1" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); font-size: 0.72rem;">
                                            <?php echo $days; ?> Days Remaining
                                        </span>
                                    </td>
                                    <td class="text-end text-white fw-bold"><?php echo format_currency($t['monthly_fee']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Column Right: Recent Subscription Invoices Logs -->
    <div class="col-lg-6">
        <div class="glass-card p-4 h-100">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-wallet2 text-primary me-2"></i>Recent Platform Billing (Tenant Invoices)
            </h5>
            
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.75rem;">
                            <th>Invoice Number</th>
                            <th>ISP Workspace</th>
                            <th>Total Billed</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php if (empty($recent_invoices)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">No billing statements generated for platform tenants yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_invoices as $inv): 
                                $status = $inv['payment_status'] ?? 'pending';
                                $badge_color = ($status === 'paid') ? 'bg-success-soft text-success' : (($status === 'pending') ? 'bg-primary-soft text-primary' : 'bg-danger-soft text-danger');
                                ?>
                                <tr>
                                    <td>
                                        <strong class="text-white"><?php echo e($inv['invoice_number']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="text-muted d-block" style="font-size: 0.72rem;"><?php echo e($inv['company_name']); ?> &bull; Plan: <?php echo e($inv['plan_name']); ?></span>
                                    </td>
                                    <td class="text-white fw-bold"><?php echo format_currency($inv['amount']); ?></td>
                                    <td class="text-center">
                                        <span class="badge text-uppercase px-2.5 py-1 <?php echo ($inv['payment_status'] === 'paid') ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger'; ?>" style="font-size: 0.7rem; background: rgba(255,255,255,0.02);">
                                            <?php echo e($inv['payment_status']); ?>
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
</div>

<!-- Row for Recent Payment Proof Submissions -->
<div class="row g-4 mt-2 mb-4">
    <div class="col-12">
        <div class="glass-card p-4">
            <h5 class="text-white font-outfit mb-3 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                <i class="bi bi-shield-check text-primary me-2"></i>Recent Payment Proof Submissions
            </h5>
            
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle mb-0" style="background: transparent;">
                    <thead>
                        <tr class="text-muted" style="font-size: 0.75rem;">
                            <th>Date Submitted</th>
                            <th>Submitter Name</th>
                            <th>Invoice Type & Number</th>
                            <th class="text-end">Amount</th>
                            <th>Method & TxID</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody style="font-size: 0.85rem;">
                        <?php if (empty($recent_submissions)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">No payment proof submissions logged on the platform yet.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_submissions as $sub): 
                                $status = $sub['status'];
                                $status_colors = [
                                    'pending' => 'bg-warning text-warning',
                                    'approved' => 'bg-success text-success',
                                    'rejected' => 'bg-danger text-danger',
                                    'more_info' => 'bg-info text-info'
                                ];
                                ?>
                                <tr>
                                    <td><?php echo date('d M, Y H:i', strtotime($sub['created_at'])); ?></td>
                                    <td>
                                        <strong class="text-white"><?php echo e($sub['payer_name']); ?></strong>
                                        <small class="d-block text-muted" style="font-size: 0.72rem; text-transform: uppercase;">
                                            <?php echo $sub['payer_type'] === 'tenant' ? 'Tenant / ISP' : 'Customer / Subscriber'; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="text-white fw-bold"><?php echo $sub['invoice_type'] === 'saas_invoice' ? 'SaaS Plan' : 'Broadband'; ?></span>
                                        <span class="d-block text-muted" style="font-size: 0.72rem;">Ref ID: <?php echo e($sub['invoice_id']); ?></span>
                                    </td>
                                    <td class="text-end text-success fw-bold font-outfit"><?php echo format_currency($sub['amount']); ?></td>
                                    <td>
                                        <strong class="text-white"><?php echo strtoupper(e($sub['payment_method'])); ?></strong>
                                        <span class="d-block text-accent font-outfit" style="font-size: 0.72rem;"><?php echo e($sub['transaction_id'] ?: 'N/A'); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?php echo $status_colors[$status]; ?> bg-opacity-10 border-0 font-outfit text-uppercase px-2.5 py-1" style="font-size: 0.7rem;">
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <a href="payment_verifications.php?action=review&id=<?php echo $sub['id']; ?>" class="btn btn-dark-glass p-1 px-2.5 rounded text-decoration-none" style="font-size: 0.78rem;">
                                            <i class="bi bi-eye"></i> Audit
                                        </a>
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
