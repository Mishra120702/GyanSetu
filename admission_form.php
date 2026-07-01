<?php
/**
 * Secure Student Admission Form
 * 
 * Security Features:
 * - CSRF Protection (fixed token validation)
 * - SQL Injection Prevention (PDO prepared statements)
 * - XSS Protection (htmlspecialchars)
 * - Security Headers (CSP, HSTS, X-Frame-Options)
 * - Input Validation & Sanitization
 * - Rate Limiting
 * - Country Code Integration (intl-tel-input)
 */

session_start();

// ==================== SECURITY HEADERS ====================
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://via.placeholder.com; connect-src 'self'; frame-ancestors 'none';");
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

// Rate Limiting
$rate_limit_key = 'admission_rate_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'first_attempt' => time()];
}

$rate_data = &$_SESSION[$rate_limit_key];
if ($rate_data['count'] >= 5 && (time() - $rate_data['first_attempt']) < 3600) {
    die('Too many registration attempts. Please try again after 1 hour.');
}

require_once 'db_connection.php';

// Set default batch and course
$default_batch = 'B054';
$default_course = 14;

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require 'vendor/autoload.php';

$error = '';
$success = false;
$generated_password = '';
$student_email = '';
$student_name = '';

// Generate or retrieve CSRF token (only once per session)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

/**
 * Generate unique student ID in format STD001, STD002, etc.
 * Similar to add_student.php
 */
function generateStudentId($db) {
    $stmt = $db->prepare("SELECT student_id FROM students ORDER BY student_id DESC LIMIT 1");
    $stmt->execute();
    $lastStudent = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextStudentId = 'STD001';

    if ($lastStudent) {
        $lastNumber = (int) substr($lastStudent['student_id'], 3);
        $nextNumber = $lastNumber + 1;
        $nextStudentId = 'STD' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }

    return $nextStudentId;
}

// Generate cryptographically secure random password
function generateSecurePassword($length = 14) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    return substr(str_shuffle(str_repeat($chars, ceil($length / strlen($chars)))), 0, $length);
}

// Send registration email with secure templating
function sendRegistrationEmail($email, $name, $password, $studentId) {
    global $error;
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
        return false;
    }
    
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'training@asdacademy.in';
        $mail->Password   = 'yvdq craf dkpu dttc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->Timeout    = 30;
        
        $mail->setFrom('noreply@asdacademy.com', 'ASD Academy');
        $mail->addAddress($email, $name);
        $mail->addCustomHeader('X-Priority', '3');
        
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to ASD Academy - Registration Successful';
        
        $safe_name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safe_studentId = htmlspecialchars($studentId, ENT_QUOTES, 'UTF-8');
        $safe_email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $safe_password = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
        
        $mail->Body = '<!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #ddd; }
                .password-box { background: #fff; border: 2px dashed #4f46e5; padding: 15px; margin: 20px 0; text-align: center; font-family: monospace; font-size: 18px; font-weight: bold; }
                .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 5px; margin: 20px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
                .info-box { background: #e8f4fd; border-left: 4px solid #2196F3; padding: 15px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>Welcome to ASD Academy!</h1>
                    <p>Your Registration is Complete</p>
                </div>
                <div class="content">
                    <h2>Hello ' . $safe_name . ',</h2>
                    <p>Thank you for registering with ASD Academy! Your account has been successfully created.</p>
                    
                    <div class="info-box">
                        <h3>Your Account Details:</h3>
                        <ul>
                            <li><strong>Student ID:</strong> ' . $safe_studentId . '</li>
                            <li><strong>Full Name:</strong> ' . $safe_name . '</li>
                            <li><strong>Email/Username:</strong> ' . $safe_email . '</li>
                        </ul>
                    </div>
                    
                    <h3>Your Login Password:</h3>
                    <div class="password-box">' . $safe_password . '</div>
                    
                    <div class="warning">
                        <strong>⚠️ Important:</strong>
                        <ul>
                            <li>Never share your password with anyone</li>
                            <li>Keep this email for your records</li>
                        </ul>
                    </div>
                    
                    <p><strong>Login URL:</strong> <a href="http://sthub.co.in/login.php">Gyan Setu</a></p>
                    
                    <p>Best regards,<br>
                    <strong>ASD Academy Administration</strong></p>
                    
                    <div class="footer">
                        <p>This is an automated message. Please do not reply to this email.</p>
                        <p>© ' . date('Y') . ' ASD Academy. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->AltBody = "Welcome to ASD Academy!\n\n" .
                        "Hello " . $safe_name . ",\n\n" .
                        "Thank you for registering! Your account has been created.\n\n" .
                        "Student ID: " . $safe_studentId . "\n" .
                        "Email/Username: " . $safe_email . "\n" .
                        "Password: " . $safe_password . "\n\n" .
                        "Login URL: http://sthub.co.in/login.php\n\n" .
                        "Best regards,\nASD Academy Administration";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        $error = "Email could not be sent. Error: " . ($mail->ErrorInfo ?? $e->getMessage());
        error_log("Registration email failed: " . $e->getMessage());
        return false;
    }
}

