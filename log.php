<?php
ob_start(); // Start output buffering

session_set_cookie_params([
    'lifetime' => 604800, // 24 hours
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Set headers to prevent caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT"); // Past date

require_once 'db_connection.php';

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in as admin
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin') {
    header("Location: dashboard/dashboard.php");
    exit;
}

// Admin user creation (only if no admin exists)
$stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$stmt->execute();
$adminExists = $stmt->fetchColumn();

if (!$adminExists) {
    $userId = 1;
    $name = 'Admin';
    $role = 'admin';
    $email = 'admin@asdacademy.com';

    $setup_password = bin2hex(random_bytes(4));
    $hashedPassword = password_hash($setup_password, PASSWORD_DEFAULT);
    file_put_contents("first_admin_password.txt", "Set your password at first login: $setup_password");

    $query = "INSERT INTO users (id, name, role, email, password_hash, failed_login_attempts, account_locked, login_attempt_limit) 
              VALUES (?, ?, ?, ?, ?, 0, 0, 3)";
    $stmt = $db->prepare($query);
    $stmt->execute([$userId, $name, $role, $email, $hashedPassword]);
}

// reCAPTCHA configuration
define('RECAPTCHA_SITE_KEY', '6LfAOhktAAAAAGAYpR4dmrDAevb2flnt7QtFrB7E');
define('RECAPTCHA_SECRET_KEY', '6LfAOhktAAAAAEGmwiRCmNffoak5mdW13Geu-0jI');
// Login processing
if (isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $login_error = 'Invalid request. Please try again.';
        error_log("CSRF token validation failed for admin login attempt from IP: " . $_SERVER['REMOTE_ADDR']);
    } else {
        // reCAPTCHA verification
        if (!isset($_POST['g-recaptcha-response'])) {
            $login_error = 'Please complete the reCAPTCHA verification.';
        } else {
            $recaptcha_response = $_POST['g-recaptcha-response'];
            
            // Verify with Google
            $recaptcha_url = 'https://www.google.com/recaptcha/api/siteverify';
            $recaptcha_data = [
                'secret' => RECAPTCHA_SECRET_KEY,
                'response' => $recaptcha_response,
                'remoteip' => $_SERVER['REMOTE_ADDR']
            ];
            
            $options = [
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($recaptcha_data)
                ]
            ];
            
            $context = stream_context_create($options);
            $result = file_get_contents($recaptcha_url, false, $context);
            $response = json_decode($result);
            
            if (false && !$response->success) {
                $login_error = 'reCAPTCHA verification failed. Please try again.';
                error_log("reCAPTCHA failed for IP: " . $_SERVER['REMOTE_ADDR'] . " Errors: " . implode(", ", $response->{'error-codes'}));
            } else {
                $username = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
                $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
                
                if (empty($username) || empty($password)) {
                    $login_error = 'Please provide both username and password.';
                } else {
                    // Rate limiting check
                    try {
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

                            $stmt = $db->prepare("SELECT * FROM users WHERE name = ? AND role = 'admin'");
                            $stmt->execute([$username]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($user) {
                                if ($user['account_locked']) {
                                    // Get lock reason from user_lock_logs
                                    $stmt = $db->prepare("SELECT reason, expiry_date 
                                                        FROM user_lock_logs 
                                                        WHERE user_id = ? AND action = 'locked' 
                                                        ORDER BY performed_at DESC LIMIT 1");
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
                                    if (password_verify($password, $user['password_hash'])) {
                                        // Reset failed attempts
                                        $db->prepare("UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL, last_login = NOW() WHERE id = ?")
                                           ->execute([$user['id']]);

                                        // Clear login attempts for this IP
                                        $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?")
                                           ->execute([$_SERVER['REMOTE_ADDR']]);

                                        // Secure session handling
                                        session_regenerate_id(true);
                                        $_SESSION['user_id'] = $user['id'];
                                        $_SESSION['user_role'] = $user['role'];
                                        $_SESSION['user_name'] = $user['name'];
                                        $_SESSION['last_activity'] = time();
                                        
                                        logSystemActivity($db, $user['id'], 'LOGIN', 'Admin logged in');

                                        header("Location: dashboard/dashboard.php");
                                        exit;
                                    } else {
                                        // Rate limiting
                                        $attempts = $user['failed_login_attempts'] + 1;
                                        $max_attempts = $user['login_attempt_limit'] ?: 3;

                                        $db->prepare("UPDATE users SET failed_login_attempts = ?, last_failed_login = NOW() WHERE id = ?")
                                           ->execute([$attempts, $user['id']]);

                                        if ($attempts >= $max_attempts) {
                                            $db->prepare("UPDATE users SET account_locked = 1, locked_at = NOW(), locked_reason = 'Too many failed login attempts' WHERE id = ?")
                                               ->execute([$user['id']]);
                                            
                                            // Log the lock action
                                            $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by, performed_at) 
                                                                 VALUES (?, 'locked', 'Too many failed login attempts', 0, NOW())");
                                            $stmt->execute([$user['id']]);
                                            
                                            $login_error = 'Too many failed attempts. Account has been locked for security reasons.';
                                        } else {
                                            $login_error = 'Incorrect password. Attempt ' . $attempts . ' of ' . $max_attempts;
                                        }
                                    }
                                }
                            } else {
                                $login_error = 'Invalid credentials. Please try again.';
                                sleep(1); // Delay to prevent brute force
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Database error during admin login: " . $e->getMessage());
                        $login_error = 'System error. Please try again later.';
                    }
                }
            }
        }
    }
}
ob_end_flush(); // End output buffering
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Admin Login - ASD Academy</title>
    <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- reCAPTCHA API -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
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
        }

        /* Rich "bhra bhra" Texture Overlay for Background */
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

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Abstract Background Elements - More of them for a filled look */
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
            background: #D2C1B6; /* Warm sand pop */
            top: 30%;
            right: 20%;
            opacity: 0.2;
            animation-delay: -14s;
        }
        .bg-shape.shape-4 {
            width: 400px;
            height: 400px;
            background: #3b82f6;
            bottom: 20%;
            left: 20%;
            opacity: 0.15;
            animation-delay: -3s;
        }

        /* Container & Glassmorphism */
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
            margin: 20px;
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

        /* Image Side */
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

        /* Glowing orb in image panel */
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

        .welcome-text {
            position: relative;
            z-index: 2;
            text-align: center;
            margin-top: 40px;
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

        /* Form Side */
        .form-panel {
            flex: 1;
            padding: 50px 70px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: var(--white);
        }

        .form-header {
            margin-bottom: 45px;
            text-align: center;
        }

        .form-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            color: var(--primary);
            font-weight: 700;
            margin-bottom: 8px;
        }

        .form-header p {
            color: var(--text-muted);
            font-size: 1rem;
        }

        /* Floating Label Inputs */
        .input-group {
            position: relative;
            margin-bottom: 28px;
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
            margin-top: 10px;
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
            background: #112738; /* Darker navy */
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

        /* Alerts & Messages */
        .alert-box {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 22px;
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

        .alert-warning {
            background-color: #fffbeb;
            color: var(--warning);
            border: 1px solid #fde68a;
        }

        .alert-success {
            background-color: #ecfdf5;
            color: var(--success);
            border: 1px solid #a7f3d0;
        }

        /* reCAPTCHA wrapper */
        .recaptcha-wrapper {
            margin-bottom: 22px;
            display: flex;
            justify-content: center;
        }

        /* Footer */
        .footer-text {
            text-align: center;
            margin-top: 40px;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        /* Responsive Design */
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
                margin: 40px auto;
            }
            .image-panel {
                padding: 40px 20px;
                min-height: 250px;
                flex: none;
            }
            .welcome-text { margin-top: 20px; }
            .image-panel img { max-width: 160px; }
            .form-panel { padding: 40px 30px; }
        }

        @media (max-width: 480px) {
            .login-wrapper {
                margin: 15px;
                border-radius: 20px;
            }
            .form-panel { padding: 30px 20px; }
            .form-header h2 { font-size: 1.8rem; }
            .image-panel { min-height: 200px; padding: 30px 20px; }
            .image-panel img { max-width: 130px; }
            .bg-shape { display: none; } /* Better performance on mobile */
        }
    </style>
</head>
<body>
    <!-- Background Abstract Shapes -->
    <div class="bg-shape shape-1"></div>
    <div class="bg-shape shape-2"></div>
    <div class="bg-shape shape-3"></div>
    <div class="bg-shape shape-4"></div>

    <div class="login-wrapper">
        <!-- Left Side: Branding / Illustration -->
        <div class="image-panel">
            <img src="logo2.png" alt="ASD Academy Logo" onerror="this.src='https://via.placeholder.com/250x250/1B3C53/D2C1B6?text=ASD'">
            
            <div class="welcome-text">
                <h1>Command Center</h1>
                <p>Welcome to the ASD Academy Administration portal.</p>
            </div>
        </div>

        <!-- Right Side: Login Form -->
        <div class="form-panel">
            <div class="form-header">
                <h2>Admin Login</h2>
                <p>Sign in to manage the academy system</p>
            </div>

            <?php if (!empty($login_error)): ?>
                <div class="alert-box <?php echo (strpos($login_error, 'Account locked') !== false) ? 'alert-warning' : 'alert-error'; ?>" id="php-alert">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <form action="log.php" method="post" autocomplete="off" id="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="input-group">
                    <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <input type="text" id="name" name="name" required 
                           placeholder="Admin Username"
                           autocomplete="username"
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
                
                <div class="input-group">
                    <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <input type="password" id="password" name="password" required 
                           placeholder="Administrator Password"
                           autocomplete="current-password">
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()" aria-label="Toggle password">
                        <svg id="eye-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="recaptcha-wrapper">
                    <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                </div>
                
                <button type="submit" name="login" class="btn-submit">
                    Access Portal
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </button>
            </form>

            <div class="footer-text">
                &copy; <?php echo date('Y'); ?> ASD Academy. Admin Portal.
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>';
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>';
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Focus on name input automatically
            document.getElementById('name').focus();
            
            // Client side validation
            const form = document.getElementById('login-form');
            form.addEventListener('submit', function(e) {
                const name = document.getElementById('name').value.trim();
                const password = document.getElementById('password').value.trim();
                
                if (!name || !password) {
                    e.preventDefault();
                    const wrapper = document.querySelector('.login-wrapper');
                    wrapper.style.animation = 'none';
                    wrapper.offsetHeight; /* trigger reflow */
                    wrapper.style.animation = 'shake 0.5s';
                }
            });
            
            // Add shake keyframe
            const style = document.createElement('style');
            style.innerHTML = `@keyframes shake { 0%, 100% {transform: translateX(0);} 20%, 60% {transform: translateX(-10px);} 40%, 80% {transform: translateX(10px);} }`;
            document.head.appendChild(style);

            // Handle responsive reCAPTCHA
            function handleRecaptchaResize() {
                const recaptchaContainer = document.querySelector('.g-recaptcha');
                if (recaptchaContainer) {
                    if (window.innerWidth < 480) {
                        recaptchaContainer.style.transform = 'scale(0.85)';
                        recaptchaContainer.style.transformOrigin = 'center';
                    } else {
                        recaptchaContainer.style.transform = 'scale(1)';
                    }
                }
            }
            handleRecaptchaResize();
            window.addEventListener('resize', handleRecaptchaResize);
            
            // Prevent form resubmission
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>