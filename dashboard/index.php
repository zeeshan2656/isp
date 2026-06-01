<?php
/**
 * Tenant main dashboard analytics panel
 */
require_once __DIR__ . '/layouts/header.php';

// Prepare metrics
$metrics = [
    'total_customers' => 0,
    'active_customers' => 0,
    'expired_customers' => 0,
    'expiring_soon' => 0,
    'monthly_revenue' => 0.00,
    'collected_payments' => 0.00,
    'pending_payments' => 0.00,
    'net_profit' => 0.00
];

// Fetch ISP internet bandwidth cost data
$bandwidth_purchased = 0;
$monthly_internet_cost = 0.00;
try {
    $stmt = $pdo->prepare("SELECT bandwidth_purchased, internet_cost FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenant_id]);
    $cost_data = $stmt->fetch();
    if ($cost_data) {
        $bandwidth_purchased = (int)$cost_data['bandwidth_purchased'];
        $monthly_internet_cost = (double)$cost_data['internet_cost'];
    }
} catch (PDOException $e) {
    error_log("Cost fetch error: " . $e->getMessage());
}

try {
    // 1. Total, Active, Expired Customers
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
        FROM customers WHERE tenant_id = ?");
    $stmt->execute([$tenant_id]);
    $counts = $stmt->fetch();
    if ($counts) {
        $metrics['total_customers'] = (int)$counts['total'];
        $metrics['active_customers'] = (int)$counts['active'];
        $metrics['expired_customers'] = (int)$counts['expired'];
    }
    
    // 2. Customers expiring in <= 10 days
    $today = date('Y-m-d');
    $warning_limit = date('Y-m-d', strtotime('+10 days'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id = ? AND status = 'active' AND expiry_date BETWEEN ? AND ?");
    $stmt->execute([$tenant_id, $today, $warning_limit]);
    $metrics['expiring_soon'] = (int)$stmt->fetchColumn();
    
    // 3. Billing & Payments for the current month
    $start_of_month = date('Y-m-01');
    $end_of_month = date('Y-m-t');
    
    $stmt = $pdo->prepare("SELECT 
        SUM(total_amount) as revenue,
        SUM(paid_amount) as collected,
        SUM(remaining_amount) as pending
        FROM invoices 
        WHERE tenant_id = ? AND created_at BETWEEN ? AND ?");
    $stmt->execute([$tenant_id, $start_of_month . ' 00:00:00', $end_of_month . ' 23:59:59']);
    $billing_metrics = $stmt->fetch();
    if ($billing_metrics) {
        $metrics['monthly_revenue'] = (double)$billing_metrics['revenue'];
        $metrics['collected_payments'] = (double)$billing_metrics['collected'];
        $metrics['pending_payments'] = (double)$billing_metrics['pending'];
    }
    
    // Net profit = Collected Payments - Internet Purchase Cost
    $metrics['net_profit'] = $metrics['collected_payments'] - $monthly_internet_cost;
    
} catch (PDOException $e) {
    error_log("Dashboard query failure: " . $e->getMessage());
}

// Fetch list of expiring soon customers (<= 10 days) for visual alert grid
$expiring_list = [];
try {
    $stmt = $pdo->prepare("SELECT c.*, p.name as package_name FROM customers c 
        LEFT JOIN packages p ON c.assigned_package_id = p.id 
        WHERE c.tenant_id = ? AND c.status = 'active' AND c.expiry_date BETWEEN ? AND ? 
        ORDER BY c.expiry_date ASC LIMIT 5");
    $stmt->execute([$tenant_id, $today, $warning_limit]);
    $expiring_list = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Expiring list fetch fail: " . $e->getMessage());
}

// Fetch recent financial data for dynamic canvas chart (last 6 months)
$chart_months = [];
$chart_revenue = [];
$chart_collected = [];
try {
    for ($i = 5; $i >= 0; $i--) {
        $m_start = date('Y-m-01', strtotime("-$i months"));
        $m_end = date('Y-m-t', strtotime("-$i months"));
        $label = date('M Y', strtotime("-$i months"));
        
        $stmt = $pdo->prepare("SELECT SUM(total_amount) as rev, SUM(paid_amount) as col FROM invoices WHERE tenant_id = ? AND created_at BETWEEN ? AND ?");
        $stmt->execute([$tenant_id, $m_start . ' 00:00:00', $m_end . ' 23:59:59']);
        $row = $stmt->fetch();
        
        $chart_months[] = $label;
        $chart_revenue[] = (double)($row['rev'] ?? 0.00);
        $chart_collected[] = (double)($row['col'] ?? 0.00);
    }
} catch (PDOException $e) {
    error_log("Chart query error: " . $e->getMessage());
}
?>

<div class="row align-items-center mb-4">
    <div class="col-md-8">
        <h2 class="text-white mb-1">Welcome back, <span class="text-gradient-purple fw-bold"><?php echo e($tenant_name); ?></span></h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Monitor operations, track internet costs, and review expiring accounts.</p>
    </div>
    <div class="col-md-4 text-md-end mt-3 mt-md-0">
        <span class="badge px-3 py-2 border rounded-pill" style="background: rgba(255,255,255,0.02); border-color: var(--border-color); font-size: 0.85rem;">
            <i class="bi bi-calendar3 text-primary me-2"></i> <?php echo date('d M, Y'); ?>
        </span>
    </div>
</div>

<!-- Dynamic KPIs Row -->
<div class="row g-3 mb-4">
    <!-- Card 1: Total Subscribers -->
    <div class="col-6 col-lg-3">
        <div class="kpi-card kpi-info h-100">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem;">Total Subscribers</span>
                <h3 class="text-white mb-0" style="font-size: 1.65rem;"><?php echo $metrics['total_customers']; ?></h3>
                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Active: <strong class="text-white"><?php echo $metrics['active_customers']; ?></strong></small>
            </div>
            <i class="bi bi-people kpi-icon"></i>
        </div>
    </div>
    
    <!-- Card 2: Expiring Soon Counter -->
    <div class="col-6 col-lg-3">
        <div class="kpi-card kpi-warning h-100">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem;">Expiring Soon</span>
                <h3 class="text-warning mb-0" style="font-size: 1.65rem;"><?php echo $metrics['expiring_soon']; ?></h3>
                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Within 10 Days</small>
            </div>
            <i class="bi bi-exclamation-triangle kpi-icon text-warning"></i>
        </div>
    </div>
    
    <!-- Card 3: Expired Subscriptions -->
    <div class="col-6 col-lg-3">
        <div class="kpi-card kpi-danger h-100">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem;">Expired Customers</span>
                <h3 class="text-danger mb-0" style="font-size: 1.65rem;"><?php echo $metrics['expired_customers']; ?></h3>
                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Requires billing renewal</small>
            </div>
            <i class="bi bi-person-x kpi-icon text-danger"></i>
        </div>
    </div>
    
    <!-- Card 4: Net Profit or Loss -->
    <div class="col-6 col-lg-3">
        <?php 
        $profit_class = $metrics['net_profit'] >= 0 ? 'kpi-success' : 'kpi-danger';
        $profit_color = $metrics['net_profit'] >= 0 ? 'text-success' : 'text-danger';
        ?>
        <div class="kpi-card <?php echo $profit_class; ?> h-100">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.8rem;">Net Profit/Loss</span>
                <h3 class="<?php echo $profit_color; ?> mb-0" style="font-size: 1.45rem; font-weight: 700;"><?php echo format_currency($metrics['net_profit']); ?></h3>
                <small class="text-muted d-block mt-1" style="font-size: 0.72rem;">Net this month</small>
            </div>
            <i class="bi bi-wallet2 kpi-icon"></i>
        </div>
    </div>
</div>

<!-- Sub-KPI Financials and Internet Expense Row -->
<div class="row g-3 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="p-3 border rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.015); border-color: var(--border-color);">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.75rem;">Billing Issued (Month)</span>
                <strong class="text-white"><?php echo format_currency($metrics['monthly_revenue']); ?></strong>
            </div>
            <i class="bi bi-receipt text-muted opacity-40 fs-4"></i>
        </div>
    </div>
    
    <div class="col-md-6 col-lg-3">
        <div class="p-3 border rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.015); border-color: var(--border-color);">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.75rem;">Collected Revenue</span>
                <strong class="text-success"><?php echo format_currency($metrics['collected_payments']); ?></strong>
            </div>
            <i class="bi bi-cash-stack text-success opacity-40 fs-4"></i>
        </div>
    </div>
    
    <div class="col-md-6 col-lg-3">
        <div class="p-3 border rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.015); border-color: var(--border-color);">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.75rem;">Pending Payments</span>
                <strong class="text-warning"><?php echo format_currency($metrics['pending_payments']); ?></strong>
            </div>
            <i class="bi bi-hourglass-split text-warning opacity-40 fs-4"></i>
        </div>
    </div>
    
    <div class="col-md-6 col-lg-3">
        <div class="p-3 border rounded-3 d-flex justify-content-between align-items-center" style="background: rgba(255,255,255,0.015); border-color: var(--border-color);">
            <div>
                <span class="text-muted d-block mb-1" style="font-size: 0.75rem;">ISP Internet Expense</span>
                <strong class="text-danger"><?php echo format_currency($monthly_internet_cost); ?></strong>
            </div>
            <span class="badge bg-secondary-soft text-light" style="font-size: 0.7rem;"><?php echo format_bandwidth($bandwidth_purchased); ?></span>
        </div>
    </div>
</div>

<!-- Main Row: Charts & Alerts -->
<div class="row g-4 mb-4">
    <!-- Chart Block -->
    <div class="col-lg-8">
        <div class="p-4 glass-card h-100">
            <h5 class="text-white mb-1"><i class="bi bi-activity text-primary me-2"></i>Revenue Analytics</h5>
            <p class="text-muted mb-4" style="font-size: 0.85rem;">Historical dynamic collection of issued billing vs. actual collections.</p>
            
            <div style="position: relative; height: 320px; width: 100%;">
                <canvas id="revenueChart" style="height: 100%; width: 100%;"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Alarm Expiry Warning Center -->
    <div class="col-lg-4">
        <div class="p-4 glass-card h-100 d-flex flex-column">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <h5 class="text-white mb-0"><i class="bi bi-bell text-warning me-2"></i>Expiring Soon</h5>
                <span class="badge bg-warning bg-opacity-25 text-warning rounded-pill" style="font-size: 0.72rem;"><?php echo count($expiring_list); ?> Listed</span>
            </div>
            <p class="text-muted mb-4" style="font-size: 0.85rem;">Subscribers with subscriptions closing in less than 10 days.</p>
            
            <div class="d-flex flex-column gap-2 mb-3">
                <?php if (empty($expiring_list)): ?>
                    <div class="p-4 text-center border border-dashed rounded-3 mt-4" style="background: rgba(255,255,255,0.01); border-color: var(--border-color);">
                        <i class="bi bi-check-circle text-success fs-3 mb-2 d-block"></i>
                        <span class="text-muted" style="font-size: 0.85rem;">All systems go! No subscribers are expiring within the next 10 days.</span>
                    </div>
                <?php else: ?>
                    <?php foreach ($expiring_list as $cust): 
                        $cfg = get_expiry_alert_config($cust['expiry_date']);
                        ?>
                        <div class="p-3 border rounded-3 d-flex align-items-center justify-content-between" style="background: rgba(255,255,255,0.015); border-color: var(--border-color);">
                            <div>
                                <span class="text-white d-block fw-bold" style="font-size: 0.88rem;"><?php echo e($cust['name']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.72rem;">Pkg: <?php echo e($cust['package_name']); ?> &bull; <?php echo e($cust['phone']); ?></small>
                            </div>
                            <span class="badge" style="background: <?php echo $cfg['bg']; ?>; color: <?php echo $cfg['text']; ?>; font-size: 0.72rem; padding: 0.45em 0.8em; border-radius: 6px;">
                                <?php echo $cfg['label']; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <a href="customers.php?expiry_filter=10" class="btn btn-dark-glass w-100 py-2 mt-auto" style="font-size: 0.85rem;">View All Warnings <i class="bi bi-arrow-right ms-1"></i></a>
        </div>
    </div>
</div>

<!-- Deferred Chart renderer using Canvas -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    const canvas = document.getElementById('revenueChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    
    // PHP variables mapped to JSON arrays
    const months = <?php echo json_encode($chart_months); ?>;
    const revenue = <?php echo json_encode($chart_revenue); ?>;
    const collected = <?php echo json_encode($chart_collected); ?>;
    
    // Draw basic HTML5 line graph dynamically to maintain 95+ PageSpeed
    // Check width / height alignment
    const dpr = window.devicePixelRatio || 1;
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width * dpr;
    canvas.height = rect.height * dpr;
    ctx.scale(dpr, dpr);
    
    const width = rect.width;
    const height = rect.height;
    
    // Padding
    const padLeft = 60;
    const padRight = 20;
    const padTop = 30;
    const padBottom = 40;
    
    const chartW = width - padLeft - padRight;
    const chartH = height - padTop - padBottom;
    
    // Find Max Value for scaling
    const maxVal = Math.max(...revenue, ...collected, 1000) * 1.15;
    
    // Grid Lines & Y Labels
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.05)';
    ctx.fillStyle = '#9CA3AF';
    ctx.font = '10px sans-serif';
    ctx.lineWidth = 1;
    
    const gridRows = 4;
    for(let r=0; r<=gridRows; r++) {
        const y = padTop + (chartH * r / gridRows);
        ctx.beginPath();
        ctx.moveTo(padLeft, y);
        ctx.lineTo(width - padRight, y);
        ctx.stroke();
        
        const labelVal = Math.round(maxVal - (maxVal * r / gridRows));
        ctx.fillText('Rs. ' + (labelVal / 1000) + 'k', 10, y + 4);
    }
    
    // Draw Lines helper
    function drawLine(data, color, shadowColor) {
        ctx.beginPath();
        ctx.lineWidth = 3;
        ctx.strokeStyle = color;
        ctx.shadowColor = shadowColor;
        ctx.shadowBlur = 8;
        
        const pts = [];
        for(let i=0; i<data.length; i++) {
            const x = padLeft + (chartW * i / (data.length - 1));
            const y = padTop + chartH - (chartH * data[i] / maxVal);
            pts.push({x, y});
            
            if (i === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        }
        ctx.stroke();
        ctx.shadowBlur = 0; // reset
        
        // Draw circles on points
        ctx.fillStyle = color;
        pts.forEach(pt => {
            ctx.beginPath();
            ctx.arc(pt.x, pt.y, 4, 0, Math.PI * 2);
            ctx.fill();
            
            ctx.fillStyle = '#0E1320';
            ctx.beginPath();
            ctx.arc(pt.x, pt.y, 2, 0, Math.PI * 2);
            ctx.fill();
            ctx.fillStyle = color; // reset
        });
    }
    
    // Draw months label
    ctx.fillStyle = '#6B7280';
    for(let i=0; i<months.length; i++) {
        const x = padLeft + (chartW * i / (months.length - 1));
        ctx.fillText(months[i].split(' ')[0], x - 12, height - 15);
    }
    
    // Draw Revenue issued (Indigo)
    drawLine(revenue, '#6366F1', 'rgba(99, 102, 241, 0.4)');
    
    // Draw Collected revenue (Emerald)
    drawLine(collected, '#10B981', 'rgba(16, 185, 129, 0.4)');
});
</script>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
