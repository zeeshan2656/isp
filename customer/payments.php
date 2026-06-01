<?php
/**
 * Customer Invoice Log & Printable Slips
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$edit_id = (int)($_GET['id'] ?? 0);

// Process payment proof submission for broadband invoices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_broadband_proof'])) {
    verify_csrf_token();
    
    // Auto-migrate: ensure proof_image column exists in invoices
    try {
        $pdo->exec("ALTER TABLE invoices ADD COLUMN proof_image VARCHAR(500) DEFAULT NULL AFTER transaction_id");
    } catch (PDOException $e) {
        // Column already exists, safe to ignore
    }
    
    $invoice_id = (int)($_POST['invoice_id'] ?? 0);
    $method = clean_input($_POST['payment_method'] ?? '');
    $trans_id = clean_input($_POST['transaction_id'] ?? '');
    $notes = clean_input($_POST['submission_notes'] ?? '');
    
    if ($invoice_id <= 0) $errors[] = "Please select a valid invoice.";
    if (empty($method)) $errors[] = "Payment method is required.";
    if (empty($trans_id)) $errors[] = "Transaction Reference / ID is required.";
    
    if (empty($errors)) {
        try {
            $stmt_inv = $pdo->prepare("SELECT total_amount, remaining_amount FROM invoices WHERE id = ? AND tenant_id = ? AND customer_id = ?");
            $stmt_inv->execute([$invoice_id, $customer_tenant_id, $customer_id]);
            $inv_data = $stmt_inv->fetch();
            
            if ($inv_data) {
                $amount_submitted = (double)$inv_data['remaining_amount'];
                
                // Handle optional file upload proof receipt
                $proof_image_name = null;
                if (isset($_FILES['proof_image']) && $_FILES['proof_image']['error'] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['proof_image']['tmp_name'];
                    $file_name = $_FILES['proof_image']['name'];
                    $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                    
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
                    if (in_array($file_ext, $allowed_exts)) {
                        $upload_dir = __DIR__ . '/../uploads/proofs/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $proof_image_name = 'proof_' . uniqid() . '.' . $file_ext;
                        move_uploaded_file($file_tmp, $upload_dir . $proof_image_name);
                    } else {
                        throw new Exception("Invalid file extension. Only JPG, PNG, and GIF images are allowed.");
                    }
                }
                
                $pdo->beginTransaction();
                
                // Update the invoice status to pending review
                $stmt = $pdo->prepare("UPDATE invoices SET payment_method = ?, transaction_id = ?, proof_submitted = 1, submission_notes = ?, submission_date = NOW(), proof_image = ? WHERE id = ? AND customer_id = ? AND tenant_id = ? AND payment_status != 'paid'");
                $stmt->execute([$method, $trans_id, $notes, $proof_image_name, $invoice_id, $customer_id, $customer_tenant_id]);
                
                // Insert into payment_submissions central table
                $stmt_sub = $pdo->prepare("INSERT INTO payment_submissions (tenant_id, payer_type, payer_id, invoice_type, invoice_id, payment_method, transaction_id, amount, submission_notes, status, proof_image) 
                    VALUES (?, 'customer', ?, 'invoice', ?, ?, ?, ?, ?, 'pending', ?)");
                $stmt_sub->execute([$customer_tenant_id, $customer_id, $invoice_id, $method, $trans_id, $amount_submitted, $notes, $proof_image_name]);
                
                $pdo->commit();
                
                // Log Audit
                log_audit_activity($pdo, $customer_tenant_id, 'customer', $customer_id, "Submitted payment proof for broadband Invoice ID: $invoice_id (Trans: $trans_id)");
                
                set_session_alert("Broadband payment proof submitted successfully! Your ISP will review and activate your speed shortly.", "success");
            } else {
                $errors[] = "Invoice not found or unauthorized access.";
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_session_alert("Database error during submission: " . $e->getMessage(), "error");
        }
        header("Location: payments.php");
        exit;
    }
}

if ($action === 'list'):
    
    // Pagination Controls
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $limit = 15;
    
    $total_records = 0;
    $invoices = [];
    
    try {
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE customer_id = ? AND tenant_id = ?");
        $count_stmt->execute([$customer_id, $customer_tenant_id]);
        $total_records = (int)$count_stmt->fetchColumn();
        
        $total_pages = ceil($total_records / $limit);
        if ($total_pages < 1) $total_pages = 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $limit;
        
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT $limit OFFSET $offset");
        $stmt->execute([$customer_id, $customer_tenant_id]);
        $invoices = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Customer invoices fetch failure: " . $e->getMessage());
    }
    ?>
    
    <div class="row align-items-center mb-4">
        <div class="col">
            <h2 class="text-white mb-1"><i class="bi bi-wallet2 text-secondary me-2"></i>Billing & Invoices Ledger</h2>
            <p class="text-muted mb-0" style="font-size: 0.95rem;">Review outstanding dues, clear billing transactions, and download printing statements.</p>
        </div>
    </div>
    
    <!-- Invoice Grid Table -->
    <div class="table-responsive-glass mb-4">
        <table class="table table-glass align-middle">
            <thead>
                <tr>
                    <th style="width: 25%;">Invoice Number</th>
                    <th style="width: 20%;">Package Snap</th>
                    <th style="width: 15%; text-align: right;">Total Bill</th>
                    <th style="width: 15%; text-align: right;">Paid Value</th>
                    <th style="width: 15%; text-align: center;">Due Date</th>
                    <th style="width: 10%; text-align: center;">Status</th>
                    <th style="width: 10%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted" style="font-size: 0.9rem;">
                            <i class="bi bi-receipt fs-2 mb-2 d-block opacity-40"></i>
                            No billing invoice recorded for your subscriber account.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="fw-bold text-white"><?php echo e($inv['invoice_number']); ?></td>
                            <td class="text-muted" style="font-size: 0.88rem;"><?php echo e($inv['package_name']); ?></td>
                            <td class="text-end fw-bold text-white"><?php echo format_currency($inv['total_amount']); ?></td>
                            <td class="text-end text-success fw-bold"><?php echo format_currency($inv['paid_amount']); ?></td>
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
                                        <?php if ($inv['proof_submitted'] == 1): ?>
                                            <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1.5" style="font-size: 0.7rem;" title="Reviewing payment proof">Pending Review</span>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-primary" style="border-color: rgba(168, 85, 247, 0.15) !important;" data-bs-toggle="modal" data-bs-target="#payModal-<?php echo $inv['id']; ?>" title="Submit Payment Proof"><i class="bi bi-send-fill text-primary"></i></button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="payments.php?action=view&id=<?php echo $inv['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-secondary" style="border-color: rgba(168, 85, 247, 0.15) !important;" title="View printable slip"><i class="bi bi-printer"></i></a>
                                </div>

                                <!-- Record Payment Modal -->
                                <div class="modal fade" id="payModal-<?php echo $inv['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px);">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                                            <div class="modal-header border-bottom border-white border-opacity-5">
                                                <h5 class="modal-title text-white">Submit Payment Proof</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <form action="payments.php" method="POST" enctype="multipart/form-data">
                                                <?php csrf_field(); ?>
                                                <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                                <input type="hidden" name="submit_broadband_proof" value="1">
                                                
                                                <div class="modal-body p-4 d-flex flex-column gap-3">
                                                    <div>
                                                        <span class="text-muted" style="font-size: 0.8rem;">Invoice Number</span>
                                                        <strong class="text-white d-block"><?php echo e($inv['invoice_number']); ?></strong>
                                                    </div>
                                                    <div class="row text-center bg-dark rounded-3 p-3 my-2" style="border: 1px solid var(--border-color); background: rgba(0,0,0,0.2) !important;">
                                                        <div class="col">
                                                            <span class="text-muted d-block" style="font-size: 0.72rem;">Billed Package</span>
                                                            <strong class="text-white"><?php echo e($inv['package_name']); ?></strong>
                                                        </div>
                                                        <div class="col border-start border-white border-opacity-5">
                                                            <span class="text-muted d-block" style="font-size: 0.72rem;">Total Dues</span>
                                                            <strong class="text-danger"><?php echo format_currency($inv['remaining_amount']); ?></strong>
                                                        </div>
                                                    </div>
                                                    <div class="row g-2">
                                                        <div class="col-sm-6">
                                                            <label class="form-label text-muted" style="font-size: 0.8rem;">Payment Channel</label>
                                                            <select name="payment_method" class="form-select text-white" style="font-size: 0.85rem;" required>
                                                                <option value="Cash Payment">Cash Payment</option>
                                                                <option value="EasyPaisa Wallet">EasyPaisa Wallet</option>
                                                                <option value="JazzCash Wallet">JazzCash Wallet</option>
                                                                <option value="Bank Wire Transfer">Bank Wire Transfer</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <label class="form-label text-muted" style="font-size: 0.8rem;">Transaction ID / Ref #</label>
                                                            <input type="text" name="transaction_id" class="form-control" placeholder="TXN9988123" required>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Upload Payment Receipt (Optional)</label>
                                                        <input type="file" name="proof_image" class="form-control" accept="image/*">
                                                        <small class="text-muted" style="font-size: 0.68rem; display: block; margin-top: 2px;">Supported formats: JPG, JPEG, PNG, GIF (Max 5MB)</small>
                                                    </div>
                                                    <div>
                                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Remarks / Notes</label>
                                                        <textarea name="submission_notes" class="form-control" rows="2" placeholder="Account name or bank code details..."></textarea>
                                                    </div>
                                                </div>
                                                <div class="modal-footer border-top border-white border-opacity-5">
                                                    <button type="submit" class="btn btn-primary-gradient px-4">Submit Proof</button>
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
                    <a class="page-link" href="payments.php?action=list&page=<?php echo $page-1; ?>"><i class="bi bi-chevron-left"></i></a>
                </li>
                <?php for($p=1; $p<=$total_pages; $p++): ?>
                    <li class="page-item <?php echo ($page === $p) ? 'active' : ''; ?>">
                        <a class="page-link" href="payments.php?action=list&page=<?php echo $p; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="payments.php?action=list&page=<?php echo $page+1; ?>"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
    
<?php elseif ($action === 'view' && $edit_id > 0): ?>
    
    <!-- PRINTABLE SLIP FOR CLIENT -->
    <?php
    $invoice = null;
    try {
        $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.email as customer_email, c.phone as customer_phone, c.address as customer_address, z.name as zone_name 
            FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            LEFT JOIN zones z ON c.zone_id = z.id
            WHERE i.id = ? AND i.customer_id = ? AND i.tenant_id = ?");
        $stmt->execute([$edit_id, $customer_id, $customer_tenant_id]);
        $invoice = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Print lookup customer failure: " . $e->getMessage());
    }
    
    if (!$invoice) {
        set_session_alert("Invoice not found or unauthorized.", "error");
        header("Location: payments.php");
        exit;
    }
    ?>
    
    <div class="row align-items-center mb-4">
        <div class="col">
            <a href="payments.php" class="btn btn-dark-glass py-2 px-3" style="font-size: 0.9rem;"><i class="bi bi-arrow-left me-1.5"></i>Back to Billing Ledger</a>
        </div>
    </div>
    
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
                <h3 class="fw-bold text-dark mb-1 font-outfit"><?php echo e($isp_name); ?></h3>
                <span class="text-muted" style="font-size: 0.85rem;">Broadband Service Provider</span>
            </div>
            <div class="text-end">
                <h4 class="fw-bold text-dark font-outfit mb-1">INVOICE</h4>
                <strong style="color: var(--secondary);"><?php echo e($invoice['invoice_number']); ?></strong>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-sm-6">
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; font-weight: 600;">Billing From:</span>
                <strong class="text-dark d-block"><?php echo e($isp_name); ?> Operations</strong>
                <span class="text-muted d-block" style="font-size: 0.85rem;">Client portal desk</span>
            </div>
            <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem; text-transform: uppercase; font-weight: 600;">Invoiced To:</span>
                <strong class="text-dark d-block"><?php echo e($invoice['customer_name']); ?></strong>
                <span class="text-muted d-block" style="font-size: 0.85rem;">Phone: <?php echo e($invoice['customer_phone']); ?> &bull; Email: <?php echo e($invoice['customer_email']); ?></span>
                <span class="text-muted d-block" style="font-size: 0.82rem;"><?php echo e($invoice['customer_address']); ?></span>
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
                        <small class="text-muted d-block">30 days broadband speeds quota</small>
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
        
        <div class="text-center text-muted border-top pt-4 mt-4" style="font-size: 0.8rem;">
            Thank you for choosing <?php echo e($isp_name); ?>. Please contact support helpline for immediate renewals.<br>
            <?php echo e(get_platform_name()); ?> Multi-Tenant Customer Desk &bull; Receipt generated securely.
        </div>
    </div>
    
    <div class="text-center mt-4">
        <button class="btn btn-primary-gradient px-5 py-2.5" onclick="window.print()" style="background: linear-gradient(135deg, var(--secondary), var(--accent));"><i class="bi bi-printer me-1.5"></i>Print Invoice / Save PDF</button>
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
    
<?php endif; ?>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