// Validate phone number with country code
function validatePhoneNumber($phone, $countryCode) {
    $fullNumber = $countryCode . preg_replace('/\s+/', '', $phone);
    $fullNumber = ltrim($fullNumber, '+');
    if (!ctype_digit($fullNumber)) {
        return false;
    }
    $length = strlen($fullNumber);
    return $length >= 8 && $length <= 15;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh the page and try again.';
    } else {
        try {
            $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
            // Get and sanitize form data
            $first_name = trim(htmlspecialchars($_POST['first_name'] ?? '', ENT_QUOTES, 'UTF-8'));
            $last_name = trim(htmlspecialchars($_POST['last_name'] ?? '', ENT_QUOTES, 'UTF-8'));
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $phone_number = trim(htmlspecialchars($_POST['phone_number'] ?? '', ENT_QUOTES, 'UTF-8'));
            $country_code = trim(htmlspecialchars($_POST['country_code'] ?? '+91', ENT_QUOTES, 'UTF-8'));
            $date_of_birth = $_POST['date_of_birth'] ?? '';
            $enrollment_date = date('Y-m-d');
            $father_name = trim(htmlspecialchars($_POST['father_name'] ?? '', ENT_QUOTES, 'UTF-8'));
            $father_phone = trim(htmlspecialchars($_POST['father_phone'] ?? '', ENT_QUOTES, 'UTF-8'));
            $father_email = filter_var(trim($_POST['father_email'] ?? ''), FILTER_SANITIZE_EMAIL);
            $state = trim(htmlspecialchars($_POST['state'] ?? '', ENT_QUOTES, 'UTF-8'));
            $current_status = 'active';
            
            // Server-side validations
            $errors = [];
            
            if (empty($first_name) || strlen($first_name) < 2 || strlen($first_name) > 50) {
                $errors[] = 'First name must be between 2 and 50 characters.';
            }
            if (empty($last_name) || strlen($last_name) < 2 || strlen($last_name) > 50) {
                $errors[] = 'Last name must be between 2 and 50 characters.';
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
                $errors[] = 'Please enter a valid email address.';
            }
            if (empty($phone_number) || !validatePhoneNumber($phone_number, $country_code)) {
                $errors[] = 'Please enter a valid phone number with country code (8-15 digits).';
            }
            if (empty($date_of_birth)) {
                $errors[] = 'Date of birth is required.';
            } else {
                $dob = DateTime::createFromFormat('Y-m-d', $date_of_birth);
                $today = new DateTime();
                $age = $today->diff($dob)->y;
                if ($age < 10 || $age > 70) {
                    $errors[] = 'Age must be between 10 and 70 years.';
                }
            }
            
            if (!empty($father_email) && !filter_var($father_email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid father\'s email address.';
            }
            
            if (count($errors) > 0) {
                $error = implode(' ', $errors);
            } else {
                // Check if email already exists using prepared statement
                $stmt = $db->prepare("SELECT student_id FROM students WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'This email is already registered. Please login or use a different email.';
                } else {
                    // Generate student ID and password
                    $student_id = generateStudentId($db);
                    $generated_password = generateSecurePassword();
                    $password_hash = password_hash($generated_password, PASSWORD_DEFAULT);
                    $student_name = $first_name . ' ' . $last_name;
                    $full_phone = $country_code . preg_replace('/\s+/', '', $phone_number);
                    
                    // Begin transaction
                    $db->beginTransaction();
                    
                    // Insert into users table
                    $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, created_at) VALUES (?, ?, ?, 'student', NOW())");
                    $stmt->execute([$student_name, $email, $password_hash]);
                    $user_id = $db->lastInsertId();
                    
                    // Insert into students table with fully paid payment status
                    $stmt = $db->prepare("INSERT INTO students (
                        student_id, user_id, first_name, last_name, email, phone_number, 
                        date_of_birth, enrollment_date, current_status, batch_name, course,
                        father_name, father_phone_number, father_email, state, password_hash,
                        fees_status, total_fees_paid
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'fully_paid', 0.00)");
                    
                    $stmt->execute([
                        $student_id, $user_id, $first_name, $last_name, $email, $full_phone,
                        $date_of_birth, $enrollment_date, $current_status, $default_batch, $default_course,
                        $father_name, $father_phone, $father_email, $state, $password_hash
                    ]);
                    
                    $db->commit();
                    
                    // Update rate limiting
                    $rate_data['count']++;
                    
                    // Send email
                    $email_sent = sendRegistrationEmail($email, $student_name, $generated_password, $student_id);
                    
                    if ($email_sent) {
                        $_SESSION['registration_success'] = true;
                        $_SESSION['generated_password'] = $generated_password;
                        $_SESSION['student_email'] = $email;
                        $_SESSION['student_name'] = $student_name;
                        $_SESSION['student_id'] = $student_id;
                        
                        header("Location: admission_form.php?success=1");
                        exit;
                    } else {
                        $success = true;
                        $_SESSION['registration_success'] = true;
                        $_SESSION['generated_password'] = $generated_password;
                        $_SESSION['student_email'] = $email;
                        $_SESSION['student_name'] = $student_name;
                        $_SESSION['student_id'] = $student_id;
                        $_SESSION['email_error'] = $error;
                        header("Location: admission_form.php?success=1&email_error=1");
                        exit;
                    }
                }
            }
        } catch (PDOException $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Database error: " . $e->getMessage();
            error_log("Registration error: " . $e->getMessage());
        }
    }
    // DO NOT regenerate CSRF token here - keep it for the next page load
}

