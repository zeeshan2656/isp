<?php
/**
 * Tenant Monthly Bandwidth Costing & Revenue Analytics Ledger
 * Premium styled interface for multi-month billing analytics.
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');

// Handle Delete Action
if ($action === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM monthly_costs WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$id, $tenant_id]);
        log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Deleted bandwidth costing record ID: $id");
        set_session_alert("Bandwidth costing record deleted successfully.", "success");
        header("Location: costing_ledger.php");
        exit;
    } catch (PDOException $e) {
        set_session_alert("Failed to delete record: " . $e->getMessage(), "error");
    }
}

// Handle Form Submission (Add / Update via ON DUPLICATE KEY UPDATE)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cost'])) {
    verify_csrf_token();
    
    $month = (int)($_POST['month'] ?? 0);
    $year = (int)($_POST['year'] ?? 0);
    $cost_type = clean_input($_POST['cost_type'] ?? 'Bandwidth Cost');
    $bandwidth = (int)($_POST['bandwidth'] ?? 0);
    $cost = (double)($_POST['cost'] ?? 0.00);
    $notes = clean_input($_POST['notes'] ?? '');
    
    if ($month < 1 || $month > 12) $errors[] = "Invalid month selected.";
    if ($year < 2020 || $year > 2035) $errors[] = "Invalid year selected.";
    if (empty($cost_type)) $errors[] = "Please select or enter a valid cost category.";
    if ($bandwidth < 0) $errors[] = "Bandwidth speed must be greater than or equal to 0 Mbps.";
    if ($cost < 0) $errors[] = "Expense cost must be greater than or equal to Rs. 0.";
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO monthly_costs (tenant_id, month, year, cost_type, bandwidth_purchased_mbps, total_cost, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE bandwidth_purchased_mbps = VALUES(bandwidth_purchased_mbps), total_cost = VALUES(total_cost), notes = VALUES(notes)");
            $stmt->execute([$tenant_id, $month, $year, $cost_type, $bandwidth, $cost, $notes]);
            
            log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Saved monthly cost ($cost_type): Month $month, Year $year ($bandwidth Mbps, Cost: Rs. $cost)");
            set_session_alert("Costing ledger record updated successfully.", "success");
            header("Location: costing_ledger.php");
            exit;
        } catch (PDOException $e) {
            $errors[] = "Database operation failure: " . $e->getMessage();
        }
    }
}

// Fetch all ledger rows with aggregated revenue
$ledger_rows = [];
$total_purchased_mbps = 0;
$total_bandwidth_costs = 0.00;
$total_revenue_collected = 0.00;

try {
    // Select costing categories
    $stmt = $pdo->prepare("SELECT mc.*, 
           (SELECT COALESCE(SUM(i.paid_amount), 0) 
            FROM invoices i 
            WHERE i.tenant_id = mc.tenant_id 
              AND YEAR(i.payment_date) = mc.year 
              AND MONTH(i.payment_date) = mc.month 
              AND i.payment_status IN ('paid', 'partial')) as revenue
    FROM monthly_costs mc
    WHERE mc.tenant_id = ?
    ORDER BY mc.year DESC, mc.month DESC, mc.cost_type ASC");
    $stmt->execute([$tenant_id]);
    $ledger_rows = $stmt->fetchAll();
    
    // Calculate total summary parameters
    foreach ($ledger_rows as $row) {
        $total_purchased_mbps += $row['bandwidth_purchased_mbps'];
        $total_bandwidth_costs += $row['total_cost'];
    }
    
    // Query actual total revenue collected directly to avoid double counting across multiple cost items per month
    $stmt_rev = $pdo->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM invoices WHERE tenant_id = ? AND payment_status IN ('paid', 'partial')");
    $stmt_rev->execute([$tenant_id]);
    $total_revenue_collected = (double)$stmt_rev->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Failed to load costing ledger stats: " . $e->getMessage());
}

$net_profit_total = $total_revenue_collected - $total_bandwidth_costs;
$margin_total = $total_revenue_collected > 0 ? ($net_profit_total / $total_revenue_collected) * 100 : ($total_bandwidth_costs > 0 ? -100 : 0);
?>

<!-- Header Section -->
<div class="row align-items-center mb-4 d-print-none">
    <div class="col-sm-8">
        <h2 class="text-white mb-1"><i class="bi bi-journal-text text-primary me-2"></i>Bandwidth Costing & Profit Ledger</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Manage monthly bandwidth purchase expenses, track customer revenue collections, and audit multi-month business profitability margins.</p>
    </div>
    <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
        <button class="btn btn-dark-glass py-2.5 px-4" onclick="window.print()" style="font-size: 0.9rem;"><i class="bi bi-printer me-1.5"></i>Print Ledger</button>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger border-0 text-white bg-danger bg-opacity-10 py-3 mb-4 d-print-none">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
                <li><?php echo e($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Combined Financial Analytics Summaries -->
<div class="row g-4 mb-4 d-print-none">
    <div class="col-md-3 col-sm-6">
        <div class="glass-card p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted" style="font-size: 0.85rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Bandwidth Purchased</span>
                <i class="bi bi-speedometer2 text-info fs-4"></i>
            </div>
            <h3 class="text-white fw-bold mb-1 font-outfit"><?php echo format_bandwidth($total_purchased_mbps); ?></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Cumulative Mbps allocated</span>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="glass-card p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted" style="font-size: 0.85rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Bandwidth Cost</span>
                <i class="bi bi-cash-stack text-danger fs-4"></i>
            </div>
            <h3 class="text-white fw-bold mb-1 font-outfit"><?php echo format_currency($total_bandwidth_costs); ?></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Cumulative purchase expense</span>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="glass-card p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted" style="font-size: 0.85rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Subscriber Revenue</span>
                <i class="bi bi-wallet2 text-success fs-4"></i>
            </div>
            <h3 class="text-white fw-bold mb-1 font-outfit"><?php echo format_currency($total_revenue_collected); ?></h3>
            <span class="text-muted" style="font-size: 0.78rem;">Aggregated invoice collections</span>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="glass-card p-4 h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted" style="font-size: 0.85rem; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px;">Net Profitability</span>
                <i class="bi <?php echo $net_profit_total >= 0 ? 'bi-graph-up-arrow text-success' : 'bi-graph-down-arrow text-danger'; ?> fs-4"></i>
            </div>
            <h3 class="<?php echo $net_profit_total >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold mb-1 font-outfit"><?php echo format_currency($net_profit_total); ?></h3>
            <span class="badge <?php echo $net_profit_total >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger'; ?> border-0 font-outfit" style="font-size: 0.75rem;">
                <?php echo number_format($margin_total, 1); ?>% Net Margin
            </span>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Cost Entry Form -->
    <div class="col-xl-4 col-lg-5 d-print-none">
        <div class="glass-card p-4">
            <h4 class="text-white mb-3 font-outfit" id="form-title"><i class="bi bi-pencil-square text-primary me-2"></i>Add / Update Cost</h4>
            <p class="text-muted mb-4" style="font-size: 0.85rem;">Input or edit bandwidth bandwidth and pricing parameters below. The system automatically reconciles matching invoices for that month.</p>
            
            <form action="costing_ledger.php" method="POST" id="costing-form">
                <?php csrf_field(); ?>
                
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Month</label>
                        <select name="month" id="form-month" class="form-select bg-dark-glass text-white border-white border-opacity-10 py-2.5" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == date('n') ? 'selected' : ''; ?>>
                                    <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Year</label>
                        <select name="year" id="form-year" class="form-select bg-dark-glass text-white border-white border-opacity-10 py-2.5" required>
                            <?php $curr_y = (int)date('Y'); ?>
                            <?php for ($y = $curr_y - 3; $y <= $curr_y + 2; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $curr_y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Cost Category / Type</label>
                        <select name="cost_type" id="form-cost-type" class="form-select bg-dark-glass text-white border-white border-opacity-10 py-2.5" required onchange="toggleBandwidthField()">
                            <option value="Bandwidth Cost">Bandwidth Purchase Cost</option>
                            <option value="SaaS Platform Fee">SaaS Plan Subscription</option>
                            <option value="Office Rental">Office Rental</option>
                            <option value="Staff Salaries">Staff Salaries</option>
                            <option value="Utility Bills">Utility Bills</option>
                            <option value="Hardware Equipment">Hardware / Optical Fibers</option>
                            <option value="Marketing Expense">Advertising & Marketing</option>
                            <option value="Miscellaneous Cost">Miscellaneous / Other</option>
                        </select>
                    </div>
                    <div class="col-12" id="bandwidth-container">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Total Bandwidth Speed (Mbps)</label>
                        <div class="input-group">
                            <span class="input-group-text border-white border-opacity-10 text-muted" style="background: rgba(0, 0, 0, 0.25) !important; color: var(--text-dim) !important; border: 1px solid rgba(255,255,255,0.1) !important;">Mbps</span>
                            <input type="number" name="bandwidth" id="form-bandwidth" class="form-control bg-dark-glass text-white border-white border-opacity-10 py-2.5" placeholder="e.g. 5000" min="0" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Expense / Cost Amount (Rs.)</label>
                        <div class="input-group">
                            <span class="input-group-text border-white border-opacity-10 text-muted" style="background: rgba(0, 0, 0, 0.25) !important; color: var(--text-dim) !important; border: 1px solid rgba(255,255,255,0.1) !important;">Rs.</span>
                            <input type="number" step="0.01" name="cost" id="form-cost" class="form-control bg-dark-glass text-white border-white border-opacity-10 py-2.5" placeholder="e.g. 50000.00" min="0" required>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Notes / Estimate Description</label>
                        <textarea name="notes" id="form-notes" class="form-control bg-dark-glass text-white border-white border-opacity-10" rows="3" placeholder="Define estimated vs actual costs, adjustments, provider source..."></textarea>
                    </div>
                    
                    <div class="col-12 mt-4 d-flex gap-2">
                        <button type="submit" name="save_cost" class="btn btn-primary-gradient py-2.5 px-4 w-100" style="font-size: 0.9rem;">
                            <i class="bi bi-check-circle me-1.5"></i>Save Costing
                        </button>
                        <button type="button" id="cancel-edit-btn" class="btn btn-dark-glass py-2.5 px-3 d-none" onclick="resetCostingForm()">
                            <i class="bi bi-x-circle text-danger"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Ledger Table Sheets -->
    <div class="col-xl-8 col-lg-7">
        <div class="glass-card p-4">
            <div id="ledger-printable-wrapper">
                <!-- Print-Only Header Statement -->
                <div class="d-none d-print-block mb-4" id="ledger-print-header" style="font-family: 'Inter', sans-serif;">
                    <div class="d-flex justify-content-between align-items-start border-bottom pb-3">
                        <div>
                            <h3 class="fw-bold text-dark mb-1 font-outfit" style="color: #1E3A8A !important;"><?php echo e($tenant_name); ?> Operations</h3>
                            <span class="text-muted d-block" style="font-size: 0.85rem; color: #475569 !important;">Subdomain Workspace: <code><?php echo e($tenant_subdomain); ?>.<?php echo e(get_platform_domain()); ?></code></span>
                        </div>
                        <div class="text-end">
                            <h4 class="fw-bold text-dark font-outfit mb-1" style="color: #1E3A8A !important;">Monthly Financial Ledger Sheet</h4>
                            <span class="text-muted" style="font-size: 0.82rem; color: #475569 !important;">Generated: <?php echo date('d M, Y H:i'); ?></span>
                        </div>
                    </div>
                </div>
                
                <h4 class="text-white mb-3 font-outfit d-print-none"><i class="bi bi-table text-primary me-2"></i>Monthly Financial Breakdown Sheet</h4>
                
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-white mb-0" id="ledger-printable-sheet" style="font-size: 0.88rem; border-color: rgba(255,255,255,0.05);">
                    <thead class="bg-dark-glass fw-bold font-outfit" style="color: var(--text-dim);">
                        <tr>
                            <th>Month / Period</th>
                            <th>Cost Category</th>
                            <th class="text-center">Purchased</th>
                            <th class="text-end">Cost/Mbps</th>
                            <th class="text-end">Cost Amount</th>
                            <th class="text-end">Monthly Revenue</th>
                            <th class="text-end">Net P&L</th>
                            <th class="text-end">Margin</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($ledger_rows)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">No monthly cost ledger entries registered. Enter a monthly cost record on the left to start analytics tracking.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($ledger_rows as $row): ?>
                                <?php 
                                $month_name = date('F, Y', mktime(0, 0, 0, $row['month'], 1, $row['year']));
                                $mbps = (int)$row['bandwidth_purchased_mbps'];
                                $total_cost = (double)$row['total_cost'];
                                $revenue = (double)$row['revenue'];
                                $profit = $revenue - $total_cost;
                                
                                $cost_per_mbps = $mbps > 0 ? $total_cost / $mbps : 0;
                                $margin = $revenue > 0 ? ($profit / $revenue) * 100 : ($total_cost > 0 ? -100 : 0);
                                
                                $profit_class = $profit >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold';
                                $badge_class = $profit >= 0 ? 'bg-success bg-opacity-10 text-success' : 'bg-danger bg-opacity-10 text-danger';
                                $margin_class = $margin >= 0 ? 'text-success' : 'text-danger';
                                ?>
                                <tr class="hover-bg-opacity">
                                    <td class="fw-bold text-white font-outfit">
                                        <?php echo e($month_name); ?>
                                        <?php if (!empty($row['notes'])): ?>
                                            <span class="d-block text-muted fw-normal mt-0.5" style="font-size: 0.72rem; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo e($row['notes']); ?>">
                                                <i class="bi bi-info-circle me-1"></i><?php echo e($row['notes']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-semibold text-white font-outfit"><?php echo e($row['cost_type'] ?: 'Bandwidth Cost'); ?></td>
                                    <td class="text-center fw-semibold text-info font-outfit">
                                        <?php echo $row['bandwidth_purchased_mbps'] > 0 ? format_bandwidth($mbps) : '-'; ?>
                                    </td>
                                    <td class="text-end text-muted font-outfit">
                                        <?php echo $row['bandwidth_purchased_mbps'] > 0 ? format_currency($cost_per_mbps) : '-'; ?>
                                    </td>
                                    <td class="text-end fw-semibold text-danger font-outfit"><?php echo format_currency($total_cost); ?></td>
                                    <td class="text-end fw-semibold text-success font-outfit"><?php echo format_currency($revenue); ?></td>
                                    <td class="text-end <?php echo $profit_class; ?> font-outfit"><?php echo format_currency($profit); ?></td>
                                    <td class="text-end <?php echo $margin_class; ?> font-outfit fw-bold"><?php echo number_format($margin, 1); ?>%</td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1.5">
                                            <button type="button" class="btn btn-dark-glass p-1.5 px-2.5 rounded border-0" 
                                                    style="font-size: 0.78rem;" 
                                                    onclick="loadRowToEdit(<?php echo $row['month']; ?>, <?php echo $row['year']; ?>, '<?php echo e(js_escape($row['cost_type'])); ?>', <?php echo $mbps; ?>, <?php echo $total_cost; ?>, '<?php echo e(js_escape($row['notes'])); ?>')"
                                                    title="Modify Ledger Record">
                                                <i class="bi bi-pencil text-primary"></i>
                                            </button>
                                            <a href="costing_ledger.php?action=delete&id=<?php echo $row['id']; ?>" 
                                               class="btn btn-dark-glass p-1.5 px-2.5 rounded border-0" 
                                               style="font-size: 0.78rem;"
                                               onclick="return confirm('Are you sure you want to delete this cost record?')"
                                               title="Delete Ledger Record">
                                                <i class="bi bi-trash text-danger"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($ledger_rows)): ?>
                    <tfoot style="border-top: 2px solid rgba(255, 255, 255, 0.15) !important;">
                        <tr class="fw-bold bg-dark-glass text-white font-outfit" style="border-color: rgba(255, 255, 255, 0.1) !important;">
                            <td colspan="2" class="text-white font-outfit text-start ps-3 py-3" style="font-size: 0.92rem;">GRAND TOTAL</td>
                            <td class="text-center text-info font-outfit py-3" style="font-size: 0.92rem;">
                                <?php echo $total_purchased_mbps > 0 ? format_bandwidth($total_purchased_mbps) : '-'; ?>
                            </td>
                            <td class="text-end text-muted font-outfit py-3">-</td>
                            <td class="text-end text-danger font-outfit py-3" style="font-size: 0.92rem;"><?php echo format_currency($total_bandwidth_costs); ?></td>
                            <td class="text-end text-success font-outfit py-3" style="font-size: 0.92rem;"><?php echo format_currency($total_revenue_collected); ?></td>
                            <td class="text-end <?php echo $net_profit_total >= 0 ? 'text-success' : 'text-danger'; ?> font-outfit py-3" style="font-size: 0.92rem;"><?php echo format_currency($net_profit_total); ?></td>
                            <td class="text-end <?php echo $margin_total >= 0 ? 'text-success' : 'text-danger'; ?> font-outfit py-3 fw-bold" style="font-size: 0.92rem;"><?php echo number_format($margin_total, 1); ?>%</td>
                            <td class="text-center d-print-none py-3"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            </div> <!-- Close #ledger-printable-wrapper -->
        </div>
    </div>
</div>

<script>
function toggleBandwidthField() {
    const select = document.getElementById('form-cost-type');
    const bandwidthContainer = document.getElementById('bandwidth-container');
    const bandwidthInput = document.getElementById('form-bandwidth');
    
    if (select && bandwidthInput && bandwidthContainer) {
        if (select.value === 'Bandwidth Cost') {
            bandwidthContainer.style.display = 'block';
            bandwidthInput.setAttribute('required', 'required');
        } else {
            bandwidthContainer.style.display = 'none';
            bandwidthInput.removeAttribute('required');
        }
    }
}

// Initialise categories on load
document.addEventListener('DOMContentLoaded', function() {
    toggleBandwidthField();
});

function loadRowToEdit(month, year, costType, bandwidth, cost, notes) {
    // Scroll smoothly to form
    document.getElementById('costing-form').scrollIntoView({ behavior: 'smooth' });
    
    // Set field values
    document.getElementById('form-month').value = month;
    document.getElementById('form-year').value = year;
    document.getElementById('form-cost-type').value = costType;
    document.getElementById('form-bandwidth').value = bandwidth;
    document.getElementById('form-cost').value = cost;
    document.getElementById('form-notes').value = notes;
    
    // Toggle fields based on costType
    toggleBandwidthField();
    
    // Style update to indicate edit mode
    document.getElementById('form-title').innerHTML = '<i class="bi bi-pencil-square text-warning me-2"></i>Modify Cost Record';
    document.getElementById('cancel-edit-btn').classList.remove('d-none');
    
    // Temporary visual border highlighting on form
    const formCard = document.getElementById('costing-form').closest('.glass-card');
    formCard.style.outline = '2px solid rgba(255, 193, 7, 0.4)';
    setTimeout(() => {
        formCard.style.transition = 'outline 0.5s ease';
        formCard.style.outline = 'none';
    }, 1500);
}

function resetCostingForm() {
    document.getElementById('costing-form').reset();
    document.getElementById('form-month').value = "<?php echo date('n'); ?>";
    document.getElementById('form-year').value = "<?php echo date('Y'); ?>";
    document.getElementById('form-cost-type').value = "Bandwidth Cost";
    toggleBandwidthField();
    document.getElementById('form-title').innerHTML = '<i class="bi bi-pencil-square text-primary me-2"></i>Add / Update Cost';
    document.getElementById('cancel-edit-btn').classList.add('d-none');
}

// Help escaping javascript arguments from PHP safely
<?php
function js_escape($str) {
    if ($str === null) return '';
    return str_replace(
        ["\\", "'", "\n", "\r", '"'],
        ["\\\\", "\\'", "\\n", "\\r", '\\"'],
        $str
    );
}
?>
</script>

<style>
@media print {
    /* Hide layout sidebars, top bars, forms, header rows, alerts, and other non-ledger elements */
    #sidebarMenu,
    .sidebar-panel,
    .sidebar-backdrop,
    body > header,
    .toggle-sidebar-btn,
    .alert,
    #costing-form,
    #cancel-edit-btn,
    .d-print-none {
        display: none !important;
        height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: hidden !important;
    }

    /* Reset DOM flow to eliminate absolute margins, paddings, and height gaps */
    html,
    body,
    .dashboard-content-wrapper,
    main,
    .container-fluid,
    .row,
    .col-xl-8,
    .col-lg-7 {
        margin: 0 !important;
        padding: 0 !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        flex: 0 0 100% !important;
        position: static !important;
        background: #FFFFFF !important;
        color: #111111 !important;
        box-shadow: none !important;
    }

    /* Ensure ledger printable wrapper flows naturally at the top of the first page */
    #ledger-printable-wrapper {
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        position: relative !important;
        background: #FFFFFF !important;
        color: #111111 !important;
    }
    
    /* Style the print-only header banner */
    #ledger-print-header {
        border-bottom: 2px solid #1E3A8A !important;
        margin-bottom: 25px !important;
        padding-bottom: 15px !important;
    }
    
    #ledger-print-header h3,
    #ledger-print-header h4 {
        color: #1E3A8A !important;
        font-family: 'Outfit', 'Inter', sans-serif !important;
        font-weight: 700 !important;
        margin: 0 0 5px 0 !important;
    }
    
    #ledger-print-header .text-muted {
        color: #475569 !important;
        font-size: 0.85rem !important;
    }
    
    #ledger-print-header code {
        background-color: #F1F5F9 !important;
        color: #0F172A !important;
        border: 1px solid #CBD5E1 !important;
        padding: 1px 5px !important;
        border-radius: 3px !important;
        font-size: 0.8rem !important;
    }
    
    /* Table general layout and appearance */
    #ledger-printable-sheet {
        width: 100% !important;
        border-collapse: collapse !important;
        background-color: #FFFFFF !important;
        color: #111111 !important;
        border: 2px solid #475569 !important;
        margin-top: 15px !important;
    }
    
    /* Ensure visible grid borders on print preview */
    #ledger-printable-sheet,
    #ledger-printable-sheet th,
    #ledger-printable-sheet td {
        border: 1px solid #475569 !important;
    }
    
    /* Table headers styling */
    #ledger-printable-sheet thead th {
        background-color: #EFF6FF !important;
        color: #1E3A8A !important;
        font-family: 'Outfit', 'Inter', sans-serif !important;
        font-weight: 800 !important;
        border: 2px solid #475569 !important;
        text-transform: uppercase !important;
        font-size: 0.8rem !important;
        letter-spacing: 0.5px !important;
        padding: 10px 8px !important;
        text-align: center !important;
    }
    
    /* Align individual columns appropriately */
    #ledger-printable-sheet thead th:nth-child(4),
    #ledger-printable-sheet thead th:nth-child(5),
    #ledger-printable-sheet thead th:nth-child(6),
    #ledger-printable-sheet thead th:nth-child(7),
    #ledger-printable-sheet thead th:nth-child(8) {
        text-align: right !important;
    }
    
    /* Table cells styling with specificity overrides */
    #ledger-printable-sheet td {
        color: #111111 !important;
        border: 1px solid #475569 !important;
        padding: 8px 10px !important;
        font-size: 0.85rem !important;
        font-weight: 500 !important;
        background: transparent !important;
    }
    
    /* Font colors in table body */
    #ledger-printable-sheet td.fw-bold,
    #ledger-printable-sheet td strong {
        font-weight: 700 !important;
        color: #111111 !important;
    }
    
    /* Make sure specific text colors are clean and high contrast on white paper */
    #ledger-printable-sheet td.text-success,
    #ledger-printable-sheet .text-success {
        color: #15803D !important; /* Rich Dark Green */
        font-weight: 700 !important;
    }
    
    #ledger-printable-sheet td.text-danger,
    #ledger-printable-sheet .text-danger {
        color: #B91C1C !important; /* Rich Dark Red */
        font-weight: 700 !important;
    }
    
    #ledger-printable-sheet td.text-info,
    #ledger-printable-sheet .text-info {
        color: #1D4ED8 !important; /* Rich Dark Blue */
        font-weight: 700 !important;
    }
    
    #ledger-printable-sheet td.text-muted,
    #ledger-printable-sheet .text-muted {
        color: #475569 !important; /* Slate gray */
        font-weight: 500 !important;
    }
    
    #ledger-printable-sheet td span.text-muted {
        color: #64748B !important;
        font-size: 0.72rem !important;
    }
    
    /* Hide the Actions column entirely on print */
    #ledger-printable-sheet th:last-child,
    #ledger-printable-sheet td:last-child,
    #ledger-printable-sheet tfoot td:last-child {
        display: none !important;
    }

    /* Style the grand total footer in print preview */
    #ledger-printable-sheet tfoot td {
        background-color: #F8FAFC !important;
        color: #0F172A !important;
        font-weight: 800 !important;
        border-top: 2px solid #475569 !important;
        border-bottom: 2px solid #475569 !important;
        border-left: 1px solid #475569 !important;
        border-right: 1px solid #475569 !important;
        font-size: 0.9rem !important;
        padding: 10px 8px !important;
    }

    #ledger-printable-sheet tfoot td.text-success {
        color: #15803D !important;
    }

    #ledger-printable-sheet tfoot td.text-danger {
        color: #B91C1C !important;
    }

    #ledger-printable-sheet tfoot td.text-info {
        color: #1D4ED8 !important;
    }
    
    /* Margin and size configuration for printer */
    @page {
        size: landscape;
        margin: 1.2cm 1cm !important;
    }
}
</style>

<?php
require_once __DIR__ . '/layouts/footer.php';
?>
