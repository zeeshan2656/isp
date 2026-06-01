<?php
/**
 * Customer Management Module (CRUD, Paginated, Filterable)
 */
require_once __DIR__ . '/layouts/header.php';

$errors = [];
$action = clean_input($_GET['action'] ?? 'list');
$edit_id = (int)($_GET['id'] ?? 0);

// Load options (packages and zones) for dropdowns
$packages_opt = [];
$zones_opt = [];
$conn_interfaces_opt = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, monthly_price FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$tenant_id]);
    $packages_opt = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE tenant_id = ? ORDER BY name ASC");
    $stmt->execute([$tenant_id]);
    $zones_opt = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT id, name, type_category FROM connection_interfaces WHERE tenant_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$tenant_id]);
    $conn_interfaces_opt = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Options fetch fail: " . $e->getMessage());
}

// Variables for Form Fields
$cust_name = '';
$cust_cnic = '';
$cust_phone = '';
$cust_email = '';
$cust_password = '';
$cust_address = '';
$cust_area = '';
$cust_zone_id = '';
$cust_conn_type = 'Fiber';
$cust_connection_interface_id = 0;
$cust_package_id = '';
$cust_monthly_fee = '';
$cust_install_fee = '0.00';
$cust_active_date = date('Y-m-d');
$cust_expiry_date = date('Y-m-d', strtotime('+30 days'));
$cust_status = 'active';
$cust_notes = '';
$cust_joining_date = date('Y-m-d');

// Handle Subscriber Promotion Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_subscriber'])) {
    verify_csrf_token();
    
    $promote_cust_id = (int)($_POST['promote_customer_id'] ?? 0);
    $new_pkg_id = (int)($_POST['promote_package_id'] ?? 0);
    $new_zone_id = (int)($_POST['promote_zone_id'] ?? 0);
    $new_fee = (double)($_POST['promote_monthly_fee'] ?? 0.00);
    
    if ($promote_cust_id <= 0) $errors[] = "Invalid subscriber selected.";
    if ($new_pkg_id <= 0) $errors[] = "Please select a target package.";
    if ($new_zone_id <= 0) $errors[] = "Please select a target zone.";
    if ($new_fee < 0) $errors[] = "Monthly fee cannot be negative.";
    
    if (empty($errors)) {
        try {
            $chk = $pdo->prepare("SELECT name, assigned_package_id, zone_id, monthly_fee FROM customers WHERE id = ? AND tenant_id = ?");
            $chk->execute([$promote_cust_id, $tenant_id]);
            $old_data = $chk->fetch();
            
            if ($old_data) {
                $stmt_pkg = $pdo->prepare("SELECT name, monthly_price FROM packages WHERE id = ? AND tenant_id = ? LIMIT 1");
                $stmt_pkg->execute([$new_pkg_id, $tenant_id]);
                $pkg = $stmt_pkg->fetch();
                
                if ($pkg) {
                    $pdo->beginTransaction();
                    
                    $stmt_upd = $pdo->prepare("UPDATE customers SET assigned_package_id = ?, zone_id = ?, monthly_fee = ? WHERE id = ? AND tenant_id = ?");
                    $stmt_upd->execute([$new_pkg_id, $new_zone_id, $new_fee, $promote_cust_id, $tenant_id]);
                    
                    $notif_title = "Subscription Plan Upgraded";
                    $notif_msg = "Congratulations! Your Internet service plan was promoted / updated to " . $pkg['name'] . " with a custom monthly fee of Rs. " . number_format($new_fee, 2) . ".";
                    $ins_notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, ?, ?, 'system')");
                    $ins_notif->execute([$tenant_id, $promote_cust_id, $notif_title, $notif_msg]);
                    
                    $pdo->commit();
                    
                    log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Promoted subscriber " . $old_data['name'] . " (ID: $promote_cust_id) - Plan: {$old_data['assigned_package_id']} -> $new_pkg_id, Zone: {$old_data['zone_id']} -> $new_zone_id");
                    
                    set_session_alert("Subscriber '" . $old_data['name'] . "' plan & zone promoted successfully!", "success");
                } else {
                    set_session_alert("Selected package plan is invalid.", "error");
                }
            } else {
                set_session_alert("Unauthorized access or invalid customer account.", "error");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_session_alert("Promotion failed: " . $e->getMessage(), "error");
        }
        header("Location: customers.php");
        exit;
    }
}
// Handle Customer Password Reset from List / Profile page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_customer_password'])) {
    verify_csrf_token();
    
    $cust_id = (int)($_POST['customer_id'] ?? 0);
    $new_password = clean_input($_POST['new_password'] ?? '');
    
    if ($cust_id <= 0) $errors[] = "Invalid subscriber selection.";
    if (empty($new_password)) $errors[] = "New password cannot be empty.";
    elseif (strlen($new_password) < 6) $errors[] = "New password must be at least 6 characters.";
    
    if (empty($errors)) {
        try {
            // Verify ownership
            $check = $pdo->prepare("SELECT name FROM customers WHERE id = ? AND tenant_id = ?");
            $check->execute([$cust_id, $tenant_id]);
            $cust = $check->fetch();
            
            if ($cust) {
                $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE customers SET password_hash = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$password_hash, $cust_id, $tenant_id]);
                
                log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Reset password for subscriber: " . $cust['name'] . " (ID: $cust_id)");
                set_session_alert("Password reset successfully for subscriber '" . $cust['name'] . "'.", "success");
            } else {
                set_session_alert("Unauthorized access or invalid customer account.", "error");
            }
        } catch (PDOException $e) {
            set_session_alert("Password reset failed: " . $e->getMessage(), "error");
        }
    } else {
        set_session_alert(implode(" ", $errors), "error");
    }
    header("Location: customers.php");
    exit;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $cust_name = clean_input($_POST['name'] ?? '');
    $cust_cnic = clean_input($_POST['cnic'] ?? '');
    $cust_phone = clean_input($_POST['phone'] ?? '');
    $cust_email = clean_input($_POST['email'] ?? '');
    $cust_password = $_POST['password'] ?? '';
    $cust_address = clean_input($_POST['address'] ?? '');
    $cust_area = clean_input($_POST['area'] ?? '');
    $cust_zone_id = (int)($_POST['zone_id'] ?? 0);
    $cust_connection_interface_id = (int)($_POST['connection_interface_id'] ?? 0);
    $cust_package_id = (int)($_POST['assigned_package_id'] ?? 0);
    $cust_monthly_fee = (double)($_POST['monthly_fee'] ?? 0.00);
    $cust_install_fee = (double)($_POST['installation_fee'] ?? 0.00);
    if ($action === 'add') {
        $cust_active_date = date('Y-m-d');
        $cust_expiry_date = date('Y-m-d', strtotime('+30 days'));
    } else {
        $cust_active_date = clean_input($_POST['activation_date'] ?? '');
        $cust_expiry_date = clean_input($_POST['expiry_date'] ?? '');
    }
    $cust_status = clean_input($_POST['status'] ?? 'active');
    $cust_notes = clean_input($_POST['notes'] ?? '');
    $cust_joining_date = clean_input($_POST['joining_date'] ?? date('Y-m-d'));
    
    // Basic Validations
    if (empty($cust_name)) $errors[] = "Subscriber name is required.";
    if (empty($cust_cnic)) $errors[] = "CNIC / National ID is required.";
    if (empty($cust_phone)) $errors[] = "Phone number is required.";
    if (!filter_var($cust_email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
    if ($cust_zone_id <= 0) $errors[] = "Please select a zone division.";
    if ($cust_connection_interface_id <= 0) $errors[] = "Please select a connection interface.";
    if ($cust_package_id <= 0) $errors[] = "Please assign a package plan.";
    if (empty($cust_active_date) || empty($cust_expiry_date)) $errors[] = "Activation and expiry dates are required.";
    if (empty($cust_joining_date)) $errors[] = "Joining date is required.";
    
    if ($action === 'add' && empty($cust_password)) {
        $errors[] = "Please provide an initial login password for the self-service portal.";
    }
    
    if (empty($errors)) {
        try {
            // Verify email uniqueness inside the customers list
            $email_check = $pdo->prepare("SELECT id FROM customers WHERE email = ? " . ($action === 'edit' ? "AND id != $edit_id" : ""));
            $email_check->execute([$cust_email]);
            if ($email_check->fetch()) {
                $errors[] = "This customer email is already registered inside another subscriber account.";
            }
            
            if (empty($errors)) {
                if ($action === 'add') {
                    // SaaS Subscription Limits check
                    try {
                        $stmt_lim = $pdo->prepare("SELECT p.max_customers FROM tenants t JOIN saas_plans p ON t.plan_id = p.id WHERE t.id = ? LIMIT 1");
                        $stmt_lim->execute([$tenant_id]);
                        $max_customers = (int)$stmt_lim->fetchColumn();
                        
                        $current_customers = (int)$pdo->query("SELECT COUNT(*) FROM customers WHERE tenant_id = $tenant_id")->fetchColumn();
                        
                        if ($current_customers >= $max_customers) {
                            $errors[] = "SaaS Plan Limit Exceeded! Your current subscription plan restricts subscriber capacity to a maximum of $max_customers. Please contact " . get_platform_email('admin') . " to upgrade your SaaS plan.";
                        }
                    } catch (PDOException $ex) {
                        $errors[] = "SaaS verification error: " . $ex->getMessage();
                    }
                }
            }
            
            if (empty($errors)) {
                // Get connection category name
                $cust_conn_type = 'Fiber';
                foreach ($conn_interfaces_opt as $ci) {
                    if ((int)$ci['id'] === $cust_connection_interface_id) {
                        $cust_conn_type = $ci['type_category'];
                        break;
                    }
                }
                
                if ($action === 'add') {
                    // CREATE
                    $password_hash = password_hash($cust_password, PASSWORD_BCRYPT);
                    
                    $stmt = $pdo->prepare("INSERT INTO customers (tenant_id, name, cnic, phone, email, password_hash, address, area, zone_id, connection_type, connection_interface_id, assigned_package_id, monthly_fee, installation_fee, activation_date, expiry_date, status, notes, joining_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $tenant_id, $cust_name, $cust_cnic, $cust_phone, $cust_email, $password_hash,
                        $cust_address, $cust_area, $cust_zone_id, $cust_conn_type, $cust_connection_interface_id, $cust_package_id,
                        $cust_monthly_fee, $cust_install_fee, $cust_active_date, $cust_expiry_date, $cust_status, $cust_notes, $cust_joining_date
                    ]);
                    
                    $new_id = $pdo->lastInsertId();
                    
                    // Create dynamic welcome notification
                    $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Welcome to " . get_platform_name() . " Portal', 'Your subscription is successfully activated. Check your active details here!', 'system')");
                    $notif->execute([$tenant_id, $new_id]);
                    
                    log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Registered new customer: $cust_name (ID: $new_id)");
                    set_session_alert("Customer account created successfully.", "success");
                    header("Location: customers.php");
                    exit;
                    
                } elseif ($action === 'edit' && $edit_id > 0) {
                    // UPDATE
                    // Verify ownership
                    $check = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND tenant_id = ?");
                    $check->execute([$edit_id, $tenant_id]);
                    
                    if ($check->fetch()) {
                        if (!empty($cust_password)) {
                            // Update password too
                            $password_hash = password_hash($cust_password, PASSWORD_BCRYPT);
                            $stmt = $pdo->prepare("UPDATE customers SET name = ?, cnic = ?, phone = ?, email = ?, password_hash = ?, address = ?, area = ?, zone_id = ?, connection_type = ?, connection_interface_id = ?, assigned_package_id = ?, monthly_fee = ?, installation_fee = ?, activation_date = ?, expiry_date = ?, status = ?, notes = ?, joining_date = ? WHERE id = ? AND tenant_id = ?");
                            $stmt->execute([
                                $cust_name, $cust_cnic, $cust_phone, $cust_email, $password_hash, $cust_address, $cust_area,
                                $cust_zone_id, $cust_conn_type, $cust_connection_interface_id, $cust_package_id, $cust_monthly_fee, $cust_install_fee,
                                $cust_active_date, $cust_expiry_date, $cust_status, $cust_notes, $cust_joining_date, $edit_id, $tenant_id
                            ]);
                        } else {
                            // Leave password unchanged
                            $stmt = $pdo->prepare("UPDATE customers SET name = ?, cnic = ?, phone = ?, email = ?, address = ?, area = ?, zone_id = ?, connection_type = ?, connection_interface_id = ?, assigned_package_id = ?, monthly_fee = ?, installation_fee = ?, activation_date = ?, expiry_date = ?, status = ?, notes = ?, joining_date = ? WHERE id = ? AND tenant_id = ?");
                            $stmt->execute([
                                $cust_name, $cust_cnic, $cust_phone, $cust_email, $cust_address, $cust_area,
                                $cust_zone_id, $cust_conn_type, $cust_connection_interface_id, $cust_package_id, $cust_monthly_fee, $cust_install_fee,
                                $cust_active_date, $cust_expiry_date, $cust_status, $cust_notes, $cust_joining_date, $edit_id, $tenant_id
                            ]);
                        }
                        
                        log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Updated customer details: $cust_name (ID: $edit_id)");
                        set_session_alert("Customer account updated successfully.", "success");
                    } else {
                        set_session_alert("Unauthorized access or invalid customer account.", "error");
                    }
                    header("Location: customers.php");
                    exit;
                }
            }
        } catch (PDOException $e) {
            $errors[] = "Operation failure: " . $e->getMessage();
        }
    }
}

