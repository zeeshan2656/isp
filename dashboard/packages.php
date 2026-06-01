<?php
/**
 * Package Management (CRUD)
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$edit_id = (int)($_GET['id'] ?? 0);

$pkg_name = '';
$pkg_speed = '';
$pkg_price = '';
$pkg_desc = '';
$pkg_status = 'active';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $pkg_name = clean_input($_POST['name'] ?? '');
    $pkg_speed = (int)($_POST['speed_mbps'] ?? 0);
    $pkg_price = (double)($_POST['monthly_price'] ?? 0.00);
    $pkg_desc = clean_input($_POST['description'] ?? '');
    $pkg_status = clean_input($_POST['status'] ?? 'active');
    
    if (empty($pkg_name)) $errors[] = "Package name is required.";
    if ($pkg_speed <= 0) $errors[] = "Package speed must be greater than 0 Mbps.";
    if ($pkg_price <= 0) $errors[] = "Package monthly price must be greater than Rs. 0.";
    
    if (empty($errors)) {
        try {
            if (isset($_POST['add_pkg'])) {
                // SaaS Subscription Limits check
                try {
                    $stmt_lim = $pdo->prepare("SELECT p.max_packages FROM tenants t JOIN saas_plans p ON t.plan_id = p.id WHERE t.id = ? LIMIT 1");
                    $stmt_lim->execute([$tenant_id]);
                    $max_packages = (int)$stmt_lim->fetchColumn();
                    
                    $current_packages = (int)$pdo->query("SELECT COUNT(*) FROM packages WHERE tenant_id = $tenant_id")->fetchColumn();
                    
                    if ($current_packages >= $max_packages) {
                        $errors[] = "SaaS Plan Limit Exceeded! Your current subscription plan restricts active bandwidth packages to a maximum of $max_packages. Please contact " . get_platform_email('admin') . " to upgrade your SaaS plan.";
                    }
                } catch (PDOException $ex) {
                    $errors[] = "SaaS verification error: " . $ex->getMessage();
                }
            }
            
            if (empty($errors) && isset($_POST['add_pkg'])) {
                // CREATE
                $stmt = $pdo->prepare("INSERT INTO packages (tenant_id, name, speed_mbps, monthly_price, description, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$tenant_id, $pkg_name, $pkg_speed, $pkg_price, $pkg_desc, $pkg_status]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Created internet package: $pkg_name ($pkg_speed Mbps)");
                set_session_alert("Internet package '$pkg_name' created successfully.", "success");
                header("Location: packages.php");
                exit;
                
            } elseif (isset($_POST['edit_pkg']) && $edit_id > 0) {
                // UPDATE
                $check = $pdo->prepare("SELECT id FROM packages WHERE id = ? AND tenant_id = ?");
                $check->execute([$edit_id, $tenant_id]);
                if ($check->fetch()) {
                    $stmt = $pdo->prepare("UPDATE packages SET name = ?, speed_mbps = ?, monthly_price = ?, description = ?, status = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$pkg_name, $pkg_speed, $pkg_price, $pkg_desc, $pkg_status, $edit_id, $tenant_id]);
                    
                    log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Updated internet package (ID: $edit_id) to: $pkg_name ($pkg_speed Mbps)");
                    set_session_alert("Internet package updated successfully.", "success");
                } else {
                    set_session_alert("Unauthorized access or invalid package.", "error");
                }
                header("Location: packages.php");
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "Database operation fail: " . $e->getMessage();
        }
    }
}

// Handle Delete Operation
if ($action === 'delete' && $edit_id > 0) {
    try {
        $check = $pdo->prepare("SELECT name FROM packages WHERE id = ? AND tenant_id = ?");
        $check->execute([$edit_id, $tenant_id]);
        $pkg = $check->fetch();
        
        if ($pkg) {
            // Check if any active customers are assigned to this package
            $cust_check = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE assigned_package_id = ? AND tenant_id = ?");
            $cust_check->execute([$edit_id, $tenant_id]);
            $assigned_count = (int)$cust_check->fetchColumn();
            
            if ($assigned_count > 0) {
                set_session_alert("Cannot delete package '{$pkg['name']}' because it is assigned to {$assigned_count} subscribers. Change their package first.", "error");
            } else {
                $del = $pdo->prepare("DELETE FROM packages WHERE id = ? AND tenant_id = ?");
                $del->execute([$edit_id, $tenant_id]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Deleted internet package: " . $pkg['name']);
                set_session_alert("Internet package deleted successfully.", "success");
            }
        } else {
            set_session_alert("Unauthorized access or invalid package.", "error");
        }
    } catch (PDOException $e) {
        set_session_alert("Delete operation fail: " . $e->getMessage(), "error");
    }
    header("Location: packages.php");
    exit;
}

// Load Package for Editing
if ($action === 'edit' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM packages WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$edit_id, $tenant_id]);
        $pkg_data = $stmt->fetch();
        if ($pkg_data) {
            $pkg_name = $pkg_data['name'];
            $pkg_speed = $pkg_data['speed_mbps'];
            $pkg_price = $pkg_data['monthly_price'];
            $pkg_desc = $pkg_data['description'];
            $pkg_status = $pkg_data['status'];
        } else {
            set_session_alert("Internet package not found or unauthorized.", "error");
            header("Location: packages.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Edit load fail: " . $e->getMessage());
    }
}

// Fetch all packages for display
$packages = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, COUNT(c.id) as subscriber_count FROM packages p 
        LEFT JOIN customers c ON p.id = c.assigned_package_id 
        WHERE p.tenant_id = ? 
        GROUP BY p.id 
        ORDER BY p.monthly_price ASC");
    $stmt->execute([$tenant_id]);
    $packages = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = "Failed to load packages: " . $e->getMessage();
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-router text-primary me-2"></i>Internet Packages</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Configure custom bandwidth limits, speeds, and monthly pricing variables.</p>
    </div>
</div>

<div class="row g-4">
    <!-- CRUD Controls Column -->
    <div class="col-lg-4">
        <div class="p-4 glass-card">
            <h5 class="text-white mb-3">
                <?php echo ($action === 'edit') ? 'Modify Package' : 'Create Custom Package'; ?>
            </h5>
            
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.85rem;">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form action="packages.php<?php echo ($action === 'edit') ? '?action=edit&id=' . $edit_id : ''; ?>" method="POST" class="d-flex flex-column gap-3">
                <?php csrf_field(); ?>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Package Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. 20 Mbps Ultra Fiber" value="<?php echo e($pkg_name); ?>" required autocomplete="off">
                </div>
                
                <div class="row g-2">
                    <div class="col">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Speed (Mbps)</label>
                        <input type="number" name="speed_mbps" class="form-control" placeholder="20" value="<?php echo e($pkg_speed); ?>" required min="1">
                    </div>
                    <div class="col">
                        <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Price (Monthly)</label>
                        <input type="number" name="monthly_price" class="form-control" placeholder="2500" value="<?php echo e($pkg_price); ?>" required min="1" step="0.01">
                    </div>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?php echo ($pkg_status === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($pkg_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Description (Optional)</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Additional features (e.g. Unlimited downloads, dedicated IP)..."><?php echo e($pkg_desc); ?></textarea>
                </div>
                
                <div class="d-flex gap-2 mt-2">
                    <?php if ($action === 'edit'): ?>
                        <button type="submit" name="edit_pkg" class="btn btn-primary-gradient flex-grow-1 py-2" style="font-size: 0.9rem;">Update Package</button>
                        <a href="packages.php" class="btn btn-dark-glass py-2 px-3" style="font-size: 0.9rem;">Cancel</a>
                    <?php else: ?>
                        <button type="submit" name="add_pkg" class="btn btn-primary-gradient w-100 py-2" style="font-size: 0.9rem;">Create Package</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Package Grid Column -->
    <div class="col-lg-8">
        <div class="table-responsive-glass">
            <table class="table table-glass align-middle">
                <thead>
                    <tr>
                        <th style="width: 25%;">Package Name</th>
                        <th style="width: 15%; text-align: center;">Speed</th>
                        <th style="width: 20%; text-align: right;">Price</th>
                        <th style="width: 12%; text-align: center;">Status</th>
                        <th style="width: 13%; text-align: center;">Users</th>
                        <th style="width: 15%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($packages)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted" style="font-size: 0.9rem;">
                                <i class="bi bi-router fs-2 mb-2 d-block opacity-40"></i>
                                No packages division created yet. Build one on the left panel!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($packages as $pkg): ?>
                            <tr>
                                <td>
                                    <span class="fw-bold text-white d-block"><?php echo e($pkg['name']); ?></span>
                                    <small class="text-muted d-block" style="font-size: 0.75rem; text-overflow: ellipsis; max-width: 200px; white-space: nowrap; overflow: hidden;"><?php echo e($pkg['description'] ?: 'No details'); ?></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-soft text-light" style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color);">
                                        <?php echo format_bandwidth($pkg['speed_mbps']); ?>
                                    </span>
                                </td>
                                <td class="text-end fw-bold text-white"><?php echo format_currency($pkg['monthly_price']); ?></td>
                                <td class="text-center">
                                    <?php if ($pkg['status'] === 'active'): ?>
                                        <span class="badge bg-success-soft text-success px-2 py-0.5" style="background: rgba(16,185,129,0.12); font-size: 0.72rem; border: 1px solid rgba(16,185,129,0.25);">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger-soft text-danger px-2 py-0.5" style="background: rgba(239,68,68,0.12); font-size: 0.72rem; border: 1px solid rgba(239,68,68,0.25);">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-soft text-light px-2.5 py-1" style="background: rgba(255,255,255,0.04); border: 1px solid var(--border-color);">
                                        <?php echo $pkg['subscriber_count']; ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1.5">
                                        <a href="packages.php?action=edit&id=<?php echo $pkg['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <a href="packages.php?action=delete&id=<?php echo $pkg['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-danger border-danger border-opacity-10" onclick="return confirm('Are you sure you want to delete internet package \'<?php echo e($pkg['name']); ?>\'?')" title="Delete"><i class="bi bi-trash"></i></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
