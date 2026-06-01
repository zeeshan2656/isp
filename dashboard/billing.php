<?php
/**
 * Billing & Payment Management Module (CRUD, Paginated, Invoices)
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$edit_id = (int)($_GET['id'] ?? 0);

// Load options (active customers) for invoicing
$customers_opt = [];
$packages_opt = [];
try {
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.monthly_fee, c.installation_fee, p.name as package_name FROM customers c LEFT JOIN packages p ON c.assigned_package_id = p.id WHERE c.tenant_id = ? AND c.status = 'active' ORDER BY c.name ASC");
    $stmt->execute([$tenant_id]);
    $customers_opt = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, name, monthly_price FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$tenant_id]);
    $packages_opt = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Active options load fail: " . $e->getMessage());
}

// Bulk Auto-Billing execution trigger
if (isset($_POST['bulk_billing'])) {
    verify_csrf_token();
    try {
        $today = date('Y-m-d');
        $due_date = date('Y-m-d', strtotime('+7 days'));
        
        // 1. Fetch all active customers
        $stmt = $pdo->prepare("SELECT c.*, p.name as package_name FROM customers c 
            LEFT JOIN packages p ON c.assigned_package_id = p.id 
            WHERE c.tenant_id = ? AND c.status = 'active'");
        $stmt->execute([$tenant_id]);
        $active_subscribers = $stmt->fetchAll();
        
        // Load customer referral settings
        $stmt_set = $pdo->prepare("SELECT enabled FROM tenant_referral_settings WHERE tenant_id = ? LIMIT 1");
        $stmt_set->execute([$tenant_id]);
        $ref_enabled = (int)($stmt_set->fetchColumn() ?? 0);
        
        $count = 0;
        foreach ($active_subscribers as $cust) {
            // Check if they already have an invoice for the current month
            $start_m = date('Y-m-01 00:00:00');
            $end_m = date('Y-m-t 23:59:59');
            $chk = $pdo->prepare("SELECT id FROM invoices WHERE tenant_id = ? AND customer_id = ? AND created_at BETWEEN ? AND ?");
            $chk->execute([$tenant_id, $cust['id'], $start_m, $end_m]);
            
            if (!$chk->fetch()) {
                // Generate Invoice
                $inv_num = generate_invoice_number($tenant_id, $cust['id']);
                $pkg_name = $cust['package_name'] ?: 'Custom Package';
                $total = $cust['monthly_fee'];
                
                // Credit deduction
                $wallet_bal = 0.00;
                $credits_used = 0.00;
                if ($ref_enabled === 1) {
                    $wallet_bal = (double)($cust['referral_wallet'] ?? 0.00);
                }
                
                if ($wallet_bal > 0) {
                    $credits_used = min($wallet_bal, $total);
                    
                    // Deduct from customer referral wallet
                    $stmt_wallet = $pdo->prepare("UPDATE customers SET referral_wallet = referral_wallet - ? WHERE id = ?");
                    $stmt_wallet->execute([$credits_used, $cust['id']]);
                }
                
                $final_amount = $total - $credits_used;
                $payment_status = ($final_amount <= 0) ? 'paid' : 'pending';
                $paid_amount = $credits_used;
                $remaining_amount = $final_amount;
                $pay_date = ($payment_status === 'paid') ? date('Y-m-d H:i:s') : null;
                
                $billing_start = date('Y-m-d');
                $billing_end = date('Y-m-d', strtotime('+30 days'));
                
                $ins = $pdo->prepare("INSERT INTO invoices (tenant_id, customer_id, invoice_number, package_name, total_amount, paid_amount, remaining_amount, due_date, payment_status, payment_date, billing_start_date, billing_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$tenant_id, $cust['id'], $inv_num, $pkg_name, $total, $paid_amount, $remaining_amount, $due_date, $payment_status, $pay_date, $billing_start, $billing_end]);
                $invoice_id = $pdo->lastInsertId();
                
                if ($credits_used > 0) {
                    // Log to ledger
                    $notes = "Auto-applied subscriber rewards wallet credits to Broadband Invoice #$inv_num";
                    $stmt_ledg = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('customer', ?, 'invoice_deduction', ?, ?, 'approved', ?)");
                    $stmt_ledg->execute([$cust['id'], $credits_used, $invoice_id, $notes]);
                }
                
                // Trigger dynamic expiry extension IF fully paid
                if ($payment_status === 'paid') {
                    $renew = $pdo->prepare("UPDATE customers SET expiry_date = ?, activation_date = ?, status = 'active' WHERE id = ? AND tenant_id = ?");
                    $renew->execute([$billing_end, $billing_start, $cust['id'], $tenant_id]);
                    
                    $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Subscription Extended', 'Thank you! Your broadband subscription was paid using your referral wallet balance and extended to " . format_date($billing_end) . ".', 'payment')");
                    $notif->execute([$tenant_id, $cust['id']]);
                } else {
                    // Add expiry-driven system notification inside customer portal
                    $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Monthly Invoice Issued', 'A monthly invoice for {$pkg_name} of Rs. {$total} has been issued. Remaining due: Rs. {$remaining_amount}. Please clear before due date.', 'payment')");
                    $notif->execute([$tenant_id, $cust['id']]);
                }
                
                $count++;
            }
        }
        
        log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Executed bulk monthly billing. Generated $count new invoices.");
        set_session_alert("Auto-Billing completed. Generated $count new subscriber invoices.", "success");
        header("Location: billing.php");
        exit;
    } catch (PDOException $e) {
        set_session_alert("Bulk billing failure: " . $e->getMessage(), "error");
    }
}

// Single Invoice Generation Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_invoice'])) {
    verify_csrf_token();
    
    $cust_id = (int)($_POST['customer_id'] ?? 0);
    $billing_start = clean_input($_POST['billing_start_date'] ?? '');
    $billing_end = clean_input($_POST['billing_end_date'] ?? '');
    $pkg_name = clean_input($_POST['package_name'] ?? '');
    $total = (double)($_POST['total_amount'] ?? 0.00);
    $due_date = clean_input($_POST['due_date'] ?? '');
    
    if ($cust_id <= 0) $errors[] = "Please select a customer.";
    if (empty($billing_start) || empty($billing_end)) $errors[] = "Please select a billing start and end date.";
    if (empty($pkg_name)) $errors[] = "Please specify a package name / Mbps speed.";
    if ($total <= 0) $errors[] = "Amount must be greater than Rs. 0.";
    if (empty($due_date)) $errors[] = "Please select a due date.";
    
    if (empty($errors)) {
        try {
            // Load subscriber details
            $stmt = $pdo->prepare("SELECT c.* FROM customers c WHERE c.id = ? AND c.tenant_id = ?");
            $stmt->execute([$cust_id, $tenant_id]);
            $cust = $stmt->fetch();
            
            if ($cust) {
                $inv_num = generate_invoice_number($tenant_id, $cust['id']);
                
                // Load customer referral settings
                $stmt_set = $pdo->prepare("SELECT enabled FROM tenant_referral_settings WHERE tenant_id = ? LIMIT 1");
                $stmt_set->execute([$tenant_id]);
                $ref_enabled = (int)($stmt_set->fetchColumn() ?? 0);
                
                // Credit deduction
                $wallet_bal = 0.00;
                $credits_used = 0.00;
                if ($ref_enabled === 1) {
                    $wallet_bal = (double)($cust['referral_wallet'] ?? 0.00);
                }
                
                if ($wallet_bal > 0) {
                    $credits_used = min($wallet_bal, $total);
                    
                    // Deduct from customer referral wallet
                    $stmt_wallet = $pdo->prepare("UPDATE customers SET referral_wallet = referral_wallet - ? WHERE id = ?");
                    $stmt_wallet->execute([$credits_used, $cust['id']]);
                }
                
                $final_amount = $total - $credits_used;
                $payment_status = ($final_amount <= 0) ? 'paid' : 'pending';
                $paid_amount = $credits_used;
                $remaining_amount = $final_amount;
                $pay_date = ($payment_status === 'paid') ? date('Y-m-d H:i:s') : null;
                
                $stmt = $pdo->prepare("INSERT INTO invoices (tenant_id, customer_id, invoice_number, package_name, total_amount, paid_amount, remaining_amount, due_date, payment_status, payment_date, billing_start_date, billing_end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$tenant_id, $cust['id'], $inv_num, $pkg_name, $total, $paid_amount, $remaining_amount, $due_date, $payment_status, $pay_date, $billing_start, $billing_end]);
                $invoice_id = $pdo->lastInsertId();
                
                if ($credits_used > 0) {
                    // Log to ledger
                    $notes = "Auto-applied subscriber rewards wallet credits to Broadband Invoice #$inv_num";
                    $stmt_ledg = $pdo->prepare("INSERT INTO referral_transactions (referrer_type, referrer_id, transaction_type, amount, reference_id, status, notes) VALUES ('customer', ?, 'invoice_deduction', ?, ?, 'approved', ?)");
                    $stmt_ledg->execute([$cust['id'], $credits_used, $invoice_id, $notes]);
                }
                
                // Trigger dynamic expiry extension IF fully paid
                if ($payment_status === 'paid') {
                    $renew = $pdo->prepare("UPDATE customers SET expiry_date = ?, activation_date = ?, status = 'active' WHERE id = ? AND tenant_id = ?");
                    $renew->execute([$billing_end, $billing_start, $cust['id'], $tenant_id]);
                    
                    $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Subscription Extended', 'Thank you! Your broadband subscription was paid using your referral wallet balance and extended to " . format_date($billing_end) . ".', 'payment')");
                    $notif->execute([$tenant_id, $cust['id']]);
                } else {
                    // Add expiry-driven system notification inside customer portal
                    $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Monthly Invoice Issued', 'A monthly invoice for {$pkg_name} of Rs. {$total} has been issued. Remaining due: Rs. {$remaining_amount}. Please clear before due date.', 'payment')");
                    $notif->execute([$tenant_id, $cust['id']]);
                }
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Generated custom invoice $inv_num for subscriber: " . $cust['name']);
                set_session_alert("Invoice generated successfully.", "success");
                header("Location: billing.php");
                exit;
            } else {
                $errors[] = "Invalid subscriber account details.";
            }
        } catch (PDOException $e) {
            $errors[] = "Invoicing database error: " . $e->getMessage();
        }
    }
}

// Record Payment Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    verify_csrf_token();
    
    $paid_amt = (double)($_POST['paid_amount'] ?? 0.00);
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    
    if ($paid_amt < 0) $errors[] = "Payment amount cannot be negative.";
    if ($invoice_id <= 0) $errors[] = "Invalid invoice selection.";
    
    if (empty($errors)) {
        try {
            // Fetch Invoice details
            $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.id as customer_id FROM invoices i 
                LEFT JOIN customers c ON i.customer_id = c.id 
                WHERE i.id = ? AND i.tenant_id = ?");
            $stmt->execute([$invoice_id, $tenant_id]);
            $inv = $stmt->fetch();
            
            if ($inv) {
                $total = (double)$inv['total_amount'];
                $new_paid = (double)$inv['paid_amount'] + $paid_amt;
                if ($new_paid > $total) {
                    $errors[] = "Recorded payments cannot exceed the total invoice amount of " . format_currency($total);
                }
                
                if (empty($errors)) {
                    $remaining = $total - $new_paid;
                    
                    // Determine Status
                    $status = 'pending';
                    if ($new_paid == $total) $status = 'paid';
                    elseif ($new_paid > 0) $status = 'partial';
                    elseif ($inv['due_date'] < date('Y-m-d')) $status = 'overdue';
                    
                    $pay_date = ($status === 'paid' || $status === 'partial') ? date('Y-m-d H:i:s') : null;
                    
                    $update = $pdo->prepare("UPDATE invoices SET paid_amount = ?, remaining_amount = ?, payment_status = ?, payment_date = ?, verified_by_admin = ? WHERE id = ? AND tenant_id = ?");
                    $update->execute([$new_paid, $remaining, $status, $pay_date, $_SESSION['tenant_name'], $invoice_id, $tenant_id]);
                    
                    // Trigger Customer referral reward credit on paid amount
                    if ($paid_amt > 0) {
                        trigger_customer_referral_reward($pdo, $tenant_id, $inv['customer_id'], $paid_amt, $invoice_id);
                    }
                    
                    // Trigger dynamic expiry extension IF fully paid
                    if ($status === 'paid') {
                        $renew = $pdo->prepare("UPDATE customers SET expiry_date = ?, activation_date = ?, status = 'active' WHERE id = ? AND tenant_id = ?");
                        $renew->execute([$inv['billing_end_date'], $inv['billing_start_date'], $inv['customer_id'], $tenant_id]);
                        
                        // Log payment notification in customer desk
                        $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Payment Received', 'Thank you! A payment of Rs. {$paid_amt} has been logged. Your subscription is extended to " . format_date($inv['billing_end_date']) . ".', 'payment')");
                        $notif->execute([$tenant_id, $inv['customer_id']]);
                    }
                    
                    log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Recorded payment of Rs. $paid_amt on invoice: " . $inv['invoice_number']);
                    set_session_alert("Payment of Rs. $paid_amt recorded successfully on invoice '{$inv['invoice_number']}'.", "success");
                    header("Location: billing.php");
                    exit;
                }
            } else {
                $errors[] = "Invoice not found or unauthorized access.";
            }
        } catch (PDOException $e) {
            $errors[] = "Billing database error: " . $e->getMessage();
        }
    }
}

// Edit Invoice Form Submit Handler (Audits revisions & delta properties)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_invoice']) && $edit_id > 0) {
    verify_csrf_token();
    
    $new_package = clean_input($_POST['package_name'] ?? '');
    $new_total = (double)($_POST['total_amount'] ?? 0.00);
    $new_paid = (double)($_POST['paid_amount'] ?? 0.00);
    $new_due = clean_input($_POST['due_date'] ?? '');
    $modification_reason = clean_input($_POST['modification_reason'] ?? '');
    
    if (empty($new_package)) $errors[] = "Package name is required.";
    if ($new_total <= 0) $errors[] = "Total bill amount must be greater than Rs. 0.";
    if ($new_paid < 0) $errors[] = "Paid collected amount cannot be negative.";
    if ($new_paid > $new_total) $errors[] = "Paid collected amount cannot exceed total invoice amount.";
    if (empty($new_due)) $errors[] = "Due date is required.";
    if (empty($modification_reason)) $errors[] = "A modification reason is required for administrative audit trails.";
    
    if (empty($errors)) {
        try {
            // Load old invoice snapshot
            $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$edit_id, $tenant_id]);
            $old_inv = $stmt->fetch();
            
            if ($old_inv) {
                $new_remaining = $new_total - $new_paid;
                
                // Determine dynamic Payment Status
                $status = 'pending';
                if ($new_remaining == 0) $status = 'paid';
                elseif ($new_paid > 0) $status = 'partial';
                elseif ($new_due < date('Y-m-d')) $status = 'overdue';
                
                $pay_date = ($status === 'paid' || $status === 'partial') ? date('Y-m-d H:i:s') : null;
                
                // Save updated record
                $stmt = $pdo->prepare("UPDATE invoices SET package_name = ?, total_amount = ?, paid_amount = ?, remaining_amount = ?, due_date = ?, payment_status = ?, payment_date = ?, verified_by_admin = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$new_package, $new_total, $new_paid, $new_remaining, $new_due, $status, $pay_date, $_SESSION['tenant_name'], $edit_id, $tenant_id]);
                
                // Format change logs JSON maps
                $prev_vals = json_encode([
                    'package_name' => $old_inv['package_name'],
                    'total_amount' => $old_inv['total_amount'],
                    'paid_amount' => $old_inv['paid_amount'],
                    'remaining_amount' => $old_inv['remaining_amount'],
                    'due_date' => $old_inv['due_date'],
                    'payment_status' => $old_inv['payment_status']
                ]);
                
                $new_vals = json_encode([
                    'package_name' => $new_package,
                    'total_amount' => $new_total,
                    'paid_amount' => $new_paid,
                    'remaining_amount' => $new_remaining,
                    'due_date' => $new_due,
                    'payment_status' => $status
                ]);
                
                // Insert into invoice_revisions
                $ins = $pdo->prepare("INSERT INTO invoice_revisions (tenant_id, invoice_id, edited_by, previous_values, new_values, modification_reason) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$tenant_id, $edit_id, $tenant_id, $prev_vals, $new_vals, $modification_reason]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Modified invoice {$old_inv['invoice_number']}. Version logged.");
                set_session_alert("Invoice modified successfully and audit version history saved.", "success");
                header("Location: billing.php");
                exit;
            } else {
                $errors[] = "Invoice not found or unauthorized access.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database operation error: " . $e->getMessage();
        }
    }
}
?>

<!-- Header Layout -->
<div class="row align-items-center mb-4">
    <div class="col-sm-6">
        <h2 class="text-white mb-1"><i class="bi bi-receipt text-primary me-2"></i>Invoices & Billing</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Track receivables, collect payments, and generate monthly billing reports.</p>
    </div>
    <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
        <?php if ($action === 'list'): ?>
            <div class="d-inline-flex gap-2">
                <form action="billing.php" method="POST" onsubmit="return confirm('Generate monthly invoices for all Active subscribers who do not have an invoice this month?')">
                    <?php csrf_field(); ?>
                    <button type="submit" name="bulk_billing" class="btn btn-dark-glass py-2.5 px-3" style="font-size: 0.9rem;"><i class="bi bi-lightning text-warning me-1.5"></i>Auto-Bill All</button>
                </form>
                <a href="billing.php?action=add" class="btn btn-primary-gradient py-2.5 px-4" style="font-size: 0.9rem;"><i class="bi bi-plus-circle me-1.5"></i>Create Invoice</a>
            </div>
        <?php else: ?>
            <a href="billing.php" class="btn btn-dark-glass py-2.5 px-4" style="font-size: 0.9rem;"><i class="bi bi-arrow-left me-1.5"></i>Back to Invoices</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'list'): ?>
    
    <!-- PAGINATED BILLING LIST VIEW -->
    <?php
    $search = clean_input($_GET['search'] ?? '');
    $filter_status = clean_input($_GET['status'] ?? '');
    
    $query_parts = ["i.tenant_id = :tenant_id"];
    $query_params = [':tenant_id' => $tenant_id];
    
    if (!empty($search)) {
        $query_parts[] = "(c.name LIKE :search OR i.invoice_number LIKE :search)";
        $query_params[':search'] = '%' . $search . '%';
    }
    
    if (!empty($filter_status)) {
        $query_parts[] = "i.payment_status = :status";
        $query_params[':status'] = $filter_status;
    }
    
    $where_sql = implode(" AND ", $query_parts);
    
    // Pagination
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $limit = 15;
    
    $total_records = 0;
    $invoices = [];
    
    try {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices i LEFT JOIN customers c ON i.customer_id = c.id WHERE $where_sql");
        $count_stmt->execute($query_params);
        $total_records = (int)$count_stmt->fetchColumn();
        
        $total_pages = ceil($total_records / $limit);
        if ($total_pages < 1) $total_pages = 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $limit;
        
        $select_sql = "SELECT i.*, c.name as customer_name, c.phone as customer_phone FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            WHERE $where_sql 
            ORDER BY i.id DESC 
            LIMIT $limit OFFSET $offset";
            
        $stmt = $pdo->prepare($select_sql);
        foreach ($query_params as $param => $val) {
            $stmt->bindValue($param, $val);
        }
        $stmt->execute();
        $invoices = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Invoices select failure: " . $e->getMessage());
    }
    ?>
    
    <!-- Filter Card panel -->
    <div class="p-4 glass-card mb-4">
        <form action="billing.php" method="GET" class="row g-3 align-items-end">
            <div class="col-md-5 col-sm-6">
                <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Search Invoices</label>
                <div class="input-group">
                    <span class="input-group-text border-0" style="background: rgba(255,255,255,0.03); color: var(--text-dim);"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Subscriber Name, Invoice #" value="<?php echo e($search); ?>" style="font-size: 0.85rem;">
                </div>
            </div>
            
            <div class="col-md-5 col-sm-6">
                <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Payment Status</label>
                <select name="status" class="form-select" style="font-size: 0.85rem;">
                    <option value="">All Statuses</option>
                    <option value="paid" <?php echo ($filter_status === 'paid') ? 'selected' : ''; ?>>Paid</option>
                    <option value="partial" <?php echo ($filter_status === 'partial') ? 'selected' : ''; ?>>Partial</option>
                    <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>Pending</option>
                    <option value="overdue" <?php echo ($filter_status === 'overdue') ? 'selected' : ''; ?>>Overdue</option>
                </select>
            </div>
            
            <div class="col-md-2 text-end">
                <button type="submit" class="btn btn-primary-gradient w-100 py-2" style="font-size: 0.85rem;"><i class="bi bi-filter me-1.5"></i>Apply Filter</button>
            </div>
        </form>
    </div>
    
    <!-- Invoices grid table -->
    <div class="table-responsive-glass mb-4">
        <table class="table table-glass align-middle">
            <thead>
                <tr>
                    <th style="width: 20%;">Invoice Number</th>
                    <th style="width: 25%;">Subscriber</th>
                    <th style="width: 15%; text-align: right;">Total Fee</th>
                    <th style="width: 15%; text-align: right;">Paid / Remaining</th>
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
                            No invoices generated yet for this filter set.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="fw-bold text-white"><?php echo e($inv['invoice_number']); ?></td>
                            <td>
                                <span class="fw-bold text-white d-block" style="font-size: 0.88rem;"><?php echo e($inv['customer_name']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.72rem;">Pkg: <?php echo e($inv['package_name']); ?> &bull; <?php echo e($inv['customer_phone']); ?></small>
                                <?php if ($inv['proof_submitted'] == 1): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1 mt-1 d-inline-block" style="font-size: 0.68rem;" title="Method: <?php echo e($inv['payment_method']); ?> | Trans: <?php echo e($inv['transaction_id']); ?>">
                                        <i class="bi bi-info-circle me-1"></i>Proof Submitted
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-bold text-white"><?php echo format_currency($inv['total_amount']); ?></td>
                            <td class="text-end">
                                <span class="text-success d-block" style="font-size: 0.82rem;">Paid: <?php echo format_currency($inv['paid_amount']); ?></span>
                                <span class="text-danger d-block" style="font-size: 0.75rem;">Due: <?php echo format_currency($inv['remaining_amount']); ?></span>
                            </td>
                            <td class="text-center">
                                <span class="text-white d-block" style="font-size: 0.85rem;"><?php echo format_date($inv['due_date']); ?></span>
                                <?php if ($inv['payment_date']): ?>
                                    <small class="text-muted" style="font-size: 0.7rem;">Paid: <?php echo date('d M, Y', strtotime($inv['payment_date'])); ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo get_invoice_status_badge($inv['payment_status']); ?></td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1">
                                    <?php if ($inv['payment_status'] !== 'paid'): ?>
                                        <button type="button" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-success" data-bs-toggle="modal" data-bs-target="#payModal-<?php echo $inv['id']; ?>" title="Collect Payment"><i class="bi bi-wallet2"></i></button>
                                    <?php endif; ?>
                                    <a href="billing.php?action=edit&id=<?php echo $inv['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-warning" title="Edit Invoice Details"><i class="bi bi-pencil-square"></i></a>
                                    <a href="billing.php?action=view&id=<?php echo $inv['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-info" title="Print/View Slip"><i class="bi bi-printer"></i></a>
                                </div>
                                
                                <!-- Record Payment Modal -->
                                <div class="modal fade" id="payModal-<?php echo $inv['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px);">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                                            <div class="modal-header border-bottom border-white border-opacity-5">
                                                <h5 class="modal-title text-white">Record Customer Payment</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form action="billing.php" method="POST">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                                <input type="hidden" name="record_payment" value="1">
                                                
                                                <div class="modal-body p-4 d-flex flex-column gap-3">
                                                    <div>
                                                        <span class="text-muted" style="font-size: 0.8rem;">Subscriber Name</span>
                                                        <strong class="text-white d-block"><?php echo e($inv['customer_name']); ?></strong>
                                                    </div>
                                                    
                                                     <?php if ($inv['proof_submitted'] == 1): ?>
                                                         <div class="p-3 rounded-3 my-1" style="font-size: 0.85rem; background: rgba(14, 165, 233, 0.1); border: 1px solid rgba(14, 165, 233, 0.25); color: #E0F2FE;">
                                                             <strong class="d-block mb-2 text-white" style="font-size: 0.9rem;"><i class="bi bi-info-circle text-info me-1.5"></i>Submitted Payment Proof Details</strong>
                                                             <span class="text-muted">Channel:</span> <strong class="text-white"><?php echo e($inv['payment_method']); ?></strong><br>
                                                             <span class="text-muted">Transaction Ref:</span> <strong class="text-accent font-outfit"><?php echo e($inv['transaction_id']); ?></strong><br>
                                                             <span class="text-muted">Submitted Date:</span> <strong class="text-white"><?php echo format_date($inv['submission_date']); ?></strong><br>
                                                             <span class="text-muted">Notes:</span> <em class="text-white bg-dark bg-opacity-25 px-1.5 py-0.5 rounded d-inline-block mt-1"><?php echo e($inv['submission_notes'] ?: 'None'); ?></em>
                                                             
                                                             <?php if (!empty($inv['proof_image'])): ?>
                                                                 <div class="mt-3 border-top border-white border-opacity-5 pt-2.5">
                                                                     <span class="text-muted" style="font-size: 0.72rem; display: block; margin-bottom: 5px;">Uploaded Payment Receipt:</span>
                                                                     <a href="../uploads/proofs/<?php echo e($inv['proof_image']); ?>" target="_blank" class="badge bg-primary bg-opacity-10 text-primary border-0 py-2 px-2.5 text-decoration-none" style="font-size: 0.76rem; border: 1px solid rgba(168, 85, 247, 0.15) !important;">
                                                                         <i class="bi bi-image me-1"></i>View Image Proof
                                                                     </a>
                                                                 </div>
                                                             <?php endif; ?>
                                                         </div>
                                                     <?php endif; ?>
                                                    
                                                    <div class="row text-center bg-dark rounded-3 p-3 my-2" style="border: 1px solid var(--border-color);">
                                                        <div class="col">
                                                            <span class="text-muted d-block" style="font-size: 0.72rem;">Total Amount</span>
                                                            <strong class="text-white"><?php echo format_currency($inv['total_amount']); ?></strong>
                                                        </div>
                                                        <div class="col border-start border-white border-opacity-5">
                                                            <span class="text-muted d-block" style="font-size: 0.72rem;">Remaining Due</span>
                                                            <strong class="text-danger"><?php echo format_currency($inv['remaining_amount']); ?></strong>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Collect Paid Amount (Rs.)</label>
                                                        <input type="number" name="paid_amount" class="form-control" value="<?php echo $inv['remaining_amount']; ?>" required min="0.01" max="<?php echo $inv['remaining_amount']; ?>" step="0.01">
                                                        <small class="text-muted" style="font-size: 0.7rem;">Enter partial or full outstanding amount</small>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-top border-white border-opacity-5">
                                                    <button type="submit" class="btn btn-primary-gradient px-4">Save Payment</button>
                                                    <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination UI Grid -->
    <?php if ($total_pages > 1): ?>
        <nav class="d-flex justify-content-between align-items-center">
            <span class="text-muted" style="font-size: 0.85rem;">Showing <?php echo count($invoices); ?> of <?php echo $total_records; ?> invoices</span>
            <ul class="pagination">
                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="billing.php?action=list&page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>"><i class="bi bi-chevron-left"></i></a>
                </li>
                <?php for($p=1; $p<=$total_pages; $p++): ?>
                    <li class="page-item <?php echo ($page === $p) ? 'active' : ''; ?>">
                        <a class="page-link" href="billing.php?action=list&page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="billing.php?action=list&page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo $filter_status; ?>"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
    
<?php elseif ($action === 'add'): ?>
    
    <!-- CUSTOM INVOICE CREATION FORM -->
    <div class="p-4 p-md-5 glass-panel" style="max-width: 600px; margin: 0 auto; border-radius: 16px;">
        <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Generate Customer Invoice</h5>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form action="billing.php?action=add" method="POST" class="d-flex flex-column gap-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="create_invoice" value="1">
            
            <div>
                <label class="form-label text-muted" style="font-size: 0.8rem;">Select Subscriber</label>
                <select name="customer_id" class="form-select" required onchange="populateCustomerDefaults(this)">
                    <option value="">-- Select Active Customer --</option>
                    <?php 
                    $preselected_customer_id = (int)($_GET['customer_id'] ?? 0);
                    foreach ($customers_opt as $c): 
                    ?>
                        <option value="<?php echo $c['id']; ?>" data-package="<?php echo e($c['package_name'] ?? 'Custom Package'); ?>" data-fee="<?php echo $c['monthly_fee']; ?>" <?php echo ($preselected_customer_id === (int)$c['id']) ? 'selected' : ''; ?>>
                            <?php echo e($c['name']); ?> (Monthly Charge: <?php echo format_currency($c['monthly_fee']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-2">
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Billing Start Date</label>
                    <input type="date" name="billing_start_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Billing End Date</label>
                    <input type="date" name="billing_end_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Package Name / Mbps Speed</label>
                    <select name="package_name" id="package_name" class="form-select" required onchange="populatePackagePrice(this)">
                        <option value="">-- Select Package --</option>
                        <?php foreach ($packages_opt as $p): ?>
                            <option value="<?php echo e($p['name']); ?>" data-price="<?php echo $p['monthly_price']; ?>">
                                <?php echo e($p['name']); ?> (Rs. <?php echo number_format($p['monthly_price'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Billed Amount (Rs.)</label>
                    <input type="number" name="total_amount" id="total_amount" class="form-control" placeholder="1500" min="0.01" step="0.01" required>
                </div>
            </div>
            
            <div>
                <label class="form-label text-muted" style="font-size: 0.8rem;">Due Date</label>
                <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
            </div>
            
            <div class="d-flex gap-2 justify-content-end mt-3 border-top pt-3 border-white border-opacity-5">
                <button type="submit" class="btn btn-primary-gradient px-4 py-2.5">Generate Invoice</button>
                <a href="billing.php" class="btn btn-dark-glass px-4 py-2.5">Cancel</a>
            </div>
        </form>

        <script>
        function populateCustomerDefaults(select) {
            const option = select.options[select.selectedIndex];
            if (option && option.value) {
                const pkg = option.getAttribute('data-package') || '';
                const fee = option.getAttribute('data-fee') || '';
                
                const pkgSelect = document.getElementById('package_name');
                if (pkgSelect) {
                    pkgSelect.value = pkg;
                }
                document.getElementById('total_amount').value = fee;
            } else {
                document.getElementById('package_name').value = '';
                document.getElementById('total_amount').value = '';
            }
        }
        
        function populatePackagePrice(select) {
            const option = select.options[select.selectedIndex];
            if (option && option.value) {
                const price = option.getAttribute('data-price') || '';
                document.getElementById('total_amount').value = price;
            }
        }
        
        document.addEventListener("DOMContentLoaded", function() {
            const selectEl = document.querySelector('select[name="customer_id"]');
            if (selectEl && selectEl.value !== "") {
                populateCustomerDefaults(selectEl);
            }
        });
        </script>
    </div>
    
<?php elseif ($action === 'view' && $edit_id > 0): ?>
    
    <!-- PRINTABLE INVOICE VIEW -->
    <?php
    $invoice = null;
    try {
        $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address, z.name as zone_name 
            FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            LEFT JOIN zones z ON c.zone_id = z.id
            WHERE i.id = ? AND i.tenant_id = ?");
        $stmt->execute([$edit_id, $tenant_id]);
        $invoice = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Print lookup failure: " . $e->getMessage());
    }
    
    if (!$invoice) {
        set_session_alert("Invoice not found or unauthorized.", "error");
        header("Location: billing.php");
        exit;
    }
    ?>
    
    <!-- Printable Slip card -->
    <div class="p-4 p-md-5 bg-white text-dark rounded-3 mx-auto shadow" id="printableInvoiceCard" style="max-width: 800px; font-family: 'Inter', sans-serif;">
        <style>
        /* DIRECT HIGH-CONTRAST INLINE STYLE SHEET */
        #printableInvoiceCard {
            background-color: #FFFFFF !important;
            color: #111111 !important; /* Pure Dark Gray/Black for body */
        }
        #printableInvoiceCard h3,
        #printableInvoiceCard h4 {
            color: #1E3A8A !important; /* Royal Blue Title */
            font-weight: 800 !important;
        }
        #printableInvoiceCard .text-muted {
            color: #1E293B !important; /* Dark Slate Gray (Highly visible) */
            font-weight: 600 !important;
        }
        #printableInvoiceCard strong {
            color: #0F172A !important; /* Pure Slate 900 Black */
            font-weight: 700 !important;
        }
        #printableInvoiceCard span, 
        #printableInvoiceCard small, 
        #printableInvoiceCard em {
            color: #1F2937 !important; /* Dark Charcoal 800 */
            font-weight: 500 !important;
        }
        #printableInvoiceCard .bg-light {
            background-color: #F8FAFC !important; /* Clean light slate background */
            border: 2px solid #64748B !important; /* Slate 500 border line */
            color: #111111 !important;
        }
        #printableInvoiceCard .bg-light .text-muted {
            color: #312E81 !important; /* Deep Indigo for labels inside boxes */
            font-weight: 700 !important;
        }
        #printableInvoiceCard .bg-light strong {
            font-weight: 800 !important;
        }
        #printableInvoiceCard .bg-light small {
            color: #374151 !important; /* Highly visible text dark slate */
            font-weight: 600 !important;
        }
        #printableInvoiceCard .text-success {
            color: #16A34A !important; /* Emerald Green */
            font-weight: 700 !important;
        }
        #printableInvoiceCard .text-danger {
            color: #DC2626 !important; /* Deep Red */
            font-weight: 700 !important;
        }
        #printableInvoiceCard .text-warning {
            color: #D97706 !important; /* Amber Yellow/Brown */
            font-weight: 700 !important;
        }
        #printableInvoiceCard table {
            border: 2px solid #475569 !important;
        }
        #printableInvoiceCard table thead th {
            background-color: #EFF6FF !important; /* Light blue header background */
            color: #1E3A8A !important; /* Deep Royal Blue */
            border: 2px solid #475569 !important;
            font-weight: 800 !important;
        }
        #printableInvoiceCard table tbody td {
            color: #111111 !important; /* Black row text */
            border: 1px solid #64748B !important;
            font-weight: 500 !important;
        }
        #printableInvoiceCard table tbody td.fw-bold,
        #printableInvoiceCard table tbody td strong {
            color: #111111 !important;
            font-weight: 700 !important;
        }
        #printableInvoiceCard table tbody td.text-success {
            color: #16A34A !important;
        }
        #printableInvoiceCard table tbody td.text-danger {
            color: #DC2626 !important;
        }
        #printableInvoiceCard table tbody td.text-muted {
            color: #475569 !important;
        }
        #printableInvoiceCard .border-top {
            border-top: 2px solid #475569 !important;
        }
        #printableInvoiceCard strong[style*="color: var(--primary)"],
        #printableInvoiceCard span[style*="color: var(--primary)"] {
            color: #4F46E5 !important; /* Override indigo */
        }
        #printableInvoiceCard .border-bottom {
            border-bottom: 2px solid #CBD5E1 !important;
        }
        </style>
        <div class="d-flex justify-content-between align-items-start border-bottom pb-4 mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-1 font-outfit"><?php echo e($tenant_name); ?></h3>
                <span class="text-muted" style="font-size: 0.85rem;">Local ISP Services</span>
            </div>
            <div class="text-end">
                <h4 class="fw-bold text-dark font-outfit mb-1">INVOICE</h4>
                <strong style="color: var(--primary);"><?php echo e($invoice['invoice_number']); ?></strong>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-sm-6">
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; font-weight: 600;">Billing From:</span>
                <strong class="text-dark d-block"><?php echo e($tenant_name); ?> Operations</strong>
                <span class="text-muted d-block" style="font-size: 0.85rem;">Workspace: <?php echo e($tenant_subdomain); ?>.<?php echo e(get_platform_domain()); ?></span>
            </div>
            <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; font-weight: 600;">Invoiced To:</span>
                <strong class="text-dark d-block"><?php echo e($invoice['customer_name']); ?></strong>
                <span class="text-muted d-block style='font-size: 0.85rem;'">Phone: <?php echo e($invoice['customer_phone']); ?> &bull; Email: <?php echo e($invoice['customer_email']); ?></span>
                <span class="text-muted d-block" style="font-size: 0.82rem;"><?php echo e($invoice['customer_address']); ?> (Zone: <?php echo e($invoice['zone_name']); ?>)</span>
            </div>
        </div>
        
        <div class="row bg-light p-3 rounded mb-4" style="font-size: 0.88rem;">
            <div class="col-6 col-sm-3">
                <span class="text-muted d-block">Issued Date:</span>
                <strong class="text-dark"><?php echo date('d M, Y', strtotime($invoice['created_at'])); ?></strong>
            </div>
            <div class="col-6 col-sm-3">
                <span class="text-muted d-block">Due Date:</span>
                <strong class="text-dark"><?php echo format_date($invoice['due_date']); ?></strong>
            </div>
            <div class="col-6 col-sm-3 mt-2 mt-sm-0">
                <span class="text-muted d-block">Status:</span>
                <strong class="text-dark" style="text-transform: uppercase;"><?php echo e($invoice['payment_status']); ?></strong>
            </div>
            <div class="col-6 col-sm-3 mt-2 mt-sm-0 text-sm-end">
                <span class="text-muted d-block">Payment Date:</span>
                <strong class="text-dark"><?php echo $invoice['payment_date'] ? date('d M, Y', strtotime($invoice['payment_date'])) : 'N/A'; ?></strong>
            </div>
        </div>
        
        <table class="table table-bordered mb-4 align-middle">
            <thead class="bg-light text-dark font-outfit fw-bold" style="font-size: 0.88rem;">
                <tr>
                    <th>Item Description</th>
                    <th style="width: 25%; text-align: right;">Unit Price</th>
                    <th style="width: 25%; text-align: right;">Subtotal</th>
                </tr>
            </thead>
            <tbody style="font-size: 0.9rem; color: #374151;">
                <tr>
                    <td>
                        <strong>Monthly internet package: <?php echo e($invoice['package_name']); ?></strong>
                        <small class="text-muted d-block">30 days unlimited broadband coverage speed quota</small>
                    </td>
                    <td class="text-end fw-bold"><?php echo format_currency($invoice['total_amount']); ?></td>
                    <td class="text-end fw-bold"><?php echo format_currency($invoice['total_amount']); ?></td>
                </tr>
            </tbody>
        </table>
        
        <div class="row justify-content-end mb-4">
            <div class="col-sm-5 text-end">
                <div class="d-flex justify-content-between border-bottom py-1.5" style="font-size: 0.88rem;">
                    <span class="text-muted">Total Gross:</span>
                    <strong class="text-dark"><?php echo format_currency($invoice['total_amount']); ?></strong>
                </div>
                <div class="d-flex justify-content-between border-bottom py-1.5" style="font-size: 0.88rem;">
                    <span class="text-success">Paid Amount:</span>
                    <strong class="text-success"><?php echo format_currency($invoice['paid_amount']); ?></strong>
                </div>
                <div class="d-flex justify-content-between py-2 fw-bold text-dark fs-5">
                    <span>Due Balance:</span>
                    <span><?php echo format_currency($invoice['remaining_amount']); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Submitted Payment Proof Details (High Contrast & Verifiable) -->
        <?php if ($invoice['proof_submitted'] == 1 || !empty($invoice['transaction_id']) || !empty($invoice['proof_image'])): ?>
            <div class="mt-4 border-top pt-4 text-start shadow-none" id="invoicePaymentProofSection">
                <h6 class="fw-bold text-dark font-outfit mb-3"><i class="bi bi-shield-check text-success me-1.5"></i>Submitted Payment Proof & Verification Info</h6>
                <div class="p-3 bg-light rounded border mb-3" style="font-size: 0.85rem; border-color: #CBD5E1 !important;">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase;">Payment Method / Channel:</span>
                            <strong class="text-dark"><?php echo e($invoice['payment_method'] ?: 'N/A'); ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase;">Transaction Reference / ID:</span>
                            <strong class="text-dark font-outfit"><?php echo e($invoice['transaction_id'] ?: 'N/A'); ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase;">Submission Date & Time:</span>
                            <strong class="text-dark"><?php echo $invoice['submission_date'] ? date('d M, Y H:i', strtotime($invoice['submission_date'])) : 'N/A'; ?></strong>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase;">Verification Status:</span>
                            <?php if ($invoice['payment_status'] === 'paid'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2.5 py-1" style="font-size: 0.72rem; font-weight: 700;">
                                    <i class="bi bi-check-circle-fill me-1"></i>Approved & Verified
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2.5 py-1" style="font-size: 0.72rem; font-weight: 700;">
                                    <i class="bi bi-hourglass-split me-1"></i>Awaiting Approval
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($invoice['verified_by_admin'])): ?>
                            <div class="col-sm-12">
                                <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase;">Verified By Operator:</span>
                                <strong class="text-indigo" style="color: #312E81 !important;"><?php echo e($invoice['verified_by_admin']); ?></strong>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($invoice['submission_notes'])): ?>
                            <div class="col-sm-12">
                                <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase;">Remarks & Notes:</span>
                                <em class="text-dark d-block bg-white bg-opacity-50 p-2 rounded border border-dotted mt-1"><?php echo e($invoice['submission_notes']); ?></em>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($invoice['proof_image'])): ?>
                            <div class="col-sm-12 d-print-none">
                                <span class="text-muted d-block mb-1.5" style="font-size: 0.75rem; text-transform: uppercase;">Uploaded Payment Receipt Image:</span>
                                <div class="bg-white p-2 rounded border text-center" style="max-width: 400px; border-color: #CBD5E1 !important;">
                                    <img src="../uploads/proofs/<?php echo e($invoice['proof_image']); ?>" class="img-fluid rounded shadow-sm mb-2" style="max-height: 250px; object-fit: contain; display: block; margin: 0 auto;" alt="Payment Proof Receipt">
                                    <a href="../uploads/proofs/<?php echo e($invoice['proof_image']); ?>" target="_blank" class="btn btn-outline-primary btn-sm px-3 py-1 font-outfit" style="font-size: 0.78rem;">
                                        <i class="bi bi-zoom-in me-1"></i>View Full Resolution Image
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Revision Timeline History Log (Ensuring full transparency) -->
        <?php
        $revisions = [];
        try {
            $stmt = $pdo->prepare("SELECT * FROM invoice_revisions WHERE invoice_id = ? AND tenant_id = ? ORDER BY id DESC");
            $stmt->execute([$edit_id, $tenant_id]);
            $revisions = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Revisions select fail: " . $e->getMessage());
        }
        ?>
        
        <?php if (!empty($revisions)): ?>
            <div class="mt-4 border-top pt-4 text-start shadow-none" id="invoiceRevisionTimeline">
                <h6 class="fw-bold text-dark font-outfit mb-3"><i class="bi bi-clock-history text-secondary me-1.5"></i>Invoice Version History (Change Logs Audit)</h6>
                <div class="d-flex flex-column gap-2.5">
                    <?php foreach ($revisions as $rev): 
                        $prev = json_decode($rev['previous_values'], true);
                        $new_val = json_decode($rev['new_values'], true);
                        ?>
                        <div class="p-3 bg-light rounded border-start" style="font-size: 0.82rem; color: #4B5563; border-left: 3px solid var(--secondary) !important;">
                            <div class="d-flex justify-content-between mb-2">
                                <strong>Modified by Workspace Admin</strong>
                                <span class="text-muted" style="font-size: 0.75rem;"><?php echo date('d M, Y H:i', strtotime($rev['change_timestamp'])); ?></span>
                            </div>
                            <p class="mb-2"><strong>Reason for modification:</strong> <?php echo e($rev['modification_reason']); ?></p>
                            <div class="row g-2 text-center text-dark" style="font-size: 0.78rem; background: rgba(0,0,0,0.02); padding: 0.5rem 0; border-radius: 4px;">
                                <div class="col-6 col-sm-3">
                                    <span class="d-block text-muted" style="font-size: 0.7rem;">Total Bill Amount:</span>
                                    <span><?php echo format_currency($prev['total_amount']); ?> &rarr; <strong><?php echo format_currency($new_val['total_amount']); ?></strong></span>
                                </div>
                                <div class="col-6 col-sm-3 border-start">
                                    <span class="d-block text-muted" style="font-size: 0.7rem;">Paid Amount:</span>
                                    <span><?php echo format_currency($prev['paid_amount']); ?> &rarr; <strong><?php echo format_currency($new_val['paid_amount']); ?></strong></span>
                                </div>
                                <div class="col-6 col-sm-3 border-start">
                                    <span class="d-block text-muted" style="font-size: 0.7rem;">Due Date:</span>
                                    <span><?php echo format_date($prev['due_date']); ?> &rarr; <strong><?php echo format_date($new_val['due_date']); ?></strong></span>
                                </div>
                                <div class="col-6 col-sm-3 border-start">
                                    <span class="d-block text-muted" style="font-size: 0.7rem;">Payment Status:</span>
                                    <span class="text-uppercase" style="font-weight: 600; font-size: 0.72rem;"><?php echo e($prev['payment_status']); ?> &rarr; <strong><?php echo e($new_val['payment_status']); ?></strong></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="text-center text-muted border-top pt-4 mt-4" style="font-size: 0.8rem;">
            Thank you for choosing <?php echo e($tenant_name); ?>. Please clear outstanding amounts before the due date to avoid service interruption.<br>
            <?php echo e(get_platform_name()); ?> Multi-Tenant Service Provider Ledger &bull; Receipt generated securely.
        </div>
    </div>
    
    <div class="text-center mt-4">
        <button class="btn btn-primary-gradient px-5 py-2.5" onclick="window.print()"><i class="bi bi-printer me-1.5"></i>Print Invoice / Save PDF</button>
    </div>
    
    <style>
    @media print {
        body * {
            visibility: hidden;
        }
        #printableInvoiceCard, #printableInvoiceCard * {
            visibility: visible;
        }
        #printableInvoiceCard {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
        }
    }
    </style>
    
