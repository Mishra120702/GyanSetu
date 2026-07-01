<?php
// login.php - Student Login Page
session_start();

// Set session cookie parameters for security
session_set_cookie_params([
    'lifetime' => 604800, // 7 days
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Set headers to prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

// Database connection
require_once 'db_connection.php';

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in as student
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'student') {
    header("Location: stu_dash/dashboard.php");
    exit;
}

// Redirect if logged in as admin
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') {
    header("Location: dashboard/dashboard.php");
    exit;
}

$login_error = '';
$login_success = '';

// Login processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // CSRF Validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $login_error = 'Invalid request. Please try again.';
        error_log("CSRF token validation failed for student login attempt from IP: " . $_SERVER['REMOTE_ADDR']);
    } else {
        // Get and sanitize inputs
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';
        
        // Validate inputs
        if (empty($email) || empty($password)) {
            $login_error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $login_error = 'Please enter a valid email address.';
        } else {
            try {
                // Rate limiting check
                $stmt = $db->prepare("SELECT COUNT(*) FROM login_attempts 
                                    WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                $stmt->execute([$_SERVER['REMOTE_ADDR']]);
                $attempts = $stmt->fetchColumn();
                
                if ($attempts > 10) {
                    $login_error = 'Too many login attempts. Please try again later.';
                    error_log("Rate limit exceeded for IP: " . $_SERVER['REMOTE_ADDR']);
                } else {
                    // Record login attempt
                    $stmt = $db->prepare("INSERT INTO login_attempts (ip_address, attempt_time) VALUES (?, NOW())");
                    $stmt->execute([$_SERVER['REMOTE_ADDR']]);
                    
                    // Find user by email
                    $stmt = $db->prepare("
                        SELECT u.*, s.student_id, s.first_name, s.last_name, s.current_status, 
                               s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4,
                               s.course, s.enrollment_date
                        FROM users u
                        LEFT JOIN students s ON u.id = s.user_id
                        WHERE u.email = ? AND u.status = 'active'
                    ");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        // Check if account is locked
                        if ($user['account_locked']) {
                            // Get lock reason
                            $stmt = $db->prepare("
                                SELECT reason, expiry_date 
                                FROM user_lock_logs 
                                WHERE user_id = ? AND action = 'locked' 
                                ORDER BY performed_at DESC LIMIT 1
                            ");
                            $stmt->execute([$user['id']]);
                            $lock_info = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            $lock_message = 'Account locked.';
                            if ($lock_info && !empty($lock_info['expiry_date'])) {
                                $expiry_date = date('F j, Y', strtotime($lock_info['expiry_date']));
                                $lock_message .= ' This is a temporary lock until ' . $expiry_date . '.';
                            }
                            if ($lock_info && !empty($lock_info['reason'])) {
                                $lock_message .= ' Reason: ' . htmlspecialchars($lock_info['reason'], ENT_QUOTES, 'UTF-8');
                            }
                            
                            $login_error = $lock_message . ' Please contact system administrator.';
                        } else {
                            // Verify password
                            if (password_verify($password, $user['password_hash'])) {
                                // Check user role - allow both student and admin
                                if ($user['role'] === 'student') {
                                    // Check student status
                                    if ($user['current_status'] === 'on hold') {
                                        $login_error = 'Your account is currently on hold. Please contact administration.';
                                    } elseif ($user['current_status'] === 'dropped') {
                                        $login_error = 'Your account has been deactivated. Please contact administration.';
                                    } else {
                                        // Reset failed attempts
                                        $db->prepare("UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL, last_login = NOW() WHERE id = ?")
                                           ->execute([$user['id']]);
                                        
                                        // Clear login attempts for this IP
                                        $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?")
                                           ->execute([$_SERVER['REMOTE_ADDR']]);
                                        
                                        // Secure session handling
                                        session_regenerate_id(true);
                                        
                                        // Set session variables
                                        $_SESSION['user_id'] = $user['id'];
                                        $_SESSION['user_role'] = $user['role'];
                                        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                                        $_SESSION['user_email'] = $user['email'];
                                        $_SESSION['student_id'] = $user['student_id'];
                                        $_SESSION['last_activity'] = time();
                                        
                                        // Log activity
                                        logSystemActivity($db, $user['id'], 'LOGIN', 'Student logged in');
                                        
                                        // Set success message for session
                                        $_SESSION['login_success'] = 'Welcome back, ' . $user['first_name'] . '!';
                                        
                                        // Redirect to student dashboard
                                        header("Location: stu_dash/dashboard.php");
                                        exit;
                                    }
                                } elseif ($user['role'] === 'admin') {
                                    // Admin login - redirect to admin dashboard
                                    session_regenerate_id(true);
                                    $_SESSION['user_id'] = $user['id'];
                                    $_SESSION['user_role'] = 'admin';
                                    $_SESSION['user_name'] = $user['name'];
                                    $_SESSION['user_email'] = $user['email'];
                                    $_SESSION['last_activity'] = time();
                                    
                                    logSystemActivity($db, $user['id'], 'LOGIN', 'Admin logged in');
                                    header("Location: dashboard/dashboard.php");
                                    exit;
                                } else {
                                    $login_error = 'Invalid user role. Please contact administration.';
                                }
                            } else {
                                // Increment failed login attempts
                                $attempts = $user['failed_login_attempts'] + 1;
                                $max_attempts = $user['login_attempt_limit'] ?: 5;
                                
                                $db->prepare("UPDATE users SET failed_login_attempts = ?, last_failed_login = NOW() WHERE id = ?")
                                   ->execute([$attempts, $user['id']]);
                                
                                if ($attempts >= $max_attempts) {
                                    // Lock the account
                                    $db->prepare("UPDATE users SET account_locked = 1, locked_at = NOW(), locked_reason = 'Too many failed login attempts' WHERE id = ?")
                                       ->execute([$user['id']]);
                                    
                                    // Log the lock action
                                    $stmt = $db->prepare("
                                        INSERT INTO user_lock_logs (user_id, action, reason, performed_by, performed_at) 
                                        VALUES (?, 'locked', 'Too many failed login attempts', 0, NOW())
                                    ");
                                    $stmt->execute([$user['id']]);
                                    
                                    $login_error = 'Too many failed attempts. Account has been locked for security reasons.';
                                } else {
                                    $remaining = $max_attempts - $attempts;
                                    $login_error = 'Incorrect password. ' . $remaining . ' attempt(s) remaining.';
                                }
                            }
                        }
                    } else {
                        // Check if user exists but is inactive
                        $stmt = $db->prepare("SELECT status, role FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        $inactive_user = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($inactive_user && $inactive_user['status'] === 'inactive') {
                            $login_error = 'Your account is inactive. Please contact administration.';
                        } else {
                            $login_error = 'Invalid email or password. Please try again.';
                        }
                        sleep(1); // Delay to prevent brute force
                    }
                }
            } catch (PDOException $e) {
                error_log("Database error during login: " . $e->getMessage());
                $login_error = 'System error. Please try again later.';
            }
        }
    }
}

/**
 * Log system activity
 */
function logSystemActivity($db, $user_id, $action_type, $description) {
    try {
        $stmt = $db->prepare("
            INSERT INTO system_activity_logs (user_id, action_type, description, ip_address, created_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$user_id, $action_type, $description, $_SERVER['REMOTE_ADDR']]);
    } catch (PDOException $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Student Login - ASD Academy</title>
    <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #1B3C53;
            --primary-light: #234C6A;
            --primary-lighter: #456882;
            --accent: #D2C1B6;
            --accent-light: #E8E0D9;
            --bg-color: #0f172a;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --white: #ffffff;
            --error: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a, #1B3C53, #1e1e2f, #0B1E2B);
            background-size: 400% 400%;
            animation: gradientBG 20s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
            padding: 20px;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Background Texture */
        body::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: 
                radial-gradient(rgba(255, 255, 255, 0.05) 2px, transparent 2px),
                radial-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 40px 40px, 15px 15px;
            background-position: 0 0, 20px 20px;
            z-index: 0;
            pointer-events: none;
        }

        /* Abstract Background Shapes */
        .bg-shape {
            position: fixed;
            border-radius: 50%;
            filter: blur(120px);
            z-index: 1;
            opacity: 0.5;
            animation: floatShape 25s ease-in-out infinite alternate;
        }

        @keyframes floatShape {
            0% { transform: scale(1) translate(0, 0) rotate(0deg); }
            50% { transform: scale(1.2) translate(50px, -50px) rotate(180deg); }
            100% { transform: scale(0.8) translate(-50px, 50px) rotate(360deg); }
        }

        .bg-shape.shape-1 {
            width: 700px;
            height: 700px;
            background: #234C6A;
            top: -200px;
            left: -200px;
        }
        .bg-shape.shape-2 {
            width: 600px;
            height: 600px;
            background: #456882;
            bottom: -200px;
            right: -150px;
            animation-delay: -7s;
        }
        .bg-shape.shape-3 {
            width: 500px;
            height: 500px;
            background: #D2C1B6;
            top: 30%;
            right: 20%;
            opacity: 0.15;
            animation-delay: -14s;
        }

        /* Login Container */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1100px;
            min-height: 600px;
            display: flex;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(25px);
            -webkit-backdrop-filter: blur(25px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 24px;
            box-shadow: 0 30px 60px -15px rgba(0, 0, 0, 0.5);
            overflow: hidden;
            animation: slideUpFade 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            opacity: 0;
            transform: translateY(30px);
        }

        @keyframes slideUpFade {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-10px); }
            40%, 80% { transform: translateX(10px); }
        }

        /* Image/Branding Panel */
        .image-panel {
            flex: 1.1;
            background: linear-gradient(135deg, #1B3C53 0%, #0B1E2B 100%);
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 40px;
            color: var(--white);
            overflow: hidden;
        }

        .image-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml;utf8,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><circle cx="2" cy="2" r="2" fill="rgba(255,255,255,0.08)"/></svg>') repeat;
            opacity: 1;
        }

        .image-panel::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(210, 193, 182, 0.15) 0%, transparent 70%);
            pointer-events: none;
        }

        .image-panel img {
            position: relative;
            z-index: 2;
            max-width: 280px;
            filter: drop-shadow(0 15px 35px rgba(0,0,0,0.6));
            transition: transform 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .image-panel:hover img {
            transform: scale(1.05) translateY(-5px);
        }

        .welcome-text {
            position: relative;
            z-index: 2;
            text-align: center;
            margin-top: 30px;
        }

        .welcome-text h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--accent);
            text-shadow: 0 2px 15px rgba(0,0,0,0.4);
            letter-spacing: 1px;
        }

        .welcome-text p {
            font-size: 1.05rem;
            line-height: 1.6;
            opacity: 0.9;
            max-width: 85%;
            margin: 0 auto;
            color: #cbd5e1;
        }

        /* Form Panel */
        .form-panel {
            flex: 1;
            padding: 50px 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--white);
        }

        .form-header {
            margin-bottom: 35px;
            text-align: center;
        }

        .form-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 6px;
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }

        /* Alert Boxes */
        .alert-box {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.4s ease;
            font-weight: 500;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-error {
            background-color: #fef2f2;
            color: var(--error);
            border: 1px solid #fecaca;
        }

        .alert-success {
            background-color: #ecfdf5;
            color: var(--success);
            border: 1px solid #a7f3d0;
        }

        .alert-warning {
            background-color: #fffbeb;
            color: var(--warning);
            border: 1px solid #fde68a;
        }

        /* Floating Label Inputs */
        .input-group {
            position: relative;
            margin-bottom: 24px;
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-lighter);
            transition: color 0.3s ease;
            z-index: 2;
        }

        .input-group input {
            width: 100%;
            padding: 18px 20px 18px 52px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            background: #f1f5f9;
            border: 2px solid transparent;
            border-radius: 14px;
            outline: none;
            transition: all 0.3s ease;
        }

        .input-group input:focus {
            background: var(--white);
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(27, 60, 83, 0.1);
        }

        .input-group input:focus ~ .input-icon {
            color: var(--primary);
        }

        .input-group input::placeholder {
            color: #94a3b8;
        }

        .input-group input.input-error {
            border-color: var(--error);
            background: #fef2f2;
        }

        /* Password Toggle */
        .toggle-password {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--primary-lighter);
            cursor: pointer;
            padding: 5px;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: var(--primary);
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: var(--primary);
            color: var(--accent);
            border: none;
            border-radius: 14px;
            font-family: 'Outfit', sans-serif;
            font-size: 1.15rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            box-shadow: 0 10px 20px -10px var(--primary);
            margin-top: 6px;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s;
        }

        .btn-submit:hover {
            background: #112738;
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -10px var(--primary);
            color: #fff;
        }

        .btn-submit:hover::before {
            transform: translateX(100%);
        }

        .btn-submit:active {
            transform: translateY(1px);
        }

        .btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }

        /* Footer */
        .footer-links {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 25px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .footer-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.3s ease;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        .footer-text {
            text-align: center;
            margin-top: 30px;
            color: var(--text-muted);
            font-size: 0.85rem;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        /* Admin Login Link */
        .admin-link {
            text-align: center;
            margin-top: 12px;
        }

        .admin-link a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.3s ease;
        }

        .admin-link a:hover {
            color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .form-panel { padding: 40px; }
            .welcome-text h1 { font-size: 2rem; }
            .login-wrapper { max-width: 900px; }
        }

        @media (max-width: 860px) {
            .login-wrapper {
                flex-direction: column;
                max-width: 480px;
                min-height: auto;
                margin: 20px auto;
            }
            .image-panel {
                padding: 30px 20px;
                min-height: 200px;
                flex: none;
            }
            .welcome-text { margin-top: 15px; }
            .image-panel img { max-width: 160px; }
            .form-panel { padding: 35px 28px; }
            .welcome-text h1 { font-size: 1.6rem; }
            .welcome-text p { font-size: 0.9rem; max-width: 100%; }
        }

        @media (max-width: 480px) {
            .login-wrapper {
                margin: 10px;
                border-radius: 20px;
            }
            .form-panel { padding: 25px 18px; }
            .form-header h2 { font-size: 1.6rem; }
            .image-panel { min-height: 160px; padding: 20px; }
            .image-panel img { max-width: 120px; }
            .bg-shape { display: none; }
            .footer-links { flex-direction: column; align-items: center; }
        }

        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s ease-in-out infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <!-- Background Shapes -->
    <div class="bg-shape shape-1"></div>
    <div class="bg-shape shape-2"></div>
    <div class="bg-shape shape-3"></div>

    <div class="login-wrapper">
        <!-- Left: Branding -->
        <div class="image-panel">
            <img src="assets/images/logo.png" alt="ASD Academy Logo" onerror="this.src='https://via.placeholder.com/250x250/1B3C53/D2C1B6?text=ASD'">
            
            <div class="welcome-text">
                <h1>Student Portal</h1>
                <p>Access your courses, track progress, and stay connected with ASD Academy.</p>
            </div>
        </div>

        <!-- Right: Login Form -->
        <div class="form-panel">
            <div class="form-header">
                <h2>Welcome Back</h2>
                <p>Sign in to continue your learning journey</p>
            </div>

            <!-- Success Message -->
            <?php if (isset($_SESSION['login_success'])): ?>
                <div class="alert-box alert-success" id="php-alert">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($_SESSION['login_success'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php unset($_SESSION['login_success']); ?>
            <?php endif; ?>

            <!-- Error Message -->
            <?php if (!empty($login_error)): ?>
                <div class="alert-box <?php echo (strpos($login_error, 'locked') !== false || strpos($login_error, 'attempt') !== false) ? 'alert-warning' : 'alert-error'; ?>" id="php-alert">
                    <i class="fas <?php echo (strpos($login_error, 'locked') !== false) ? 'fa-lock' : (strpos($login_error, 'attempt') !== false ? 'fa-exclamation-triangle' : 'fa-exclamation-circle'); ?>"></i>
                    <span><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="post" autocomplete="off" id="login-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <!-- Email -->
                <div class="input-group">
                    <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <input type="email" id="email" name="email" required 
                           placeholder="Email Address"
                           autocomplete="email"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
                
                <!-- Password -->
                <div class="input-group">
                    <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <input type="password" id="password" name="password" required 
                           placeholder="Password"
                           autocomplete="current-password">
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()" aria-label="Toggle password visibility">
                        <svg id="eye-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
                
                <!-- Submit -->
                <button type="submit" name="login" class="btn-submit" id="loginBtn">
                    <span id="btn-text">Sign In</span>
                    <svg id="btn-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </button>
            </form>

            <!-- Footer Links -->
            <div class="footer-links">
                <a href="#"><i class="fas fa-question-circle"></i> Help</a>
                <a href="forgot-password.php"><i class="fas fa-key"></i> Forgot Password?</a>
            </div>

            <div class="admin-link">
                <a href="log.php"><i class="fas fa-user-shield"></i> Admin Login</a>
            </div>

            <div class="footer-text">
                &copy; <?php echo date('Y'); ?> ASD Academy. All rights reserved.
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>';
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';
            }
        }

        // Form submission handling
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('login-form');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const loginBtn = document.getElementById('loginBtn');
            const btnText = document.getElementById('btn-text');
            const btnIcon = document.getElementById('btn-icon');
            
            // Focus on email input
            emailInput.focus();

            // Form validation and submission
            form.addEventListener('submit', function(e) {
                const email = emailInput.value.trim();
                const password = passwordInput.value.trim();
                let isValid = true;
                
                // Reset error states
                emailInput.classList.remove('input-error');
                passwordInput.classList.remove('input-error');
                
                // Validate email
                if (!email) {
                    emailInput.classList.add('input-error');
                    isValid = false;
                } else if (!isValidEmail(email)) {
                    emailInput.classList.add('input-error');
                    isValid = false;
                }
                
                // Validate password
                if (!password) {
                    passwordInput.classList.add('input-error');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    // Shake animation
                    const wrapper = document.querySelector('.login-wrapper');
                    wrapper.style.animation = 'none';
                    wrapper.offsetHeight;
                    wrapper.style.animation = 'shake 0.5s';
                    
                    // Show error message
                    showToast('Please fill in all required fields correctly.', 'error');
                } else {
                    // Show loading state
                    loginBtn.disabled = true;
                    btnText.textContent = 'Signing In...';
                    btnIcon.innerHTML = '<span class="spinner"></span>';
                }
            });

            // Email validation helper
            function isValidEmail(email) {
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            }

            // Toast notification
            function showToast(message, type = 'info') {
                const toast = document.createElement('div');
                toast.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg text-white z-50 transform transition-all duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500'}`;
                toast.innerHTML = `
                    <div class="flex items-center">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
                        <span>${message}</span>
                    </div>
                `;
                
                document.body.appendChild(toast);
                
                setTimeout(() => {
                    toast.classList.add('translate-x-0', 'opacity-100');
                }, 10);
                
                setTimeout(() => {
                    toast.classList.remove('translate-x-0', 'opacity-100');
                    toast.classList.add('translate-x-full', 'opacity-0');
                    setTimeout(() => {
                        document.body.removeChild(toast);
                    }, 300);
                }, 4000);
            }

            // Auto-dismiss PHP alerts after 5 seconds
            const phpAlert = document.getElementById('php-alert');
            if (phpAlert) {
                setTimeout(() => {
                    phpAlert.style.transition = 'opacity 0.5s';
                    phpAlert.style.opacity = '0';
                    setTimeout(() => {
                        phpAlert.style.display = 'none';
                    }, 500);
                }, 5000);
            }

            // Handle Enter key on password field to submit form
            passwordInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    form.dispatchEvent(new Event('submit'));
                }
            });

            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>