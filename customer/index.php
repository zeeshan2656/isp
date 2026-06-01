<?php
/**
 * Customer Self-Service Dashboard Home
 */
require_once __DIR__ . '/layouts/header.php';

$cust = null;
$recent_invoices = [];
$inbox_notifications = [];

try {
    // 1. Fetch complete customer profile & assigned package details
    $stmt = $pdo->prepare("SELECT c.*, p.name as package_name, p.speed_mbps, p.monthly_price as package_base_price, z.name as zone_name 
        FROM customers c 
        LEFT JOIN packages p ON c.assigned_package_id = p.id 
        LEFT JOIN zones z ON c.zone_id = z.id
        WHERE c.id = ? AND c.tenant_id = ? LIMIT 1");
    $stmt->execute([$customer_id, $customer_tenant_id]);
    $cust = $stmt->fetch();
    
    if (!$cust) {
        set_session_alert("Unable to verify portal profile.", "error");
        header("Location: ../logout.php");
        exit;
    }
    
    // 2. Fetch recent invoices (limit 5)
    $stmt = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$customer_id, $customer_tenant_id]);
    $recent_invoices = $stmt->fetchAll();
    
    // 3. Fetch notifications (limit 5)
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE customer_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 5");
    $stmt->execute([$customer_id, $customer_tenant_id]);
    $inbox_notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Customer dashboard data fail: " . $e->getMessage());
}

$cfg = get_expiry_alert_config($cust['expiry_date']);
?>

<div class="row align-items-center mb-4">
    <div class="col-md-8">
        <h2 class="text-white mb-1">Hello, <span class="text-gradient-purple fw-bold" style="background: linear-gradient(135deg, var(--secondary), var(--accent)); -webkit-background-clip: text;"><?php echo e($customer_name); ?></span></h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Review your internet speeds, subscription due dates, and invoices status.</p>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <span class="badge px-3 py-2 border rounded-pill" style="background: rgba(255,255,255,0.02); border-color: rgba(168, 85, 247, 0.15); font-size: 0.85rem;">
            <i class="bi bi-person text-secondary me-2"></i> Client Desk
        </span>
    </div>
</div>