<?php elseif ($action === 'edit' && $edit_id > 0): ?>
    
    <!-- CUSTOM INVOICE MODIFICATION & AUDITING FORM -->
    <?php
    $invoice = null;
    try {
        $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            WHERE i.id = ? AND i.tenant_id = ? LIMIT 1");
        $stmt->execute([$edit_id, $tenant_id]);
        $invoice = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Edit load failure: " . $e->getMessage());
    }
    
    if (!$invoice) {
        set_session_alert("Invoice not found or unauthorized.", "error");
        header("Location: billing.php");
        exit;
    }
    ?>
    
    <div class="p-4 p-md-5 glass-panel" style="max-width: 650px; margin: 0 auto; border-radius: 16px;">
        <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;">
            <i class="bi bi-pencil-square me-2 text-primary"></i>Modify Invoice Details: <?php echo e($invoice['invoice_number']); ?>
        </h5>
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo e($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form action="billing.php?action=edit&id=<?php echo $edit_id; ?>" method="POST" class="d-flex flex-column gap-3">
            <?php csrf_field(); ?>
            <input type="hidden" name="edit_invoice" value="1">
            
            <div class="row g-2">
                <div class="col">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Subscriber Contact</label>
                    <input type="text" class="form-control" value="<?php echo e($invoice['customer_name']); ?>" disabled style="opacity: 0.6; background: rgba(0,0,0,0.2);">
                </div>
                <div class="col">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Package Snap Label</label>
                    <select name="package_name" class="form-select" required>
                        <option value="">-- Select Package --</option>
                        <?php foreach ($packages_opt as $p): ?>
                            <option value="<?php echo e($p['name']); ?>" <?php echo ($invoice['package_name'] === $p['name']) ? 'selected' : ''; ?>>
                                <?php echo e($p['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="row g-2">
                <div class="col">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Total Bill (Rs.)</label>
                    <input type="number" name="total_amount" class="form-control" value="<?php echo $invoice['total_amount']; ?>" required min="0.01" step="0.01">
                </div>
                <div class="col">
                    <label class="form-label text-muted" style="font-size: 0.8rem;">Paid Amount Collected (Rs.)</label>
                    <input type="number" name="paid_amount" class="form-control" value="<?php echo $invoice['paid_amount']; ?>" required min="0" step="0.01">
                </div>
            </div>
            
            <div>
                <label class="form-label text-muted" style="font-size: 0.8rem;">Due Date</label>
                <input type="date" name="due_date" class="form-control" value="<?php echo $invoice['due_date']; ?>" required>
            </div>
            
            <div>
                <label class="form-label text-danger" style="font-size: 0.8rem; font-weight: 600;">Modification Reason (Required for Audit Trail) *</label>
                <textarea name="modification_reason" class="form-control border-danger border-opacity-25" rows="3" placeholder="Enter justification (e.g. customer router discount, billing discrepancy resolution, package adjustments)..." required></textarea>
            </div>
            
            <div class="d-flex gap-2 justify-content-end mt-3 border-top pt-3 border-white border-opacity-5">
                <button type="submit" class="btn btn-primary-gradient px-4 py-2.5">Apply Modification</button>
                <a href="billing.php" class="btn btn-dark-glass px-4 py-2.5">Cancel</a>
            </div>
        </form>
    </div>
    
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