// Handle Customer Approval Action
if ($action === 'approve' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM customers WHERE id = ? AND tenant_id = ? AND status = 'pending'");
        $stmt->execute([$edit_id, $tenant_id]);
        $cust = $stmt->fetch();
        
        if ($cust) {
            $upd = $pdo->prepare("UPDATE customers SET status = 'active' WHERE id = ? AND tenant_id = ?");
            $upd->execute([$edit_id, $tenant_id]);
            
            // Log dynamic welcome notification inside customer portal
            $notif = $pdo->prepare("INSERT INTO notifications (tenant_id, customer_id, title, message, type) VALUES (?, ?, 'Welcome to the Network!', 'Your broadband account is approved by the ISP operator! Please settle your first invoice to activate speed.', 'system')");
            $notif->execute([$tenant_id, $edit_id]);
            
            log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Approved subscriber account: " . $cust['name'] . " (ID: $edit_id)");
            set_session_alert("Subscriber account approved successfully! Connection speed will activate automatically after payment is logged.", "success");
        } else {
            set_session_alert("Customer already active or invalid customer account.", "error");
        }
    } catch (PDOException $e) {
        set_session_alert("Approval failed: " . $e->getMessage(), "error");
    }
    header("Location: customers.php");
    exit;
}

// Handle Delete Operation
if ($action === 'delete' && $edit_id > 0) {
    try {
        $check = $pdo->prepare("SELECT name FROM customers WHERE id = ? AND tenant_id = ?");
        $check->execute([$edit_id, $tenant_id]);
        $cust = $check->fetch();
        
        if ($cust) {
            $del = $pdo->prepare("DELETE FROM customers WHERE id = ? AND tenant_id = ?");
            $del->execute([$edit_id, $tenant_id]);
            
            log_audit_activity($pdo, $tenant_id, 'tenant', $tenant_id, "Deleted customer account: " . $cust['name']);
            set_session_alert("Subscriber deleted successfully.", "success");
        } else {
            set_session_alert("Unauthorized access or invalid customer account.", "error");
        }
    } catch (PDOException $e) {
        set_session_alert("Delete operation fail: " . $e->getMessage(), "error");
    }
    header("Location: customers.php");
    exit;
}

// Load Customer for Editing
if ($action === 'edit' && $edit_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND tenant_id = ?");
        $stmt->execute([$edit_id, $tenant_id]);
        $cust_data = $stmt->fetch();
        if ($cust_data) {
            $cust_name = $cust_data['name'];
            $cust_cnic = $cust_data['cnic'];
            $cust_phone = $cust_data['phone'];
            $cust_email = $cust_data['email'];
            $cust_address = $cust_data['address'];
            $cust_area = $cust_data['area'];
            $cust_zone_id = $cust_data['zone_id'];
            $cust_conn_type = $cust_data['connection_type'];
            $cust_connection_interface_id = (int)$cust_data['connection_interface_id'];
            $cust_package_id = $cust_data['assigned_package_id'];
            $cust_monthly_fee = $cust_data['monthly_fee'];
            $cust_install_fee = $cust_data['installation_fee'];
            $cust_active_date = $cust_data['activation_date'];
            $cust_expiry_date = $cust_data['expiry_date'];
            $cust_status = $cust_data['status'];
            $cust_notes = $cust_data['notes'];
            $cust_joining_date = $cust_data['joining_date'] ?: $cust_data['activation_date'];
        } else {
            set_session_alert("Customer account not found or unauthorized.", "error");
            header("Location: customers.php");
            exit;
        }
    } catch (PDOException $e) {
        error_log("Edit load failure: " . $e->getMessage());
    }
}
?>

