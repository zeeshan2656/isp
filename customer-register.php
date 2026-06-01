<?php
/**
 * Dynamic Customer Self-Registration Gateway (Referred Subscribers Only)
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$errors = [];
$success = "";

// 1. Resolve active referral code
$ref_code = $_SESSION['netpulse_ref'] ?? $_COOKIE['netpulse_ref'] ?? '';
if (isset($_GET['ref']) && !empty($_GET['ref'])) {
    $ref_code = preg_replace('/[^a-zA-Z0-9-]/', '', $_GET['ref']);
}

$referrer = null;
$tenant_settings = null;
$zones = [];
$packages = [];

if (empty($ref_code)) {
    $errors[] = "A valid friend's referral link or code is required to register on this network.";
} else {
    try {
        // Find referrer Customer and their parent ISP Tenant details
        $stmt = $pdo->prepare("SELECT c.id as referrer_id, c.name as referrer_name, c.tenant_id, t.company_name FROM customers c JOIN tenants t ON c.tenant_id = t.id WHERE c.referral_code = ? LIMIT 1");
        $stmt->execute([$ref_code]);
        $referrer = $stmt->fetch();
        
        if (!$referrer) {
            $errors[] = "The referral code '{$ref_code}' is invalid or expired.";
        } else {
            // Verify if parent ISP Tenant has enabled the Customer Referral Program
            $stmt = $pdo->prepare("SELECT * FROM tenant_referral_settings WHERE tenant_id = ? LIMIT 1");
            $stmt->execute([$referrer['tenant_id']]);
            $tenant_settings = $stmt->fetch();
            
            if (!$tenant_settings || (int)$tenant_settings['enabled'] !== 1) {
                $errors[] = "The Customer Referral Program for {$referrer['company_name']} is currently inactive.";
                $referrer = null; // Lock registration form
            } else {
                // Load Zones of this specific ISP Tenant
                $stmt = $pdo->prepare("SELECT id, name FROM zones WHERE tenant_id = ? ORDER BY name ASC");
                $stmt->execute([$referrer['tenant_id']]);
                $zones = $stmt->fetchAll();
                
                // Load active Bandwidth Packages of this specific ISP Tenant
                $stmt = $pdo->prepare("SELECT id, name, monthly_price, speed_mbps FROM packages WHERE tenant_id = ? AND status = 'active' ORDER BY monthly_price ASC");
                $stmt->execute([$referrer['tenant_id']]);
                $packages = $stmt->fetchAll();
            }
        }
    } catch (PDOException $e) {
        $errors[] = "Referrer lookup database failed: " . $e->getMessage();
    }
}

// Handle Customer signup post
$name = "";
$email = "";
$phone = "";
$zone_id = 0;
$package_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $referrer) {
    verify_csrf_token();
    
    $name = clean_input($_POST['name'] ?? '');
    $email = clean_input($_POST['email'] ?? '');
    $phone = clean_input($_POST['phone'] ?? '');
    $zone_id = (int)($_POST['zone_id'] ?? 0);
    $package_id = (int)($_POST['package_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    
    if (empty($name)) $errors[] = "Full Name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "A valid email address is required.";
    if (empty($phone)) $errors[] = "Phone number is required.";
    if ($zone_id <= 0) $errors[] = "Please select your coverage area/zone.";
    if ($package_id <= 0) $errors[] = "Please select an internet package.";
    if (strlen($password) < 6) $errors[] = "Password must be at least 6 characters long.";
    if ($password !== $password_confirm) $errors[] = "Passwords do not match.";
    
    if (empty($errors)) {
        try {
            // Check email uniqueness under the SAME tenant
            $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ? AND tenant_id = ?");
            $stmt->execute([$email, $referrer['tenant_id']]);
            if ($stmt->fetch()) {
                $errors[] = "This email is already registered under {$referrer['company_name']}.";
            } else {
                $pdo->beginTransaction();
                
                $hash = password_hash($password, PASSWORD_BCRYPT);
                // Unique Customer referral code
                $cus_ref_code = 'CUS-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                
                // Fetch package details first (needed for monthly_fee in customer record)
                $stmt = $pdo->prepare("SELECT name, monthly_price FROM packages WHERE id = ? AND tenant_id = ? LIMIT 1");
                $stmt->execute([$package_id, $referrer['tenant_id']]);
                $pkg = $stmt->fetch();
                $pkg_name = $pkg ? $pkg['name'] : 'Internet Package';
                $price = $pkg ? (double)$pkg['monthly_price'] : 0.00;
                
                // Set activation and expiry dates
                $activation_date = date('Y-m-d');
                $expiry_date = date('Y-m-d', strtotime('+30 days'));
                
                // Find default connection interface for this tenant
                $stmt_conn = $pdo->prepare("SELECT id, type_category FROM connection_interfaces WHERE tenant_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
                $stmt_conn->execute([$referrer['tenant_id']]);
                $conn_iface = $stmt_conn->fetch();
                $conn_interface_id = $conn_iface ? (int)$conn_iface['id'] : null;
                $conn_category = $conn_iface ? $conn_iface['type_category'] : 'Fiber';
                
                // 1. Insert new Customer (column names match actual schema)
                $ins = $pdo->prepare("INSERT INTO customers (tenant_id, name, cnic, email, phone, password_hash, address, zone_id, connection_type, connection_interface_id, assigned_package_id, monthly_fee, activation_date, expiry_date, status, referred_by_id, referral_code, joining_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, CURDATE())");
                $ins->execute([$referrer['tenant_id'], $name, 'N/A', $email, $phone, $hash, '', $zone_id, $conn_category, $conn_interface_id, $package_id, $price, $activation_date, $expiry_date, $referrer['referrer_id'], $cus_ref_code]);
                $customer_id = $pdo->lastInsertId();
                
                // 2. Automatically generate their First Invoice
                $inv_num = 'INV-' . date('Ymd') . '-' . sprintf('%03d', $referrer['tenant_id']) . '-' . sprintf('%04d', $customer_id) . '-' . rand(100, 999);
                $due_date = date('Y-m-d', strtotime('+3 days'));
                
                $stmt = $pdo->prepare("INSERT INTO invoices (tenant_id, customer_id, invoice_number, package_name, total_amount, remaining_amount, due_date, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
                $stmt->execute([$referrer['tenant_id'], $customer_id, $inv_num, $pkg_name, $price, $price, $due_date]);
                
                // Log Audit
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $log = $pdo->prepare("INSERT INTO audit_logs (tenant_id, user_type, user_id, action, ip_address) VALUES (?, 'customer', ?, 'Subscriber self-registered via referral link', ?)");
                $log->execute([$referrer['tenant_id'], $customer_id, $ip]);
                
                $pdo->commit();
                
                // Clean up cookies
                setcookie('netpulse_ref', '', time() - 3600, "/");
                unset($_SESSION['netpulse_ref']);
                
                set_session_alert("Broadband subscription created successfully! Please log in to complete your first billing payment.", "success");
                header("Location: customer-login.php");
                exit;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "Subscriber signup database failed: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<section class="py-5" style="min-height: 80vh; background: radial-gradient(circle at bottom left, rgba(99, 102, 241, 0.05) 0%, rgba(8, 11, 17, 0) 60%);">
    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                
                <div class="text-center mb-4">
                    <h2 class="display-6 fw-bold text-white mb-2">Subscriber Self-Registration</h2>
                    <p class="text-muted">Register and activate your new high-speed broadband connection in minutes.</p>
                </div>
                
                <div class="p-4 p-md-5 glass-panel" style="border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
                    
                    <?php if (!empty($errors) && !$referrer): ?>
                        <div class="alert alert-danger border-0 rounded-lg p-3 text-center" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.95rem;">
                            <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
                            <ul class="mb-0 list-unstyled">
                                <?php foreach ($errors as $error): ?>
                                    <li><strong>Error:</strong> <?php echo e($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php else: ?>
                        
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger border-0 rounded-lg p-3 mb-4" style="background: rgba(239, 68, 68, 0.1); color: #F87171; font-size: 0.9rem;">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo e($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <div class="p-3 bg-primary bg-opacity-10 text-primary border border-primary border-opacity-20 rounded-3 mb-4" style="font-size: 0.85rem;">
                            <i class="bi bi-person-check-fill me-1.5 fs-5 align-middle"></i>
                            Referred by <strong><?php echo e($referrer['referrer_name']); ?></strong> to register on <strong><?php echo e($referrer['company_name']); ?></strong> network.
                        </div>
                        
                        <form action="customer-register.php" method="POST" class="d-flex flex-column gap-3">
                            <?php csrf_field(); ?>
                            
                            <div>
                                <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Subscriber Full Name</label>
                                <input type="text" name="name" class="form-control" placeholder="John Doe" value="<?php echo e($name); ?>" required>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Email Address</label>
                                    <input type="email" name="email" class="form-control" placeholder="john@email.com" value="<?php echo e($email); ?>" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Phone Number</label>
                                    <input type="text" name="phone" class="form-control" placeholder="03001234567" value="<?php echo e($phone); ?>" required>
                                </div>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Coverage Zone / Area</label>
                                    <select name="zone_id" class="form-select" required>
                                        <option value="">-- Select Area --</option>
                                        <?php foreach ($zones as $z): ?>
                                            <option value="<?php echo $z['id']; ?>" <?php echo ($zone_id === (int)$z['id']) ? 'selected' : ''; ?>><?php echo e($z['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Internet Speed & Package</label>
                                    <select name="package_id" class="form-select" required>
                                        <option value="">-- Select speed --</option>
                                        <?php foreach ($packages as $pkg): ?>
                                            <option value="<?php echo $pkg['id']; ?>" <?php echo ($package_id === (int)$pkg['id']) ? 'selected' : ''; ?>>
                                                <?php echo e($pkg['name']); ?> (<?php echo e($pkg['speed_mbps']); ?> Mbps - Rs. <?php echo number_format($pkg['monthly_price'], 0); ?>/mo)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row g-2">
                                <div class="col-sm-6">
                                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Choose Account Password</label>
                                    <input type="password" name="password" class="form-control" placeholder="••••••••" required>
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label text-muted" style="font-size: 0.8rem; font-weight: 500;">Confirm Password</label>
                                    <input type="password" name="password_confirm" class="form-control" placeholder="••••••••" required>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary-gradient py-2.5 mt-2" style="background: linear-gradient(135deg, #A855F7, #6366F1); font-family: 'Outfit'; font-size: 1rem;">Complete Connection Signup</button>
                        </form>
                    <?php endif; ?>
                    
                    <div class="text-center mt-4 border-top pt-3" style="border-color: rgba(255,255,255,0.06) !important;">
                        <span class="text-muted" style="font-size: 0.85rem;">Already a broadband member?</span>
                        <a href="customer-login.php" class="text-primary ms-1 text-decoration-none" style="font-size: 0.85rem; font-weight: 600;">Log In to Portal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
