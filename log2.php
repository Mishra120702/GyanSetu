<?php
ob_start(); // Start output buffering

session_set_cookie_params([
    'lifetime' => 604800, // 7 days (1 week)
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
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

// Check if already logged in as mentor (with "remember me" for 1 week)
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'mentor') {
    // Check if session is still valid (within 1 week)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] < 604800)) {
        // Session is still valid, redirect to dashboard
        $_SESSION['last_activity'] = time(); // Renew session activity
        header("Location: dash_t/dashboard/dashboard.php");
        exit;
    } else {
        // Session expired, destroy it
        session_unset();
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
    }
}

// Check for "remember me" cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['mentor_remember_token'])) {
    // Include remember me handler if it exists
    if (file_exists('remember_me_handler.php')) {
        require_once 'remember_me_handler.php';
    }
}


// reCAPTCHA configuration
define('RECAPTCHA_SITE_KEY', '6LfAOhktAAAAAGAYpR4dmrDAevb2flnt7QtFrB7E');
define('RECAPTCHA_SECRET_KEY', '6LfAOhktAAAAAEGmwiRCmNffoak5mdW13Geu-0jI');

$login_error = '';

// Login processing
if (isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $login_error = 'Invalid request. Please try again.';
        error_log("CSRF token validation failed for trainer login attempt from IP: " . $_SERVER['REMOTE_ADDR']);
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
                $remember_me = isset($_POST['remember_me']) ? true : false;
                
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

                            // Check if user exists and is a mentor
                            $stmt = $db->prepare("SELECT id, password_hash, name, email, role, account_locked, failed_login_attempts, login_attempt_limit 
                                                FROM users 
                                                WHERE name = ? AND role = 'mentor'");
                            $stmt->execute([$username]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($user) {
                                // Check if account is locked
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
                                    
                                    $login_error = $lock_message . ' Please contact administrator for assistance.';
                                } else {
                                    // Verify password
                                    if (password_verify($password, $user['password_hash'])) {
                                        // Reset failed attempts
                                        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL, last_login = NOW() WHERE id = ?");
                                        $stmt->execute([$user['id']]);

                                        // Clear login attempts for this IP
                                        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                                        $stmt->execute([$_SERVER['REMOTE_ADDR']]);

                                        // Secure session handling
                                        session_regenerate_id(true);
                                        $_SESSION['user_id'] = $user['id'];
                                        $_SESSION['user_role'] = $user['role'];
                                        $_SESSION['user_name'] = $user['name'];
                                        $_SESSION['email'] = $user['email'] ?? '';
                                        $_SESSION['last_activity'] = time();
                                        
                                        // Handle "Remember me" option
                                        if ($remember_me) {
                                            // Generate a secure random token
                                            $token = bin2hex(random_bytes(32));
                                            $expiry = time() + (7 * 24 * 60 * 60); // 7 days
                                            
                                            // Store token in database
                                            $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))");
                                            $stmt->execute([$user['id'], hash('sha256', $token), $expiry]);
                                            
                                            // Set secure cookie
                                            setcookie('mentor_remember_token', $token, $expiry, '/', '', true, true);
                                            setcookie('mentor_user_id', $user['id'], $expiry, '/', '', true, true);
                                        }

                                        logSystemActivity($db, $user['id'], 'LOGIN', 'Trainer logged in');

                                        header("Location: dash_t/dashboard/dashboard.php");
                                        exit;
                                    } else {
                                        // Increment failed attempts
                                        $attempts = $user['failed_login_attempts'] + 1;
                                        $max_attempts = $user['login_attempt_limit'] ?: 3;

                                        $stmt = $db->prepare("UPDATE users SET failed_login_attempts = ?, last_failed_login = NOW() WHERE id = ?");
                                        $stmt->execute([$attempts, $user['id']]);

                                        if ($attempts >= $max_attempts) {
                                            // Lock account
                                            $stmt = $db->prepare("UPDATE users SET account_locked = 1, locked_at = NOW(), locked_reason = 'Too many failed login attempts' WHERE id = ?");
                                            $stmt->execute([$user['id']]);
                                            
                                            // Log the lock action
                                            $stmt = $db->prepare("INSERT INTO user_lock_logs (user_id, action, reason, performed_by, performed_at) 
                                                                 VALUES (?, 'locked', 'Too many failed login attempts', 0, NOW())");
                                            $stmt->execute([$user['id']]);
                                            
                                            $login_error = 'Too many failed attempts. Account has been locked for security reasons. Please contact administrator.';
                                        } else {
                                            $login_error = 'Incorrect password. Attempt ' . $attempts . ' of ' . $max_attempts;
                                        }
                                    }
                                }
                            } else {
                                // User not found
                                $login_error = 'Invalid username or password. Please try again.';
                                sleep(1); // Delay to prevent brute force
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Database error during trainer login: " . $e->getMessage());
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
    <title>Trainer Login - ASD Academy</title>
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
            --bg-color: #F8F9FA;
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
            background: linear-gradient(-45deg, #e3ebf3, #cedae6, #d7e0e8, #e8eff5);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            overflow-y: auto;
        }

        /* Subtle Dots Texture Overlay for Background */
        body::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: radial-gradient(rgba(27, 60, 83, 0.08) 1.5px, transparent 1.5px);
            background-size: 25px 25px;
            z-index: 0;
            pointer-events: none;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Abstract Background Elements */
        .bg-shape {
            position: fixed;
            border-radius: 50%;
            filter: blur(100px);
            z-index: 1;
            opacity: 0.6;
            animation: floatShape 20s ease-in-out infinite alternate;
        }

        @keyframes floatShape {
            0% { transform: scale(1) translate(0, 0); }
            50% { transform: scale(1.1) translate(40px, -40px); }
            100% { transform: scale(0.9) translate(-40px, 40px); }
        }

        .bg-shape.shape-1 {
            width: 600px;
            height: 600px;
            background: #234C6A;
            top: -150px;
            right: -150px;
        }
        .bg-shape.shape-2 {
            width: 500px;
            height: 500px;
            background: #D2C1B6;
            bottom: -150px;
            left: -100px;
            animation-delay: -5s;
        }
        .bg-shape.shape-3 {
            width: 450px;
            height: 450px;
            background: #456882;
            top: 60%;
            right: 40%;
            transform: translate(50%, -50%);
            opacity: 0.3;
            animation-delay: -10s;
        }

        /* Container & Glassmorphism */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1100px;
            min-height: 600px;
            display: flex;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.6);
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(27, 60, 83, 0.25);
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
            background: linear-gradient(135deg, var(--primary) 0%, #0f172a 100%);
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
            background: url('data:image/svg+xml;utf8,<svg width="100" height="100" xmlns="http://www.w3.org/2000/svg"><circle cx="2" cy="2" r="2" fill="rgba(255,255,255,0.05)"/></svg>') repeat;
            opacity: 1;
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
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .welcome-text p {
            font-size: 1.05rem;
            line-height: 1.6;
            opacity: 0.9;
            max-width: 85%;
            margin: 0 auto;
        }

        .image-panel img {
            position: relative;
            z-index: 2;
            max-width: 280px;
            filter: drop-shadow(0 15px 25px rgba(0,0,0,0.4));
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
            margin-bottom: 35px;
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
            margin-bottom: 22px;
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
            padding: 16px 20px 16px 52px;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            color: var(--text-dark);
            background: var(--bg-color);
            border: 2px solid transparent;
            border-radius: 14px;
            outline: none;
            transition: all 0.3s ease;
        }

        .input-group input:focus {
            background: var(--white);
            border-color: var(--primary-light);
            box-shadow: 0 0 0 4px rgba(35, 76, 106, 0.1);
        }

        .input-group input:focus ~ .input-icon {
            color: var(--primary);
        }

        .input-group input::placeholder {
            color: #9ca3af;
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

        /* Checkbox */
        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            font-size: 0.95rem;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            cursor: pointer;
            user-select: none;
            color: var(--text-muted);
            font-weight: 500;
        }

        .checkbox-container input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .checkmark {
            height: 22px;
            width: 22px;
            background-color: var(--white);
            border: 2px solid var(--primary-lighter);
            border-radius: 6px;
            margin-right: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }

        .checkbox-container:hover input ~ .checkmark {
            border-color: var(--primary);
        }

        .checkbox-container input:checked ~ .checkmark {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .checkmark svg {
            display: none;
            color: white;
            width: 14px;
            height: 14px;
        }

        .checkbox-container input:checked ~ .checkmark svg {
            display: block;
        }

        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            color: var(--accent);
            border: none;
            border-radius: 14px;
            font-family: 'Outfit', sans-serif;
            font-size: 1.1rem;
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
            background: var(--primary-light);
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
            margin-top: 35px;
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

    <div class="login-wrapper">
        <!-- Left Side: Branding / Illustration -->
        <div class="image-panel">
            <img src="logo2.png" alt="ASD Academy Logo" onerror="this.src='https://via.placeholder.com/250x250/1B3C53/D2C1B6?text=ASD'">
            
            <div class="welcome-text">
                <h1>Faculty Portal</h1>
                <p>Welcome to the ASD Academy Trainer & Staff dashboard.</p>
            </div>
        </div>

        <!-- Right Side: Login Form -->
        <div class="form-panel">
            <div class="form-header">
                <h2>Trainer Login</h2>
                <p>Sign in to manage your classes and students</p>
            </div>

            <?php if (!empty($login_error)): ?>
                <div class="alert-box <?php echo (strpos($login_error, 'Account locked') !== false) ? 'alert-warning' : 'alert-error'; ?>" id="php-alert">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <form action="log2.php" method="post" autocomplete="off" id="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="input-group">
                    <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    <input type="text" id="name" name="name" required 
                           placeholder="Trainer Name"
                           autocomplete="username"
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
                
                <div class="input-group">
                    <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    <input type="password" id="password" name="password" required 
                           placeholder="Password"
                           autocomplete="current-password">
                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()" aria-label="Toggle password">
                        <svg id="eye-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                    </button>
                </div>

                <div class="options-row">
                    <label class="checkbox-container">
                        <input type="checkbox" id="remember_me" name="remember_me" value="1" <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>>
                        <div class="checkmark">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        </div>
                        Keep me logged in for 1 week
                    </label>
                </div>
                
                <div class="recaptcha-wrapper">
                    <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                </div>
                
                <button type="submit" name="login" class="btn-submit">
                    Sign In to Portal
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                </button>
            </form>

            <div class="footer-text">
                &copy; <?php echo date('Y'); ?> ASD Academy. All rights reserved.
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
            
            // Check for URL parameters to display dynamic messages
            const urlParams = new URLSearchParams(window.location.search);
            const messages = {
                'expired': { text: 'Your session has expired. Please login again.', type: 'warning' },
                'logout': { text: 'You have been successfully logged out.', type: 'success' },
                'timeout': { text: 'Your session timed out due to inactivity.', type: 'warning' },
                'unauthorized': { text: 'Please login to access that page.', type: 'error' }
            };
            
            for (const [param, data] of Object.entries(messages)) {
                if (urlParams.has(param)) {
                    if (document.getElementById('php-alert')) return;

                    const alertDiv = document.createElement('div');
                    alertDiv.className = `alert-box alert-${data.type}`;
                    
                    let iconHtml = '';
                    if (data.type === 'success') {
                        iconHtml = '<svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                    } else if (data.type === 'warning') {
                        iconHtml = '<svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';
                    } else {
                        iconHtml = '<svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
                    }

                    alertDiv.innerHTML = `${iconHtml}<span>${data.text}</span>`;
                    
                    const form = document.getElementById('login-form');
                    form.parentNode.insertBefore(alertDiv, form);
                    
                    if (window.history.replaceState) {
                        window.history.replaceState(null, null, window.location.href);
                    }
                    break;
                }
            }

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
        });
    </script>
</body>
</html>