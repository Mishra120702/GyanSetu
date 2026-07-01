<?php
ob_start(); // Start output buffering

session_set_cookie_params([
    'lifetime' => 604800, // 7 days (1 week)
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

// Check if already logged in as student (with "remember me" for 1 week)
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'student') {
    // Check if session is still valid (within 1 week)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] < 604800)) {
        // Session is still valid, redirect to dashboard
        $_SESSION['last_activity'] = time(); // Renew session activity
        
        // Check if student is locked by accounts team
        if (isset($_SESSION['student_id'])) {
            $accounts_lock = checkAccountsLock($db, $_SESSION['student_id']);
            if ($accounts_lock['is_locked_by_accounts']) {
                // Destroy current session and redirect to payment
                session_unset();
                session_destroy();
                setcookie(session_name(), '', time() - 3600, '/');
                header("Location: payment/a.php?student_id=" . urlencode($accounts_lock['student_id']) . "&accounts_lock=1");
                exit;
            }
        }
        
        // Payment verification check for logged-in students
        if (isset($_SESSION['student_id'])) {
            $requires_payment = checkStudentPaymentStatus($db, $_SESSION['student_id']);
            if ($requires_payment['redirect']) {
                // Destroy current session and redirect to payment
                session_unset();
                session_destroy();
                setcookie(session_name(), '', time() - 3600, '/');
                header("Location: payment/a.php?student_id=" . urlencode($_SESSION['student_id']));
                exit;
            }
        }
        
        // Check terms acceptance if student is from a batch
        if (isset($_SESSION['student_id'])) {
            // Get student batch info from session or database
            if (!isset($_SESSION['student_batch_id']) && isset($_SESSION['student_id'])) {
                $stmt = $db->prepare("SELECT batch_name_2 FROM students WHERE student_id = ?");
                $stmt->execute([$_SESSION['student_id']]);
                $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($student_info && !empty($student_info['batch_name_2'])) {
                    $_SESSION['student_batch_id'] = $student_info['batch_name_2'];
                }
            }
            
            if (isset($_SESSION['student_batch_id'])) {
                $terms_result = checkTermsAcceptance($db, $_SESSION['student_id'], $_SESSION['student_batch_id']);
                if ($terms_result['redirect']) {
                    redirectToTermsPage($terms_result);
                }
            }
        }
        
        // If no terms redirect needed, continue to dashboard
        header("Location: stu_dash/dashboard.php");
        exit;
    } else {
        // Session expired, destroy it
        session_unset();
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');
    }
}

// Check for "remember me" cookie
if (!isset($_SESSION['user_id']) && isset($_COOKIE['student_remember_token'])) {
    // Include remember me handler if it exists
    if (file_exists('remember_me_handler.php')) {
        require_once 'remember_me_handler.php';
    }
}

// reCAPTCHA configuration (using same keys as admin)
define('RECAPTCHA_SITE_KEY', '6LeYkgItAAAAANaUAZn4XqV7ijRFtDMnN4eVR4Ew');
define('RECAPTCHA_SECRET_KEY', '6LeYkgItAAAAAEZACG1m_qP_Q0GJ857cCRJ0xYGm');

$login_error = '';

