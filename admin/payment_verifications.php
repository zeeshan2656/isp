<?php
/**
 * Super Admin Payment Verifications Central Panel
 * Complete workflow for approving, rejecting, or requesting more information on invoice proofs.
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$review_id = (int)($_GET['id'] ?? 0);

// Fetch Admin Session details
$admin_name_display = $_SESSION['super_admin_name'];

// Handle Review Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    verify_csrf_token();
    
    $submission_id = (int)($_POST['submission_id'] ?? 0);
    $review_status = clean_input($_POST['status'] ?? '');
    $review_notes = clean_input($_POST['review_notes'] ?? '');
    
    if ($submission_id <= 0) $errors[] = "Invalid submission selected for review.";
    if (!in_array($review_status, ['approved', 'rejected', 'more_info'])) $errors[] = "Please select a valid review status.";
    if (empty($review_notes)) $errors[] = "Please provide review decision remarks / feedback notes.";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 1. Fetch submission details
            $stmt = $pdo->prepare("SELECT * FROM payment_submissions WHERE id = ? FOR UPDATE");
            $stmt->execute([$submission_id]);
            $sub = $stmt->fetch();
            
            if (!$sub) {
                throw new Exception("Payment proof submission record not found.");
            }
            
            if ($sub['status'] === 'approved') {
                throw new Exception("This payment submission is already approved and settled.");
            }
            
            // 2. Update Submission status
            $upd_sub = $pdo->prepare("UPDATE payment_submissions 
                SET status = ?, reviewed_by = ?, review_notes = ?, reviewed_at = NOW() 
                WHERE id = ?");
            $upd_sub->execute([$review_status, $admin_id, $review_notes, $submission_id]);
            
            // 3. Handle status-specific workflows
            if ($review_status === 'approved') {
                $payer_amt = (double)$sub['amount'];
                
                if ($sub['invoice_type'] === 'invoice') {
                    // --- CUSTOMER BROADBAND INVOICE WORKFLOW ---
                    // Fetch invoice details
                    $stmt_inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ? FOR UPDATE");
                    $stmt_inv->execute([$sub['invoice_id']]);
                    $inv = $stmt_inv->fetch();
                    
                    if (!$inv) {
                        throw new Exception("Broadband invoice not found for this submission.");
                    }
                    
                    // Mark invoice as PAID
                    $upd_inv = $pdo->prepare("UPDATE invoices 
                        SET payment_status = 'paid', paid_amount = total_amount, remaining_amount = 0.00, payment_date = NOW(), verified_by_admin = ? 
                        WHERE id = ?");
                    $upd_inv->execute([$admin_name_display, $sub['invoice_id']]);
                    
                    // Fetch customer details
                    $stmt_cust = $pdo->prepare("SELECT * FROM customers WHERE id = ? FOR UPDATE");
                    $stmt_cust->execute([$sub['payer_id']]);
                    $cust = $stmt_cust->fetch();
                    
                    if ($cust) {
                        // Activate customer & extend billing end date
                        $billing_start = $inv['billing_start_date'] ?: date('Y-m-d');
                        $billing_end = $inv['billing_end_date'] ?: date('Y-m-d', strtotime('+30 days'));
                        
                        $upd_cust = $pdo->prepare("UPDATE customers 
                            SET expiry_date = ?, activation_date = ?, status = 'active' 
                            WHERE id = ?");
                        $upd_cust->execute([$billing_end, $billing_start, $cust['id']]);
                        
                        // Send system notification to subscriber
                        $notif_msg = "Your broadband payment proof of Rs. " . number_format($payer_amt, 2) . " for Invoice #" . $inv['invoice_number'] . " has been verified and APPROVED by Super Admin. Your internet package speed has been fully activated / restored.";
                        $ins_notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Broadband Payment Approved', ?, 'payment')");
                        $ins_notif->execute([$sub['tenant_id'], $cust['id'], $notif_msg]);
                    }
                    
                    log_audit_activity($pdo, $sub['tenant_id'], 'customer', $sub['payer_id'], "Super Admin approved payment proof on broadband Invoice #" . $inv['invoice_number']);
                    
                } elseif ($sub['invoice_type'] === 'saas_invoice') {
                    // --- TENANT SaaS INVOICE WORKFLOW ---
                    // Fetch SaaS invoice details
                    $stmt_sinv = $pdo->prepare("SELECT * FROM saas_invoices WHERE id = ? FOR UPDATE");
                    $stmt_sinv->execute([$sub['invoice_id']]);
                    $sinv = $stmt_sinv->fetch();
                    
                    if (!$sinv) {
                        throw new Exception("SaaS subscription invoice not found for this submission.");
                    }
                    
                    // Mark SaaS invoice as PAID
                    $upd_sinv = $pdo->prepare("UPDATE saas_invoices 
                        SET payment_status = 'paid', paid_amount = amount, remaining_amount = 0.00, payment_date = NOW(), verified_by_admin = ? 
                        WHERE id = ?");
                    $upd_sinv->execute([$admin_name_display, $sub['invoice_id']]);
                    
                    // Load tenant referral details
                    $stmt_ref = $pdo->prepare("SELECT referred_by_type, referred_by_id FROM tenants WHERE id = ?");
                    $stmt_ref->execute([$sub['tenant_id']]);
                    $ref_data = $stmt_ref->fetch();
                    
                    if ($ref_data && $ref_data['referred_by_type'] !== 'none' && $ref_data['referred_by_id'] > 0) {
                        $ref_type = $ref_data['referred_by_type'];
                        $ref_id = $ref_data['referred_by_id'];
                        
                        if ($ref_type === 'affiliate') {
                            $stmt_pct = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key = 'affiliate_commission_percentage' LIMIT 1");
                            $stmt_pct->execute();
                            $aff_pct = (double)($stmt_pct->fetchColumn() ?? 20.00);
                            $comm_amt = ($payer_amt * $aff_pct) / 100;
                            
                            if ($comm_amt > 0) {
                                $stmt_c = $pdo->prepare("UPDATE affiliates SET wallet_balance = wallet_balance + ? WHERE id = ?");
                                $stmt_c->execute([$comm_amt, $ref_id]);
                                
                                $notes = "Affiliate commission credited on SaaS invoice paid via Super Admin Verification: " . $sinv['invoice_number'];
                                $stmt_l = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('affiliate', ?, 'commission_credit', ?, ?, 'approved', ?)");
                                $stmt_l->execute([$ref_id, $comm_amt, $sub['invoice_id'], $notes]);
                            }
                        } elseif ($ref_type === 'tenant') {
                            $stmt_pct = $pdo->prepare("SELECT setting_value FROM saas_settings WHERE setting_key = 'tenant_referral_percentage' LIMIT 1");
                            $stmt_pct->execute();
                            $tenant_pct = (double)($stmt_pct->fetchColumn() ?? 10.00);
                            $comm_amt = ($payer_amt * $tenant_pct) / 100;
                            
                            if ($comm_amt > 0) {
                                $stmt_c = $pdo->prepare("UPDATE tenants SET referral_wallet = referral_wallet + ? WHERE id = ?");
                                $stmt_c->execute([$comm_amt, $ref_id]);
                                
                                $notes = "Tenant referral rewards credited on SaaS invoice paid via Super Admin Verification: " . $sinv['invoice_number'];
                                $stmt_l = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('tenant', ?, 'commission_credit', ?, ?, 'approved', ?)");
                                $stmt_l->execute([$ref_id, $comm_amt, $sub['invoice_id'], $notes]);
                            }
                        }
                    }
                    
                    // Extend SaaS Tenant subscription
                    $days = (int)($sinv['duration_days'] ?? 30);
                    $stmt_t = $pdo->prepare("SELECT subscription_end FROM tenants WHERE id = ? LIMIT 1 FOR UPDATE");
                    $stmt_t->execute([$sub['tenant_id']]);
                    $current_end = $stmt_t->fetchColumn();
                    
                    $today = date('Y-m-d');
                    $start_date = (empty($current_end) || $current_end < $today) ? $today : $current_end;
                    $new_end = date('Y-m-d', strtotime($start_date . " + $days days"));
                    
                    $upd_t = $pdo->prepare("UPDATE tenants SET subscription_start = ?, subscription_end = ?, subscription_duration = ?, status = 'active' WHERE id = ?");
                    $upd_t->execute([$today, $new_end, $days, $sub['tenant_id']]);
                    
                    log_audit_activity($pdo, $sub['tenant_id'], 'tenant', $sub['tenant_id'], "Super Admin approved SaaS invoice proof #" . $sinv['invoice_number'] . ". SaaS subscription extended to: " . $new_end);
                }
            } else {
                // Rejected / More Info Workflow
                if ($sub['invoice_type'] === 'invoice') {
                    // Send system notification to subscriber about rejection / details requested
                    $action_lbl = ($review_status === 'rejected') ? 'REJECTED' : 'DETAILS REQUESTED';
                    $notif_msg = "Your broadband payment proof submission for Invoice has been " . $action_lbl . " by Super Admin. Feedback: '" . $review_notes . "'. Please review and resubmit.";
                    $ins_notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Broadband Payment Update', ?, 'payment')");
                    $ins_notif->execute([$sub['tenant_id'], $sub['payer_id'], $notif_msg]);
                    
                    log_audit_activity($pdo, $sub['tenant_id'], 'customer', $sub['payer_id'], "Super Admin reviewed payment proof as " . strtoupper($review_status));
                } else {
                    // Send alert to Tenant dashboard notifications (can log to audit or specific logs)
                    log_audit_activity($pdo, $sub['tenant_id'], 'tenant', $sub['tenant_id'], "Super Admin reviewed SaaS payment proof as " . strtoupper($review_status) . ". Feedback: " . $review_notes);
                }
            }
            
            $pdo->commit();
            set_session_alert("Payment review decision successfully saved and processed.", "success");
            header("Location: payment_verifications.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Review failed: " . $e->getMessage();
        }
    }
}

// Fetch single submission details if review action
$review_row = null;
if ($action === 'review' && $review_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT ps.*, 
            CASE 
                WHEN ps.payer_type = 'tenant' THEN t.company_name
                WHEN ps.payer_type = 'customer' THEN c.name
            END as payer_name,
            CASE 
                WHEN ps.payer_type = 'tenant' THEN t.email
                WHEN ps.payer_type = 'customer' THEN c.email
            END as payer_email,
            CASE 
                WHEN ps.payer_type = 'tenant' THEN NULL
                WHEN ps.payer_type = 'customer' THEN tc.company_name
            END as customer_tenant_name,
            CASE 
                WHEN ps.invoice_type = 'invoice' THEN inv.invoice_number
                WHEN ps.invoice_type = 'saas_invoice' THEN sinv.invoice_number
            END as invoice_number,
            CASE 
                WHEN ps.invoice_type = 'invoice' THEN inv.total_amount
                WHEN ps.invoice_type = 'saas_invoice' THEN sinv.amount
            END as invoice_total_amount
        FROM payment_submissions ps
        LEFT JOIN tenants t ON ps.payer_type = 'tenant' AND ps.payer_id = t.id
        LEFT JOIN customers c ON ps.payer_type = 'customer' AND ps.payer_id = c.id
        LEFT JOIN tenants tc ON ps.payer_type = 'customer' AND c.tenant_id = tc.id
        LEFT JOIN invoices inv ON ps.invoice_type = 'invoice' AND ps.invoice_id = inv.id
        LEFT JOIN saas_invoices sinv ON ps.invoice_type = 'saas_invoice' AND ps.invoice_id = sinv.id
        WHERE ps.id = ?");
        $stmt->execute([$review_id]);
        $review_row = $stmt->fetch();
        
        if (!$review_row) {
            set_session_alert("Payment review submission record not found.", "error");
            header("Location: payment_verifications.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Failed to load review record: " . $e->getMessage());
    }
}
?>

<!-- Header Layout -->
<div class="row align-items-center mb-4">
    <div class="col-sm-8">
        <h2 class="text-white mb-1"><i class="bi bi-shield-check text-primary me-2"></i>Payment Verification Desk</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Review, approve, and reject payment proofs submitted by SaaS Tenants (ISPs) and broadband Subscribers.</p>
    </div>
    <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
        <?php if ($action === 'review'): ?>
            <a href="payment_verifications.php" class="btn btn-dark-glass py-2.5 px-4" style="font-size: 0.9rem;"><i class="bi bi-arrow-left me-1.5"></i>Back to Reviews</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger border-0 text-white bg-danger bg-opacity-10 py-3 mb-4">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?php echo e($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($action === 'review' && $review_row): ?>
    <!-- SINGLE PAYMENT PROOF DETAILS & AUDIT ACTIONS -->
    <div class="row g-4">
        <!-- Details Card -->
        <div class="col-xl-6 col-lg-6">
            <div class="glass-card p-4 h-100">
                <h4 class="text-white mb-3 font-outfit"><i class="bi bi-file-text text-primary me-2"></i>Proof Submission Details</h4>
                <hr class="border-white border-opacity-5 mb-4">
                
                <div class="row g-3">
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Submitter Type</span>
                        <strong class="text-white font-outfit" style="text-transform: uppercase;">
                            <?php echo $review_row['payer_type'] === 'tenant' ? '<span class="badge bg-purple bg-opacity-10 text-purple border-0">Tenant / ISP</span>' : '<span class="badge bg-info bg-opacity-10 text-info border-0">Customer / Subscriber</span>'; ?>
                        </strong>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Submitter Name</span>
                        <strong class="text-white"><?php echo e($review_row['payer_name']); ?></strong>
                        <?php if ($review_row['customer_tenant_name']): ?>
                            <small class="d-block text-muted" style="font-size: 0.75rem;">ISP Provider: <?php echo e($review_row['customer_tenant_name']); ?></small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Invoice Association</span>
                        <strong class="text-white">
                            <?php echo $review_row['invoice_type'] === 'saas_invoice' ? 'SaaS Plan Invoice' : 'Subscriber Invoice'; ?> 
                            (<?php echo e($review_row['invoice_number']); ?>)
                        </strong>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Invoice Amount / Claimed Amount</span>
                        <strong class="text-white font-outfit">
                            <?php echo format_currency($review_row['invoice_total_amount']); ?> / 
                            <span class="text-success"><?php echo format_currency($review_row['amount']); ?></span>
                        </strong>
                    </div>
                    
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Payment Method</span>
                        <strong class="text-white font-outfit"><?php echo strtoupper(e($review_row['payment_method'])); ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Transaction ID Reference</span>
                        <strong class="text-white font-outfit text-accent"><?php echo e($review_row['transaction_id'] ?: 'N/A'); ?></strong>
                    </div>
                    
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Date Submitted</span>
                        <strong class="text-white"><?php echo date('d M, Y H:i', strtotime($review_row['created_at'])); ?></strong>
                    </div>
                    <div class="col-sm-6">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Current Status</span>
                        <?php 
                        $status_colors = [
                            'pending' => 'bg-warning text-warning bg-opacity-10',
                            'approved' => 'bg-success text-success bg-opacity-10',
                            'rejected' => 'bg-danger text-danger bg-opacity-10',
                            'more_info' => 'bg-info text-info bg-opacity-10'
                        ];
                        ?>
                        <span class="badge <?php echo $status_colors[$review_row['status']]; ?> border-0 font-outfit" style="text-transform: uppercase;">
                            <?php echo $review_row['status']; ?>
                        </span>
                    </div>
                    
                    <div class="col-12">
                        <span class="text-muted d-block" style="font-size: 0.75rem;">Payer Remarks / Submission Notes</span>
                        <div class="p-3 bg-dark bg-opacity-50 rounded border border-white border-opacity-5 text-white" style="font-size: 0.85rem; min-height: 80px;">
                            <?php echo nl2br(e($review_row['submission_notes'] ?: 'No notes included in this submission.')); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action & Form Card -->
        <div class="col-xl-6 col-lg-6">
            <div class="glass-card p-4 h-100 d-flex flex-column justify-content-between">
                <div>
                    <h4 class="text-white mb-3 font-outfit"><i class="bi bi-file-earmark-check text-primary me-2"></i>Review Proof & Audit decision</h4>
                    <hr class="border-white border-opacity-5 mb-4">
                    
                    <?php if ($review_row['proof_image']): ?>
                        <div class="mb-4 text-center">
                            <span class="text-muted d-block text-start mb-2" style="font-size: 0.75rem;">Uploaded Payment Receipt / Proof Attachment</span>
                            <a href="../uploads/proofs/<?php echo e($review_row['proof_image']); ?>" target="_blank" class="d-block position-relative rounded overflow-hidden border border-white border-opacity-10 hover-bg-opacity">
                                <img src="../uploads/proofs/<?php echo e($review_row['proof_image']); ?>" class="img-fluid rounded" style="max-height: 250px; object-fit: contain; background: #000;" alt="Payment Proof Attachment">
                                <div class="position-absolute top-50 start-50 translate-middle bg-dark bg-opacity-75 p-2 px-3 rounded text-white font-outfit" style="font-size: 0.8rem;">
                                    <i class="bi bi-fullscreen me-1.5"></i>Open Full Receipt
                                </div>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning border-0 bg-warning bg-opacity-10 text-warning mb-4" style="font-size: 0.85rem;">
                            <i class="bi bi-exclamation-triangle me-2"></i>No attachment or receipt image uploaded with this submission. Verify based on transaction ID reference.
                        </div>
                    <?php endif; ?>
                </div>
                
                <form action="payment_verifications.php?action=review&id=<?php echo $review_row['id']; ?>" method="POST">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="submission_id" value="<?php echo $review_row['id']; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Review Decision</label>
                        <select name="status" class="form-select bg-dark-glass text-white border-white border-opacity-10 py-2.5" required>
                            <option value="" disabled selected>-- Select Audit decision --</option>
                            <option value="approved">Approve Payment (Marks Invoice Paid & Restores Speeds / Plan)</option>
                            <option value="rejected">Reject Payment (Marks Rejected & Requests New Submission)</option>
                            <option value="more_info">Request More Info (Alerts Submitter to Provide Additional Proofs)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Decision remarks / Submitter Feedback</label>
                        <textarea name="review_notes" class="form-control bg-dark-glass text-white border-white border-opacity-10" rows="4" placeholder="Input specific remarks, transaction validation confirmation, or reasons for rejection..." required></textarea>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <button type="submit" name="submit_review" class="btn btn-primary-gradient w-100 py-2.5">
                            <i class="bi bi-shield-check me-1.5"></i>Log Audit Decision
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
<?php else: ?>
    
    <!-- PAGINATED LIST VIEW -->
    <?php
    $search = clean_input($_GET['search'] ?? '');
    $filter_status = clean_input($_GET['status'] ?? '');
    $filter_type = clean_input($_GET['type'] ?? '');
    
    $query_parts = ["1=1"];
    $query_params = [];
    
    if (!empty($search)) {
        $query_parts[] = "(t.company_name LIKE :search OR c.name LIKE :search OR ps.transaction_id LIKE :search)";
        $query_params[':search'] = '%' . $search . '%';
    }
    
    if (!empty($filter_status)) {
        $query_parts[] = "ps.status = :status";
        $query_params[':status'] = $filter_status;
    }
    
    if (!empty($filter_type)) {
        $query_parts[] = "ps.payer_type = :type";
        $query_params[':type'] = $filter_type;
    }
    
    $where_sql = implode(" AND ", $query_parts);
    
    // Pagination
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $limit = 10;
    
    $total_records = 0;
    $submissions = [];
    
    try {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_submissions ps
            LEFT JOIN tenants t ON ps.payer_type = 'tenant' AND ps.payer_id = t.id
            LEFT JOIN customers c ON ps.payer_type = 'customer' AND ps.payer_id = c.id
            WHERE $where_sql");
        $count_stmt->execute($query_params);
        $total_records = (int)$count_stmt->fetchColumn();
        
        $total_pages = ceil($total_records / $limit);
        if ($total_pages < 1) $total_pages = 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $limit;
        
        $select_sql = "SELECT ps.*, 
            CASE 
                WHEN ps.payer_type = 'tenant' THEN t.company_name
                WHEN ps.payer_type = 'customer' THEN c.name
            END as payer_name,
            CASE 
                WHEN ps.payer_type = 'tenant' THEN NULL
                WHEN ps.payer_type = 'customer' THEN tc.company_name
            END as customer_tenant_name,
            CASE 
                WHEN ps.invoice_type = 'invoice' THEN inv.invoice_number
                WHEN ps.invoice_type = 'saas_invoice' THEN sinv.invoice_number
            END as invoice_number,
            CASE 
                WHEN ps.invoice_type = 'invoice' THEN inv.total_amount
                WHEN ps.invoice_type = 'saas_invoice' THEN sinv.amount
            END as invoice_total_amount
        FROM payment_submissions ps
        LEFT JOIN tenants t ON ps.payer_type = 'tenant' AND ps.payer_id = t.id
        LEFT JOIN customers c ON ps.payer_type = 'customer' AND ps.payer_id = c.id
        LEFT JOIN tenants tc ON ps.payer_type = 'customer' AND c.tenant_id = tc.id
        LEFT JOIN invoices inv ON ps.invoice_type = 'invoice' AND ps.invoice_id = inv.id
        LEFT JOIN saas_invoices sinv ON ps.invoice_type = 'saas_invoice' AND ps.invoice_id = sinv.id
        WHERE $where_sql 
        ORDER BY ps.id DESC 
        LIMIT $limit OFFSET $offset";
        
        $stmt = $pdo->prepare($select_sql);
        foreach ($query_params as $param => $val) {
            $stmt->bindValue($param, $val);
        }
        $stmt->execute();
        $submissions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Payment submissions select failed: " . $e->getMessage());
    }
    ?>
    
    <!-- Filter Panel -->
    <div class="p-4 glass-card mb-4">
        <form action="payment_verifications.php" method="GET" class="row g-3 align-items-end">
            <div class="col-md-4 col-sm-6">
                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Search Submitter / TxID</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark-glass border-white border-opacity-10 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control bg-dark-glass text-white border-white border-opacity-10" placeholder="Subscriber name, ISP Tenant name, transaction ID..." value="<?php echo e($search); ?>">
                </div>
            </div>
            <div class="col-md-2.5 col-sm-6">
                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Review Status</label>
                <select name="status" class="form-select bg-dark-glass text-white border-white border-opacity-10">
                    <option value="">-- All Statuses --</option>
                    <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending Approval</option>
                    <option value="approved" <?php echo $filter_status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $filter_status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                    <option value="more_info" <?php echo $filter_status === 'more_info' ? 'selected' : ''; ?>>More Info</option>
                </select>
            </div>
            <div class="col-md-2.5 col-sm-6">
                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Submitter Type</label>
                <select name="type" class="form-select bg-dark-glass text-white border-white border-opacity-10">
                    <option value="">-- All Types --</option>
                    <option value="tenant" <?php echo $filter_type === 'tenant' ? 'selected' : ''; ?>>Tenant / ISP</option>
                    <option value="customer" <?php echo $filter_type === 'customer' ? 'selected' : ''; ?>>Customer / Subscriber</option>
                </select>
            </div>
            <div class="col-md-3 col-sm-6 d-flex gap-2">
                <button type="submit" class="btn btn-primary-gradient w-100 py-2.5"><i class="bi bi-funnel me-1.5"></i>Filter</button>
                <a href="payment_verifications.php" class="btn btn-dark-glass w-100 py-2.5 text-center"><i class="bi bi-x-circle me-1.5"></i>Reset</a>
            </div>
        </form>
    </div>
    
    <!-- Table list -->
    <div class="glass-card p-4">
        <h4 class="text-white mb-3 font-outfit"><i class="bi bi-list-check text-primary me-2"></i>Proofs Verification Logs</h4>
        
        <div class="table-responsive">
            <table class="table table-bordered align-middle text-white mb-0" style="font-size: 0.88rem; border-color: rgba(255,255,255,0.05) !important;">
                <thead class="bg-dark-glass fw-bold font-outfit" style="color: var(--text-dim);">
                    <tr>
                        <th>Date Submitted</th>
                        <th>Submitter / Payer</th>
                        <th>Invoice / Type</th>
                        <th class="text-end">Amount</th>
                        <th>Method / TxID</th>
                        <th class="text-center">Attachment</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No payment proofs matching the active filter parameters are currently available.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $row): ?>
                            <?php 
                            $status_colors = [
                                'pending' => 'bg-warning text-warning bg-opacity-10',
                                'approved' => 'bg-success text-success bg-opacity-10',
                                'rejected' => 'bg-danger text-danger bg-opacity-10',
                                'more_info' => 'bg-info text-info bg-opacity-10'
                            ];
                            ?>
                            <tr class="hover-bg-opacity">
                                <td><?php echo date('d M, Y H:i', strtotime($row['created_at'])); ?></td>
                                <td>
                                    <strong class="text-white font-outfit"><?php echo e($row['payer_name']); ?></strong>
                                    <span class="d-block text-muted" style="font-size: 0.72rem; text-transform: uppercase;">
                                        <?php echo $row['payer_type'] === 'tenant' ? '<span class="text-purple fw-semibold">Tenant</span>' : '<span class="text-info fw-semibold">Subscriber</span>'; ?>
                                        <?php if ($row['customer_tenant_name']): ?>
                                            &bull; ISP: <?php echo e($row['customer_tenant_name']); ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-semibold text-white"><?php echo e($row['invoice_number']); ?></span>
                                    <span class="d-block text-muted" style="font-size: 0.72rem;">
                                        <?php echo $row['invoice_type'] === 'saas_invoice' ? 'SaaS Plan' : 'Broadband Subscription'; ?>
                                    </span>
                                </td>
                                <td class="text-end fw-semibold text-success font-outfit"><?php echo format_currency($row['amount']); ?></td>
                                <td>
                                    <span class="fw-semibold text-white"><?php echo strtoupper(e($row['payment_method'])); ?></span>
                                    <span class="d-block text-accent font-outfit" style="font-size: 0.72rem;"><?php echo e($row['transaction_id'] ?: 'N/A'); ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['proof_image']): ?>
                                        <a href="../uploads/proofs/<?php echo e($row['proof_image']); ?>" target="_blank" class="badge bg-primary bg-opacity-10 text-primary border-0 py-1.5 px-2 text-decoration-none">
                                            <i class="bi bi-image me-1"></i>View Proof
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">No Receipt</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge <?php echo $status_colors[$row['status']]; ?> border-0 font-outfit px-2.5" style="text-transform: uppercase; font-size: 0.75rem;">
                                        <?php echo $row['status']; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($row['status'] === 'pending' || $row['status'] === 'more_info'): ?>
                                        <a href="payment_verifications.php?action=review&id=<?php echo $row['id']; ?>" class="btn btn-primary-gradient p-1.5 px-3 rounded text-decoration-none" style="font-size: 0.8rem;">
                                            <i class="bi bi-shield-check me-1"></i>Review
                                        </a>
                                    <?php else: ?>
                                        <a href="payment_verifications.php?action=review&id=<?php echo $row['id']; ?>" class="btn btn-dark-glass p-1.5 px-3 rounded text-decoration-none" style="font-size: 0.8rem;">
                                            <i class="bi bi-eye me-1"></i>Audit
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination controls -->
        <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center mt-4 border-top border-white border-opacity-5 pt-3">
                <span class="text-muted" style="font-size: 0.8rem;">Showing page <strong><?php echo $page; ?></strong> of <strong><?php echo $total_pages; ?></strong> (Total logs: <strong><?php echo $total_records; ?></strong>)</span>
                
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm mb-0 gap-1">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link bg-dark-glass border-white border-opacity-5 text-white" href="payment_verifications.php?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>"><i class="bi bi-chevron-left"></i></a>
                        </li>
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <li class="page-item <?php echo ($p === $page) ? 'active' : ''; ?>">
                                <a class="page-link <?php echo ($p === $page) ? 'bg-primary-gradient border-0' : 'bg-dark-glass border-white border-opacity-5 text-white'; ?>" href="payment_verifications.php?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>"><?php echo $p; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link bg-dark-glass border-white border-opacity-5 text-white" href="payment_verifications.php?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>&type=<?php echo $filter_type; ?>"><i class="bi bi-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/layouts/footer.php';
?>
