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
define('RECAPTCHA_SITE_KEY', '6LeYkgItAAAAANaUAZn4XqV7ijRFtDMnN4eVR4Ew');
define('RECAPTCHA_SECRET_KEY', '6LeYkgItAAAAAEZACG1m_qP_Q0GJ857cCRJ0xYGm');

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
            
            if (!$response->success) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Admin Login - ASD Academy</title>
    <!-- reCAPTCHA API -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #0835feff 0%, #16111bff 100%);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #333;
            overflow: hidden;
        }

        .login-container {
            background-color: white;
            padding: 2.5rem;
            border-radius: 10px;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
            transform-origin: bottom center;
            animation: pinchOut 1s ease-out forwards;
            opacity: 0;
        }

        @keyframes pinchOut {
            0% {
                transform: scale(0.1) translateY(100px);
                opacity: 0;
            }
            50% {
                transform: scale(1.05) translateY(-10px);
                opacity: 1;
            }
            70% {
                transform: scale(0.98);
            }
            100% {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        h1 {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #4a5568;
        }

        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #4a5568;
        }

        input {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
        }

        button {
            width: 100%;
            padding: 0.8rem;
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        button:hover {
            background: linear-gradient(to right, #5a6fd1, #6a4299);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .error-message {
            color: #e53e3e;
            text-align: center;
            margin-top: 1rem;
            font-size: 0.9rem;
            animation: shake 0.5s;
        }

        .lock-message {
            color: #d69e2e;
            text-align: center;
            margin-top: 1rem;
            font-size: 0.9rem;
            padding: 0.8rem;
            background-color: #fefcbf;
            border-radius: 6px;
            border-left: 4px solid #d69e2e;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        .brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .brand h2 {
            color: #667eea;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            animation: colorPulse 4s infinite alternate;
        }

        @keyframes colorPulse {
            0% { color: #667eea; }
            50% { color: #764ba2; }
            100% { color: #667eea; }
        }

        .brand p {
            color: #718096;
            font-size: 0.9rem;
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #718096;
        }

        .toggle-password:hover {
            color: #667eea;
        }

        .g-recaptcha {
            margin: 1.5rem 0;
            display: flex;
            justify-content: center;
            transform: scale(0.9);
            transform-origin: center;
        }
        
        .contact-admin {
            text-align: center;
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #718096;
        }
        
        .contact-admin a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .contact-admin a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="brand">
            <h2>ASD Academy Admin Portal</h2>
            <p>Administrator Sign In</p>
        </div>
        <form action="log.php" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            
            <div class="form-group">
                <label for="name">Admin Name</label>
                <input type="text" id="name" name="name" required placeholder="Enter admin name" autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                    <span class="toggle-password" onclick="togglePasswordVisibility()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                </div>
            </div>
            
            <!-- reCAPTCHA Widget -->
            <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
            
            <button type="submit" name="login">Login</button>
            
            <?php if (isset($login_error)): ?>
                <?php if (strpos($login_error, 'Account locked') !== false): ?>
                    <div class="lock-message"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <div class="error-message"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
            <?php endif; ?>
            
        </form>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.querySelector('.toggle-password svg');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                passwordInput.type = 'password';
                toggleIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        }
        
        // Auto-focus on username field
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('name').focus();
            
            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        });
    </script>
</body>
</html>