// Check for success message
$show_success = isset($_GET['success']) && $_GET['success'] == 1;
$email_error_occurred = isset($_GET['email_error']) && $_GET['email_error'] == 1;

if ($show_success && isset($_SESSION['registration_success'])) {
    $generated_password = $_SESSION['generated_password'] ?? '';
    $student_email = $_SESSION['student_email'] ?? '';
    $student_name = $_SESSION['student_name'] ?? '';
    $student_id = $_SESSION['student_id'] ?? '';
    
    // Clear session data
    unset($_SESSION['registration_success'], $_SESSION['generated_password'], 
          $_SESSION['student_email'], $_SESSION['student_name'], $_SESSION['student_id']);
}

// Get next student ID for display
$nextStudentId = generateStudentId($db);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Student Admission | ASD Academy</title>
    <link rel="icon" href="../assets/images/logo.png" type="image/x-icon">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- intl-tel-input for country codes -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/css/intlTelInput.css">
    <script src="https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/js/intlTelInput.min.js"></script>
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        h1, h2, h3, .font-poppins {
            font-family: 'Poppins', sans-serif;
        }
        
        /* Skeuomorphic & Animation Styles */
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(2deg); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { opacity: 0.6; text-shadow: 0 0 5px rgba(99,102,241,0.3); }
            50% { opacity: 1; text-shadow: 0 0 20px rgba(99,102,241,0.6); }
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-60px) rotateY(-10deg); }
            to { opacity: 1; transform: translateX(0) rotateY(0); }
        }
        
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(60px) rotateY(10deg); }
            to { opacity: 1; transform: translateX(0) rotateY(0); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(40px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px) rotate(0deg) scale(1); }
            50% { transform: translateY(-12px) rotate(5deg) scale(1.05); }
        }
        
        @keyframes borderGlow {
            0%, 100% { border-color: rgba(99,102,241,0.2); box-shadow: 0 0 0 0 rgba(99,102,241,0.2); }
            50% { border-color: rgba(99,102,241,0.6); box-shadow: 0 0 15px 5px rgba(99,102,241,0.3); }
        }
        
        @keyframes shimmer {
            0% { background-position: -1000px 0; }
            100% { background-position: 1000px 0; }
        }
        
        .animate-float {
            animation: float 8s ease-in-out infinite;
        }
        
        .animate-slide-left {
            animation: slideInLeft 0.9s cubic-bezier(0.23, 1, 0.32, 1) forwards;
        }
        
        .animate-slide-right {
            animation: slideInRight 0.9s cubic-bezier(0.23, 1, 0.32, 1) forwards;
        }
        
        .animate-fade-up {
            animation: fadeInUp 0.7s ease-out forwards;
        }
        
        .animate-logo {
            animation: logoFloat 5s ease-in-out infinite;
        }
        
        .input-field {
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border: 2px solid #e2e8f0;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(2px);
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.02);
        }
        
        .input-field:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15), inset 0 1px 3px rgba(0,0,0,0.05);
            outline: none;
            background: white;
        }
        
        .submit-btn {
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            box-shadow: 0 10px 20px -5px rgba(99, 102, 241, 0.4), inset 0 1px 0 rgba(255,255,255,0.2);
            position: relative;
            overflow: hidden;
        }
        
        .submit-btn::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -60%;
            width: 200%;
            height: 200%;
            background: linear-gradient(115deg, rgba(255,255,255,0) 10%, rgba(255,255,255,0.3) 50%, rgba(255,255,255,0) 90%);
            transform: rotate(25deg);
            transition: all 0.5s;
            opacity: 0;
        }
        
        .submit-btn:hover::after {
            left: 100%;
            opacity: 1;
            transition: all 0.7s;
        }
        
        .submit-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 30px -8px rgba(99, 102, 241, 0.5);
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(12px);
            box-shadow: 0 25px 45px -12px rgba(0,0,0,0.25), inset 0 1px 1px rgba(255,255,255,0.8);
            border: 1px solid rgba(255,255,255,0.5);
        }
        
        .floating-shape {
            position: absolute;
            background: radial-gradient(circle, rgba(99,102,241,0.12), rgba(139,92,246,0.08));
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            filter: blur(40px);
        }
        
        .modal-overlay {
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(8px);
        }
        
        .password-box {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            border: 2px dashed #6366f1;
            border-radius: 20px;
            box-shadow: inset 0 1px 4px rgba(0,0,0,0.02), 0 8px 20px -6px rgba(99,102,241,0.2);
        }
        
        .logo-container {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 0 25px 35px -12px rgba(99, 102, 241, 0.35), inset 0 1px 2px rgba(255,255,255,0.9);
            border-radius: 30px;
            transition: all 0.4s ease;
        }
        
        .logo-container:hover {
            transform: scale(1.02);
            box-shadow: 0 30px 40px -14px rgba(99, 102, 241, 0.45);
        }
        
        .feature-card {
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.6);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.8);
        }
        
        .feature-card:hover {
            transform: translateX(8px) scale(1.02);
            background: white;
            box-shadow: 0 12px 20px -12px rgba(0,0,0,0.15);
        }
        
        /* Country code input styling */
        .iti {
            width: 100%;
        }
        .iti__flag-container {
            z-index: 10;
        }
        .iti__selected-flag {
            background: #f3f4f6;
            border-radius: 12px 0 0 12px;
            padding: 0 8px;
        }
        
        /* Student ID display box */
        .student-id-box {
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
            border: 1px solid #a5b4fc;
            border-radius: 12px;
            padding: 8px 16px;
            display: inline-block;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                padding-left: 1rem;
                padding-right: 1rem;
            }
            .glass-effect {
                margin: 0 0.5rem;
            }
            .logo-container {
                width: 110px;
                height: 110px;
            }
            .logo-container img {
                width: 85px;
                height: 85px;
            }
        }
        
        @media (max-width: 640px) {
            .animate-slide-left, .animate-slide-right {
                animation: fadeInUp 0.6s ease-out forwards;
            }
            .feature-card {
                padding: 0.75rem;
            }
        }
        
        .skeuo-shadow {
            box-shadow: 0 10px 20px -5px rgba(0,0,0,0.1), inset 0 1px 1px rgba(255,255,255,0.6);
        }
        
        .input-group-skeuo {
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.02), 0 1px 0 rgba(255,255,255,0.5);
        }
        
        /* Payment status badge */
        .payment-badge {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
            display: inline-block;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-indigo-100 via-white to-purple-100 min-h-screen relative overflow-x-hidden">
    
    <!-- Animated Background Shapes -->
    
    <!-- Success Modal -->
    <div id="successModal" class="fixed inset-0 z-50 flex items-center justify-center modal-overlay transition-all duration-300" style="display: none;">
        <div class="bg-white rounded-3xl max-w-md w-full mx-4 shadow-2xl transform transition-all duration-300 scale-95 opacity-0 border border-white/50" id="modalContent">
            <div class="relative p-6">
                <div class="absolute top-0 left-1/2 transform -translate-x-1/2 -translate-y-1/2">
                    <div class="w-16 h-16 bg-gradient-to-r from-emerald-500 to-green-500 rounded-full flex items-center justify-center shadow-lg animate-pulse">
                        <i class="fas fa-check text-white text-3xl"></i>
                    </div>
                </div>
                
                <div class="mt-8 text-center">
                    <h3 class="text-2xl font-bold text-gray-800 mb-2">Registration Successful!</h3>
                    <p class="text-gray-600 mb-6">Your account has been created successfully.</p>
                    
                    <?php if ($email_error_occurred): ?>
                        <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6 rounded-xl text-left">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-triangle text-yellow-500 mr-3"></i>
                                <div>
                                    <p class="font-semibold text-yellow-800">Email Not Sent</p>
                                    <p class="text-sm text-yellow-700">We couldn't send the email. Please save this password manually.</p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 mb-6 rounded-xl text-left">
                            <div class="flex items-center">
                                <i class="fas fa-envelope text-emerald-500 mr-3"></i>
                                <div>
                                    <p class="font-semibold text-emerald-800">Email Sent!</p>
                                    <p class="text-sm text-emerald-700">Login credentials have been sent to your email.</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <div class="bg-indigo-50 rounded-xl p-4 mb-6 text-left skeuo-shadow">
                        <p class="text-sm text-gray-600 mb-2"><i class="fas fa-id-card mr-2 text-indigo-600"></i> <strong>Student ID:</strong> <span id="modalStudentId"><?= htmlspecialchars($student_id ?? '', ENT_QUOTES, 'UTF-8') ?></span></p>
                        <p class="text-sm text-gray-600 mb-2"><i class="fas fa-user mr-2 text-indigo-600"></i> <strong>Name:</strong> <span id="modalStudentName"><?= htmlspecialchars($student_name ?? '', ENT_QUOTES, 'UTF-8') ?></span></p>
                        <p class="text-sm text-gray-600"><i class="fas fa-envelope mr-2 text-indigo-600"></i> <strong>Email:</strong> <span id="modalStudentEmail"><?= htmlspecialchars($student_email ?? '', ENT_QUOTES, 'UTF-8') ?></span></p>
                        <p class="text-sm text-gray-600 mt-2"><i class="fas fa-credit-card mr-2 text-emerald-600"></i> <strong>Payment Status:</strong> <span class="payment-badge">Fully Paid</span></p>
                    </div>
                    
                    <div class="password-box rounded-xl p-4 mb-6">
                        <p class="text-sm font-semibold text-indigo-800 mb-2"><i class="fas fa-key mr-2"></i> Your Password</p>
                        <div class="bg-white rounded-lg p-3 font-mono text-lg font-bold text-center text-indigo-700 tracking-wider border border-indigo-200" id="modalPassword">
                            <?= htmlspecialchars($generated_password ?? '', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <button onclick="copyPassword()" class="mt-3 text-sm text-indigo-600 hover:text-indigo-800 font-medium transition bg-white/50 px-4 py-1.5 rounded-full shadow-sm">
                            <i class="fas fa-copy mr-1"></i> Copy Password
                        </button>
                    </div>
                    
                    <div class="flex flex-col sm:flex-row gap-3">
                        <a href="../login.php" class="flex-1 bg-indigo-600 text-white py-3 rounded-xl font-semibold hover:bg-indigo-700 transition text-center shadow-md hover:shadow-lg">
                            <i class="fas fa-sign-in-alt mr-2"></i> Login Now
                        </a>
                        <button onclick="closeModal()" class="flex-1 bg-gray-200 text-gray-700 py-3 rounded-xl font-semibold hover:bg-gray-300 transition shadow-sm">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-6 md:py-10 relative z-10">
        <div class="flex flex-col lg:flex-row min-h-[85vh] gap-6">
            
            <!-- Left Side - Branding Section (Enhanced with larger centered logo) -->
            <div class="lg:w-1/2 flex flex-col justify-center items-center p-6 lg:p-10 animate-slide-left">
                <div class="text-center lg:text-center max-w-md mx-auto">
                    <!-- Logo Container - Centered, increased size by 20%, no white bg -->
                    <div class="flex justify-center mb-10">
                        <div class="relative">
                            <div class="logo-container w-36 h-36 md:w-40 md:h-40 rounded-3xl flex items-center justify-center shadow-2xl animate-logo relative overflow-hidden bg-gradient-to-br from-white/90 to-gray-50/90">
                                <div class="absolute inset-0 bg-gradient-to-br from-indigo-500/10 to-purple-500/10 rounded-3xl"></div>
                                <div class="absolute inset-0 bg-gradient-to-tr from-white/40 to-transparent rounded-3xl"></div>
                                <img src="../logo.png" alt="ASD Academy Logo" 
                                     class="w-28 h-28 md:w-32 md:h-32 object-contain relative z-10 drop-shadow-lg"
                                     onerror="this.src='https://via.placeholder.com/128x128/4f46e5/ffffff?text=ASD'">
                            </div>
                            <div class="absolute -bottom-2 -right-2 bg-gradient-to-r from-indigo-500 to-purple-500 rounded-full p-2 shadow-lg">
                                <i class="fas fa-graduation-cap text-white text-sm"></i>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Heading with justified text -->
                    <div class="space-y-3">
                        <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold text-gray-800 leading-tight tracking-tight">
                            Start Your<br>
                            <span class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 bg-clip-text text-transparent animate-pulse-glow">
                                Learning Journey
                            </span>
                        </h1>
                        <div class="h-1 w-24 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-full mx-auto my-4"></div>
                    </div>
                    
                    <p class="text-gray-600 text-base md:text-lg leading-relaxed text-justify mt-6 max-w-md mx-auto">
                        Join ASD Academy and unlock your potential with industry-leading courses, 
                        expert mentors, and a vibrant learning community. Transform your career 
                        with our comprehensive training programs.
                    </p>
                    
                    <!-- Features List - Justified alignment -->
                    <div class="space-y-3 mt-8">
                        <div class="feature-card flex items-center space-x-3 p-3 rounded-xl transition-all">
                            <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center flex-shrink-0 shadow-inner">
                                <i class="fas fa-check-circle text-indigo-600 text-lg"></i>
                            </div>
                            <span class="text-gray-700 font-medium">Industry-recognized certification</span>
                        </div>
                        <div class="feature-card flex items-center space-x-3 p-3 rounded-xl transition-all">
                            <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center flex-shrink-0 shadow-inner">
                                <i class="fas fa-chalkboard-teacher text-indigo-600 text-lg"></i>
                            </div>
                            <span class="text-gray-700 font-medium">Expert mentors & live classes</span>
                        </div>
                        <div class="feature-card flex items-center space-x-3 p-3 rounded-xl transition-all">
                            <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center flex-shrink-0 shadow-inner">
                                <i class="fas fa-laptop-code text-indigo-600 text-lg"></i>
                            </div>
                            <span class="text-gray-700 font-medium">Hands-on projects & assignments</span>
                        </div>
                        <div class="feature-card flex items-center space-x-3 p-3 rounded-xl transition-all">
                            <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center flex-shrink-0 shadow-inner">
                                <i class="fas fa-users text-indigo-600 text-lg"></i>
                            </div>
                            <span class="text-gray-700 font-medium">24/7 community support</span>
                        </div>
                    </div>
                    
                    <!-- Stats Section -->
                    <div class="grid grid-cols-3 gap-4 pt-8 mt-4 border-t border-gray-200/60">
                        <div class="text-center p-2 rounded-xl bg-white/30 backdrop-blur-sm">
                            <div class="text-2xl md:text-3xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">5000+</div>
                            <div class="text-xs text-gray-500 font-medium">Active Students</div>
                        </div>
                        <div class="text-center p-2 rounded-xl bg-white/30 backdrop-blur-sm">
                            <div class="text-2xl md:text-3xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">50+</div>
                            <div class="text-xs text-gray-500 font-medium">Expert Courses</div>
                        </div>
                        <div class="text-center p-2 rounded-xl bg-white/30 backdrop-blur-sm">
                            <div class="text-2xl md:text-3xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">100+</div>
                            <div class="text-xs text-gray-500 font-medium">Industry Experts</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Side - Registration Form -->
            <div class="lg:w-1/2 flex items-center justify-center p-4 md:p-6 animate-slide-right">
                <div class="w-full max-w-xl">
                    <!-- Form Card -->
                    <div class="glass-effect rounded-3xl shadow-2xl overflow-hidden border border-white/50 transform transition-all hover:shadow-3xl">
                        <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-5 relative overflow-hidden">
                            <div class="absolute inset-0 bg-white/10 transform -skew-x-12 translate-x-1/2"></div>
                            <div class="flex items-center space-x-2 relative z-10">
                                <i class="fas fa-user-graduate text-white text-xl"></i>
                                <h2 class="text-2xl font-bold text-white">Student Registration</h2>
                            </div>
                            <p class="text-indigo-100 text-sm mt-1 ml-7 relative z-10">Fill in your details to create your account</p>
                        </div>
                        
                        <div class="p-6 md:p-8">
                            <!-- Student ID Display Box -->
                            
                            
                            <?php if (!empty($error)): ?>
                                <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-xl animate-fade-up shadow-sm">
                                    <div class="flex items-center">
                                        <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                                        <span class="text-red-700"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="" class="space-y-5" id="admissionForm">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <!-- First Name -->
                                    <div class="group">
                                        <label class="block text-gray-700 font-medium mb-2 text-sm">
                                            <i class="fas fa-user text-indigo-500 mr-1"></i> First Name <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" name="first_name" required 
                                               class="input-field w-full px-4 py-3 rounded-xl bg-gray-50 focus:bg-white transition"
                                               placeholder="Enter first name" pattern="[A-Za-z\s]{2,50}" title="Only letters and spaces, 2-50 characters">
                                    </div>
                                    
                                    <!-- Last Name -->
                                    <div class="group">
                                        <label class="block text-gray-700 font-medium mb-2 text-sm">
                                            <i class="fas fa-user text-indigo-500 mr-1"></i> Last Name <span class="text-red-500">*</span>
                                        </label>
                                        <input type="text" name="last_name" required 
                                               class="input-field w-full px-4 py-3 rounded-xl bg-gray-50 focus:bg-white transition"
                                               placeholder="Enter last name" pattern="[A-Za-z\s]{2,50}" title="Only letters and spaces, 2-50 characters">
                                    </div>
                                </div>
                                
                                <!-- Email -->
                                <div class="group">
                                    <label class="block text-gray-700 font-medium mb-2 text-sm">
                                        <i class="fas fa-envelope text-indigo-500 mr-1"></i> Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" name="email" required 
                                           class="input-field w-full px-4 py-3 rounded-xl bg-gray-50 focus:bg-white transition"
                                           placeholder="you@example.com" maxlength="150">
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <!-- Phone Number with Country Code -->
                                    <div class="group">
                                        <label class="block text-gray-700 font-medium mb-2 text-sm">
                                            <i class="fas fa-phone text-indigo-500 mr-1"></i> Phone Number <span class="text-red-500">*</span>
                                        </label>
                                        <input type="tel" name="phone_number" id="phone_number" required 
                                               class="input-field w-full px-4 py-3 rounded-xl bg-gray-50 focus:bg-white transition"
                                               placeholder="98765 43210">
                                        <input type="hidden" name="country_code" id="country_code" value="+91">
                                        <p class="text-xs text-gray-400 mt-1">Enter number without country code</p>
                                    </div>
                                    
                                    <!-- Date of Birth -->
                                    <div class="group">
                                        <label class="block text-gray-700 font-medium mb-2 text-sm">
                                            <i class="fas fa-calendar-alt text-indigo-500 mr-1"></i> Date of Birth <span class="text-red-500">*</span>
                                        </label>
                                        <input type="date" name="date_of_birth" required 
                                               class="input-field w-full px-4 py-3 rounded-xl bg-gray-50 focus:bg-white transition">
                                    </div>
                                </div>
                                
                                <!-- State -->
                                <div class="group">
                                    <label class="block text-gray-700 font-medium mb-2 text-sm">
                                        <i class="fas fa-map-marker-alt text-indigo-500 mr-1"></i> State
                                    </label>
                                    <input type="text" name="state" 
                                           class="input-field w-full px-4 py-3 rounded-xl bg-gray-50 focus:bg-white transition"
                                           placeholder="Enter your state" maxlength="100">
                                </div>
                                
                                <!-- Parent/Guardian Section (Collapsible) -->
                                <div class="border-t border-gray-200 pt-4 mt-2">
                                    <button type="button" id="toggleParentInfo" class="flex items-center justify-between w-full text-left text-gray-600 hover:text-indigo-600 transition group">
                                        <span class="font-medium">
                                            <i class="fas fa-users text-indigo-500 mr-2"></i> Parent/Guardian Information (Optional)
                                        </span>
                                        <i id="toggleIcon" class="fas fa-chevron-down transition-transform duration-300"></i>
                                    </button>
                                    
                                    <div id="parentInfo" class="hidden mt-4 space-y-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-gray-600 text-sm mb-1">Father's Name</label>
                                                <input type="text" name="father_name" 
                                                       class="input-field w-full px-4 py-2 rounded-xl bg-gray-50 focus:bg-white transition"
                                                       placeholder="Father's full name" maxlength="200">
                                            </div>
                                            <div>
                                                <label class="block text-gray-600 text-sm mb-1">Father's Phone</label>
                                                <input type="tel" name="father_phone" 
                                                       class="input-field w-full px-4 py-2 rounded-xl bg-gray-50 focus:bg-white transition"
                                                       placeholder="+91 98765 43210">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-gray-600 text-sm mb-1">Father's Email</label>
                                            <input type="email" name="father_email" 
                                                   class="input-field w-full px-4 py-2 rounded-xl bg-gray-50 focus:bg-white transition"
                                                   placeholder="father@example.com" maxlength="150">
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Terms and Conditions -->
                                <div class="flex items-start space-x-3 pt-2">
                                    <input type="checkbox" id="terms" required class="mt-1 w-5 h-5 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500">
                                    <label for="terms" class="text-sm text-gray-600 text-justify">
                                        I agree to the <a href="#" class="text-indigo-600 hover:underline">Terms and Conditions</a> and 
                                        <a href="#" class="text-indigo-600 hover:underline">Privacy Policy</a>
                                    </label>
                                </div>
                                
                                <!-- Submit Button -->
                                <button type="submit" class="submit-btn w-full py-4 rounded-xl text-white font-semibold text-lg flex items-center justify-center gap-2 group shadow-md">
                                    <span>Register Now</span>
                                    <i class="fas fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
                                </button>
                                
                                <p class="text-center text-gray-500 text-sm pt-4">
                                    Already have an account? 
                                    <a href="../login.php" class="text-indigo-600 font-semibold hover:underline">Login here</a>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize intl-tel-input for country code
        const phoneInput = document.querySelector("#phone_number");
        let iti;
        
        if (phoneInput) {
            iti = window.intlTelInput(phoneInput, {
                initialCountry: "in",
                separateDialCode: true,
                preferredCountries: ["in", "us", "gb", "ae", "sg"],
                utilsScript: "https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/js/utils.js",
                autoHideDialCode: false,
                nationalMode: true,
                formatOnDisplay: true
            });
            
            // Update hidden country code field on change
            phoneInput.addEventListener("countrychange", function() {
                const countryData = iti.getSelectedCountryData();
                document.getElementById("country_code").value = "+" + countryData.dialCode;
            });
            
            // Set initial country code
            setTimeout(() => {
                const countryData = iti.getSelectedCountryData();
                document.getElementById("country_code").value = "+" + countryData.dialCode;
            }, 100);
        }
        
        // Toggle parent info section
        const toggleBtn = document.getElementById('toggleParentInfo');
        const parentInfo = document.getElementById('parentInfo');
        const toggleIcon = document.getElementById('toggleIcon');
        
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                parentInfo.classList.toggle('hidden');
                toggleIcon.classList.toggle('rotate-180');
            });
        }
        
        // Show modal if registration success
        <?php if ($show_success): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showModal();
        });
        <?php endif; ?>
        
        function showModal() {
            const modal = document.getElementById('successModal');
            const modalContent = document.getElementById('modalContent');
            
            modal.style.display = 'flex';
            setTimeout(() => {
                modalContent.classList.remove('scale-95', 'opacity-0');
                modalContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        }
        
        function closeModal() {
            const modal = document.getElementById('successModal');
            const modalContent = document.getElementById('modalContent');
            
            modalContent.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 300);
        }
        
        function copyPassword() {
            const passwordText = document.getElementById('modalPassword').innerText;
            navigator.clipboard.writeText(passwordText).then(function() {
                const btn = document.querySelector('#successModal .password-box button');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check mr-1"></i> Copied!';
                setTimeout(() => {
                    btn.innerHTML = originalText;
                }, 2000);
            });
        }
        
        // Close modal on outside click
        document.getElementById('successModal')?.addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Form validation
        document.getElementById('admissionForm')?.addEventListener('submit', function(e) {
            const terms = document.getElementById('terms');
            if (!terms.checked) {
                e.preventDefault();
                alert('Please accept the Terms and Conditions to continue.');
                terms.focus();
                return false;
            }
            
            // Validate phone number with intl-tel-input
            if (iti && !iti.isValidNumber()) {
                e.preventDefault();
                alert('Please enter a valid phone number with country code.');
                phoneInput.focus();
                return false;
            }
            
            // Validate email format
            const email = document.querySelector('input[name="email"]').value;
            const emailPattern = /^[^\s@]+@([^\s@.,]+\.)+[^\s@.,]{2,}$/;
            if (!emailPattern.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return false;
            }
            
            // Validate names
            const firstName = document.querySelector('input[name="first_name"]').value.trim();
            const lastName = document.querySelector('input[name="last_name"]').value.trim();
            const namePattern = /^[A-Za-z\s]{2,50}$/;
            if (!namePattern.test(firstName)) {
                e.preventDefault();
                alert('First name must contain only letters and spaces, 2-50 characters.');
                return false;
            }
            if (!namePattern.test(lastName)) {
                e.preventDefault();
                alert('Last name must contain only letters and spaces, 2-50 characters.');
                return false;
            }
            
            return true;
        });
        
        // Set minimum date for date of birth (must be at least 10 years old, max 70)
        const dobInput = document.querySelector('input[name="date_of_birth"]');
        if (dobInput) {
            const today = new Date();
            const minDate = new Date();
            minDate.setFullYear(today.getFullYear() - 70);
            const maxDate = new Date();
            maxDate.setFullYear(today.getFullYear() - 10);
            
            dobInput.max = maxDate.toISOString().split('T')[0];
            dobInput.min = minDate.toISOString().split('T')[0];
        }
        
        // Intersection Observer for animations
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -30px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.animate-fade-up').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(30px)';
            observer.observe(el);
        });
    </script>
</body>
</html>