// Login processing
if (isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $login_error = 'Invalid request. Please try again.';
        error_log("CSRF token validation failed for student login attempt from IP: " . $_SERVER['REMOTE_ADDR']);
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
                $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
                $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
                $remember_me = isset($_POST['remember_me']) ? true : false;
                
                if (empty($email) || empty($password)) {
                    $login_error = 'Please provide both email and password.';
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

                            // Check in users table first (for student portal)
                            $stmt = $db->prepare("SELECT u.*, s.student_id, s.first_name, s.last_name, s.current_status, s.email as student_email, s.batch_name_2
                                                 FROM users u 
                                                 LEFT JOIN students s ON u.id = s.user_id 
                                                 WHERE u.email = ? AND u.role = 'student'");
                            $stmt->execute([$email]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);

                            if (!$user) {
                                // Check in students table directly (some students might not have users entry)
                                $stmt = $db->prepare("SELECT * FROM students WHERE email = ?");
                                $stmt->execute([$email]);
                                $student_direct = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($student_direct) {
                                    // Check if student is locked by accounts team
                                    $accounts_lock = checkAccountsLock($db, $student_direct['student_id']);
                                    if ($accounts_lock['is_locked_by_accounts']) {
                                        // Redirect to payment page with accounts lock flag
                                        redirectToPaymentForAccountsLock($accounts_lock);
                                        exit;
                                    }
                                    
                                    // Check in test_portal_users (for test portal students)
                                    $stmt = $db->prepare("SELECT * FROM test_portal_users WHERE email = ? AND role = 'student'");
                                    $stmt->execute([$email]);
                                    $test_user = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($test_user) {
                                        // Verify password for test portal user
                                        if (password_verify($password, $test_user['password_hash'])) {
                                            // Check if student is locked by accounts team
                                            $accounts_lock = checkAccountsLock($db, $student_direct['student_id']);
                                            if ($accounts_lock['is_locked_by_accounts']) {
                                                // Redirect to payment page with accounts lock flag
                                                redirectToPaymentForAccountsLock($accounts_lock);
                                                exit;
                                            }
                                            
                                            // Create a session for test portal student
                                            session_regenerate_id(true);
                                            $_SESSION['user_id'] = $test_user['id'];
                                            $_SESSION['user_role'] = 'student';
                                            $_SESSION['user_name'] = $test_user['name'] ?: $test_user['username'];
                                            $_SESSION['email'] = $test_user['email'];
                                            $_SESSION['last_activity'] = time();
                                            $_SESSION['is_test_portal_user'] = true;
                                            
                                            if ($remember_me) {
                                                // Set remember me cookie for 30 days
                                                $token = bin2hex(random_bytes(32));
                                                $expiry = time() + (30 * 24 * 60 * 60); // 30 days
                                                
                                                $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))");
                                                $stmt->execute([$test_user['id'], hash('sha256', $token), $expiry]);
                                                
                                                setcookie('student_remember_token', $token, $expiry, '/', '', isset($_SERVER['HTTPS']), true);
                                                setcookie('student_user_id', $test_user['id'], $expiry, '/', '', isset($_SERVER['HTTPS']), true);
                                            }
                                            
                                            // Reset failed attempts for this IP
                                            $db->prepare("DELETE FROM login_attempts WHERE ip_address = ?")
                                               ->execute([$_SERVER['REMOTE_ADDR']]);
                                            
                                            // Update last login
                                            $db->prepare("UPDATE test_portal_users SET last_login = NOW() WHERE id = ?")
                                               ->execute([$test_user['id']]);
                                            
                                            // Check payment verification for test portal students
                                            $verification_result = checkStudentPaymentStatus($db, $student_direct['student_id'], $email);
                                            if ($verification_result['redirect']) {
                                                redirectToPaymentPage($verification_result);
                                            }
                                            
                                            // Check terms acceptance
                                            if (!empty($student_direct['batch_name_2'])) {
                                                $terms_result = checkTermsAcceptance($db, $student_direct['student_id'], $student_direct['batch_name_2']);
                                                if ($terms_result['redirect']) {
                                                    redirectToTermsPage($terms_result);
                                                }
                                            }
                                            
                                            header("Location: stu_dash/dashboard.php");
                                            exit;
                                        }
                                    } else {
                                        // Student exists but not in test_portal_users - check password directly
                                        if (password_verify($password, $student_direct['password_hash'])) {
                                            // Check if student is locked by accounts team
                                            $accounts_lock = checkAccountsLock($db, $student_direct['student_id']);
                                            if ($accounts_lock['is_locked_by_accounts']) {
                                                // Redirect to payment page with accounts lock flag
                                                redirectToPaymentForAccountsLock($accounts_lock);
                                                exit;
                                            }
                                            
                                            session_regenerate_id(true);
                                            $_SESSION['user_id'] = 0; // Temporary ID for direct student login
                                            $_SESSION['user_role'] = 'student';
                                            $_SESSION['student_id'] = $student_direct['student_id'];
                                            $_SESSION['user_name'] = $student_direct['first_name'] . ' ' . $student_direct['last_name'];
                                            $_SESSION['email'] = $student_direct['email'];
                                            $_SESSION['last_activity'] = time();
                                            $_SESSION['is_direct_student'] = true;
                                            
                                            // Check payment verification
                                            $verification_result = checkStudentPaymentStatus($db, $student_direct['student_id'], $email);
                                            if ($verification_result['redirect']) {
                                                redirectToPaymentPage($verification_result);
                                            }
                                            
                                            // Check terms acceptance
                                            if (!empty($student_direct['batch_name_2'])) {
                                                $terms_result = checkTermsAcceptance($db, $student_direct['student_id'], $student_direct['batch_name_2']);
                                                if ($terms_result['redirect']) {
                                                    redirectToTermsPage($terms_result);
                                                }
                                            }
                                            
                                            header("Location: stu_dash/dashboard.php");
                                            exit;
                                        } else {
                                            $login_error = 'Invalid email or password.';
                                            sleep(1);
                                        }
                                    }
                                } else {
                                    $login_error = 'Invalid email or password.';
                                    sleep(1); // Delay to prevent brute force
                                }
                            } else {
                                // User found in users table
                                
                                // Check if student is locked by accounts team
                                if ($user['student_id']) {
                                    $accounts_lock = checkAccountsLock($db, $user['student_id']);
                                    if ($accounts_lock['is_locked_by_accounts']) {
                                        // Redirect to payment page with accounts lock flag
                                        redirectToPaymentForAccountsLock($accounts_lock);
                                        exit;
                                    }
                                }
                                
                                if ($user['account_locked']) {
                                    // Get lock reason from user_lock_logs
                                    $stmt = $db->prepare("SELECT reason, expiry_date, performed_by 
                                                        FROM user_lock_logs 
                                                        WHERE user_id = ? AND action = 'locked' 
                                                        ORDER BY performed_at DESC LIMIT 1");
                                    $stmt->execute([$user['id']]);
                                    $lock_info = $stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    // Check if locked by accounts team
                                    $is_locked_by_accounts = false;
                                    if ($lock_info && $lock_info['performed_by']) {
                                        // Check if performer is in accounts team
                                        $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                                        $stmt->execute([$lock_info['performed_by']]);
                                        $performer = $stmt->fetch(PDO::FETCH_ASSOC);
                                        if ($performer && $performer['role'] === 'accounts') {
                                            $is_locked_by_accounts = true;
                                            // Redirect to payment page
                                            redirectToPaymentForAccountsLock([
                                                'student_id' => $user['student_id'],
                                                'lock_reason' => $lock_info['reason']
                                            ]);
                                            exit;
                                        }
                                    }
                                    
                                    $lock_message = 'Account locked.';
                                    if ($lock_info && !empty($lock_info['expiry_date'])) {
                                        $expiry_date = date('F j, Y', strtotime($lock_info['expiry_date']));
                                        $lock_message .= ' This is a temporary lock until ' . $expiry_date . '.';
                                    }
                                    
                                    if ($lock_info && !empty($lock_info['reason'])) {
                                        $lock_message .= ' Reason: ' . htmlspecialchars($lock_info['reason'], ENT_QUOTES, 'UTF-8');
                                    }
                                    
                                    $login_error = $lock_message . ' Please contact administration.';
                                } else {
                                    // Check if student account is active
                                    if ($user['current_status'] && $user['current_status'] !== 'active'&& $user['current_status'] !== 'completed') {
                                        $login_error = 'Your account is not active. Current status: ' . htmlspecialchars($user['current_status']) . '. Please contact administration.';
                                    } else if (password_verify($password, $user['password_hash'])) {
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
                                        $_SESSION['email'] = $user['email'];
                                        $_SESSION['last_activity'] = time();
                                        
                                        // Add student-specific data if available
                                        if ($user['student_id']) {
                                            $_SESSION['student_id'] = $user['student_id'];
                                            $_SESSION['first_name'] = $user['first_name'];
                                            $_SESSION['last_name'] = $user['last_name'];
                                            
                                            // Store batch info in session
                                            if (!empty($user['batch_name_2'])) {
                                                $_SESSION['student_batch_id'] = $user['batch_name_2'];
                                            }
                                            
                                            // Check payment verification BEFORE redirecting to dashboard
                                            $student_email = $user['student_email'] ?? $email;
                                            $verification_result = checkStudentPaymentStatus($db, $user['student_id'], $student_email);
                                            if ($verification_result['redirect']) {
                                                redirectToPaymentPage($verification_result);
                                            }
                                        }
                                        
                                        // Handle "Remember me" option
                                        if ($remember_me) {
                                            // Generate a secure random token
                                            $token = bin2hex(random_bytes(32));
                                            $expiry = time() + (7 * 24 * 60 * 60); // 7 days
                                            
                                            // Store token in database
                                            $stmt = $db->prepare("INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))");
                                            $stmt->execute([$user['id'], hash('sha256', $token), $expiry]);
                                            
                                            // Set secure cookie
                                            setcookie('student_remember_token', $token, $expiry, '/', '', isset($_SERVER['HTTPS']), true);
                                            setcookie('student_user_id', $user['id'], $expiry, '/', '', isset($_SERVER['HTTPS']), true);
                                        }
                                        
                                        // Check terms acceptance AFTER successful login
                                        if (!empty($user['batch_name_2'])) {
                                            $terms_result = checkTermsAcceptance($db, $user['student_id'], $user['batch_name_2']);
                                            if ($terms_result['redirect']) {
                                                redirectToTermsPage($terms_result);
                                            }
                                        }

                                        header("Location: stu_dash/dashboard.php");
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
                            }
                        }
                    } catch (PDOException $e) {
                        error_log("Database error during student login: " . $e->getMessage());
                        $login_error = 'System error. Please try again later.';
                    }
                }
            }
        }
    }
}