<?php if ($customer_status_lock !== 'active'): ?>
    <!-- Display Dynamic Restricted View -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-danger border-0 rounded-4 p-4 mb-4" style="background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.15) !important;">
                <div class="d-flex align-items-center gap-3">
                    <div class="p-3 bg-danger bg-opacity-10 rounded-3 text-danger border border-danger border-opacity-10 animate-pulse">
                        <i class="bi bi-shield-lock-fill fs-2"></i>
                    </div>
                    <div>
                        <?php if ($customer_status_lock === 'expired'): ?>
                            <h5 class="fw-bold text-white mb-1">Broadband Lease Expired</h5>
                            <p class="text-muted mb-0" style="font-size: 0.9rem;">Your active billing subscription period has ended. Please pay the outstanding renewal dues below to restore your broadband connection speeds (currently throttled to 0 Mbps).</p>
                        <?php else: ?>
                            <h5 class="fw-bold text-white mb-1">Broadband Pending Payment Activation</h5>
                            <p class="text-muted mb-0" style="font-size: 0.9rem;">Your account is approved but pending payment. Please submit payment proof for your outstanding invoice below to activate your internet speed.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Restricted Layout: Outstanding Dues & Ledger ONLY -->
    <div class="row g-4 mb-4">
        <!-- Outstanding Billing & Renewal Proof Form -->
        <div class="col-lg-7">
            <div class="p-4 glass-card h-100">
                <h5 class="text-white mb-3"><i class="bi bi-file-earmark-text text-secondary me-2"></i>Pending Renewal Invoices</h5>
                
                <?php
                // Fetch pending invoices
                $stmt_pending = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = ? AND tenant_id = ? AND payment_status != 'paid' ORDER BY id DESC");
                $stmt_pending->execute([$customer_id, $customer_tenant_id]);
                $pending_invoices = $stmt_pending->fetchAll();
                
                if (empty($pending_invoices)):
                ?>
                    <div class="p-4 text-center border border-dashed rounded-3 text-muted" style="background: rgba(255,255,255,0.01); border-color: var(--border-color); font-size: 0.88rem;">
                        <i class="bi bi-check-circle text-success fs-3 mb-2 d-block"></i>
                        No outstanding invoices found. If you recently paid, please wait for your ISP operator to review and verify your payment proof.
                    </div>
                <?php else: ?>
                    <div class="table-responsive-glass mb-4">
                        <table class="table table-glass align-middle">
                            <thead style="font-size: 0.8rem;">
                                <tr>
                                    <th>Inv Number</th>
                                    <th>Mbps Plan</th>
                                    <th style="text-align: right;">Amount Due</th>
                                    <th style="text-align: center;">Due Date</th>
                                    <th style="text-align: center;">Proof</th>
                                </tr>
                            </thead>
                            <tbody style="font-size: 0.85rem;">
                                <?php foreach ($pending_invoices as $inv): ?>
                                    <tr>
                                        <td class="fw-bold text-white"><?php echo e($inv['invoice_number']); ?></td>
                                        <td><?php echo e($inv['package_name']); ?></td>
                                        <td class="text-end fw-bold text-white"><?php echo format_currency($inv['total_amount']); ?></td>
                                        <td class="text-center text-muted"><?php echo format_date($inv['due_date']); ?></td>
                                        <td class="text-center">
                                            <?php if ($inv['proof_submitted'] == 1): ?>
                                                <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">Submitted</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Submit Renewal Proof Card -->
                    <div class="p-3 bg-dark bg-opacity-50 rounded-3 border" style="border-color: rgba(255,255,255,0.05);">
                        <h6 class="text-white font-outfit mb-2"><i class="bi bi-send text-secondary me-1.5"></i>Submit Payment / Renewal Proof</h6>
                        <p class="text-muted mb-3" style="font-size: 0.78rem;">Submit your transaction details after transferring the subscription amount (Bank/EasyPaisa/JazzCash).</p>
                        
                        <form action="payments.php" method="POST" class="d-flex flex-column gap-2.5">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="submit_broadband_proof" value="1">
                            
                            <div>
                                <label class="form-label text-muted mb-1" style="font-size: 0.72rem;">Select Invoice</label>
                                <select name="invoice_id" class="form-select" style="font-size: 0.82rem;" required>
                                    <?php foreach ($pending_invoices as $inv): ?>
                                        <option value="<?php echo $inv['id']; ?>" <?php echo ($inv['proof_submitted'] == 1) ? 'disabled' : ''; ?>>
                                            <?php echo e($inv['invoice_number']); ?> (Rs. <?php echo number_format($inv['total_amount'], 2); ?> - <?php echo e($inv['package_name']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label text-muted mb-1" style="font-size: 0.72rem;">Payment Method</label>
                                    <select name="payment_method" class="form-select" style="font-size: 0.82rem;" required>
                                        <option value="HBL Bank Transfer">HBL Bank Transfer</option>
                                        <option value="EasyPaisa Mobile Wallet">EasyPaisa Mobile Wallet</option>
                                        <option value="JazzCash Mobile Wallet">JazzCash Mobile Wallet</option>
                                        <option value="Other Method">Other Method</option>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label text-muted mb-1" style="font-size: 0.72rem;">Transaction Reference ID</label>
                                    <input type="text" name="transaction_id" class="form-control" placeholder="TXN7788123" style="font-size: 0.82rem;" required>
                                </div>
                            </div>
                            
                            <div>
                                <label class="form-label text-muted mb-1" style="font-size: 0.72rem;">Notes / Remarks</label>
                                <textarea name="submission_notes" class="form-control" rows="1.5" placeholder="Date of deposit, payer name..." style="font-size: 0.82rem;"></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary-gradient w-100 py-2 mt-1.5" style="font-family: 'Outfit'; font-size: 0.88rem;">
                                Submit Verification Proof
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Billing History & Helpline Column -->
        <div class="col-lg-5">
            <div class="p-4 glass-card h-100 d-flex flex-column justify-content-between">
                <div>
                    <h5 class="text-white mb-3"><i class="bi bi-clock-history text-secondary me-2"></i>Recent Payments Ledger</h5>
                    <div class="table-responsive-glass">
                        <table class="table table-glass align-middle mb-0">
                            <thead style="font-size: 0.8rem;">
                                <tr>
                                    <th>Inv Number</th>
                                    <th style="text-align: right;">Amount</th>
                                    <th style="text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody style="font-size: 0.82rem;">
                                <?php if (empty($recent_invoices)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center py-4 text-muted">No transaction log.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recent_invoices as $inv): ?>
                                        <tr>
                                            <td class="fw-bold text-white"><?php echo e($inv['invoice_number']); ?></td>
                                            <td class="text-end text-white"><?php echo format_currency($inv['total_amount']); ?></td>
                                            <td class="text-center"><?php echo get_invoice_status_badge($inv['payment_status']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 p-3 bg-dark rounded-3 border" style="border-color: var(--border-color); font-size: 0.85rem;">
                    <span class="text-muted d-block" style="font-size: 0.72rem;">Internet Service Provider Helpline</span>
                    <strong class="text-white d-block mb-1"><?php echo e($isp_name); ?></strong>
                    <div class="text-muted"><i class="bi bi-telephone text-accent me-1.5"></i>+92 300 1234567</div>
                    <div class="text-muted"><i class="bi bi-envelope text-accent me-1.5"></i>support@<?php echo e($customer_tenant_id); ?>-isp.net</div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Main Row: Client Overview Cards -->
    <div class="row g-4 mb-4">
        <!-- Active Subscription Details Card -->
        <div class="col-lg-8">
            <div class="p-4 p-md-5 glass-panel h-100" style="border-color: rgba(168, 85, 247, 0.12) !important;">
                <?php
                $display_speed = format_bandwidth($cust['speed_mbps']);
                $display_status_label = 'ACTIVE SUBSCRIPTION';
                $display_days = max(0, $cfg['days']);
                $display_badge_text = $cfg['label'];
                $display_badge_bg = $cfg['bg'];
                $display_badge_color = $cfg['text'];
                $display_circle_color = $cfg['bg'];
                ?>
                <div class="row gy-4 align-items-center">
                    <div class="col-sm-7">
                        <span class="badge bg-secondary-soft text-light px-3 py-1.5 rounded-pill mb-3" style="background: rgba(168,85,247,0.15); border: 1px solid rgba(168,85,247,0.25);"><?php echo $display_status_label; ?></span>
                        <h3 class="text-white mb-2 fs-2 fw-bold font-outfit"><?php echo e($cust['package_name']); ?></h3>
                        
                        <div class="d-flex align-items-center gap-3 my-3">
                            <div class="d-flex align-items-center gap-2 text-white">
                                <i class="bi bi-lightning-charge text-secondary fs-4"></i>
                                <div>
                                    <span class="text-muted d-block" style="font-size: 0.72rem;">Speed</span>
                                    <strong style="font-size: 1rem;"><?php echo $display_speed; ?></strong>
                                </div>
                            </div>
                            <div class="border-start border-white border-opacity-10 py-3"></div>
                            <div class="d-flex align-items-center gap-2 text-white">
                                <i class="bi bi-credit-card text-secondary fs-4"></i>
                                <div>
                                    <span class="text-muted d-block" style="font-size: 0.72rem;">Monthly Charge</span>
                                    <strong style="font-size: 1rem;"><?php echo format_currency($cust['monthly_fee']); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex flex-column gap-1 text-muted" style="font-size: 0.85rem;">
                            <div><i class="bi bi-calendar2-check text-secondary me-2"></i>Activation: <strong class="text-white"><?php echo format_date($cust['activation_date']); ?></strong></div>
                            <div><i class="bi bi-calendar-x text-secondary me-2"></i>Expires on: <strong class="text-white"><?php echo format_date($cust['expiry_date']); ?></strong></div>
                            <div><i class="bi bi-router text-secondary me-2"></i>Interface: <span class="text-white"><?php echo e($cust['connection_type']); ?> (Area: <?php echo e($cust['area']); ?>)</span></div>
                        </div>
                    </div>
                    
                    <!-- Countdown Visual Circular Badge -->
                    <div class="col-sm-5 text-center">
                        <div class="d-inline-flex flex-column align-items-center justify-content-center rounded-circle p-4 border shadow" style="width: 175px; height: 175px; background: rgba(8,11,17,0.7); border-color: <?php echo $display_circle_color; ?> !important; box-shadow: 0 0 20px <?php echo $display_circle_color; ?>40 !important;">
                            <span class="text-muted" style="font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em;">Days Remaining</span>
                            <h2 class="fw-extrabold text-white my-1 font-outfit" style="font-size: 2rem;"><?php echo $display_days; ?></h2>
                            <span class="badge" style="background: <?php echo $display_badge_bg; ?>; color: <?php echo $display_badge_color; ?>; font-size: 0.68rem; border-radius: 4px; padding: 0.35em 0.7em;">
                                <?php echo $display_badge_text; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ISP Support Contact Card -->
        <div class="col-lg-4">
            <div class="p-4 glass-card h-100 d-flex flex-column" style="border-color: rgba(6, 182, 212, 0.1) !important;">
                <h5 class="text-white mb-1"><i class="bi bi-headset text-accent me-2"></i>ISP Support Desk</h5>
                <p class="text-muted mb-4" style="font-size: 0.85rem;">Contact your internet provider for renewals or tech support.</p>
                
                <div class="d-flex flex-column gap-3 p-3 bg-dark rounded-3 border" style="border-color: var(--border-color); font-size: 0.9rem;">
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.72rem;">Service Provider Name</span>
                        <strong class="text-white"><?php echo e($isp_name); ?></strong>
                    </div>
                    <div class="d-flex align-items-center gap-2.5">
                        <i class="bi bi-envelope text-accent fs-5"></i>
                        <div>
                            <span class="text-muted d-block" style="font-size: 0.72rem;">Tech Email</span>
                            <span class="text-white">billing@<?php echo e($customer_tenant_id); ?>-isp.net</span>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2.5">
                        <i class="bi bi-telephone text-accent fs-5"></i>
                        <div>
                            <span class="text-muted d-block" style="font-size: 0.72rem;">Helpline</span>
                            <span class="text-white">+92 300 1234567</span>
                        </div>
                    </div>
                </div>
                
                <span class="text-muted text-center mt-3 d-block" style="font-size: 0.75rem;">Your account IP: <?php echo $_SERVER['REMOTE_ADDR']; ?></span>
            </div>
        </div>
    </div>
    
    <!-- Lower Block: Invoices & Dynamic Alerts Inbox -->
    <div class="row g-4">
        <!-- Invoice Log -->
        <div class="col-lg-7">
            <div class="p-4 glass-card h-100">
                <h5 class="text-white mb-3"><i class="bi bi-credit-card text-secondary me-2"></i>Recent Bill Payments</h5>
                
                <div class="table-responsive-glass">
                    <table class="table table-glass align-middle">
                        <thead style="font-size: 0.8rem;">
                            <tr>
                                <th>Inv Number</th>
                                <th>Total Bill</th>
                                <th style="text-align: center;">Due Date</th>
                                <th style="text-align: center;">Status</th>
                            </tr>
                        </thead>
                        <tbody style="font-size: 0.85rem;">
                            <?php if (empty($recent_invoices)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">No invoices generated for this client.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_invoices as $inv): ?>
                                    <tr>
                                        <td class="fw-bold text-white"><?php echo e($inv['invoice_number']); ?></td>
                                        <td class="fw-bold text-white"><?php echo format_currency($inv['total_amount']); ?></td>
                                        <td class="text-center text-muted"><?php echo format_date($inv['due_date']); ?></td>
                                        <td class="text-center"><?php echo get_invoice_status_badge($inv['payment_status']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="text-end mt-3">
                    <a href="payments.php" class="text-secondary text-decoration-none" style="font-size: 0.85rem; font-weight: 600;">View Complete Ledger <i class="bi bi-arrow-right ms-1"></i></a>
                </div>
            </div>
        </div>
        
        <!-- Inbox Alerts Log -->
        <div class="col-lg-5">
            <div class="p-4 glass-card h-100">
                <h5 class="text-white mb-3"><i class="bi bi-inbox text-secondary me-2"></i>Inbox Notifications</h5>
                
                <div class="d-flex flex-column gap-2.5">
                    <?php if (empty($inbox_notifications)): ?>
                        <div class="p-4 text-center border border-dashed rounded-3 text-muted" style="background: rgba(255,255,255,0.01); border-color: var(--border-color); font-size: 0.85rem;">
                            <i class="bi bi-check2-circle text-success fs-3 mb-2 d-block"></i>
                            No alerts or unread notifications inside your portal.
                        </div>
                    <?php else: ?>
                        <?php foreach ($inbox_notifications as $notif): 
                            $bg = 'rgba(255,255,255,0.015)';
                            $border = 'var(--border-color)';
                            if ($notif['type'] === 'payment') {
                                $bg = 'rgba(168,85,247,0.03)';
                                $border = 'rgba(168,85,247,0.15)';
                            }
                            ?>
                            <div class="p-3 border rounded-3 text-start" style="background: <?php echo $bg; ?>; border-color: <?php echo $border; ?>;">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <strong class="text-white" style="font-size: 0.85rem;"><?php echo e($notif['title']); ?></strong>
                                    <small class="text-muted" style="font-size: 0.68rem;"><?php echo date('d M', strtotime($notif['created_at'])); ?></small>
                                </div>
                                <p class="text-muted mb-0" style="font-size: 0.78rem;"><?php echo e($notif['message']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
