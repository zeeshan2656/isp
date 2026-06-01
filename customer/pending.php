<?php
/**
 * NetPulse Customer Self-Service Portal - Registration Pending Lock Screen
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// Enforce customer login guard
require_customer_login();

$customer_id = $_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'];
$customer_tenant_id = $_SESSION['customer_tenant_id'];

// Check dynamic status
$lock_state = check_customer_lock_state($pdo, $customer_id);

// If active, redirect straight to their overview dashboard!
if ($lock_state === 'active' || $lock_state === 'awaiting_payment' || $lock_state === 'expired') {
    header("Location: index.php");
    exit;
}

// Fetch ISP name for display
$isp_name = 'Internet Service Provider';
try {
    $stmt = $pdo->prepare("SELECT company_name FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$customer_tenant_id]);
    $isp_name = $stmt->fetchColumn() ?: 'Internet Service Provider';
} catch (PDOException $e) {
    error_log("ISP lookup failed on customer pending: " . $e->getMessage());
}

require_once __DIR__ . '/../includes/header.php';
?>

<section class="py-5" style="min-height: 85vh; background: radial-gradient(circle at top left, rgba(168, 85, 247, 0.04) 0%, rgba(8, 11, 17, 0) 70%); display: flex; flex-direction: column; justify-content: center;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                
                <div class="text-center mb-4">
                    <div class="d-inline-flex p-3 bg-warning bg-opacity-10 rounded-4 text-warning border border-warning border-opacity-10 mb-3 animate-pulse">
                        <i class="bi bi-clock-history fs-2"></i>
                    </div>
                    <h3 class="fw-bold text-white font-outfit mb-1"><?php echo e($customer_name); ?></h3>
                    <p class="text-muted" style="font-size: 0.88rem;">Broadband Subscriber Portal Activation Gate</p>
                </div>
                
                <div class="p-4 p-md-5 glass-panel text-center" style="border-radius: 16px; border-color: rgba(168, 85, 247, 0.15) !important;">
                    <h5 class="text-warning fw-bold mb-3 font-outfit"><i class="bi bi-shield-exclamation me-1.5"></i>Account Pending ISP Activation</h5>
                    
                    <p class="text-muted" style="font-size: 0.95rem; line-height: 1.7; text-align: left;">
                        Your account is currently pending approval from your Internet Service Provider. Please wait for approval before accessing services.
                        <br><br>
                        Once <strong><?php echo e($isp_name); ?></strong> operators verify your physical line connection and approve your account, your self-service portal will be automatically unlocked.
                    </p>
                    
                    <div class="alert alert-warning border-0 rounded-lg p-3 mt-4 text-start" style="background: rgba(245, 158, 11, 0.08); color: #FBBF24; font-size: 0.85rem;">
                        <i class="bi bi-info-circle me-1.5"></i>ISP Operator: <strong><?php echo e($isp_name); ?></strong> Operations Desk.
                    </div>
                    
                    <div class="mt-4 border-top pt-3 text-center" style="border-color: rgba(255,255,255,0.06) !important;">
                        <a href="../logout.php" class="btn btn-dark-glass py-2 px-4" style="font-size: 0.9rem;"><i class="bi bi-box-arrow-left me-1.5"></i>Exit Portal Session</a>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