<!-- Header Section -->
<div class="row align-items-center mb-4">
    <div class="col-sm-8">
        <h2 class="text-white mb-1"><i class="bi bi-people text-primary me-2"></i>Subscriber Directory</h2>
        <p class="text-muted mb-0" style="font-size: 0.95rem;">Manage account statuses, connection details, and customize monthly billing fees.</p>
    </div>
    <div class="col-sm-4 text-sm-end mt-3 mt-sm-0">
        <?php if ($action === 'list'): ?>
            <a href="customers.php?action=add" class="btn btn-primary-gradient px-4 py-2.5" style="font-size: 0.9rem;"><i class="bi bi-person-plus me-1.5"></i>Add Subscriber</a>
        <?php else: ?>
            <a href="customers.php" class="btn btn-dark-glass px-4 py-2.5" style="font-size: 0.9rem;"><i class="bi bi-arrow-left me-1.5"></i>Back to Directory</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($action === 'list'): ?>
    
    <!-- GRID DIRECTORY LIST VIEW -->
    <?php
    // Setup Search & Filters parameters
    $search = clean_input($_GET['search'] ?? '');
    $filter_zone = (int)($_GET['zone'] ?? 0);
    $filter_package = (int)($_GET['package'] ?? 0);
    $filter_status = clean_input($_GET['status'] ?? '');
    $expiry_filter = (int)($_GET['expiry_filter'] ?? 0); // e.g. 10, 7, 5, 3, 1 day left
    
    // Construct Query Dynamically
    $query_parts = ["c.tenant_id = :tenant_id"];
    $query_params = [':tenant_id' => $tenant_id];
    
    if (!empty($search)) {
        $query_parts[] = "(c.name LIKE :search OR c.cnic LIKE :search OR c.phone LIKE :search OR c.email LIKE :search)";
        $query_params[':search'] = '%' . $search . '%';
    }
    
    if ($filter_zone > 0) {
        $query_parts[] = "c.zone_id = :zone_id";
        $query_params[':zone_id'] = $filter_zone;
    }
    
    if ($filter_package > 0) {
        $query_parts[] = "c.assigned_package_id = :package_id";
        $query_params[':package_id'] = $filter_package;
    }
    
    if (!empty($filter_status)) {
        $query_parts[] = "c.status = :status";
        $query_params[':status'] = $filter_status;
    }
    
    // Alert System Warning filters
    if ($expiry_filter > 0) {
        $today = date('Y-m-d');
        $expiry_limit = date('Y-m-d', strtotime("+$expiry_filter days"));
        $query_parts[] = "c.status = 'active' AND c.expiry_date BETWEEN :today AND :expiry_limit";
        $query_params[':today'] = $today;
        $query_params[':expiry_limit'] = $expiry_limit;
    }
    
    $where_sql = implode(" AND ", $query_parts);
    
    // Pagination Controls
    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) $page = 1;
    $limit = 15;
    $offset = ($page - 1) * $limit;
    
    $total_records = 0;
    $customers = [];
    
    try {
        // Count Query
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM customers c WHERE $where_sql");
        $count_stmt->execute($query_params);
        $total_records = (int)$count_stmt->fetchColumn();
        
        $total_pages = ceil($total_records / $limit);
        if ($total_pages < 1) $total_pages = 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $limit;
        
        // Select Query
        $select_sql = "SELECT c.*, p.name as package_name, p.monthly_price as package_base_price, z.name as zone_name, ci.name as conn_interface_name 
            FROM customers c 
            LEFT JOIN packages p ON c.assigned_package_id = p.id 
            LEFT JOIN zones z ON c.zone_id = z.id 
            LEFT JOIN connection_interfaces ci ON c.connection_interface_id = ci.id
            WHERE $where_sql 
            ORDER BY c.id DESC 
            LIMIT $limit OFFSET $offset";
            
        $stmt = $pdo->prepare($select_sql);
        
        // Bind parameters safely
        foreach ($query_params as $param => $val) {
            $stmt->bindValue($param, $val);
        }
        $stmt->execute();
        $customers = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Directory select failure: " . $e->getMessage());
    }
    ?>
    
    <!-- Filters Sidebar Panel card -->
    <div class="p-4 glass-card mb-4">
        <form action="customers.php" method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="list">
            
            <div class="col-md-3 col-sm-6">
                <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Search Subscriber</label>
                <div class="input-group">
                    <span class="input-group-text border-0" style="background: rgba(255,255,255,0.03); color: var(--text-dim);"><i class="bi bi-search"></i></span>
                    <input type="text" name="search" class="form-control" placeholder="Name, Phone, Email, CNIC" value="<?php echo e($search); ?>" style="font-size: 0.85rem;">
                </div>
            </div>
            
            <div class="col-md-2 col-sm-6">
                <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Zone Area</label>
                <select name="zone" class="form-select" style="font-size: 0.85rem;">
                    <option value="0">All Zones</option>
                    <?php foreach ($zones_opt as $z): ?>
                        <option value="<?php echo $z['id']; ?>" <?php echo ($filter_zone === (int)$z['id']) ? 'selected' : ''; ?>><?php echo e($z['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 col-sm-6">
                <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Assigned Package</label>
                <select name="package" class="form-select" style="font-size: 0.85rem;">
                    <option value="0">All Packages</option>
                    <?php foreach ($packages_opt as $p): ?>
                        <option value="<?php echo $p['id']; ?>" <?php echo ($filter_package === (int)$p['id']) ? 'selected' : ''; ?>><?php echo e($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2 col-sm-6">
                <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Sub Status</label>
                <select name="status" class="form-select" style="font-size: 0.85rem;">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="expired" <?php echo ($filter_status === 'expired') ? 'selected' : ''; ?>>Expired</option>
                    <option value="suspended" <?php echo ($filter_status === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                    <option value="pending" <?php echo ($filter_status === 'pending') ? 'selected' : ''; ?>>Pending Approval</option>
                </select>
            </div>
            
            <div class="col-md-2 col-sm-6">
                <label class="form-label text-muted mb-1" style="font-size: 0.75rem;">Expiry Alert Warnings</label>
                <select name="expiry_filter" class="form-select text-warning" style="font-size: 0.85rem; font-weight: 500;">
                    <option value="0">All Warnings</option>
                    <option value="10" <?php echo ($expiry_filter === 10) ? 'selected' : ''; ?>>10 Days Remaining</option>
                    <option value="7" <?php echo ($expiry_filter === 7) ? 'selected' : ''; ?>>7 Days Remaining</option>
                    <option value="5" <?php echo ($expiry_filter === 5) ? 'selected' : ''; ?>>5 Days Remaining</option>
                    <option value="3" <?php echo ($expiry_filter === 3) ? 'selected' : ''; ?>>3 Days Remaining</option>
                    <option value="1" <?php echo ($expiry_filter === 1) ? 'selected' : ''; ?>>1 Day Remaining</option>
                </select>
            </div>
            
            <div class="col-md-1 col-sm-6 text-end">
                <button type="submit" class="btn btn-primary-gradient w-100 py-2" style="font-size: 0.85rem;"><i class="bi bi-filter"></i></button>
            </div>
        </form>
    </div>
    
    <!-- Subscribers Paginated Grid table -->
    <div class="table-responsive-glass mb-4">
        <table class="table table-glass align-middle">
            <thead>
                <tr>
                    <th style="width: 25%;">Subscriber Details</th>
                    <th style="width: 15%;">Zone & Connection</th>
                    <th style="width: 18%;">Assigned Package</th>
                    <th style="width: 17%; text-align: center;">Subscription Expiry</th>
                    <th style="width: 10%; text-align: center;">Status</th>
                    <th style="width: 15%; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted" style="font-size: 0.9rem;">
                            <i class="bi bi-people fs-2 mb-2 d-block opacity-40"></i>
                            No subscriber found matching your filter criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): 
                        $cfg = get_expiry_alert_config($c['expiry_date']);
                        // Overridden cost color code
                        $fee_color = ($c['monthly_fee'] !== $c['package_base_price']) ? 'text-warning font-weight-bold' : 'text-white';
                        ?>
                        <tr>
                            <td>
                                <span class="fw-bold text-white d-block"><?php echo e($c['name']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.75rem;">
                                    CNIC: <?php echo e($c['cnic']); ?> &bull; Phone: <?php echo e($c['phone']); ?> <br>
                                    Email: <?php echo e($c['email']); ?>
                                </small>
                            </td>
                            <td>
                                <span class="text-white d-block" style="font-size: 0.88rem; font-weight: 500;"><?php echo e($c['zone_name']); ?></span>
                                <small class="text-muted d-block" style="font-size: 0.72rem;"><?php echo e($c['conn_interface_name'] ?: $c['connection_type']); ?> &bull; <?php echo e($c['area']); ?></small>
                            </td>
                            <td>
                                <span class="text-white d-block" style="font-size: 0.88rem; font-weight: 500;"><?php echo e($c['package_name']); ?></span>
                                <small class="<?php echo $fee_color; ?> d-block" style="font-size: 0.75rem;" title="Custom dynamic override price">Fee: <?php echo format_currency($c['monthly_fee']); ?></small>
                            </td>
                            <td class="text-center">
                                <span class="text-white d-block fw-bold" style="font-size: 0.85rem;"><?php echo format_date($c['expiry_date']); ?></span>
                                <span class="badge mt-1" style="background: <?php echo $cfg['bg']; ?>; color: <?php echo $cfg['text']; ?>; font-size: 0.65rem; border-radius: 4px; padding: 0.35em 0.7em;">
                                    <?php echo $cfg['label']; ?>
                                </span>
                            </td>
                             <td class="text-center">
                                <?php if ($c['status'] === 'active'): ?>
                                    <span class="badge bg-success-soft text-success px-2.5 py-1 rounded-pill" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.25);">Active</span>
                                <?php elseif ($c['status'] === 'expired'): ?>
                                    <span class="badge bg-danger-soft text-danger px-2.5 py-1 rounded-pill animate-pulse" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.25);">Expired</span>
                                <?php elseif ($c['status'] === 'pending'): ?>
                                    <span class="badge bg-warning-soft text-warning px-2.5 py-1 rounded-pill" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.25);">Pending Approval</span>
                                <?php else: ?>
                                    <span class="badge bg-warning-soft text-warning px-2.5 py-1 rounded-pill" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.25);">Suspended</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-1.5">
                                    <a href="customers.php?action=view&id=<?php echo $c['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-primary" style="border-color: rgba(168, 85, 247, 0.15) !important;" title="View Subscriber Profile"><i class="bi bi-eye text-primary"></i></a>
                                    <?php if ($c['status'] === 'pending'): ?>
                                        <a href="customers.php?action=approve&id=<?php echo $c['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-success" onclick="return confirm('Are you sure you want to approve customer account \'<?php echo e($c['name']); ?>\'?')" title="Approve Account"><i class="bi bi-check-circle text-success"></i></a>
                                    <?php endif; ?>
                                     <a href="customers.php?action=edit&id=<?php echo $c['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2" title="Modify"><i class="bi bi-pencil"></i></a>
                                     <button type="button" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-warning" style="border-color: rgba(255, 193, 7, 0.15) !important;" data-bs-toggle="modal" data-bs-target="#createBillModal-<?php echo $c['id']; ?>" title="Create Bill / Invoice"><i class="bi bi-receipt text-warning"></i></button>

                                     <!-- Create Bill Modal for Customer -->
                                     <div class="modal fade" id="createBillModal-<?php echo $c['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px);">
                                         <div class="modal-dialog modal-dialog-centered">
                                             <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                                                 <div class="modal-header border-bottom border-white border-opacity-5">
                                                     <h5 class="modal-title text-white"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Generate Customer Invoice</h5>
                                                     <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                 </div>
                                                 <form action="billing.php" method="POST">
                                                     <?php csrf_field(); ?>
                                                     <input type="hidden" name="create_invoice" value="1">
                                                     <input type="hidden" name="customer_id" value="<?php echo $c['id']; ?>">
                                                     
                                                     <div class="modal-body p-4 d-flex flex-column gap-3">
                                                         <div>
                                                             <span class="text-muted" style="font-size: 0.8rem;">Subscriber Name</span>
                                                             <input type="text" class="form-control text-white" value="<?php echo e($c['name']); ?>" disabled style="opacity: 0.7; background: rgba(0,0,0,0.2);">
                                                         </div>
                                                         
                                                         <div class="row g-2">
                                                             <div class="col-sm-6">
                                                                 <label class="form-label text-muted" style="font-size: 0.8rem;">Billing Start Date</label>
                                                                 <input type="date" name="billing_start_date" class="form-control text-white" value="<?php echo date('Y-m-d'); ?>" required>
                                                             </div>
                                                             <div class="col-sm-6">
                                                                 <label class="form-label text-muted" style="font-size: 0.8rem;">Billing End Date</label>
                                                                 <input type="date" name="billing_end_date" class="form-control text-white" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                                                             </div>
                                                         </div>
                                                         
                                                         <div class="row g-2">
                                                             <div class="col-sm-6">
                                                                 <label class="form-label text-muted" style="font-size: 0.8rem;">Package Name / Mbps Speed</label>
                                                                 <select name="package_name" id="package_name-<?php echo $c['id']; ?>" class="form-select text-white" required onchange="populatePackagePrice_<?php echo $c['id']; ?>(this)">
                                                                     <option value="">-- Select Package --</option>
                                                                     <?php foreach ($packages_opt as $p): ?>
                                                                         <option value="<?php echo e($p['name']); ?>" data-price="<?php echo $p['monthly_price']; ?>" <?php echo ($c['package_name'] === $p['name']) ? 'selected' : ''; ?>>
                                                                             <?php echo e($p['name']); ?> (Rs. <?php echo number_format($p['monthly_price'], 2); ?>)
                                                                         </option>
                                                                     <?php endforeach; ?>
                                                                 </select>
                                                             </div>
                                                             <div class="col-sm-6">
                                                                 <label class="form-label text-muted" style="font-size: 0.8rem;">Billed Amount (Rs.)</label>
                                                                 <input type="number" name="total_amount" id="total_amount-<?php echo $c['id']; ?>" class="form-control text-white" value="<?php echo $c['monthly_fee']; ?>" min="0.01" step="0.01" required>
                                                             </div>
                                                         </div>
                                                         
                                                         <div>
                                                             <label class="form-label text-muted" style="font-size: 0.8rem;">Due Date</label>
                                                             <input type="date" name="due_date" class="form-control text-white" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                                                         </div>
                                                     </div>
                                                     
                                                     <div class="modal-footer border-top border-white border-opacity-5">
                                                         <button type="submit" class="btn btn-primary-gradient px-4">Generate Invoice</button>
                                                         <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                                                     </div>
                                                 </form>
                                             </div>
                                         </div>
                                     </div>

                                     <script>
                                     function populatePackagePrice_<?php echo $c['id']; ?>(select) {
                                         const option = select.options[select.selectedIndex];
                                         if (option && option.value) {
                                             const price = option.getAttribute('data-price') || '';
                                             document.getElementById('total_amount-<?php echo $c['id']; ?>').value = price;
                                         }
                                     }
                                     </script>
                                      <button type="button" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-info" style="border-color: rgba(168, 85, 247, 0.15) !important;" onclick="triggerPromoteModal(<?php echo $c['id']; ?>, '<?php echo e(js_escape($c['name'])); ?>', <?php echo $c['assigned_package_id']; ?>, <?php echo $c['zone_id']; ?>, <?php echo $c['monthly_fee']; ?>)" title="Promote Plan / Zone"><i class="bi bi-arrow-up-circle text-info"></i></button>
                                      <button type="button" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-primary" style="border-color: rgba(13, 110, 253, 0.15) !important;" data-bs-toggle="modal" data-bs-target="#resetPasswordModal-<?php echo $c['id']; ?>" title="Reset Subscriber Password"><i class="bi bi-key text-primary"></i></button>
                                      <a href="customers.php?action=delete&id=<?php echo $c['id']; ?>" class="btn btn-dark-glass btn-sm p-1.5 px-2 text-danger border-danger border-opacity-10" onclick="return confirm('Are you sure you want to delete customer \'<?php echo e($c['name']); ?>\'?')" title="Remove"><i class="bi bi-trash"></i></a>

                                      <!-- Reset Customer Password Modal -->
                                      <div class="modal fade customer-reset-modal" id="resetPasswordModal-<?php echo $c['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px);">
                                          <div class="modal-dialog modal-dialog-centered">
                                              <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                                                  <div class="modal-header border-bottom border-white border-opacity-5">
                                                      <h5 class="modal-title text-white"><i class="bi bi-key me-2 text-primary"></i>Reset Subscriber Password</h5>
                                                      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                  </div>
                                                  <form action="customers.php" method="POST">
                                                      <?php csrf_field(); ?>
                                                      <input type="hidden" name="reset_customer_password" value="1">
                                                      <input type="hidden" name="customer_id" value="<?php echo $c['id']; ?>">
                                                      
                                                      <div class="modal-body p-4">
                                                          <p class="text-muted" style="font-size: 0.85rem;">Set a new portal access password for subscriber <strong><?php echo e($c['name']); ?></strong>.</p>
                                                          <div class="mb-3">
                                                              <label class="form-label text-muted" style="font-size: 0.8rem;">New Password</label>
                                                              <input type="password" name="new_password" class="form-control text-white" placeholder="Minimum 6 characters" required minlength="6">
                                                          </div>
                                                      </div>
                                                      <div class="modal-footer border-top border-white border-opacity-5">
                                                          <button type="submit" class="btn btn-primary px-4">Update Password</button>
                                                          <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                                                      </div>
                                                  </form>
                                              </div>
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
            <span class="text-muted" style="font-size: 0.85rem;">Showing <?php echo count($customers); ?> of <?php echo $total_records; ?> subscribers</span>
            <ul class="pagination">
                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="customers.php?action=list&page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&zone=<?php echo $filter_zone; ?>&package=<?php echo $filter_package; ?>&status=<?php echo $filter_status; ?>&expiry_filter=<?php echo $expiry_filter; ?>"><i class="bi bi-chevron-left"></i></a>
                </li>
                
                <?php for($p=1; $p<=$total_pages; $p++): ?>
                    <li class="page-item <?php echo ($page === $p) ? 'active' : ''; ?>">
                        <a class="page-link" href="customers.php?action=list&page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>&zone=<?php echo $filter_zone; ?>&package=<?php echo $filter_package; ?>&status=<?php echo $filter_status; ?>&expiry_filter=<?php echo $expiry_filter; ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
                
                <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="customers.php?action=list&page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&zone=<?php echo $filter_zone; ?>&package=<?php echo $filter_package; ?>&status=<?php echo $filter_status; ?>&expiry_filter=<?php echo $expiry_filter; ?>"><i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>
    
