<?php
/**
 * Zone Divisions Management (CRUD)
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$edit_id = (int)($_GET['id'] ?? 0);
$zone_name = '';
$zone_desc = '';

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $zone_name = clean_input($_POST['name'] ?? '');
    $zone_desc = clean_input($_POST['description'] ?? '');
    
    if (empty($zone_name)) {
        $errors[] = "Zone division name is required.";
    }
    
    if (empty($errors)) {
        try {
            if (isset($_POST['add_zone'])) {
                // SaaS Subscription Limits check
                try {
                    $stmt_lim = $pdo->prepare("SELECT p.max_zones FROM tenants t JOIN saas_plans p ON t.plan_id = p.id WHERE t.id = ? LIMIT 1");
                    $stmt_lim->execute([$tenant_id]);
                    $max_zones = (int)$stmt_lim->fetchColumn();
                    
                    $current_zones = (int)$pdo->query("SELECT COUNT(*) FROM zones WHERE tenant_id = $tenant_id")->fetchColumn();
                    
                    if ($current_zones >= $max_zones) {
                        $errors[] = "SaaS Plan Limit Exceeded! Your current subscription plan restricts geographic coverage divisions to a maximum of $max_zones. Please contact " . get_platform_email('admin') . " to upgrade your SaaS plan.";
                    }
                } catch (PDOException $ex) {
                    $errors[] = "SaaS verification error: " . $ex->getMessage();
                }
            }
            
            if (empty($errors) && isset($_POST['add_zone'])) {
                // CREATE
                $stmt = $pdo->prepare("INSERT INTO zones (tenant_id, name, description) VALUES (?, ?, ?)");
                $stmt->execute([$tenant_id, $zone_name, $zone_desc]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Created network zone division: $zone_name");
                set_session_alert("Zone division '$zone_name' created successfully.", "success");
                header("Location: zones.php");
                exit;
                
            } elseif (isset($_POST['edit_zone']) && $edit_id > 0) {
                // UPDATE
                // Verify zone belongs to tenant
                $check = $pdo->prepare("SELECT id FROM zones WHERE id = ? AND tenant_id = ?");
                $check->execute([$edit_id, $tenant_id]);
                if ($check->fetch()) {
                    $stmt = $pdo->prepare("UPDATE zones SET name = ?, description = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$zone_name, $zone_desc, $edit_id, $tenant_id]);
                    
                    log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Updated network zone division (ID: $edit_id) to: $zone_name");
                    set_session_alert("Zone division updated successfully.", "success");
                } else {
                    set_session_alert("Unauthorized access or invalid zone division.", "error");
                }
                header("Location: zones.php");
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
        // 1. Verify zone belongs to tenant
        $check = $pdo->prepare("SELECT name FROM zones WHERE id = ? AND tenant_id = ?");
        $check->execute([$edit_id, $tenant_id]);
        $zone = $check->fetch();
        
        if ($zone) {
            // 2. Check if any customers are assigned to this zone
            $cust_check = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE zone_id = ? AND tenant_id = ?");
            $cust_check->execute([$edit_id, $tenant_id]);
            $assigned_count = (int)$cust_check->fetchColumn();
            
            if ($assigned_count > 0) {
                set_session_alert("Cannot delete zone '{$zone['name']}' because it has {$assigned_count} customers assigned. Reassign them first.", "error");
            } else {
                $del = $pdo->prepare("DELETE FROM zones WHERE id = ? AND tenant_id = ?");
                $del->execute([$edit_id, $tenant_id]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Deleted network zone division: " . $zone['name']);
                set_session_alert("Zone division deleted successfully.", "success");
            }
        } else {
            set_session_alert("Unauthorized access or invalid zone division.", "error");
        }
    } catch (PDOException $e) {
        set_session_alert("Delete operation fail: " . $e->getMessage(), "error");
    }
    header("Location: zones.php");
    exit;
}

// Load Zone for Editing
if ($action === 'edit' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM zones WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$edit_id, $tenant_id]);
        $zone_data = $stmt->fetch();
        if ($zone_data) {
            $zone_name = $zone_data['name'];
            $zone_desc = $zone_data['description'];
        } else {
            set_session_alert("Zone division not found or unauthorized.", "error");
            header("Location: zones.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Edit load fail: " . $e->getMessage());
    }
}

// Fetch all zones for display
$zones = [];
try {
    $stmt = $pdo->prepare("SELECT z.*, COUNT(c.id) as customer_count FROM zones z 
        LEFT JOIN customers c ON z.id = c.zone_id 
        WHERE z.tenant_id = ? 
        GROUP BY z.id 
        ORDER BY z.name ASC");
    $stmt->execute([$tenant_id]);
    $zones = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = "Failed to load zone list: " . $e->getMessage();
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-geo text-primary me-2"></i>Zone Divisions</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Group subscribers geographically to filter notifications and financial reports.</p>
    </div>
</div>

<div class="row g-4">
    <!-- CRUD Control Panel Column -->
    <div class="col-lg-4">
        <div class="p-4 glass-card">
            <h5 class="text-white mb-3">
                <?php echo ($action === 'edit') ? 'Modify Zone Division' : 'Add New Zone Division'; ?>
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
            
            <form action="zones.php<?php echo ($action === 'edit') ? '?action=edit&id=' . $edit_id : ''; ?>" method="POST" class="d-flex flex-column gap-3">
                <?php csrf_field(); ?>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Zone Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Zone A / Sector 4" value="<?php echo e($zone_name); ?>" required autocomplete="off">
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Description (Optional)</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Define coverage area details..."><?php echo e($zone_desc); ?></textarea>
                </div>
                
                <div class="d-flex gap-2 mt-2">
                    <?php if ($action === 'edit'): ?>
                        <button type="submit" name="edit_zone" class="btn btn-primary-gradient flex-grow-1 py-2" style="font-size: 0.9rem;">Update Division</button>
                        <a href="zones.php" class="btn btn-dark-glass py-2 px-3" style="font-size: 0.9rem;">Cancel</a>
                    <?php else: ?>
                        <button type="submit" name="add_zone" class="btn btn-primary-gradient w-100 py-2" style="font-size: 0.9rem;">Create Zone Division</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Zone Listing Grid Column -->
    <div class="col-lg-8">
        <div class="table-responsive-glass">
            <table class="table table-glass align-middle">
                <thead>
                    <tr>
                        <th style="width: 25%;">Zone Name</th>
                        <th style="width: 40%;">Description</th>
                        <th style="width: 15%; text-align: center;">Subscribers</th>
                        <th style="width: 20%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($zones)): ?>
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted" style="font-size: 0.9rem;">
                                <i class="bi bi-geo-alt fs-2 mb-2 d-block opacity-40"></i>
                                No zones division created yet. Set up one on the left panel!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($zones as $zone): ?>
                            <tr>
                                <td class="fw-bold text-white"><?php echo e($zone['name']); ?></td>
                                <td class="text-muted" style="font-size: 0.88rem;"><?php echo e($zone['description'] ?: 'N/A'); ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-soft text-light px-2.5 py-1" style="background: rgba(255,255,255,0.04); border: 1px solid var(--border-color);">
                                        <?php echo $zone['customer_count']; ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1.5">
                                        <a href="zones.php?action=edit&id=<?php echo $zone['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <a href="zones.php?action=delete&id=<?php echo $zone['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-danger border-danger border-opacity-10" onclick="return confirm('Are you sure you want to delete zone division \'<?php echo e($zone['name']); ?>\'?')" title="Delete"><i class="bi bi-trash"></i></a>
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
