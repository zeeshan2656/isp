<?php
/**
 * Connection Interfaces Management Module (CRUD)
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$edit_id = (int)($_GET['id'] ?? 0);

$conn_name = '';
$conn_category = 'Fiber';
$conn_desc = '';
$conn_speed = '';
$conn_status = 'active';

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $conn_name = clean_input($_POST['name'] ?? '');
    $conn_category = clean_input($_POST['type_category'] ?? 'Fiber');
    $conn_desc = clean_input($_POST['description'] ?? '');
    $conn_speed = clean_input($_POST['speed_capacity'] ?? '');
    $conn_status = clean_input($_POST['status'] ?? 'active');
    
    if (empty($conn_name)) {
        $errors[] = "Connection interface name is required.";
    }
    if (empty($conn_category)) {
        $errors[] = "Connection category is required.";
    }
    
    if (empty($errors)) {
        try {
            if (isset($_POST['add_interface'])) {
                // CREATE
                $stmt = $pdo->prepare("INSERT INTO connection_interfaces (tenant_id, name, type_category, description, speed_capacity, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$tenant_id, $conn_name, $conn_category, $conn_desc, $conn_speed, $conn_status]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Created connection interface: $conn_name ($conn_category)");
                set_session_alert("Connection interface '$conn_name' created successfully.", "success");
                header("Location: connection_interfaces.php");
                exit;
                
            } elseif (isset($_POST['edit_interface']) && $edit_id > 0) {
                // UPDATE
                // Verify interface belongs to tenant
                $check = $pdo->prepare("SELECT id FROM connection_interfaces WHERE id = ? AND tenant_id = ?");
                $check->execute([$edit_id, $tenant_id]);
                if ($check->fetch()) {
                    $stmt = $pdo->prepare("UPDATE connection_interfaces SET name = ?, type_category = ?, description = ?, speed_capacity = ?, status = ? WHERE id = ? AND tenant_id = ?");
                    $stmt->execute([$conn_name, $conn_category, $conn_desc, $conn_speed, $conn_status, $edit_id, $tenant_id]);
                    
                    log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Updated connection interface (ID: $edit_id) to: $conn_name");
                    set_session_alert("Connection interface updated successfully.", "success");
                } else {
                    set_session_alert("Unauthorized access or invalid connection interface.", "error");
                }
                header("Location: connection_interfaces.php");
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
        // Verify interface belongs to tenant
        $check = $pdo->prepare("SELECT name FROM connection_interfaces WHERE id = ? AND tenant_id = ?");
        $check->execute([$edit_id, $tenant_id]);
        $conn = $check->fetch();
        
        if ($conn) {
            // Check if any customers are assigned to this connection interface
            $cust_check = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE connection_interface_id = ? AND tenant_id = ?");
            $cust_check->execute([$edit_id, $tenant_id]);
            $assigned_count = (int)$cust_check->fetchColumn();
            
            if ($assigned_count > 0) {
                set_session_alert("Cannot delete interface '{$conn['name']}' because it has {$assigned_count} customers assigned. Reassign them first.", "error");
            } else {
                $del = $pdo->prepare("DELETE FROM connection_interfaces WHERE id = ? AND tenant_id = ?");
                $del->execute([$edit_id, $tenant_id]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Deleted connection interface: " . $conn['name']);
                set_session_alert("Connection interface deleted successfully.", "success");
            }
        } else {
            set_session_alert("Unauthorized access or invalid connection interface.", "error");
        }
    } catch (PDOException $e) {
        set_session_alert("Delete operation fail: " . $e->getMessage(), "error");
    }
    header("Location: connection_interfaces.php");
    exit;
}

// Load Interface for Editing
if ($action === 'edit' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM connection_interfaces WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$edit_id, $tenant_id]);
        $conn_data = $stmt->fetch();
        if ($conn_data) {
            $conn_name = $conn_data['name'];
            $conn_category = $conn_data['type_category'];
            $conn_desc = $conn_data['description'];
            $conn_speed = $conn_data['speed_capacity'];
            $conn_status = $conn_data['status'];
        } else {
            set_session_alert("Connection interface not found or unauthorized.", "error");
            header("Location: connection_interfaces.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Edit load fail: " . $e->getMessage());
    }
}

// Fetch all connection interfaces for display
$interfaces = [];
try {
    $stmt = $pdo->prepare("SELECT i.*, COUNT(c.id) as customer_count 
        FROM connection_interfaces i 
        LEFT JOIN customers c ON i.id = c.connection_interface_id 
        WHERE i.tenant_id = ? 
        GROUP BY i.id 
        ORDER BY i.name ASC");
    $stmt->execute([$tenant_id]);
    $interfaces = $stmt->fetchAll();
} catch (PDOException $e) {
    $errors[] = "Failed to load connection interfaces list: " . $e->getMessage();
}
?>

<div class="row align-items-center mb-4">
    <div class="col">
        <h2 class="text-white mb-1"><i class="bi bi-hdd-network text-primary me-2"></i>Connection Interfaces</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Configure custom connection types (Fiber, FTTH, Wireless, GPON, etc.) and assign them dynamically to subscribers.</p>
    </div>
</div>

<div class="row g-4">
    <!-- CRUD Control Panel Column -->
    <div class="col-lg-4">
        <div class="p-4 glass-card">
            <h5 class="text-white mb-3">
                <?php echo ($action === 'edit') ? 'Modify Connection Interface' : 'Add Connection Interface'; ?>
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
            
            <form action="connection_interfaces.php<?php echo ($action === 'edit') ? '?action=edit&id=' . $edit_id : ''; ?>" method="POST" class="d-flex flex-column gap-3">
                <?php csrf_field(); ?>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Interface Name</label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. FTTH Premium / Optical Fiber" value="<?php echo e($conn_name); ?>" required autocomplete="off">
                </div>

                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Type Category</label>
                    <select name="type_category" class="form-select" required>
                        <option value="Fiber" <?php echo ($conn_category === 'Fiber') ? 'selected' : ''; ?>>Optical Fiber / FTTH</option>
                        <option value="GPON" <?php echo ($conn_category === 'GPON') ? 'selected' : ''; ?>>GPON</option>
                        <option value="Wireless" <?php echo ($conn_category === 'Wireless') ? 'selected' : ''; ?>>Wireless Link</option>
                        <option value="Radio" <?php echo ($conn_category === 'Radio') ? 'selected' : ''; ?>>Radio Connection</option>
                        <option value="Cable" <?php echo ($conn_category === 'Cable') ? 'selected' : ''; ?>>Coaxial Cable</option>
                        <option value="Corporate" <?php echo ($conn_category === 'Corporate') ? 'selected' : ''; ?>>Dedicated Corporate Line</option>
                        <option value="Custom" <?php echo ($conn_category === 'Custom') ? 'selected' : ''; ?>>Custom / Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Speed Capacity (e.g. 100 Mbps, 1 Gbps)</label>
                    <input type="text" name="speed_capacity" class="form-control" placeholder="e.g. 100 Mbps" value="<?php echo e($conn_speed); ?>" autocomplete="off">
                </div>

                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Status</label>
                    <select name="status" class="form-select" required>
                        <option value="active" <?php echo ($conn_status === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo ($conn_status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div>
                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Description (Optional)</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Define physical layout, setup constraints..."><?php echo e($conn_desc); ?></textarea>
                </div>
                
                <div class="d-flex gap-2 mt-2">
                    <?php if ($action === 'edit'): ?>
                        <button type="submit" name="edit_interface" class="btn btn-primary-gradient flex-grow-1 py-2" style="font-size: 0.9rem;">Update Interface</button>
                        <a href="connection_interfaces.php" class="btn btn-dark-glass py-2 px-3" style="font-size: 0.9rem;">Cancel</a>
                    <?php else: ?>
                        <button type="submit" name="add_interface" class="btn btn-primary-gradient w-100 py-2" style="font-size: 0.9rem;">Create Interface</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Listing Grid Column -->
    <div class="col-lg-8">
        <div class="table-responsive-glass">
            <table class="table table-glass align-middle">
                <thead>
                    <tr>
                        <th style="width: 25%;">Name</th>
                        <th style="width: 15%;">Category</th>
                        <th style="width: 15%;">Speed Capacity</th>
                        <th style="width: 15%; text-align: center;">Status</th>
                        <th style="width: 15%; text-align: center;">Subscribers</th>
                        <th style="width: 15%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($interfaces)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted" style="font-size: 0.9rem;">
                                <i class="bi bi-hdd-network fs-2 mb-2 d-block opacity-40"></i>
                                No connection interfaces defined yet. Create your first dynamic type on the left!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($interfaces as $item): ?>
                            <tr>
                                <td>
                                    <span class="fw-bold text-white d-block"><?php echo e($item['name']); ?></span>
                                    <small class="text-muted" style="font-size: 0.75rem;"><?php echo e($item['description'] ?: 'No description'); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-20 px-2 py-0.5 rounded" style="font-size: 0.75rem;">
                                        <?php echo e($item['type_category']); ?>
                                    </span>
                                </td>
                                <td class="text-white fw-bold" style="font-size: 0.88rem;"><?php echo e($item['speed_capacity'] ?: 'Unlimited'); ?></td>
                                <td class="text-center">
                                    <?php if ($item['status'] === 'active'): ?>
                                        <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-2 py-0.5 rounded-pill" style="font-size: 0.72rem;">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-15 text-danger border border-danger border-opacity-25 px-2 py-0.5 rounded-pill" style="font-size: 0.72rem;">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-soft text-light px-2.5 py-1" style="background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); font-size: 0.8rem;">
                                        <?php echo $item['customer_count']; ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1.5">
                                        <a href="connection_interfaces.php?action=edit&id=<?php echo $item['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2" title="Edit"><i class="bi bi-pencil"></i></a>
                                        <a href="connection_interfaces.php?action=delete&id=<?php echo $item['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-danger border-danger border-opacity-10" onclick="return confirm('Are you sure you want to delete connection interface \'<?php echo e($item['name']); ?>\'?')" title="Delete"><i class="bi bi-trash"></i></a>
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
