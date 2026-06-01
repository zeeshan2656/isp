<?php
/**
 * Financial & Zone-Based Reporting Module
 * Dynamic filters, analytical summaries, and lightweight CSV exports.
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';


require_tenant_login();
$tenant_id = $_SESSION['tenant_id'];

// Capture Filter Parameters
$filter_type = clean_input($_GET['type'] ?? 'revenue'); // revenue, pending, zone, profitability
$start_date = clean_input($_GET['start_date'] ?? date('Y-m-01'));
$end_date = clean_input($_GET['end_date'] ?? date('Y-m-t'));

// --- CSV EXPORTER ACTION ---
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    try {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . str_replace(' ', '_', get_platform_name()) . '_Report_' . $filter_type . '_' . date('Ymd') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        if ($filter_type === 'revenue') {
            // REVENUE EXPORT
            fputcsv($output, ['Invoice Number', 'Subscriber Name', 'Package Name', 'Total Bill (Rs.)', 'Paid Amount (Rs.)', 'Due Balance (Rs.)', 'Date Issued', 'Payment Status']);
            
            $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name FROM invoices i 
                LEFT JOIN customers c ON i.customer_id = c.id 
                WHERE i.tenant_id = ? AND i.created_at BETWEEN ? AND ? 
                ORDER BY i.id DESC");
            $stmt->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['invoice_number'],
                    $row['customer_name'],
                    $row['package_name'],
                    $row['total_amount'],
                    $row['paid_amount'],
                    $row['remaining_amount'],
                    date('d M, Y', strtotime($row['created_at'])),
                    strtoupper($row['payment_status'])
                ]);
            }
            
        } elseif ($filter_type === 'pending') {
            // PENDING/RECEIVABLES EXPORT
            fputcsv($output, ['Invoice Number', 'Subscriber Name', 'Phone', 'Total Bill (Rs.)', 'Remaining Due (Rs.)', 'Due Date', 'Status']);
            
            $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.phone as customer_phone FROM invoices i 
                LEFT JOIN customers c ON i.customer_id = c.id 
                WHERE i.tenant_id = ? AND i.payment_status != 'paid' AND i.created_at BETWEEN ? AND ? 
                ORDER BY i.due_date ASC");
            $stmt->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['invoice_number'],
                    $row['customer_name'],
                    $row['customer_phone'],
                    $row['total_amount'],
                    $row['remaining_amount'],
                    date('d M, Y', strtotime($row['due_date'])),
                    strtoupper($row['payment_status'])
                ]);
            }
            
        } elseif ($filter_type === 'zone') {
            // ZONE EXPORT
            fputcsv($output, ['Zone Name', 'Total Subscribers', 'Active Subscribers', 'Expired Subscribers', 'Monthly Revenue Volume (Rs.)']);
            
            $stmt = $pdo->prepare("SELECT z.name as zone_name,
                COUNT(c.id) as total_subs,
                SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active_subs,
                SUM(CASE WHEN c.status = 'expired' THEN 1 ELSE 0 END) as expired_subs,
                SUM(c.monthly_fee) as monthly_volume
                FROM zones z 
                LEFT JOIN customers c ON z.id = c.zone_id 
                WHERE z.tenant_id = ? 
                GROUP BY z.id 
                ORDER BY z.name ASC");
            $stmt->execute([$tenant_id]);
            
            while ($row = $stmt->fetch()) {
                fputcsv($output, [
                    $row['zone_name'],
                    $row['total_subs'],
                    $row['active_subs'],
                    $row['expired_subs'],
                    $row['monthly_volume'] ?? 0.00
                ]);
            }
        }
        
        fclose($output);
        exit;
    } catch (PDOException $e) {
        error_log("CSV Export fail: " . $e->getMessage());
    }
}

// Load dynamic Layout Header after possible export checks
require_once __DIR__ . '/layouts/header.php';

// Prepare variables based on report filters
$report_title = "Billing & Issued Revenue Ledger";
$summary_metrics = [];
$report_rows = [];

try {
    if ($filter_type === 'revenue') {
        $report_title = "Billing & Issued Revenue Ledger";
        
        // Issued totals summary
        $stmt = $pdo->prepare("SELECT 
            COUNT(*) as invoice_count,
            SUM(total_amount) as total_issued,
            SUM(paid_amount) as total_collected,
            SUM(remaining_amount) as total_pending
            FROM invoices 
            WHERE tenant_id = ? AND created_at BETWEEN ? AND ?");
        $stmt->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $summary_metrics = $stmt->fetch();
        
        // Fetch rows
        $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            WHERE i.tenant_id = ? AND i.created_at BETWEEN ? AND ? 
            ORDER BY i.id DESC");
        $stmt->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $report_rows = $stmt->fetchAll();
        
    } elseif ($filter_type === 'pending') {
        $report_title = "Outstanding Receivables & Due Logs";
        
        $stmt = $pdo->prepare("SELECT 
            COUNT(*) as invoice_count,
            SUM(total_amount) as total_issued,
            SUM(paid_amount) as total_collected,
            SUM(remaining_amount) as total_pending
            FROM invoices 
            WHERE tenant_id = ? AND payment_status != 'paid' AND created_at BETWEEN ? AND ?");
        $stmt->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $summary_metrics = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT i.*, c.name as customer_name, c.phone as customer_phone FROM invoices i 
            LEFT JOIN customers c ON i.customer_id = c.id 
            WHERE i.tenant_id = ? AND i.payment_status != 'paid' AND i.created_at BETWEEN ? AND ? 
            ORDER BY i.due_date ASC");
        $stmt->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $report_rows = $stmt->fetchAll();
        
    } elseif ($filter_type === 'zone') {
        $report_title = "Geographical Zone Metrics Overview";
        
        // Zone Divisions summary
        $stmt = $pdo->prepare("SELECT z.name as zone_name,
            COUNT(c.id) as total_subs,
            SUM(CASE WHEN c.status = 'active' THEN 1 ELSE 0 END) as active_subs,
            SUM(CASE WHEN c.status = 'expired' THEN 1 ELSE 0 END) as expired_subs,
            SUM(c.monthly_fee) as monthly_volume
            FROM zones z 
            LEFT JOIN customers c ON z.id = c.zone_id 
            WHERE z.tenant_id = ? 
            GROUP BY z.id 
            ORDER BY z.name ASC");
        $stmt->execute([$tenant_id]);
        $report_rows = $stmt->fetchAll();
        
    } elseif ($filter_type === 'profitability') {
        $report_title = "ISP Profit & Loss Audit Sheet";
        
        // Fetch legacy monthly bandwidth purchase values as a fallback
        $stmt = $pdo->prepare("SELECT bandwidth_purchased, internet_cost FROM tenants WHERE id = ? LIMIT 1");
        $stmt->execute([$tenant_id]);
        $cost_data = $stmt->fetch();
        
        $bandwidth_m = (int)($cost_data['bandwidth_purchased'] ?? 0);
        $cost_m = (double)($cost_data['internet_cost'] ?? 0.00);
        
        // Fetch all dynamic expenses from monthly_costs during the filtered period
        $stmt_costs = $pdo->prepare("SELECT cost_type, SUM(total_cost) as category_total, SUM(bandwidth_purchased_mbps) as category_bandwidth
            FROM monthly_costs 
            WHERE tenant_id = ? AND DATE(CONCAT(year, '-', month, '-01')) BETWEEN DATE_FORMAT(?, '%Y-%m-01') AND DATE_FORMAT(?, '%Y-%m-%t')
            GROUP BY cost_type
            ORDER BY category_total DESC");
        $stmt_costs->execute([$tenant_id, $start_date, $end_date]);
        $expenses_breakdown = $stmt_costs->fetchAll();
        
        $total_expenses = 0.00;
        $total_bandwidth_purchased = 0;
        foreach ($expenses_breakdown as $expense) {
            $total_expenses += (double)$expense['category_total'];
            if ($expense['cost_type'] === 'Bandwidth Cost') {
                $total_bandwidth_purchased += (int)$expense['category_bandwidth'];
            }
        }
        
        // Revenue collection for filtered period
        $stmt = $pdo->prepare("SELECT SUM(paid_amount) as collected FROM invoices WHERE tenant_id = ? AND created_at BETWEEN ? AND ?");
        $stmt->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $collected_m = (double)($stmt->fetchColumn() ?? 0.00);
        
        $net_profit = !empty($expenses_breakdown) ? ($collected_m - $total_expenses) : ($collected_m - $cost_m);
    }
} catch (PDOException $e) {
    error_log("Reports loader fail: " . $e->getMessage());
}
?>

<!-- Header Options row -->
<div class="row align-items-center mb-4">
    <div class="col-md-7">
        <h2 class="text-white mb-1"><i class="bi bi-bar-chart text-primary me-2"></i>Financial & Zone Reports</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Review performance charts, outstanding invoices, and compile audit trails.</p>
    </div>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
        <a href="reports.php?export=csv&type=<?php echo $filter_type; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="btn btn-dark-glass py-2 px-3 me-2" style="font-size: 0.9rem;"><i class="bi bi-file-earmark-excel text-success me-1.5"></i>Export CSV</a>
        <button class="btn btn-primary-gradient py-2 px-4" onclick="window.print()" style="font-size: 0.9rem;"><i class="bi bi-printer me-1.5"></i>Print Sheet</button>
    </div>
</div>

<!-- Filters Panel card -->
<div class="p-4 glass-card mb-4" id="reportFilterPanel">
    <form action="reports.php" method="GET" class="row g-3 align-items-end">
        <div class="col-md-4 col-sm-6">
            <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Select Report Type</label>
            <select name="type" class="form-select font-outfit" style="font-size: 0.85rem; font-weight: 500;">
                <option value="revenue" <?php echo ($filter_type === 'revenue') ? 'selected' : ''; ?>>Billing & Collections Report</option>
                <option value="pending" <?php echo ($filter_type === 'pending') ? 'selected' : ''; ?>>Receivables & Outstanding Due</option>
                <option value="zone" <?php echo ($filter_type === 'zone') ? 'selected' : ''; ?>>Subscriber Metrics by Zone</option>
                <option value="profitability" <?php echo ($filter_type === 'profitability') ? 'selected' : ''; ?>>Internet Costs P&L Summary</option>
            </select>
        </div>
        
        <div class="col-md-3 col-6">
            <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">From Date</label>
            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>" style="font-size: 0.85rem;">
        </div>
        
        <div class="col-md-3 col-6">
            <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">To Date</label>
            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>" style="font-size: 0.85rem;">
        </div>
        
        <div class="col-md-2 text-end">
            <button type="submit" class="btn btn-dark-glass w-100 py-2" style="font-size: 0.85rem;"><i class="bi bi-arrow-repeat me-1.5"></i>Update Report</button>
        </div>
    </form>
</div>

<!-- Report Summary cards block -->
<?php if ($filter_type === 'revenue' || $filter_type === 'pending'): ?>
    <div class="row g-3 mb-4" id="reportSummaryCards">
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 bg-dark border rounded-3 text-start" style="border-color: var(--border-color) !important;">
                <span class="text-muted d-block" style="font-size: 0.72rem;">Total Invoices Issued</span>
                <strong class="text-white fs-5 font-outfit mt-1 d-block"><?php echo (int)($summary_metrics['invoice_count'] ?? 0); ?> Records</strong>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 bg-dark border rounded-3 text-start" style="border-color: var(--border-color) !important;">
                <span class="text-muted d-block" style="font-size: 0.72rem;">Total Issued Value</span>
                <strong class="text-white fs-5 font-outfit mt-1 d-block"><?php echo format_currency($summary_metrics['total_issued'] ?? 0.00); ?></strong>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 bg-dark border rounded-3 text-start" style="border-color: var(--border-color) !important;">
                <span class="text-success d-block" style="font-size: 0.72rem;">Actual Collected Income</span>
                <strong class="text-success fs-5 font-outfit mt-1 d-block"><?php echo format_currency($summary_metrics['total_collected'] ?? 0.00); ?></strong>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 bg-dark border rounded-3 text-start" style="border-color: var(--border-color) !important;">
                <span class="text-danger d-block" style="font-size: 0.72rem;">Outstanding Receivables</span>
                <strong class="text-danger fs-5 font-outfit mt-1 d-block"><?php echo format_currency($summary_metrics['total_pending'] ?? 0.00); ?></strong>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Main Sheet Panel -->
<div class="p-4 bg-white text-dark rounded-3 shadow" id="printableReportSheet">
    <style>
    /* DIRECT HIGH-CONTRAST INLINE STYLE SHEET */
    #printableReportSheet {
        background-color: #FFFFFF !important;
        color: #111111 !important; /* Pure Dark Gray/Black for body */
    }
    #printableReportSheet h4 {
        color: #1E3A8A !important; /* Royal Blue Title */
        font-weight: 800 !important;
    }
    #printableReportSheet .text-muted {
        color: #1E293B !important; /* Dark Slate Gray (Highly visible) */
        font-weight: 600 !important;
    }
    #printableReportSheet strong {
        color: #0F172A !important; /* Pure Slate 900 Black */
        font-weight: 700 !important;
    }
    #printableReportSheet span, 
    #printableReportSheet small, 
    #printableReportSheet em {
        color: #1F2937 !important; /* Dark Charcoal 800 */
        font-weight: 500 !important;
    }
    #printableReportSheet .bg-light {
        background-color: #F8FAFC !important; /* Clean light slate background */
        border: 2px solid #64748B !important; /* Slate 500 border line */
        color: #111111 !important;
    }
    #printableReportSheet .bg-light .text-muted {
        color: #312E81 !important; /* Deep Indigo for labels inside boxes */
        font-weight: 700 !important;
    }
    #printableReportSheet .bg-light strong {
        font-weight: 800 !important;
    }
    #printableReportSheet .bg-light small {
        color: #374151 !important; /* Highly visible text dark slate */
        font-weight: 600 !important;
    }
    #printableReportSheet .text-success {
        color: #16A34A !important; /* Emerald Green */
        font-weight: 700 !important;
    }
    #printableReportSheet .text-danger {
        color: #DC2626 !important; /* Deep Red */
        font-weight: 700 !important;
    }
    #printableReportSheet .text-warning {
        color: #D97706 !important; /* Amber Yellow/Brown */
        font-weight: 700 !important;
    }
    #printableReportSheet table {
        border: 2px solid #475569 !important;
    }
    #printableReportSheet table thead th {
        background-color: #EFF6FF !important; /* Light blue header background */
        color: #1E3A8A !important; /* Deep Royal Blue */
        border: 2px solid #475569 !important;
        font-weight: 800 !important;
    }
    #printableReportSheet table tbody td {
        color: #111111 !important; /* Black row text */
        border: 1px solid #64748B !important;
        font-weight: 500 !important;
    }
    #printableReportSheet table tbody td.fw-bold,
    #printableReportSheet table tbody td strong {
        color: #111111 !important;
        font-weight: 700 !important;
    }
    #printableReportSheet table tbody td.text-success {
        color: #16A34A !important;
    }
    #printableReportSheet table tbody td.text-danger {
        color: #DC2626 !important;
    }
    #printableReportSheet table tbody td.text-muted {
        color: #475569 !important;
    }
    #printableReportSheet .border-top {
        border-top: 2px solid #475569 !important;
    }
    #printableReportSheet .badge.bg-dark {
        background-color: #1E3A8A !important; /* Royal blue header badge */
        color: #FFFFFF !important;
    }
    </style>
    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
        <div>
            <h4 class="fw-bold text-dark font-outfit mb-1"><?php echo $report_title; ?></h4>
            <span class="text-muted" style="font-size: 0.85rem;">ISP: <strong><?php echo e($tenant_name); ?></strong> &bull; Period: <?php echo format_date($start_date); ?> to <?php echo format_date($end_date); ?></span>
        </div>
        <span class="badge bg-dark font-outfit px-3 py-1.5" style="font-size: 0.75rem;"><?php echo strtoupper(e(get_platform_name())); ?> SAAS PLATFORM</span>
    </div>
    
    <?php if ($filter_type === 'revenue'): ?>
        
        <!-- REVENUE GRID SHEET -->
        <table class="table table-bordered table-striped align-middle" style="font-size: 0.88rem;">
            <thead class="bg-light text-dark fw-bold font-outfit">
                <tr>
                    <th>Invoice Number</th>
                    <th>Subscriber Name</th>
                    <th>Package Plan</th>
                    <th style="text-align: right;">Total Bill</th>
                    <th style="text-align: right;">Paid Value</th>
                    <th style="text-align: right;">Due Balance</th>
                    <th style="text-align: center;">Payment Status</th>
                    <th style="text-align: center;">Issued Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_rows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No records found for the selected timeline.</td></tr>
                <?php else: ?>
                    <?php foreach ($report_rows as $row): ?>
                        <tr>
                            <td class="fw-bold"><?php echo e($row['invoice_number']); ?></td>
                            <td><?php echo e($row['customer_name']); ?></td>
                            <td><?php echo e($row['package_name']); ?></td>
                            <td class="text-end fw-bold"><?php echo format_currency($row['total_amount']); ?></td>
                            <td class="text-end text-success"><?php echo format_currency($row['paid_amount']); ?></td>
                            <td class="text-end text-danger"><?php echo format_currency($row['remaining_amount']); ?></td>
                            <td class="text-center font-outfit" style="text-transform: uppercase; font-size: 0.78rem; font-weight: 600;"><?php echo e($row['payment_status']); ?></td>
                            <td class="text-center text-muted"><?php echo date('d M, Y', strtotime($row['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php elseif ($filter_type === 'pending'): ?>
        
        <!-- PENDING OUTSTANDING RECEIVABLES SHEET -->
        <table class="table table-bordered table-striped align-middle" style="font-size: 0.88rem;">
            <thead class="bg-light text-dark fw-bold font-outfit">
                <tr>
                    <th>Invoice Number</th>
                    <th>Subscriber Name</th>
                    <th>Phone Contact</th>
                    <th style="text-align: right;">Total Bill</th>
                    <th style="text-align: right;">Remaining Due</th>
                    <th style="text-align: center;">Due Date</th>
                    <th style="text-align: center;">Payment Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_rows)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">All clear! No pending payments in this bracket.</td></tr>
                <?php else: ?>
                    <?php foreach ($report_rows as $row): ?>
                        <tr>
                            <td class="fw-bold"><?php echo e($row['invoice_number']); ?></td>
                            <td><?php echo e($row['customer_name']); ?></td>
                            <td><?php echo e($row['customer_phone']); ?></td>
                            <td class="text-end fw-bold"><?php echo format_currency($row['total_amount']); ?></td>
                            <td class="text-end text-danger fw-bold"><?php echo format_currency($row['remaining_amount']); ?></td>
                            <td class="text-center text-danger fw-bold"><?php echo format_date($row['due_date']); ?></td>
                            <td class="text-center font-outfit" style="text-transform: uppercase; font-size: 0.78rem; font-weight: 600;"><?php echo e($row['payment_status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php elseif ($filter_type === 'zone'): ?>
        
        <!-- GEOGRAPHICAL DIVISION METRICS SHEET -->
        <table class="table table-bordered table-striped align-middle" style="font-size: 0.88rem;">
            <thead class="bg-light text-dark fw-bold font-outfit">
                <tr>
                    <th>Zone Division Name</th>
                    <th style="text-align: center;">Total Active Subscribers</th>
                    <th style="text-align: center;">Subscribers Active</th>
                    <th style="text-align: center;">Subscribers Expired</th>
                    <th style="text-align: right;">Billing Value Volume / mo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_rows)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No zone data to summarize. Create zones first.</td></tr>
                <?php else: ?>
                    <?php foreach ($report_rows as $row): ?>
                        <tr>
                            <td class="fw-bold"><?php echo e($row['zone_name']); ?></td>
                            <td class="text-center"><?php echo $row['total_subs']; ?></td>
                            <td class="text-center text-success fw-bold"><?php echo $row['active_subs']; ?></td>
                            <td class="text-center text-danger fw-bold"><?php echo $row['expired_subs']; ?></td>
                            <td class="text-end fw-bold"><?php echo format_currency($row['monthly_volume'] ?? 0.00); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
    <?php elseif ($filter_type === 'profitability'): ?>
        
        <!-- PROFIT & LOSS AUDIT SHEET -->
        <div class="row g-4 justify-content-center p-3">
            <div class="col-md-9">
                <div class="p-4 border rounded-3 bg-light d-flex flex-column gap-3" style="font-size: 0.95rem;">
                    
                    <!-- REVENUE BLOCK -->
                    <div class="d-flex justify-content-between border-bottom pb-2.5">
                        <span class="text-muted fw-semibold">Filtered Revenue Collected (A):</span>
                        <strong class="text-success fw-bold" style="font-size: 1.1rem;"><?php echo format_currency($collected_m); ?></strong>
                    </div>
                    
                    <!-- DYNAMIC EXPENSES LEDGER BLOCK -->
                    <div class="mt-2">
                        <h6 class="fw-bold text-dark font-outfit border-bottom pb-2 mb-3"><i class="bi bi-wallet2 text-danger me-2"></i>Expenses & Cost Breakdown (B)</h6>
                        
                        <?php if (!empty($expenses_breakdown)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered align-middle mb-2" style="font-size: 0.85rem; background: #FFFFFF; border-color: #CBD5E1 !important;">
                                    <thead class="table-light font-outfit text-dark fw-bold" style="background-color: #F8FAFC !important;">
                                        <tr>
                                            <th class="ps-2 py-2">Cost Category / Expense Type</th>
                                            <th class="text-center py-2" style="width: 135px;">Bandwidth Speed</th>
                                            <th class="text-end pe-2 py-2" style="width: 180px;">Expense Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($expenses_breakdown as $expense): ?>
                                            <tr>
                                                <td class="fw-semibold text-dark ps-2 py-2"><?php echo e($expense['cost_type']); ?></td>
                                                <td class="text-center fw-semibold text-info py-2">
                                                    <?php echo $expense['category_bandwidth'] > 0 ? format_bandwidth($expense['category_bandwidth']) : '-'; ?>
                                                </td>
                                                <td class="text-end fw-bold text-danger pe-2 py-2"><?php echo format_currency($expense['category_total']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <tr class="table-danger fw-bold border-top-2" style="background-color: #FEF2F2 !important;">
                                            <td colspan="2" class="text-dark font-outfit ps-2 py-2.5">TOTAL EXPENSES (B)</td>
                                            <td class="text-end text-danger pe-2 py-2.5" style="font-size: 1rem;"><?php echo format_currency($total_expenses); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <!-- LEGACY FALLBACK BLOCK -->
                            <div class="d-flex justify-content-between border-bottom pb-2">
                                <span class="text-muted">Bandwidth Purchased:</span>
                                <strong class="text-dark"><?php echo format_bandwidth($bandwidth_m); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2">
                                <span class="text-muted">Internet Costs Purchased (B):</span>
                                <strong class="text-danger fw-bold"><?php echo format_currency($cost_m); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between border-bottom pb-2 fw-bold bg-danger bg-opacity-10 p-2.5 rounded text-danger" style="background-color: #FEF2F2 !important;">
                                <span>TOTAL EXPENSES (B):</span>
                                <span><?php echo format_currency($cost_m); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php 
                    $net_pl_val = !empty($expenses_breakdown) ? ($collected_m - $total_expenses) : ($collected_m - $cost_m);
                    $pl_color = $net_pl_val >= 0 ? 'text-success' : 'text-danger';
                    $pl_label = $net_pl_val >= 0 ? 'Net Profit Margin' : 'Net Resource Loss';
                    ?>
                    
                    <!-- AUDIT NET P&L -->
                    <div class="d-flex justify-content-between pt-3 border-top fw-bold fs-4 <?php echo $pl_color; ?>">
                        <span><?php echo $pl_label; ?> (A - B):</span>
                        <span><?php echo format_currency($net_pl_val); ?></span>
                    </div>
                    
                    <small class="text-muted text-center mt-3 d-block" style="font-size: 0.78rem;">
                        <?php if (!empty($expenses_breakdown)): ?>
                            Expenses are aggregated dynamically from your Costing Ledger records matching this filter period. Profit margin is calculated against actual collections during this interval.
                        <?php else: ?>
                            Internet costs are defined globally in your ISP profile setup. Profit margin is calculated against actual collections during this filter interval.
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
        
    <?php endif; ?>
    
    <div class="text-center mt-5 text-muted border-top pt-3" style="font-size: 0.78rem;">
        Compiled dynamically by <?php echo e(get_platform_name()); ?> Multi-Tenant Platform &bull; Date generated: <?php echo date('d M, Y H:i'); ?>
    </div>
</div>

<style>
@media print {
    body * {
        visibility: hidden;
    }
    #printableReportSheet, #printableReportSheet * {
        visibility: visible;
    }
    #printableReportSheet {
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

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