/**
 * Check if student is locked by accounts team
 */
function checkAccountsLock($db, $student_id) {
    try {
        // Get the user_id for the student
        $stmt = $db->prepare("SELECT user_id FROM students WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student || !$student['user_id']) {
            return ['is_locked_by_accounts' => false, 'student_id' => $student_id];
        }
        
        // Check if user is locked
        $stmt = $db->prepare("SELECT account_locked FROM users WHERE id = ?");
        $stmt->execute([$student['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !$user['account_locked']) {
            return ['is_locked_by_accounts' => false, 'student_id' => $student_id];
        }
        
        // Get the latest lock info
        $stmt = $db->prepare("SELECT ull.reason, ull.performed_by, ull.performed_at, u.role 
                             FROM user_lock_logs ull
                             JOIN users u ON ull.performed_by = u.id
                             WHERE ull.user_id = ? AND ull.action = 'locked' 
                             ORDER BY ull.performed_at DESC LIMIT 1");
        $stmt->execute([$student['user_id']]);
        $lock_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($lock_info && $lock_info['role'] === 'accounts') {
            return [
                'is_locked_by_accounts' => true,
                'student_id' => $student_id,
                'lock_reason' => $lock_info['reason'],
                'locked_by_accounts_id' => $lock_info['performed_by'],
                'locked_at' => $lock_info['performed_at']
            ];
        }
        
        return ['is_locked_by_accounts' => false, 'student_id' => $student_id];
        
    } catch (PDOException $e) {
        error_log("Accounts lock check error: " . $e->getMessage());
        return ['is_locked_by_accounts' => false, 'student_id' => $student_id];
    }
}

/**
 * Redirect to payment page for accounts lock
 */
function redirectToPaymentForAccountsLock($lock_info) {
    // Store lock data in session
    $_SESSION['accounts_lock_data'] = [
        'student_id' => $lock_info['student_id'],
        'lock_reason' => $lock_info['lock_reason'] ?? 'Account locked by accounts team',
        'redirected_at' => time(),
        'is_accounts_lock' => true
    ];
    
    // Destroy the main authentication session
    $_SESSION = array();
    
    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
    
    // Start a new minimal session for payment page
    session_start();
    $_SESSION['accounts_lock_data'] = [
        'student_id' => $lock_info['student_id'],
        'lock_reason' => $lock_info['lock_reason'] ?? 'Account locked by accounts team',
        'is_accounts_lock' => true
    ];
    
    // Redirect to payment page with accounts lock parameter
    header("Location: payment/a.php?student_id=" . urlencode($lock_info['student_id']) . "&accounts_lock=1");
    exit;
}

/**
 * Check if student needs to accept terms
 */
function checkTermsAcceptance($db, $student_id, $batch_id) {
    try {
        // First, get student's current terms acceptance status
        $stmt = $db->prepare("
            SELECT terms_accepted, batch_name_2 
            FROM students 
            WHERE student_id = ?
        ");
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return ['redirect' => false, 'reason' => 'Student not found'];
        }
        
        // Check if student has already accepted terms
        if ($student['terms_accepted'] == 1) {
            return ['redirect' => false, 'reason' => 'Already accepted'];
        }
        
        // If no batch ID provided, try to get it from student record
        if (empty($batch_id) && !empty($student['batch_name_2'])) {
            $batch_id = $student['batch_name_2'];
        }
        
        if (empty($batch_id)) {
            // If no batch assigned, don't require terms
            return ['redirect' => false, 'reason' => 'No batch assigned'];
        }
        
        // Check if batch requires terms acceptance
        $stmt = $db->prepare("
            SELECT require_terms_acceptance 
            FROM batch_terms_settings 
            WHERE batch_id = ?
        ");
        $stmt->execute([$batch_id]);
        $batch_terms = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Default to requiring terms if no setting exists
        $require_terms = $batch_terms ? $batch_terms['require_terms_acceptance'] : 1;
        
        if (!$require_terms) {
            // If batch doesn't require terms, mark as accepted for consistency
            $update_stmt = $db->prepare("
                UPDATE students 
                SET terms_accepted = 1, 
                    terms_accepted_date = NOW(),
                    terms_accepted_ip = ?
                WHERE student_id = ?
            ");
            $update_stmt->execute([$_SERVER['REMOTE_ADDR'], $student_id]);
            
            return ['redirect' => false, 'reason' => 'Terms not required'];
        }
        
        // Terms required and not yet accepted
        return [
            'redirect' => true,
            'redirect_url' => 'stu_dash/terms_conditions.php',
            'student_id' => $student_id,
            'batch_id' => $batch_id,
            'requires_terms' => true
        ];
        
    } catch (PDOException $e) {
        error_log("Terms check error: " . $e->getMessage());
        return ['redirect' => false, 'reason' => 'System error'];
    }
}

/**
 * Redirect to terms page with session data
 */
function redirectToTermsPage($terms_result) {
    // Store terms data in session
    $_SESSION['terms_verification_data'] = [
        'batch_id' => $terms_result['batch_id'],
        'student_id' => $terms_result['student_id'],
        'redirected_at' => time(),
        'requires_terms' => true
    ];
    
    // Redirect to terms page
    header("Location: " . $terms_result['redirect_url']);
    exit;
}

/**
 * Check if student requires payment verification
 */
function checkStudentPaymentStatus($db, $student_id, $email = null) {
    try {
        // Get student's batch and fees status using student_id or email
        if ($student_id) {
            $stmt = $db->prepare("
                SELECT 
                    s.student_id,
                    s.batch_name,
                    s.fees_status,
                    s.current_status,
                    s.first_name,
                    s.last_name,
                    b.batch_name as batch_full_name,
                    b.batch_id
                FROM students s
                LEFT JOIN batches b ON s.batch_name = b.batch_name
                WHERE s.student_id = ?
                LIMIT 1
            ");
            $stmt->execute([$student_id]);
        } else if ($email) {
            $stmt = $db->prepare("
                SELECT 
                    s.student_id,
                    s.batch_name,
                    s.fees_status,
                    s.current_status,
                    s.first_name,
                    s.last_name,
                    b.batch_name as batch_full_name,
                    b.batch_id
                FROM students s
                LEFT JOIN batches b ON s.batch_name = b.batch_name
                WHERE s.email = ?
                LIMIT 1
            ");
            $stmt->execute([$email]);
        } else {
            return ['redirect' => false, 'reason' => 'No student identifier provided'];
        }
        
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return ['redirect' => false, 'reason' => 'Student record not found'];
        }
        
        // Check if student's batch requires payment verification
        $stmt = $db->prepare("
            SELECT is_active 
            FROM payment_verification_settings 
            WHERE batch_id = ? AND is_active = 1
            LIMIT 1
        ");
        // Use batch_id from batches table if available, otherwise use batch_name
        $batch_identifier = $student['batch_id'] ?? $student['batch_name'];
        $stmt->execute([$batch_identifier]);
        $verification_required = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If batch doesn't require verification, allow login without payment check
        if (!$verification_required) {
            return ['redirect' => false, 'reason' => 'Payment verification not required for this batch'];
        }
        
        // Check fees status only if verification is required
        $unpaid_statuses = ['unpaid'];
        if (in_array($student['fees_status'], $unpaid_statuses)) {
            return [
                'redirect' => true,
                'redirect_url' => 'payment/a.php',
                'batch_id' => $student['batch_id'] ?? $student['batch_name'],
                'batch_name' => $student['batch_full_name'] ?? $student['batch_name'],
                'student_id' => $student['student_id'],
                'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                'fees_status' => $student['fees_status']
            ];
        }
        
        return ['redirect' => false, 'reason' => 'Fees are paid'];
        
    } catch (PDOException $e) {
        error_log("Payment verification error: " . $e->getMessage());
        return ['redirect' => false, 'reason' => 'System error'];
    }
}

/**
 * Redirect to payment page with session data
 */
function redirectToPaymentPage($verification_result) {
    // Store verification data in a separate session
    $_SESSION['payment_verification_data'] = [
        'batch_id' => $verification_result['batch_id'],
        'batch_name' => $verification_result['batch_name'],
        'student_id' => $verification_result['student_id'],
        'student_name' => $verification_result['student_name'],
        'fees_status' => $verification_result['fees_status'],
        'redirected_at' => time(),
        'requires_payment' => true
    ];
    
    // Keep user logged in for the payment session
    $_SESSION['payment_pending'] = true;
    
    // Redirect to payment page with student ID as parameter
    header("Location: " . $verification_result['redirect_url'] . "?student_id=" . urlencode($verification_result['student_id']));
    exit;
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
    <title>Student Login - ASD Academy</title>
    <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
    <!-- reCAPTCHA API -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <style>
        /* --- RESET AND BASE STYLES --- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        html, body {
            height: 100%;
            width: 100%;
            overflow-x: hidden;
        }
        
        /* --- MAIN BACKGROUND WITH COVER IMAGE --- */
        body {
            background-color: #ffffff;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #333;
            position: relative;
            min-height: 100vh;
            padding: 20px;
            
            /* Main brand background image */
            background-image: url('logo.png');
            background-size: 1100px;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
        }
        
        /* Overlay to improve readability */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.85);
            z-index: 1;
        }
        
        /* Animated tech background using pseudo-element */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1) 0%, rgba(124, 58, 237, 0.1) 100%);
            opacity: 0.1;
            z-index: 2;
            background-image: radial-gradient(#4f46e522 1px, transparent 1px);
            background-size: 30px 30px;
            animation: moveBackground 80s linear infinite;
            pointer-events: none;
        }

        @keyframes moveBackground {
            0% { background-position: 0 0; }
            100% { background-position: 300px 300px; }
        }
        
        /* --- MAIN CONTAINER --- */
        .main-container {
            background-color: rgba(255, 255, 255, 0.98);
            display: flex;
            border-radius: 20px;
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.3);
            max-width: 1100px;
            width: 100%;
            overflow: hidden;
            min-height: 600px;
            z-index: 20;
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.4);
            position: relative;
            
            /* Animation */
            animation: fadeInScaleUp 1s cubic-bezier(0.25, 0.46, 0.45, 0.94) forwards;
            opacity: 0;
        }

        @keyframes fadeInScaleUp {
            0% {
                opacity: 0;
                transform: scale(0.95) translateY(20px);
            }
            100% {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        /* --- IMAGE SIDE --- */
        .image-side {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.9) 0%, rgba(124, 58, 237, 0.9) 100%);
            min-width: 45%;
            position: relative;
            overflow: hidden;
            padding: 40px;
        }

        .image-side::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, rgba(255,255,255,0.15) 0%, rgba(255,255,255,0) 100%);
            z-index: 1;
        }

        .image-side img {
            width: 100%;
            height: auto;
            max-height: 80%;
            object-fit: contain;
            z-index: 2;
            position: relative;
            filter: drop-shadow(0 10px 25px rgba(0, 0, 0, 0.4));
            transition: transform 0.5s ease;
        }

        .image-side:hover img {
            transform: scale(1.05);
        }
        
        /* --- LOGIN SIDE --- */
        .login-side {
            flex: 1;
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-width: 55%;
            position: relative;
        }
        
        /* --- BRAND STYLING --- */
        .brand {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .brand h2 {
            color: #4f46e5;
            font-size: 2.2rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            animation: colorPulse 4s infinite alternate;
            line-height: 1.3;
        }

        @keyframes colorPulse {
            0% { color: #4f46e5; }
            50% { color: #7c3aed; }
            100% { color: #4f46e5; }
        }

        .brand p {
            color: #718096;
            font-size: 1.1rem;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        
        /* --- FORM STYLING --- */
        #login-form {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #4a5568;
            font-size: 0.95rem;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background-color: #f8fafc;
            color: #2d3748;
        }

        input:focus {
            outline: none;
            border-color: #4f46e5;
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15);
        }

        input::placeholder {
            color: #a0aec0;
            font-size: 0.95rem;
        }
        
        /* --- PASSWORD TOGGLE --- */
        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #718096;
            transition: color 0.3s;
            background: transparent;
            border: none;
            padding: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .toggle-password:hover {
            color: #4f46e5;
            background-color: rgba(79, 70, 229, 0.1);
        }

        .toggle-password svg {
            width: 22px;
            height: 22px;
        }
        
        /* --- CHECKBOX --- */
        .checkbox-group {
            display: flex;
            align-items: center;
            margin: 20px 0 25px 0;
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            accent-color: #4f46e5;
            cursor: pointer;
            border-radius: 4px;
        }

        .checkbox-group label {
            margin-bottom: 0;
            font-weight: normal;
            color: #4a5568;
            cursor: pointer;
            font-size: 0.95rem;
            user-select: none;
        }
        
        /* --- RECAPTCHA --- */
        .g-recaptcha {
            margin: 25px 0;
            display: flex;
            justify-content: center;
            transform: scale(0.95);
            transform-origin: center;
        }
        
        /* --- BUTTON --- */
        button[type="submit"] {
            width: 100%;
            padding: 16px;
            background: linear-gradient(to right, #4f46e5, #7c3aed);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-top: 10px;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }
        
        button[type="submit"]::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: all 0.6s;
        }

        button[type="submit"]:hover {
            background: linear-gradient(to right, #4338ca, #6d28d9);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.4);
        }
        
        button[type="submit"]:hover::after {
            left: 100%;
        }

        button[type="submit"]:active {
            transform: translateY(-1px);
        }
        
        /* --- MESSAGES --- */
        .error-message {
            color: #e53e3e;
            text-align: center;
            margin-top: 20px;
            font-size: 0.95rem;
            padding: 15px;
            background-color: #fed7d7;
            border-radius: 8px;
            border-left: 4px solid #e53e3e;
            animation: shake 0.5s;
            line-height: 1.5;
        }

        .lock-message {
            color: #d69e2e;
            text-align: center;
            margin-top: 20px;
            font-size: 0.95rem;
            padding: 15px;
            background-color: #fefcbf;
            border-radius: 8px;
            border-left: 4px solid #d69e2e;
            line-height: 1.5;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        
        /* --- LINKS --- */
        .links {
            display: flex;
            justify-content: space-between;
            margin-top: 25px;
            font-size: 0.95rem;
        }
        
        .links a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            padding: 8px 12px;
            border-radius: 6px;
        }
        
        .links a:hover {
            text-decoration: underline;
            background-color: rgba(79, 70, 229, 0.1);
        }
        
        .contact-support {
            text-align: center;
            margin-top: 25px;
            font-size: 0.9rem;
            color: #718096;
            line-height: 1.6;
        }
        
        .contact-support a {
            color: #4f46e5;
            text-decoration: none;
            font-weight: 500;
        }
        
        .contact-support a:hover {
            text-decoration: underline;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #718096;
            font-size: 0.85rem;
        }
        
        .login-footer a {
            color: #4f46e5;
            text-decoration: none;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        /* --- BADGE --- */
        .student-badge {
            display: inline-block;
            background: linear-gradient(to right, #4f46e5, #7c3aed);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-left: 8px;
            vertical-align: middle;
            animation: badgePulse 2s infinite;
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3);
        }
        
        @keyframes badgePulse {
            0% { transform: scale(1); box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3); }
            50% { transform: scale(1.05); box-shadow: 0 4px 12px rgba(79, 70, 229, 0.4); }
            100% { transform: scale(1); box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3); }
        }
        
        /* --- SUCCESS MESSAGES --- */
        .success-message {
            color: #10b981;
            text-align: center;
            margin-top: 20px;
            font-size: 0.95rem;
            padding: 15px;
            background-color: #f0fdf4;
            border-radius: 8px;
            border-left: 4px solid #10b981;
            line-height: 1.5;
        }
        
        /* --- RESPONSIVE DESIGN --- */
        
        /* Tablets and small laptops */
        @media (max-width: 1024px) {
            .main-container {
                max-width: 900px;
                min-height: 550px;
            }
            
            .login-side {
                padding: 40px;
            }
            
            .brand h2 {
                font-size: 2rem;
            }
        }
        
        /* Tablets */
        @media (max-width: 768px) {
            body {
                padding: 15px;
                background-attachment: scroll;
                background-size: 800px;
            }
            
            .main-container {
                flex-direction: column;
                max-width: 500px;
                min-height: auto;
                margin: 0 auto;
                max-height: 90vh;
                overflow-y: auto;
            }
            
            .image-side {
                min-height: 200px;
                max-height: 250px;
                padding: 30px;
                min-width: 100%;
                order: 2;
            }
            
            .image-side img {
                max-height: 150px;
            }
            
            .login-side {
                padding: 40px 30px;
                min-width: 100%;
                order: 1;
            }
            
            #login-form {
                max-width: 100%;
            }
            
            .brand h2 {
                font-size: 1.8rem;
            }
        }
        
        /* Mobile phones */
        @media (max-width: 480px) {
            body {
                padding: 10px;
                background-size: 600px;
            }
            
            .main-container {
                border-radius: 15px;
                max-width: 100%;
                min-height: auto;
            }
            
            .image-side {
                min-height: 180px;
                max-height: 200px;
                padding: 20px;
            }
            
            .login-side {
                padding: 30px 20px;
            }
            
            .brand h2 {
                font-size: 1.6rem;
            }
            
            .brand p {
                font-size: 1rem;
            }
            
            input[type="email"],
            input[type="password"],
            input[type="text"] {
                padding: 13px;
            }
            
            button[type="submit"] {
                padding: 14px;
            }
            
            .links {
                flex-direction: column;
                gap: 10px;
                align-items: center;
            }
            
            .g-recaptcha {
                transform: scale(0.85);
            }
        }
        
        /* Small mobile phones */
        @media (max-width: 360px) {
            .brand h2 {
                font-size: 1.4rem;
            }
            
            .login-side {
                padding: 25px 15px;
            }
            
            .form-group {
                margin-bottom: 20px;
            }
            
            .main-container {
                min-height: auto;
            }
        }
        
        /* Landscape mode */
        @media (max-height: 700px) and (orientation: landscape) {
            body {
                align-items: flex-start;
                padding-top: 20px;
                padding-bottom: 20px;
                overflow-y: auto;
            }
            
            .main-container {
                max-height: 90vh;
                min-height: auto;
                overflow-y: auto;
            }
            
            .image-side {
                min-height: auto;
            }
            
            .login-side {
                padding: 25px;
                justify-content: flex-start;
            }
            
            .brand {
                margin-bottom: 20px;
            }
        }
        
        /* High-resolution screens */
        @media (min-width: 1400px) {
            .main-container {
                max-width: 1200px;
            }
            
            .login-side {
                padding: 60px;
            }
            
            .brand h2 {
                font-size: 2.5rem;
            }
        }
        
        /* Print styles */
        @media print {
            body::before,
            body::after,
            .image-side {
                display: none;
            }
            
            .main-container {
                box-shadow: none;
                border: 1px solid #ccc;
                max-width: 100%;
                min-height: auto;
            }
            
            .login-side {
                width: 100%;
                min-width: 100%;
            }
        }
        
        /* Reduced motion preference */
        @media (prefers-reduced-motion: reduce) {
            .main-container,
            .image-side img,
            .student-badge,
            .brand h2,
            button[type="submit"]::after,
            button[type="submit"]:hover {
                animation: none;
                transition: none;
            }
            
            body::after {
                animation: none;
            }
        }
        
        /* Dark mode support */
        @media (prefers-color-scheme: dark) {
            body {
                background-color: #1a202c;
                color: #e2e8f0;
            }
            
            body::before {
                background: rgba(26, 32, 44, 0.9);
            }
            
            .main-container {
                background-color: rgba(45, 55, 72, 0.98);
                border-color: rgba(255, 255, 255, 0.1);
            }
            
            input[type="email"],
            input[type="password"],
            input[type="text"] {
                background-color: #2d3748;
                border-color: #4a5568;
                color: #e2e8f0;
            }
            
            input:focus {
                background-color: #2d3748;
                border-color: #4f46e5;
            }
            
            label,
            .checkbox-group label,
            .brand p,
            .contact-support,
            .login-footer {
                color: #cbd5e0;
            }
            
            .error-message {
                background-color: #742a2a;
                color: #fed7d7;
            }
            
            .lock-message {
                background-color: #744210;
                color: #fefcbf;
            }
            
            .success-message {
                background-color: #22543d;
                color: #f0fdf4;
            }
        }
        
        /* Fallback for older browsers */
        @supports not (backdrop-filter: blur(5px)) {
            .main-container {
                background-color: rgba(255, 255, 255, 0.95);
            }
            
            @media (prefers-color-scheme: dark) {
                .main-container {
                    background-color: rgba(45, 55, 72, 0.95);
                }
            }
        }
        
        /* Ensure visibility on all devices */
        .main-container {
            visibility: visible !important;
            display: flex !important;
        }
        
        /* Fix for very small screens */
        @media (max-width: 320px) {
            .brand h2 {
                font-size: 1.3rem;
            }
            
            .login-side {
                padding: 20px 15px;
            }
            
            .main-container {
                border-radius: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="main-container">
        <div class="image-side">
            <img src="logo2.png" alt="ASD Academy Student Portal Logo" 
                 onerror="this.src='https://via.placeholder.com/400x400/4f46e5/ffffff?text=ASD+Student'">
        </div>
        
        <div class="login-side">
            <div class="brand">
                <h2>ASD Academy Student Portal</h2>
                <p>Student Sign In</p>
            </div>
            
            <form action="login.php" method="post" autocomplete="off" id="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required 
                           placeholder="Enter your registered email"
                           autocomplete="email"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" required 
                               placeholder="Enter your password"
                               autocomplete="current-password">
                        <button type="button" class="toggle-password" onclick="togglePasswordVisibility()" aria-label="Toggle password visibility">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="remember_me" name="remember_me" value="1" <?php echo isset($_POST['remember_me']) ? 'checked' : ''; ?>>
                    <label for="remember_me">Keep me logged in for 1 week</label>
                </div>
                
                <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                
                <button type="submit" name="login">Sign In</button>
                
                <?php if (!empty($login_error)): ?>
                    <?php if (strpos($login_error, 'Account locked') !== false): ?>
                        <div class="lock-message"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php else: ?>
                        <div class="error-message"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                <?php endif; ?>
               
            </form>
            
            <div class="login-footer">
                &copy; <?php echo date('Y'); ?> ASD Academy. All rights reserved.
            </div>
        </div>
    </div>

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.toggle-password');
            const toggleIcon = toggleButton.querySelector('svg');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                // Change to "hide" icon (eye with slash)
                toggleIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
                toggleButton.setAttribute('aria-label', 'Hide password');
            } else {
                passwordInput.type = 'password';
                // Change to "show" icon (regular eye)
                toggleIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
                toggleButton.setAttribute('aria-label', 'Show password');
            }
        }
        
        // Auto-focus on email field
        document.addEventListener('DOMContentLoaded', function() {
            // Force display of main container
            document.querySelector('.main-container').style.display = 'flex';
            
            document.getElementById('email').focus();
            
            // Check for URL parameters
            const urlParams = new URLSearchParams(window.location.search);
            
            // Handle URL parameters for messages
            const messages = {
                'expired': 'Your session has expired. Please login again.',
                'logout': 'You have been successfully logged out.',
                'registered': 'Registration successful! Please login with your credentials.',
                'timeout': 'Your session timed out due to inactivity.',
                'unauthorized': 'Please login to access that page.',
                'payment_required': 'Payment verification required. Please complete your fees payment.',
                'payment_pending': 'Your payment is pending verification. You will be notified once verified.',
                'payment_submitted': 'Payment submitted for verification. You can login once verified.'
            };
            
            for (const [param, message] of Object.entries(messages)) {
                if (urlParams.has(param)) {
                    const messageDiv = document.createElement('div');
                    if (param === 'logout' || param === 'registered' || param === 'payment_submitted') {
                        messageDiv.className = 'success-message';
                    } else if (param === 'expired' || param === 'payment_required' || param === 'payment_pending') {
                        messageDiv.className = 'lock-message';
                    } else {
                        messageDiv.className = 'error-message';
                    }
                    messageDiv.textContent = message;
                    const button = document.querySelector('button[type="submit"]');
                    button.parentNode.insertBefore(messageDiv, button);
                    break;
                }
            }
            
            // Prevent form resubmission on page refresh
            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
            
            // Form validation
            const form = document.getElementById('login-form');
            form.addEventListener('submit', function(e) {
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value.trim();
                const recaptcha = document.querySelector('.g-recaptcha-response');
                
                // Basic client-side validation
                if (!email || !email.includes('@')) {
                    e.preventDefault();
                    showError('Please enter a valid email address.');
                    document.getElementById('email').focus();
                    return;
                }
                
                if (!password) {
                    e.preventDefault();
                    showError('Please enter your password.');
                    document.getElementById('password').focus();
                    return;
                }
                
                // Check if reCAPTCHA is filled (if present)
                if (recaptcha && !recaptcha.value) {
                    e.preventDefault();
                    showError('Please complete the reCAPTCHA verification.');
                    return;
                }
            });
            
            // Show error function
            function showError(message) {
                // Remove any existing error messages
                const existingError = document.querySelector('.error-message');
                if (existingError) {
                    existingError.remove();
                }
                
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-message';
                errorDiv.textContent = message;
                const button = document.querySelector('button[type="submit"]');
                button.parentNode.insertBefore(errorDiv, button);
                
                // Scroll to error
                errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            // Add input focus effects
            const inputs = document.querySelectorAll('input[type="email"], input[type="password"]');
            inputs.forEach(input => {
                const wrapper = input.closest('.form-group');
                
                input.addEventListener('focus', function() {
                    wrapper.classList.add('focused');
                });
                
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        wrapper.classList.remove('focused');
                    }
                });
                
                // Add filled class if input has value on load
                if (input.value) {
                    wrapper.classList.add('filled');
                }
                
                input.addEventListener('input', function() {
                    if (this.value) {
                        wrapper.classList.add('filled');
                    } else {
                        wrapper.classList.remove('filled');
                    }
                });
            });
            
            // Add keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Ctrl+Enter to submit form
                if (e.ctrlKey && e.key === 'Enter') {
                    document.querySelector('button[type="submit"]').click();
                }
                
                // Escape to clear form
                if (e.key === 'Escape') {
                    document.getElementById('login-form').reset();
                    document.getElementById('email').focus();
                }
            });
            
            // Handle responsive reCAPTCHA
            function handleRecaptchaResize() {
                const recaptchaContainer = document.querySelector('.g-recaptcha');
                if (recaptchaContainer) {
                    if (window.innerWidth < 480) {
                        recaptchaContainer.style.transform = 'scale(0.85)';
                    } else if (window.innerWidth < 768) {
                        recaptchaContainer.style.transform = 'scale(0.9)';
                    } else {
                        recaptchaContainer.style.transform = 'scale(0.95)';
                    }
                }
            }
            
            // Initial call
            handleRecaptchaResize();
            
            // Listen for window resize
            window.addEventListener('resize', handleRecaptchaResize);
            
            // Add Enter key support for toggling password visibility
            document.querySelector('.toggle-password').addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    togglePasswordVisibility();
                }
            });
            
            // Ensure main container is visible
            setTimeout(function() {
                document.querySelector('.main-container').style.opacity = '1';
            }, 100);
        });

        // Force browser to not cache this page
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
        
        // Handle page visibility changes
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                // Page became visible again, refresh CSRF token if needed
                fetch('refresh_csrf.php').catch(() => {
                    // Silently fail if refresh endpoint doesn't exist
                });
            }
        });
        
        // Fix for iOS Safari viewport issues
        window.onload = function() {
            setTimeout(function() {
                // Force redraw on iOS
                document.body.style.display = 'none';
                document.body.offsetHeight; // Trigger reflow
                document.body.style.display = 'flex';
            }, 100);
        };
    </script>
</body>
</html>