<?php elseif ($action === 'view' && $edit_id > 0): 
    // Fetch Subscriber Details Joined with Packages, Interface, and Zones
    try {
        $stmt = $pdo->prepare("SELECT c.*, p.name as package_name, p.monthly_price as package_base_price, p.speed_mbps, z.name as zone_name, ci.name as conn_interface_name, ci.speed_capacity as conn_interface_speed 
            FROM customers c 
            LEFT JOIN packages p ON c.assigned_package_id = p.id 
            LEFT JOIN zones z ON c.zone_id = z.id 
            LEFT JOIN connection_interfaces ci ON c.connection_interface_id = ci.id
            WHERE c.id = ? AND c.tenant_id = ? LIMIT 1");
        $stmt->execute([$edit_id, $tenant_id]);
        $customer = $stmt->fetch();
        
        if (!$customer) {
            set_session_alert("Subscriber not found or unauthorized access.", "error");
            header("Location: customers.php");
            exit;
        }
        
        // Fetch all invoices belonging to this subscriber
        $stmt_inv = $pdo->prepare("SELECT * FROM invoices WHERE customer_id = ? AND tenant_id = ? ORDER BY id DESC");
        $stmt_inv->execute([$edit_id, $tenant_id]);
        $invoices = $stmt_inv->fetchAll();
        
        // Calculate ledger stats
        $total_invoiced = 0.00;
        $total_paid = 0.00;
        $total_pending = 0.00;
        $paid_count = 0;
        $pending_count = 0;
        
        foreach ($invoices as $inv) {
            $total_invoiced += (double)$inv['total_amount'];
            $total_paid += (double)$inv['paid_amount'];
            $total_pending += (double)$inv['remaining_amount'];
            
            if ($inv['payment_status'] === 'paid') {
                $paid_count++;
            } else {
                $pending_count++;
            }
        }
        
        // Remaining Lease Days calculation
        $days_cfg = get_expiry_alert_config($customer['expiry_date']);
        $days_remaining = calculate_days_remaining($customer['expiry_date']);
        
    } catch (PDOException $e) {
        set_session_alert("Database query failure: " . $e->getMessage(), "error");
        header("Location: customers.php");
        exit;
    }
?>
    
    <!-- VIEW CUSTOMER DETAILS PANEL -->
    <div class="row align-items-center mb-4">
        <div class="col-sm-8 d-flex align-items-center gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center text-primary fs-3 fw-bold border" style="width: 56px; height: 56px; background: rgba(168, 85, 247, 0.1); border-color: rgba(168, 85, 247, 0.2) !important;">
                <?php echo get_platform_logo($customer['name']); ?>
            </div>
            <div>
                <h3 class="text-white mb-0 font-outfit"><?php echo e($customer['name']); ?></h3>
                <span class="text-muted" style="font-size: 0.9rem;">Subscriber ID: #<?php echo $customer['id']; ?> &bull; Joined: <?php echo format_date($customer['joining_date']); ?></span>
            </div>
        </div>
        <div class="col-sm-4 text-sm-end mt-3 mt-sm-0 d-flex justify-content-sm-end gap-2">
            <a href="customers.php?action=edit&id=<?php echo $customer['id']; ?>" class="btn btn-dark-glass px-3.5 py-2 text-primary" style="border-color: rgba(168, 85, 247, 0.15) !important; font-size: 0.88rem;"><i class="bi bi-pencil me-1.5 text-primary"></i>Modify Profile</a>
            <button type="button" class="btn btn-primary-gradient px-4 py-2" style="font-size: 0.88rem;" data-bs-toggle="modal" data-bs-target="#createBillModal-<?php echo $customer['id']; ?>"><i class="bi bi-receipt me-1.5"></i>Create Bill</button>

            <!-- Create Bill Modal for Customer -->
            <div class="modal fade" id="createBillModal-<?php echo $customer['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px);">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                        <div class="modal-header border-bottom border-white border-opacity-5">
                            <h5 class="modal-title text-white"><i class="bi bi-file-earmark-plus me-2 text-primary"></i>Generate Customer Invoice</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="billing.php" method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="create_invoice" value="1">
                            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                            
                            <div class="modal-body p-4 d-flex flex-column gap-3">
                                <div>
                                    <span class="text-muted" style="font-size: 0.8rem;">Subscriber Name</span>
                                    <input type="text" class="form-control text-white" value="<?php echo e($customer['name']); ?>" disabled style="opacity: 0.7; background: rgba(0,0,0,0.2);">
                                </div>
                                
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Billing Start Date</label>
                                        <input type="date" name="billing_start_date" class="form-control text-white" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Billing End Date</label>
                                        <input type="date" name="billing_end_date" class="form-control text-white" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="row g-2">
                                    <div class="col-sm-6">
                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Package Name / Mbps Speed</label>
                                        <select name="package_name" id="package_name-<?php echo $customer['id']; ?>" class="form-select text-white" required onchange="populatePackagePrice_<?php echo $customer['id']; ?>(this)">
                                            <option value="">-- Select Package --</option>
                                            <?php foreach ($packages_opt as $p): ?>
                                                <option value="<?php echo e($p['name']); ?>" data-price="<?php echo $p['monthly_price']; ?>" <?php echo ($customer['package_name'] === $p['name']) ? 'selected' : ''; ?>>
                                                    <?php echo e($p['name']); ?> (Rs. <?php echo number_format($p['monthly_price'], 2); ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label text-muted" style="font-size: 0.8rem;">Billed Amount (Rs.)</label>
                                        <input type="number" name="total_amount" id="total_amount-<?php echo $customer['id']; ?>" class="form-control text-white" value="<?php echo $customer['monthly_fee']; ?>" min="0.01" step="0.01" required>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="form-label text-muted" style="font-size: 0.8rem;">Due Date</label>
                                    <input type="date" name="due_date" class="form-control text-white" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required>
                                </div>
                            </div>
                            
                            <div class="modal-footer border-top border-white border-opacity-5">
                                <button type="submit" class="btn btn-primary-gradient px-4">Generate Invoice</button>
                                <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <script>
            function populatePackagePrice_<?php echo $customer['id']; ?>(select) {
                const option = select.options[select.selectedIndex];
                if (option && option.value) {
                    const price = option.getAttribute('data-price') || '';
                    document.getElementById('total_amount-<?php echo $customer['id']; ?>').value = price;
                }
            }
            </script>
            <button type="button" class="btn btn-dark-glass px-3.5 py-2 text-info" style="border-color: rgba(168, 85, 247, 0.15) !important; font-size: 0.88rem;" onclick="triggerPromoteModal(<?php echo $customer['id']; ?>, '<?php echo e(js_escape($customer['name'])); ?>', <?php echo $customer['assigned_package_id']; ?>, <?php echo $customer['zone_id']; ?>, <?php echo $customer['monthly_fee']; ?>)" title="Promote Plan / Zone"><i class="bi bi-arrow-up-circle me-1.5 text-info"></i>Promote</button>
            <button type="button" class="btn btn-dark-glass px-3.5 py-2 text-primary" style="border-color: rgba(13, 110, 253, 0.15) !important; font-size: 0.88rem;" data-bs-toggle="modal" data-bs-target="#resetPasswordModal-<?php echo $customer['id']; ?>" title="Reset Subscriber Password"><i class="bi bi-key me-1.5 text-primary"></i>Reset Password</button>

            <!-- Reset Customer Password Modal -->
            <div class="modal fade customer-reset-modal" id="resetPasswordModal-<?php echo $customer['id']; ?>" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px);">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
                        <div class="modal-header border-bottom border-white border-opacity-5">
                            <h5 class="modal-title text-white"><i class="bi bi-key me-2 text-primary"></i>Reset Subscriber Password</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <form action="customers.php" method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="reset_customer_password" value="1">
                            <input type="hidden" name="customer_id" value="<?php echo $customer['id']; ?>">
                            
                            <div class="modal-body p-4">
                                <p class="text-muted" style="font-size: 0.85rem;">Set a new portal access password for subscriber <strong><?php echo e($customer['name']); ?></strong>.</p>
                                <div class="mb-3">
                                    <label class="form-label text-muted" style="font-size: 0.8rem;">New Password</label>
                                    <input type="password" name="new_password" class="form-control text-white" placeholder="Minimum 6 characters" required minlength="6">
                                </div>
                            </div>
                            <div class="modal-footer border-top border-white border-opacity-5">
                                <button type="submit" class="btn btn-primary px-4">Update Password</button>
                                <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- KPI Summary Grid Cards -->
    <div class="row g-3 mb-4">
        <!-- Account Status Card -->
        <div class="col-xl-3 col-sm-6">
            <div class="p-3.5 glass-card d-flex align-items-center gap-3 h-100">
                <div class="rounded-lg p-2.5 d-flex align-items-center justify-content-center" style="background: rgba(16, 185, 129, 0.1); color: #10B981; width: 44px; height: 44px;">
                    <i class="bi bi-shield-check fs-5"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.75rem;">Account Status</span>
                    <?php if ($customer['status'] === 'active'): ?>
                        <span class="badge bg-success-soft text-success px-2 py-0.5 rounded" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.25); font-size: 0.82rem;">Active</span>
                    <?php elseif ($customer['status'] === 'expired'): ?>
                        <span class="badge bg-danger-soft text-danger px-2 py-0.5 rounded animate-pulse" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.25); font-size: 0.82rem;">Expired</span>
                    <?php elseif ($customer['status'] === 'pending'): ?>
                        <span class="badge bg-warning-soft text-warning px-2 py-0.5 rounded" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.25); font-size: 0.82rem;">Pending Approval</span>
                    <?php else: ?>
                        <span class="badge bg-warning-soft text-warning px-2 py-0.5 rounded" style="background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.25); font-size: 0.82rem;">Suspended</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Expiry / Remaining Days Card -->
        <div class="col-xl-3 col-sm-6">
            <div class="p-3.5 glass-card d-flex align-items-center gap-3 h-100">
                <div class="rounded-lg p-2.5 d-flex align-items-center justify-content-center" style="background: <?php echo $days_cfg['bg']; ?>20; color: <?php echo $days_cfg['bg']; ?>; width: 44px; height: 44px;">
                    <i class="bi bi-clock-history fs-5"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.75rem;">Time Lease Remaining</span>
                    <span class="text-white d-block fw-bold fs-5 font-outfit" style="color: <?php echo $days_cfg['bg']; ?> !important;">
                        <?php echo $days_cfg['label']; ?>
                    </span>
                    <small class="text-muted" style="font-size: 0.7rem;">Expires: <?php echo format_date($customer['expiry_date']); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Paid Invoices Card -->
        <div class="col-xl-3 col-sm-6">
            <div class="p-3.5 glass-card d-flex align-items-center gap-3 h-100">
                <div class="rounded-lg p-2.5 d-flex align-items-center justify-content-center" style="background: rgba(59, 130, 246, 0.1); color: #3B82F6; width: 44px; height: 44px;">
                    <i class="bi bi-wallet2 fs-5"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.75rem;">Total Paid Billings</span>
                    <span class="text-white d-block fw-bold fs-5 font-outfit"><?php echo format_currency($total_paid); ?></span>
                    <small class="text-muted" style="font-size: 0.7rem;"><?php echo $paid_count; ?> fully-paid invoices</small>
                </div>
            </div>
        </div>
        
        <!-- Pending Balance Card -->
        <div class="col-xl-3 col-sm-6">
            <div class="p-3.5 glass-card d-flex align-items-center gap-3 h-100">
                <div class="rounded-lg p-2.5 d-flex align-items-center justify-content-center" style="background: rgba(245, 158, 11, 0.1); color: #F59E0B; width: 44px; height: 44px;">
                    <i class="bi bi-exclamation-circle fs-5"></i>
                </div>
                <div>
                    <span class="text-muted d-block" style="font-size: 0.75rem;">Outstanding Balance</span>
                    <span class="text-warning d-block fw-bold fs-5 font-outfit"><?php echo format_currency($total_pending); ?></span>
                    <small class="text-muted" style="font-size: 0.7rem;"><?php echo $pending_count; ?> unpaid/partial invoices</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Detail Profiles Grid -->
    <div class="row g-4 mb-5">
        <!-- Left Column: Subscriber Profile & Connection Parameters -->
        <div class="col-lg-4 d-flex flex-column gap-4">
            <!-- Account Profile Info Card -->
            <div class="p-4 glass-card d-flex flex-column gap-3">
                <h5 class="text-white font-outfit mb-0 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                    <i class="bi bi-person text-primary me-2"></i>Account Profile
                </h5>
                
                <div class="d-flex flex-column gap-3 mt-2">
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">CNIC / National ID</span>
                        <span class="text-white fw-medium" style="font-size: 0.95rem;"><?php echo e($customer['cnic']); ?></span>
                    </div>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Phone Number</span>
                        <a href="tel:<?php echo $customer['phone']; ?>" class="text-white text-decoration-none fw-medium" style="font-size: 0.95rem;"><i class="bi bi-telephone text-muted me-1.5"></i><?php echo e($customer['phone']); ?></a>
                    </div>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Email Address</span>
                        <a href="mailto:<?php echo $customer['email']; ?>" class="text-white text-decoration-none fw-medium" style="font-size: 0.95rem;"><i class="bi bi-envelope text-muted me-1.5"></i><?php echo e($customer['email']); ?></a>
                    </div>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Zone Area Division</span>
                        <span class="text-white fw-medium" style="font-size: 0.95rem;"><?php echo e($customer['zone_name']); ?> &bull; <?php echo e($customer['area'] ?: 'No Area'); ?></span>
                    </div>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Permanent Installation Address</span>
                        <span class="text-white" style="font-size: 0.88rem; line-height: 1.45;"><?php echo e($customer['address']); ?></span>
                    </div>
                    <?php if (!empty($customer['notes'])): ?>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Internal Admin Notes</span>
                        <div class="p-2.5 rounded-lg border text-muted" style="background: rgba(255,255,255,0.01); border-color: rgba(255,255,255,0.05) !important; font-size: 0.82rem; line-height: 1.45;">
                            <?php echo nl2br(e($customer['notes'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Connection Specifications Card -->
            <div class="p-4 glass-card d-flex flex-column gap-3">
                <h5 class="text-white font-outfit mb-0 border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                    <i class="bi bi-hdd-network text-primary me-2"></i>Service Connection
                </h5>
                
                <div class="d-flex flex-column gap-3 mt-2">
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Internet Package / Speed</span>
                        <span class="text-white fw-medium d-block" style="font-size: 0.95rem;"><?php echo e($customer['package_name']); ?></span>
                        <small class="text-muted" style="font-size: 0.72rem;">Base Speed Capacity: <?php echo format_bandwidth($customer['speed_mbps'] ?? 0); ?></small>
                    </div>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Connection Interface</span>
                        <span class="text-white fw-medium d-block" style="font-size: 0.95rem;"><?php echo e($customer['conn_interface_name'] ?: 'Static Port'); ?></span>
                        <small class="text-muted" style="font-size: 0.72rem;">Type: <?php echo e($customer['connection_type'] ?: 'Fiber'); ?> &bull; <?php echo e($customer['conn_interface_speed'] ?: 'Standard'); ?></small>
                    </div>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Assigned Monthly Fee</span>
                        <span class="text-white fw-medium fs-5 font-outfit" style="font-size: 1.1rem;">
                            <?php echo format_currency($customer['monthly_fee']); ?>
                        </span>
                        <?php if ((double)$customer['monthly_fee'] !== (double)$customer['package_base_price']): ?>
                            <small class="text-warning d-block" style="font-size: 0.72rem;"><i class="bi bi-exclamation-triangle me-1"></i>Fee overridden (Base: <?php echo format_currency($customer['package_base_price']); ?>)</small>
                        <?php else: ?>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Standard retail price matches package</small>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="text-muted d-block" style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px;">Installation Setup Charges</span>
                        <span class="text-white fw-medium" style="font-size: 0.92rem;"><?php echo format_currency($customer['installation_fee']); ?></span>
                    </div>
                    
                    <div class="row g-2 border-top pt-2.5 mt-1" style="border-color: rgba(255,255,255,0.06) !important;">
                        <div class="col-6">
                            <span class="text-muted d-block" style="font-size: 0.7rem; text-transform: uppercase;">Active Date</span>
                            <span class="text-white fw-medium" style="font-size: 0.85rem;"><?php echo format_date($customer['activation_date']); ?></span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted d-block" style="font-size: 0.7rem; text-transform: uppercase;">Expiry Date</span>
                            <span class="text-white fw-medium" style="font-size: 0.85rem;"><?php echo format_date($customer['expiry_date']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Invoices Ledger Sheet -->
        <div class="col-lg-8">
            <div class="p-4 glass-card d-flex flex-column gap-3.5 h-100">
                <div class="d-flex align-items-center justify-content-between border-bottom pb-2" style="border-color: rgba(255,255,255,0.06) !important;">
                    <h5 class="text-white font-outfit mb-0">
                        <i class="bi bi-journal-text text-primary me-2"></i>Invoices History & Ledger
                    </h5>
                    <span class="badge bg-dark-glass text-muted" style="font-size: 0.8rem;"><?php echo count($invoices); ?> Invoices Logged</span>
                </div>
                
                <div class="table-responsive-glass">
                    <table class="table table-glass align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; width: 22%;">Invoice No / Plan</th>
                                <th style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; width: 25%;">Billing Cycle</th>
                                <th style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; text-align: right; width: 18%;">Amount / Paid</th>
                                <th style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; text-align: center; width: 15%;">Status</th>
                                <th style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; text-align: right; width: 20%;">Verification</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($invoices)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted" style="font-size: 0.9rem;">
                                        <i class="bi bi-journal-x fs-3 d-block mb-2 opacity-50"></i>
                                        No invoices have been logged for this customer yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($invoices as $inv): ?>
                                    <tr>
                                        <td>
                                            <span class="text-white fw-medium d-block" style="font-size: 0.85rem;"><?php echo e($inv['invoice_number']); ?></span>
                                            <small class="text-muted d-block" style="font-size: 0.72rem;"><?php echo e($inv['package_name']); ?></small>
                                            <?php if ($inv['proof_submitted'] == 1): ?>
                                                <button type="button" class="btn btn-dark-glass btn-sm py-0.5 px-1.5 text-info mt-1 d-inline-flex align-items-center gap-1 border-info border-opacity-10" style="font-size: 0.65rem;" data-bs-toggle="collapse" data-bs-target="#proof_<?php echo $inv['id']; ?>">
                                                    <i class="bi bi-shield-check"></i> Proof Submitted
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-white d-block" style="font-size: 0.78rem;">Period: <?php echo format_date($inv['billing_start_date']); ?> &bull; <?php echo format_date($inv['billing_end_date']); ?></small>
                                            <small class="text-muted" style="font-size: 0.7rem;">Due: <?php echo format_date($inv['due_date']); ?></small>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-white fw-bold d-block" style="font-size: 0.85rem;"><?php echo format_currency($inv['total_amount']); ?></span>
                                            <small class="text-success d-block" style="font-size: 0.72rem;">Paid: <?php echo format_currency($inv['paid_amount']); ?></small>
                                        </td>
                                        <td class="text-center">
                                            <?php echo get_invoice_status_badge($inv['payment_status']); ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($inv['payment_status'] === 'paid' || $inv['payment_status'] === 'partial'): ?>
                                                <span class="text-white d-block" style="font-size: 0.78rem;"><?php echo $inv['payment_date'] ? date('d M, Y', strtotime($inv['payment_date'])) : 'Auto-Settled'; ?></span>
                                                <small class="text-muted d-block" style="font-size: 0.7rem;">Verified By: <?php echo e($inv['verified_by_admin'] ?: 'System'); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted" style="font-size: 0.8rem;">Unverified</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    
                                    <?php if ($inv['proof_submitted'] == 1): ?>
                                        <tr class="border-0">
                                            <td colspan="5" class="p-0 border-0">
                                                <div class="collapse" id="proof_<?php echo $inv['id']; ?>">
                                                    <div class="p-3 rounded-3 my-2 text-start mx-3" style="font-size: 0.82rem; background: rgba(14, 165, 233, 0.06); border: 1px solid rgba(14, 165, 233, 0.15); color: #E0F2FE;">
                                                        <strong class="d-block mb-2 text-white" style="font-size: 0.85rem;"><i class="bi bi-info-circle text-info me-1.5"></i>Submitted Payment Proof Details</strong>
                                                        <div class="row g-2">
                                                            <div class="col-sm-6">
                                                                <span class="text-muted">Payment Channel:</span> <strong class="text-white"><?php echo e($inv['payment_method']); ?></strong><br>
                                                                <span class="text-muted">Transaction Ref:</span> <strong class="text-accent font-outfit text-warning"><?php echo e($inv['transaction_id']); ?></strong><br>
                                                                <span class="text-muted">Amount Submited:</span> <strong class="text-white"><?php echo format_currency($inv['total_amount']); ?></strong>
                                                            </div>
                                                            <div class="col-sm-6">
                                                                <span class="text-muted">Submission Date:</span> <strong class="text-white"><?php echo $inv['submission_date'] ? date('d M, Y H:i', strtotime($inv['submission_date'])) : 'N/A'; ?></strong><br>
                                                                <span class="text-muted">Client Notes:</span> <em class="text-white d-inline-block mt-0.5"><?php echo e($inv['submission_notes'] ?: 'None'); ?></em>
                                                            </div>
                                                        </div>
                                                        
                                                        <?php if (!empty($inv['proof_image'])): ?>
                                                            <div class="mt-2.5 border-top border-white border-opacity-5 pt-2">
                                                                <span class="text-muted" style="font-size: 0.72rem; display: block; margin-bottom: 5px;">Uploaded Payment Receipt:</span>
                                                                <a href="../uploads/proofs/<?php echo e($inv['proof_image']); ?>" target="_blank" class="badge bg-primary bg-opacity-10 text-primary border-0 py-2 px-2.5 text-decoration-none" style="font-size: 0.76rem; border: 1px solid rgba(168, 85, 247, 0.15) !important;">
                                                                    <i class="bi bi-image me-1"></i>View Image Proof
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                    
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
<?php else: ?>
    
    <!-- ADD / EDIT DYNAMIC FORM PANEL -->
    <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px;">
        <h5 class="text-white mb-4 border-bottom pb-3" style="border-color: rgba(255,255,255,0.06) !important;">
            <i class="bi bi-person-gear text-primary me-2"></i><?php echo ($action === 'edit') ? 'Modify Subscriber: ' . e($cust_name) : 'Register New Subscriber'; ?>
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
        
        <form action="customers.php<?php echo ($action === 'edit') ? '?action=edit&id=' . $edit_id : '?action=add'; ?>" method="POST">
            <?php csrf_field(); ?>
            
            <div class="row g-4">
                <!-- Column 1: Personal credentials info -->
                <div class="col-md-6 d-flex flex-column gap-3">
                    <h6 class="text-primary font-outfit mb-0">1. Personal & Contact Credentials</h6>
                    
                    <div>
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Full Name</label>
                        <input type="text" name="name" class="form-control" placeholder="Muhammad Hashim" value="<?php echo e($cust_name); ?>" required autocomplete="off">
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">CNIC / National ID</label>
                            <input type="text" name="cnic" class="form-control" placeholder="42101-XXXXXXX-X" value="<?php echo e($cust_cnic); ?>" required autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Phone Number</label>
                            <input type="tel" name="phone" class="form-control" placeholder="03001234567" value="<?php echo e($cust_phone); ?>" required autocomplete="off">
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="client@email.com" value="<?php echo e($cust_email); ?>" required autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Portal Password</label>
                            <input type="password" name="password" class="form-control" placeholder="<?php echo ($action === 'edit') ? 'Leave blank to keep current' : '••••••••'; ?>" <?php echo ($action === 'add') ? 'required' : ''; ?>>
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Zone Division</label>
                            <select name="zone_id" class="form-select" required>
                                <option value="">Select Zone</option>
                                <?php foreach ($zones_opt as $z): ?>
                                    <option value="<?php echo $z['id']; ?>" <?php echo ($cust_zone_id == $z['id']) ? 'selected' : ''; ?>><?php echo e($z['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Area / Street Sector</label>
                            <input type="text" name="area" class="form-control" placeholder="Sector 4-B" value="<?php echo e($cust_area); ?>">
                        </div>
                    </div>
                    
                    <div>
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Permanent Address</label>
                        <textarea name="address" class="form-control" rows="2.5" placeholder="House #, Road division..." required><?php echo e($cust_address); ?></textarea>
                    </div>
                </div>
                
                <!-- Column 2: Package Subscription settings -->
                <div class="col-md-6 d-flex flex-column gap-3">
                    <h6 class="text-primary font-outfit mb-0">2. Connection & Billing Operations</h6>
                    
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Assigned Package</label>
                            <select name="assigned_package_id" id="assigned_package_id" class="form-select" required onchange="updateDefaultFee()">
                                <option value="">Select Package</option>
                                <?php foreach ($packages_opt as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['monthly_price']; ?>" <?php echo ($cust_package_id == $p['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($p['name']); ?> (<?php echo format_currency($p['monthly_price']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Connection Interface</label>
                            <select name="connection_interface_id" class="form-select" required>
                                <option value="">-- Select Interface --</option>
                                <?php foreach ($conn_interfaces_opt as $ci): ?>
                                    <option value="<?php echo $ci['id']; ?>" <?php echo ($cust_connection_interface_id == $ci['id']) ? 'selected' : ''; ?>>
                                        <?php echo e($ci['name']); ?> (<?php echo e($ci['type_category']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Custom Monthly Fee (Rs.)</label>
                            <input type="number" name="monthly_fee" id="monthly_fee" class="form-control" placeholder="2500" value="<?php echo e($cust_monthly_fee); ?>" required min="0" step="0.01">
                            <small class="text-muted" style="font-size: 0.7rem;">Can override base package price</small>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Installation Fee Charged (Rs.)</label>
                            <input type="number" name="installation_fee" class="form-control" placeholder="3000" value="<?php echo e($cust_install_fee); ?>" min="0" step="0.01">
                        </div>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-sm-12">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Joining Date</label>
                            <input type="date" name="joining_date" class="form-control" value="<?php echo e($cust_joining_date); ?>" required>
                        </div>
                    </div>
                    
                    <?php if ($action === 'edit'): ?>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Activation Date</label>
                            <input type="date" name="activation_date" class="form-control" value="<?php echo e($cust_active_date); ?>" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control" value="<?php echo e($cust_expiry_date); ?>" required>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Subscription Status</label>
                            <select name="status" class="form-select">
                                <option value="active" <?php echo ($cust_status === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="expired" <?php echo ($cust_status === 'expired') ? 'selected' : ''; ?>>Expired</option>
                                <option value="suspended" <?php echo ($cust_status === 'suspended') ? 'selected' : ''; ?>>Suspended</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Internal Admin Notes (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Router model details, optical power level (-22dB)..."><?php echo e($cust_notes); ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="mt-4 border-top pt-3 d-flex gap-2 justify-content-end" style="border-color: rgba(255,255,255,0.06) !important;">
                <button type="submit" class="btn btn-primary-gradient px-5 py-2.5" style="font-family: 'Outfit'; font-size: 0.95rem;">
                    <?php echo ($action === 'edit') ? 'Save Settings Changes' : 'Register Subscriber'; ?>
                </button>
                <a href="customers.php" class="btn btn-dark-glass px-4 py-2.5" style="font-size: 0.95rem;">Cancel</a>
            </div>
        </form>
    </div>
    
    <!-- Dynamic helper script to populate default package fee -->
    <script>
    function updateDefaultFee() {
        const select = document.getElementById('assigned_package_id');
        const feeInput = document.getElementById('monthly_fee');
        
        if (select && feeInput) {
            const selectedOption = select.options[select.selectedIndex];
            const basePrice = selectedOption.getAttribute('data-price');
            if (basePrice) {
                feeInput.value = parseFloat(basePrice).toFixed(2);
            }
        }
    }
    </script>
    
<?php endif; ?>

<!-- Promote/Transfer Plan & Zone Modal -->
<div class="modal fade" id="promoteModal" tabindex="-1" aria-hidden="true" style="backdrop-filter: blur(8px); text-align: left;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-start border" style="background: var(--bg-surface); border-color: var(--border-color) !important;">
            <div class="modal-header border-bottom border-white border-opacity-5">
                <h5 class="modal-title text-white"><i class="bi bi-arrow-up-circle text-info me-2"></i>Promote / Transfer Subscriber</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="customers.php" method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="promote_subscriber" value="1">
                <input type="hidden" name="promote_customer_id" id="promote_customer_id" value="">
                
                <div class="modal-body p-4 d-flex flex-column gap-3">
                    <div>
                        <span class="text-muted" style="font-size: 0.8rem; display: block; margin-bottom: 2px;">Subscriber Name</span>
                        <strong class="text-white d-block fs-5 font-outfit" id="promote_customer_name_display">Loading...</strong>
                    </div>
                    
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Target Internet Package</label>
                            <select name="promote_package_id" id="promote_package_id" class="form-select text-white" style="font-size: 0.85rem;" required onchange="updatePromoteDefaultFee(this)">
                                <option value="" disabled>Select target package...</option>
                                <?php foreach ($packages_opt as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" data-price="<?php echo $p['monthly_price']; ?>">
                                        <?php echo e($p['name']); ?> (Rs. <?php echo number_format($p['monthly_price'], 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label text-muted" style="font-size: 0.8rem;">Target Zone Division</label>
                            <select name="promote_zone_id" id="promote_zone_id" class="form-select text-white" style="font-size: 0.85rem;" required>
                                <option value="" disabled>Select target zone...</option>
                                <?php foreach ($zones_opt as $z): ?>
                                    <option value="<?php echo $z['id']; ?>">
                                        <?php echo e($z['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="form-label text-muted" style="font-size: 0.8rem;">Dynamically Overridden Plan Fee (Rs.)</label>
                        <input type="number" step="0.01" min="0" name="promote_monthly_fee" id="promote_monthly_fee" class="form-control" placeholder="e.g. 2500" required>
                        <small class="text-muted" style="font-size: 0.7rem;">Defaults to the selected package's monthly price but can be overridden</small>
                    </div>
                </div>
                
                <div class="modal-footer border-top border-white border-opacity-5">
                    <button type="submit" class="btn btn-primary-gradient px-4 py-2">Save Upgrade Promotion</button>
                    <button type="button" class="btn btn-dark-glass" data-bs-dismiss="modal">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function triggerPromoteModal(id, name, packageId, zoneId, currentFee) {
    document.getElementById('promote_customer_id').value = id;
    document.getElementById('promote_customer_name_display').textContent = name;
    document.getElementById('promote_package_id').value = packageId;
    document.getElementById('promote_zone_id').value = zoneId;
    document.getElementById('promote_monthly_fee').value = currentFee;
    
    const promoteModal = new bootstrap.Modal(document.getElementById('promoteModal'));
    promoteModal.show();
}

function updatePromoteDefaultFee(select) {
    const option = select.options[select.selectedIndex];
    if (option) {
        const basePrice = option.getAttribute('data-price');
        if (basePrice) {
            document.getElementById('promote_monthly_fee').value = parseFloat(basePrice).toFixed(2);
        }
    }
}
</script>

<?php
function js_escape($str) {
    if ($str === null) return '';
    return str_replace(
        ["\\", "'", "\n", "\r", '"'],
        ["\\\\", "\\'", "\\n", "\\r", '\\"'],
        $str
    );
}
require_once __DIR__ . '/layouts/footer.php';
?>
