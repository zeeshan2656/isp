<?php
/**
 * Super Admin Secure Authentication Portal
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// If already logged in, redirect straight to Analytics index
if (is_super_admin_logged_in()) {
    header("Location: index.php");
    exit;
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    
    $email = clean_input($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email)) $errors[] = "Please enter your administrative email.";
    if (empty($password)) $errors[] = "Please enter your access password.";
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM super_admins WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $admin = $stmt->fetch();
            
            if ($admin && password_verify($password, $admin['password_hash'])) {
                // Success! Set session parameters
                session_regenerate_id(true);
                $_SESSION['super_admin_id'] = $admin['id'];
                $_SESSION['super_admin_name'] = $admin['name'];
                $_SESSION['super_admin_email'] = $admin['email'];
                $_SESSION['role'] = 'super_admin';
                $_SESSION['initiated_at'] = time();
                $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                
                log_audit_activity($pdo, 1, 'tenant', 1, "Super Admin logged in from Console.");
                
                header("Location: index.php");
                exit;
            } else {
                $errors[] = "Invalid Super Admin credentials.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database operation error: " . $e->getMessage();
        }
    }
}

$platform_name = get_platform_name();
$platform_logo = get_platform_logo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($platform_name); ?> - Super Admin Sign In</title>
    <meta name="description" content="Secure administrative login for <?php echo e($platform_name); ?> SaaS Platform Management Console.">
    
    <!-- Google Fonts Outfit & Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <!-- Premium Stylesheet -->
    <link rel="stylesheet" href="../assets/css/style.css">
    
    <style>
        :root {
            --primary: #A855F7;
            --primary-rgb: 168, 85, 247;
            --primary-hover: #9333EA;
            --primary-glow: rgba(168, 85, 247, 0.25);
            --accent: #E9D5FF;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        
        /* Animated mesh gradient background */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(ellipse at 20% 20%, rgba(168, 85, 247, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(99, 102, 241, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(6, 182, 212, 0.04) 0%, transparent 60%);
            animation: meshShift 20s ease-in-out infinite alternate;
            z-index: 0;
            pointer-events: none;
        }
        
        @keyframes meshShift {
            0% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(-5%, 3%) scale(1.02); }
            66% { transform: translate(3%, -5%) scale(0.98); }
            100% { transform: translate(-2%, 2%) scale(1.01); }
        }
        
        /* Floating orb decorations */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.4;
            pointer-events: none;
            z-index: 0;
        }
        .orb-1 {
            width: 350px;
            height: 350px;
            background: rgba(168, 85, 247, 0.12);
            top: -100px;
            right: -80px;
            animation: orbFloat1 15s ease-in-out infinite;
        }
        .orb-2 {
            width: 280px;
            height: 280px;
            background: rgba(99, 102, 241, 0.1);
            bottom: -60px;
            left: -60px;
            animation: orbFloat2 18s ease-in-out infinite;
        }
        .orb-3 {
            width: 200px;
            height: 200px;
            background: rgba(6, 182, 212, 0.08);
            top: 40%;
            right: 15%;
            animation: orbFloat3 12s ease-in-out infinite;
        }
        
        @keyframes orbFloat1 {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(-30px, 40px); }
        }
        @keyframes orbFloat2 {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(40px, -30px); }
        }
        @keyframes orbFloat3 {
            0%, 100% { transform: translate(0, 0); }
            50% { transform: translate(-20px, -25px); }
        }
        
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 440px;
            padding: 0 1rem;
        }
        
        .login-card {
            background: rgba(14, 19, 31, 0.75);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(168, 85, 247, 0.12);
            border-radius: 20px;
            padding: 2.5rem;
            box-shadow: 
                0 25px 60px rgba(0, 0, 0, 0.4),
                0 0 80px rgba(168, 85, 247, 0.05),
                inset 0 1px 0 rgba(255, 255, 255, 0.05);
            transition: border-color 0.4s ease, box-shadow 0.4s ease;
        }
        
        .login-card:hover {
            border-color: rgba(168, 85, 247, 0.2);
            box-shadow: 
                0 30px 70px rgba(0, 0, 0, 0.45),
                0 0 100px rgba(168, 85, 247, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.06);
        }
        
        /* Brand logo icon */
        .brand-icon {
            width: 64px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(168, 85, 247, 0.15), rgba(99, 102, 241, 0.1));
            border: 1px solid rgba(168, 85, 247, 0.2);
            border-radius: 18px;
            margin: 0 auto 1.25rem;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.4rem;
            color: #A855F7;
            letter-spacing: -0.02em;
            box-shadow: 0 8px 30px rgba(168, 85, 247, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .brand-icon:hover {
            transform: scale(1.05);
            box-shadow: 0 12px 40px rgba(168, 85, 247, 0.15);
        }
        
        /* Input group styling */
        .login-input-group {
            position: relative;
            margin-bottom: 1.25rem;
        }
        
        .login-input-group label {
            display: block;
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--text-dim);
            margin-bottom: 0.4rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .login-input-wrap {
            display: flex;
            align-items: center;
            background: rgba(8, 11, 17, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .login-input-wrap:focus-within {
            border-color: rgba(168, 85, 247, 0.4);
            box-shadow: 0 0 0 4px rgba(168, 85, 247, 0.1);
            background: rgba(14, 19, 31, 0.6);
        }
        
        .login-input-wrap .input-icon {
            padding: 0 0.85rem;
            color: var(--text-dim);
            font-size: 1.05rem;
            transition: color 0.3s ease;
        }
        
        .login-input-wrap:focus-within .input-icon {
            color: #A855F7;
        }
        
        .login-input-wrap input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            color: var(--text-primary);
            padding: 0.75rem 0.85rem 0.75rem 0;
            font-size: 0.92rem;
            font-family: 'Inter', sans-serif;
        }
        
        .login-input-wrap input::placeholder {
            color: rgba(148, 163, 184, 0.5);
        }
        
        /* Password toggle button */
        .toggle-password {
            background: transparent;
            border: none;
            color: var(--text-dim);
            padding: 0 0.85rem;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        
        .toggle-password:hover {
            color: #A855F7;
        }
        
        /* Submit button */
        .btn-admin-login {
            width: 100%;
            padding: 0.8rem;
            background: linear-gradient(135deg, #A855F7, #6366F1);
            color: #FFFFFF;
            border: none;
            border-radius: 12px;
            font-family: 'Outfit', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 6px 20px rgba(168, 85, 247, 0.25);
            position: relative;
            overflow: hidden;
        }
        
        .btn-admin-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 200%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.6s ease;
        }
        
        .btn-admin-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(168, 85, 247, 0.35), 0 0 15px rgba(168, 85, 247, 0.15);
            filter: brightness(1.05);
        }
        
        .btn-admin-login:hover::before {
            left: 100%;
        }
        
        .btn-admin-login:active {
            transform: translateY(0);
        }
        
        /* Error alert */
        .login-error {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: 12px;
            padding: 0.85rem 1rem;
            margin-bottom: 1.25rem;
            color: #F87171;
            font-size: 0.85rem;
        }
        
        .login-error ul {
            margin: 0;
            padding-left: 1.25rem;
        }
        
        .login-error li {
            margin-bottom: 0.15rem;
        }
        
        .login-error li:last-child {
            margin-bottom: 0;
        }
        
        /* Footer links */
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            position: relative;
            z-index: 1;
        }
        
        .login-footer a {
            color: var(--text-dim);
            text-decoration: none;
            font-size: 0.82rem;
            transition: color 0.25s ease;
        }
        
        .login-footer a:hover {
            color: #A855F7;
        }
        
        /* Grid lines background decoration */
        .grid-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(168, 85, 247, 0.015) 1px, transparent 1px),
                linear-gradient(90deg, rgba(168, 85, 247, 0.015) 1px, transparent 1px);
            background-size: 60px 60px;
            z-index: 0;
            pointer-events: none;
        }
        
        /* Security badge */
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.7rem;
            color: rgba(148, 163, 184, 0.6);
            margin-top: 1.25rem;
            letter-spacing: 0.03em;
        }
        
        .security-badge i {
            font-size: 0.8rem;
            color: rgba(16, 185, 129, 0.6);
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 1.75rem 1.5rem;
                border-radius: 16px;
            }
            .brand-icon {
                width: 56px;
                height: 56px;
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>

    <!-- Background decorations -->
    <div class="grid-bg"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="login-container">
        
        <!-- Platform Branding -->
        <div class="text-center mb-4">
            <div class="brand-icon">
                <?php echo e($platform_logo); ?>
            </div>
            <h3 class="fw-bold text-white mb-1" style="font-family: 'Outfit', sans-serif; letter-spacing: -0.02em;"><?php echo e($platform_name); ?></h3>
            <p style="color: var(--text-dim); font-size: 0.84rem; margin-bottom: 0;">Platform Administration Console</p>
        </div>
        
        <!-- Glassmorphic Login Card -->
        <div class="login-card">
            <div class="d-flex align-items-center gap-2 mb-4">
                <div style="width: 4px; height: 22px; background: linear-gradient(180deg, #A855F7, #6366F1); border-radius: 2px;"></div>
                <h5 class="text-white fw-bold mb-0" style="font-family: 'Outfit', sans-serif; font-size: 1.05rem;">Super Admin Sign In</h5>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="login-error">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form action="login.php" method="POST" autocomplete="off">
                <?php csrf_field(); ?>
                
                <div class="login-input-group">
                    <label>Admin Email Address</label>
                    <div class="login-input-wrap">
                        <span class="input-icon"><i class="bi bi-envelope"></i></span>
                        <input type="email" name="email" placeholder="admin@<?php echo strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $platform_name)); ?>.saas" value="<?php echo e($email); ?>" required id="adminEmail">
                    </div>
                </div>
                
                <div class="login-input-group">
                    <label>Access Password</label>
                    <div class="login-input-wrap">
                        <span class="input-icon"><i class="bi bi-key"></i></span>
                        <input type="password" name="password" placeholder="••••••••" required id="adminPassword">
                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility()" title="Toggle password visibility">
                            <i class="bi bi-eye" id="togglePwdIcon"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-admin-login mt-2" id="adminLoginBtn">
                    <i class="bi bi-shield-lock-fill me-2"></i>Authenticate Session
                </button>
            </form>
            
            <div class="text-center">
                <div class="security-badge">
                    <i class="bi bi-shield-check"></i>
                    <span>256-bit encrypted session · <?php echo e($platform_name); ?> SaaS</span>
                </div>
            </div>
        </div>
        
        <!-- Footer links -->
        <div class="login-footer">
            <a href="../login.php"><i class="bi bi-arrow-left me-1"></i>ISP Tenant Workspace Login</a>
            <span style="color: rgba(148,163,184,0.3); margin: 0 0.75rem;">·</span>
            <a href="../index.php"><i class="bi bi-globe me-1"></i>Public Website</a>
        </div>
        
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function togglePasswordVisibility() {
        const pwd = document.getElementById('adminPassword');
        const icon = document.getElementById('togglePwdIcon');
        if (pwd.type === 'password') {
            pwd.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            pwd.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    }
    </script>
</body>
</html>
