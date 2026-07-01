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

// reCAPTCHA configuration
define('RECAPTCHA_SITE_KEY', '6LfAOhktAAAAAGAYpR4dmrDAevb2flnt7QtFrB7E');
define('RECAPTCHA_SECRET_KEY', '6LfAOhktAAAAAEGmwiRCmNffoak5mdW13Geu-0jI');

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

$login_error = '';

// Login processing
if (isset($_POST['login'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $login_error = 'Invalid request. Please try again.';
        error_log("CSRF token validation failed for student login attempt from IP: " . $_SERVER['REMOTE_ADDR']);
    } else {
        // reCAPTCHA verification
        if (!isset($_POST['g-recaptcha-response']) || empty($_POST['g-recaptcha-response'])) {
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
            
            // **FIXED: Removed 'false &&' to properly enable reCAPTCHA verification**
            if (!$response->success) {
                $login_error = 'reCAPTCHA verification failed. Please try again.';
                error_log("reCAPTCHA failed for IP: " . $_SERVER['REMOTE_ADDR'] . " Errors: " . implode(", ", $response->{'error-codes'} ?? ['unknown']));
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
                                            
                                            logSystemActivity($db, $test_user['id'], 'LOGIN', 'Student test portal logged in');
                                            
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
                                        
                                        logSystemActivity($db, $user['id'], 'LOGIN', 'Student logged in');
                                        
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
            background: linear-gradient(-45deg, #f0ebe4, #e2d7cc, #d1dde5, #e0e7ec);
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

        /* Texture Grid Overlay for Background */
        body::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: 
                linear-gradient(rgba(27, 60, 83, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(27, 60, 83, 0.04) 1px, transparent 1px);
            background-size: 30px 30px;
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
            opacity: 0.7;
            animation: floatShape 20s ease-in-out infinite alternate;
        }

        @keyframes floatShape {
            0% { transform: scale(1) translate(0, 0); }
            50% { transform: scale(1.1) translate(50px, 30px); }
            100% { transform: scale(0.9) translate(-30px, 50px); }
        }

        .bg-shape.shape-1 {
            width: 600px;
            height: 600px;
            background: var(--primary-light);
            top: -200px;
            left: -150px;
        }
        .bg-shape.shape-2 {
            width: 500px;
            height: 500px;
            background: var(--accent);
            bottom: -150px;
            right: -100px;
            animation-delay: -5s;
        }
        .bg-shape.shape-3 {
            width: 400px;
            height: 400px;
            background: var(--primary-lighter);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
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
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
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

        /* Floating elements in Image Side */
        .float-el {
            position: absolute;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.2);
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 3;
            animation: float 6s ease-in-out infinite;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }

        .float-1 {
            top: 15%;
            left: -20px;
            animation-delay: 0s;
        }

        .float-2 {
            bottom: 20%;
            right: -10px;
            animation-delay: -3s;
        }

        .float-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
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
            .float-el { display: none; }
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
                <h1>Welcome Back!</h1>
                <p>Unlock your learning potential with our comprehensive student portal.</p>
            </div>
        </div>

        <!-- Right Side: Login Form -->
        <div class="form-panel">
            <div class="form-header">
                <h2>Student Portal</h2>
                <p>Enter your credentials to access your account</p>
            </div>

            <?php if (!empty($login_error)): ?>
                <div class="alert-box <?php echo (strpos($login_error, 'Account locked') !== false) ? 'alert-warning' : 'alert-error'; ?>" id="php-alert">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php" method="post" autocomplete="off" id="login-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                
                <div class="input-group">
                    <svg class="input-icon" width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path></svg>
                    <input type="email" id="email" name="email" required 
                           placeholder="Email Address"
                           autocomplete="email"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email'], ENT_QUOTES, 'UTF-8') : ''; ?>">
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
                        Keep me logged in
                    </label>
                </div>
                
                <div class="recaptcha-wrapper">
                    <div class="g-recaptcha" data-sitekey="<?php echo RECAPTCHA_SITE_KEY; ?>"></div>
                </div>
                
                <button type="submit" name="login" class="btn-submit">
                    Sign In
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
            // Focus on email input automatically
            document.getElementById('email').focus();
            
            // Check for URL parameters to display dynamic messages
            const urlParams = new URLSearchParams(window.location.search);
            const messages = {
                'expired': { text: 'Your session has expired. Please login again.', type: 'warning' },
                'logout': { text: 'You have been successfully logged out.', type: 'success' },
                'registered': { text: 'Registration successful! Please login.', type: 'success' },
                'timeout': { text: 'Your session timed out due to inactivity.', type: 'warning' },
                'unauthorized': { text: 'Please login to access that page.', type: 'error' },
                'payment_required': { text: 'Payment verification required. Please complete your fees payment.', type: 'warning' },
                'payment_pending': { text: 'Your payment is pending verification. You will be notified once verified.', type: 'warning' },
                'payment_submitted': { text: 'Payment submitted for verification. You can login once verified.', type: 'success' }
            };
            
            for (const [param, data] of Object.entries(messages)) {
                if (urlParams.has(param)) {
                    // Check if there is already an alert from PHP
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
                    
                    // Prevent form resubmission
                    if (window.history.replaceState) {
                        window.history.replaceState(null, null, window.location.href);
                    }
                    break;
                }
            }

            // Client side validation
            const form = document.getElementById('login-form');
            form.addEventListener('submit', function(e) {
                const email = document.getElementById('email').value.trim();
                const password = document.getElementById('password').value.trim();
                
                if (!email || !email.includes('@') || !password) {
                    e.preventDefault();
                    // Shake animation for the whole form wrapper
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
        });
    </script>
</body>
</html>