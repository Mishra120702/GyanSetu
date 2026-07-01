<?php
/**
 * user_management.php
 * Comprehensive User Management System
 * Includes management of all member types: Students, Mentors, Sales, Accounts, Admins
 */

require_once '../db_connection.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Include PHPMailer for email functionality
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require '../vendor/autoload.php';

// Handle form submissions
$message = '';
$message_type = '';
$errors = [];

// Get user statistics
$stats_stmt = $db->query("SELECT 
    role,
    COUNT(*) as count,
    SUM(status = 'active') as active,
    SUM(status = 'inactive') as inactive,
    SUM(account_locked = 1) as locked
    FROM users 
    GROUP BY role");
$role_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_stats = [
    'all' => ['count' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0],
    'admin' => ['count' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0],
    'mentor' => ['count' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0],
    'student' => ['count' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0],
    'sales' => ['count' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0],
    'accounts' => ['count' => 0, 'active' => 0, 'inactive' => 0, 'locked' => 0]
];

foreach ($role_stats as $stat) {
    $role = $stat['role'];
    $total_stats[$role] = $stat;
    $total_stats['all']['count'] += $stat['count'];
    $total_stats['all']['active'] += $stat['active'];
    $total_stats['all']['inactive'] += $stat['inactive'];
    $total_stats['all']['locked'] += $stat['locked'];
}

// Handle form submission for adding new user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    try {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $status = 'active'; // Default status
        $send_email = isset($_POST['send_email']) && $_POST['send_email'] === 'yes';
        
        // Password handling
        $password = '';
        if (!empty($_POST['generated_password'])) {
            $password = $_POST['generated_password'];
        } else if (!empty($_POST['password'])) {
            $password = $_POST['password'];
        } else {
            // Generate random password if not provided
            $password = generateRandomPassword(12);
        }
        
        // Validation
        $errors = validateUserData($name, $email, $password);
        
        if (empty($errors)) {
            // Check if email already exists
            $check_email = $db->prepare("SELECT id FROM users WHERE email = ?");
            $check_email->execute([$email]);
            
            if ($check_email->rowCount() > 0) {
                $errors[] = 'Email already exists!';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user
                $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, status, created_at) 
                                     VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $email, $hashed_password, $role, $status]);
                
                $user_id = $db->lastInsertId();
                
                // Send welcome email if selected
                $email_sent = false;
                if ($send_email) {
                    $email_sent = sendWelcomeEmail($name, $email, $password, $role);
                }
                
                // Store success message and data in session
                $_SESSION['user_created'] = true;
                $_SESSION['generated_password'] = $password;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = $role;
                $_SESSION['send_email'] = $send_email;
                $_SESSION['email_sent'] = $email_sent;
                $_SESSION['user_id'] = $user_id;
                
                // Redirect to show success message
                header("Location: user_management.php?created=true&email_sent=" . ($email_sent ? '1' : '0'));
                exit();
            }
        }
    } catch (Exception $e) {
        $errors[] = 'Error creating user: ' . $e->getMessage();
    }
}

// Update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    try {
        $user_id = $_POST['user_id'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $role = $_POST['role'];
        $status = $_POST['status'];
        
        // Check if email already exists (excluding current user)
        $check_email = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check_email->execute([$email, $user_id]);
        
        if ($check_email->rowCount() > 0) {
            $errors[] = 'Email already exists!';
        } else {
            // Start transaction
            $db->beginTransaction();
            
            // Update user
            $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, role = ?, status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$name, $email, $role, $status, $user_id]);
            
            // Reset password if provided
            if (!empty($_POST['password']) && $_POST['password'] === $_POST['confirm_password']) {
                $hashed_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $reset_stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $reset_stmt->execute([$hashed_password, $user_id]);
                
                // Send password reset email if requested
                if (isset($_POST['send_reset_email']) && $_POST['send_reset_email'] === 'yes') {
                    sendPasswordResetEmail($name, $email, $_POST['password'], $role);
                }
            }
            
            $db->commit();
            
            $_SESSION['message'] = 'User updated successfully!';
            $_SESSION['message_type'] = 'success';
            
            header("Location: user_management.php");
            exit();
        }
    } catch (Exception $e) {
        $db->rollBack();
        $errors[] = 'Error updating user: ' . $e->getMessage();
    }
}

// Delete user
if (isset($_GET['delete'])) {
    $user_id = $_GET['delete'];
    
    // Prevent deleting self
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['message'] = 'You cannot delete your own account!';
        $_SESSION['message_type'] = 'danger';
    } else {
        try {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            
            $_SESSION['message'] = 'User deleted successfully!';
            $_SESSION['message_type'] = 'success';
        } catch (Exception $e) {
            $_SESSION['message'] = 'Error deleting user: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
    }
    
    header("Location: user_management.php");
    exit();
}

// Toggle user status
if (isset($_GET['toggle_status'])) {
    $user_id = $_GET['toggle_status'];
    
    try {
        // Get current status
        $stmt = $db->prepare("SELECT status FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $current_status = $stmt->fetchColumn();
        
        $new_status = ($current_status == 'active') ? 'inactive' : 'active';
        
        $update_stmt = $db->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?");
        $update_stmt->execute([$new_status, $user_id]);
        
        $_SESSION['message'] = 'User status updated!';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error updating status: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    header("Location: user_management.php");
    exit();
}

// Check for session messages
if (isset($_SESSION['message']) && isset($_SESSION['message_type'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message'], $_SESSION['message_type']);
}

// Check for success message from user creation
if (isset($_SESSION['user_created']) && $_SESSION['user_created']) {
    $show_success_modal = true;
    $generated_password = $_SESSION['generated_password'] ?? '';
    $user_email = $_SESSION['user_email'] ?? '';
    $user_name = $_SESSION['user_name'] ?? '';
    $user_role = $_SESSION['user_role'] ?? '';
    $send_email = $_SESSION['send_email'] ?? false;
    $email_sent = $_SESSION['email_sent'] ?? false;
    $user_id = $_SESSION['user_id'] ?? '';
    
    // Clear session data
    unset($_SESSION['user_created'], $_SESSION['generated_password'], $_SESSION['user_email'], 
          $_SESSION['user_name'], $_SESSION['user_role'], $_SESSION['send_email'], 
          $_SESSION['email_sent'], $_SESSION['user_id']);
} else {
    $show_success_modal = false;
}

// Search and filter
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Build query
$query = "SELECT * FROM users WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (name LIKE ? OR email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($role_filter != 'all') {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}

if ($status_filter != 'all') {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY created_at DESC";

// Get users with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$total_stmt = $db->prepare(str_replace('*', 'COUNT(*)', $query));
$total_stmt->execute($params);
$total_users = $total_stmt->fetchColumn();

$query .= " LIMIT $limit OFFSET $offset";
$stmt = $db->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Send welcome email using PHPMailer
 */
function sendWelcomeEmail(string $name, string $email, string $password, string $role): bool {
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'training@asdacademy.in';  // Replace with your email
        $mail->Password   = 'yvdq craf dkpu dttc';     // Replace with your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Sender and recipient
        $mail->setFrom('noreply@asdacademy.com', 'ASD Academy');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to ASD Academy - Your Account Has Been Created';
        
        // Email body
        $mail->Body = '
        <!DOCTYPE html>
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
                    <h1>Welcome to ASD Academy</h1>
                    <p>Your Account Has Been Created</p>
                </div>
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($name) . ',</h2>
                    <p>Your account has been successfully created with the ' . htmlspecialchars($role) . ' role.</p>
                    
                    <div class="info-box">
                        <h3>Your Account Details:</h3>
                        <ul>
                            <li><strong>Full Name:</strong> ' . htmlspecialchars($name) . '</li>
                            <li><strong>Email/Username:</strong> ' . htmlspecialchars($email) . '</li>
                            <li><strong>Role:</strong> ' . htmlspecialchars(ucfirst($role)) . '</li>
                            <li><strong>Account Created:</strong> ' . date('F j, Y') . '</li>
                        </ul>
                    </div>
                    
                    <h3>Your Login Credentials:</h3>
                    <div class="password-box">' . htmlspecialchars($password) . '</div>
                    
                    <div class="warning">
                        <strong>⚠️ Important Security Information:</strong>
                        <ul>
                            <li>This is your temporary password</li>
                            <li>For security reasons, please change your password after first login</li>
                            <li>Never share your password with anyone</li>
                            <li>This email contains sensitive information - keep it secure</li>
                        </ul>
                    </div>
                    
                    <p><strong>Login URL:</strong> <a href="http://sthub.co.in/log_sales.php">Gyan Setu Portal</a></p>
                    
                    <h3>Next Steps:</h3>
                    <ol>
                        <li>Login using your email and the password above</li>
                        <li>Change your password immediately</li>
                        <li>Complete your profile information</li>
                        <li>Check your assigned tasks and schedule</li>
                    </ol>
                    
                    <p>If you have any questions or need assistance, please contact the administrator.</p>
                    
                    <p>Best regards,<br>
                    <strong>ASD Academy Administration</strong></p>
                    
                    <div class="footer">
                        <p>This is an automated message from ASD Academy. Please do not reply to this email.</p>
                        <p>© ' . date('Y') . ' ASD Academy. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        // Plain text version
        $mail->AltBody = "Hello " . $name . ",\n\n" .
                        "Welcome to ASD Academy! Your account has been created with the " . $role . " role.\n\n" .
                        "Email/Username: " . $email . "\n" .
                        "Password: " . $password . "\n\n" .
                        "Login URL: http://sthub.co.in/log_sales.php\n\n" .
                        "Please change your password after first login and do not share it with anyone.\n\n" .
                        "Best regards,\nASD Academy Administration";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send password reset email
 */
function sendPasswordResetEmail(string $name, string $email, string $password, string $role): bool {
    try {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'training@asdacademy.in';
        $mail->Password   = 'tjvu fqrx avkq odxq';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        // Sender and recipient
        $mail->setFrom('noreply@asdacademy.com', 'ASD Academy');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'ASD Academy - Password Has Been Reset';
        
        // Email body
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #ddd; }
                .password-box { background: #fff; border: 2px dashed #dc3545; padding: 15px; margin: 20px 0; text-align: center; font-family: monospace; font-size: 18px; font-weight: bold; }
                .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 5px; margin: 20px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>ASD Academy - Password Reset</h1>
                    <p>Your password has been reset by administrator</p>
                </div>
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($name) . ',</h2>
                    <p>Your account password has been reset by the administrator.</p>
                    
                    <h3>Your New Login Credentials:</h3>
                    <div class="password-box">' . htmlspecialchars($password) . '</div>
                    
                    <div class="warning">
                        <strong>⚠️ Important Security Information:</strong>
                        <ul>
                            <li>For security reasons, please change your password after first login</li>
                            <li>Never share your password with anyone</li>
                            <li>This email contains sensitive information - keep it secure</li>
                        </ul>
                    </div>
                    
                    <p><strong>Login URL:</strong> <a href="http://sthub.co.in/log_sales.php">Gyan Setu Portal</a></p>
                    
                    <p>If you did not request this password reset, please contact the administrator immediately.</p>
                    
                    <p>Best regards,<br>
                    <strong>ASD Academy Administration</strong></p>
                    
                    <div class="footer">
                        <p>This is an automated message from ASD Academy. Please do not reply to this email.</p>
                        <p>© ' . date('Y') . ' ASD Academy. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Password reset email failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Validate user data
 */
function validateUserData(string $name, string $email, string $password): array {
    $errors = [];
    
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }
    
    return $errors;
}

/**
 * Generate random password
 */
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    $charLength = strlen($chars) - 1;
    
    // Ensure at least one of each type
    $password .= getRandomChar("abcdefghijklmnopqrstuvwxyz");
    $password .= getRandomChar("ABCDEFGHIJKLMNOPQRSTUVWXYZ");
    $password .= getRandomChar("0123456789");
    $password .= getRandomChar("!@#$%^&*()");
    
    // Fill the rest randomly
    for ($i = 4; $i < $length; $i++) {
        $password .= $chars[rand(0, $charLength)];
    }
    
    // Shuffle the password
    return str_shuffle($password);
}

function getRandomChar($chars) {
    return $chars[rand(0, strlen($chars) - 1)];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --primary: #3B82F6;
            --primary-light: #EFF6FF;
            --secondary: #10B981;
            --danger: #EF4444;
            --warning: #F59E0B;
            --info: #6366F1;
            --dark: #1F2937;
            --light: #F9FAFB;
            --gray: #6B7280;
            
            --admin-color: #dc3545;
            --mentor-color: #198754;
            --student-color: #0d6efd;
            --sales-color: #fd7e14;
            --accounts-color: #6f42c1;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #F3F4F6;
            color: var(--dark);
        }
        
        /* Main content area - matches dashboard width */
        .main-content {
            margin-left: 0;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        
        @media (min-width: 768px) {
            .main-content {
                margin-left: 250px;
            }
        }
        
        /* Stat cards - similar to dashboard */
        .stat-card {
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            border-radius: 12px;
            border: 1px solid #e5e7eb;
            background: white;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 0;
            background: linear-gradient(to bottom, var(--primary), var(--info));
            transition: height 0.3s ease;
        }
        
        .stat-card:hover::before {
            height: 100%;
        }
        
        /* Role badges */
        .role-badge {
            padding: 0.375rem 0.875rem;
            border-radius: 20px;
            font-weight: 500;
            font-size: 0.75rem;
            letter-spacing: 0.025em;
        }
        
        .badge-admin { background-color: rgba(220, 53, 69, 0.1); color: var(--admin-color); border: 1px solid rgba(220, 53, 69, 0.2); }
        .badge-mentor { background-color: rgba(25, 135, 84, 0.1); color: var(--mentor-color); border: 1px solid rgba(25, 135, 84, 0.2); }
        .badge-student { background-color: rgba(13, 110, 253, 0.1); color: var(--student-color); border: 1px solid rgba(13, 110, 253, 0.2); }
        .badge-sales { background-color: rgba(253, 126, 20, 0.1); color: var(--sales-color); border: 1px solid rgba(253, 126, 20, 0.2); }
        .badge-accounts { background-color: rgba(111, 66, 193, 0.1); color: var(--accounts-color); border: 1px solid rgba(111, 66, 193, 0.2); }
        
        /* User avatar */
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        /* Status indicator */
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        
        .status-active { background-color: #28a745; }
        .status-inactive { background-color: #dc3545; }
        
        /* Table styling */
        .data-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }
        
        .data-table thead th {
            background-color: #f9fafb;
            border-bottom: 2px solid #e5e7eb;
            font-weight: 600;
            color: #374151;
            padding: 1rem;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .data-table tbody td {
            padding: 1rem;
            vertical-align: middle;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .data-table tbody tr:hover {
            background-color: #f9fafb;
        }
        
        /* Action buttons */
        .action-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            margin: 0 2px;
        }
        
        .action-btn:hover {
            transform: translateY(-1px);
        }
        
        /* Filter card */
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
        }
        
        /* Form controls */
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #d1d5db;
            padding: 0.625rem 1rem;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        /* Password strength */
        .password-strength {
            height: 4px;
            background: #e5e7eb;
            border-radius: 2px;
            margin-top: 0.5rem;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease;
            background: var(--secondary);
        }
        
        /* Modal styling */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
        }
        
        /* Pagination */
        .pagination .page-link {
            border-radius: 8px;
            margin: 0 2px;
            border: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 0.875rem;
            padding: 0.5rem 0.75rem;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        /* Header styling - matches dashboard */
        .page-header {
            background: white;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        /* Main container padding */
        .content-container {
            padding: 1.5rem;
        }
        
        @media (min-width: 768px) {
            .content-container {
                padding: 2rem;
            }
        }
        
        /* Empty state */
        .empty-state {
            padding: 3rem 1.5rem;
            text-align: center;
            background: white;
            border-radius: 12px;
            border: 2px dashed #e5e7eb;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 767px) {
            .data-table {
                font-size: 0.875rem;
            }
            
            .stat-card {
                margin-bottom: 1rem;
            }
            
            .action-btn {
                width: 28px;
                height: 28px;
                font-size: 0.75rem;
            }
            
            .page-header {
                padding: 0.75rem 1rem;
            }
            
            .content-container {
                padding: 1rem;
            }
        }
        
        /* Success message animation */
        .animate-fade-in {
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="text-xl font-bold text-gray-800 flex items-center space-x-2">
                    <i class="fas fa-users-cog text-blue-500"></i>
                    <span>User Management</span>
                </h1>
                <p class="text-sm text-gray-600 mt-1">Manage all users including Admins, Mentors, Students, Sales, and Accounts staff</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
                <i class="fas fa-user-plus me-2"></i>Add New User
            </button>
        </div>

        <div class="content-container">
            <!-- Message Alert -->
            <?php if ($message): ?>
            <div class="animate-fade-in mb-6">
                <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
            <div class="animate-fade-in mb-6">
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>There were errors with your submission:</strong><br>
                    <?php foreach ($errors as $error): ?>
                        <div><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-6">
                <!-- Total Users -->
                <div class="col-md-6 col-lg-3 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <div class="user-avatar"><?php echo $total_stats['all']['count']; ?></div>
                            </div>
                            <div>
                                <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['all']['count']; ?></h4>
                                <p class="text-gray-600 mb-0 text-sm">Total Users</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Active Users -->
                <div class="col-md-6 col-lg-3 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="fas fa-user-check fa-2x text-success"></i>
                            </div>
                            <div>
                                <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['all']['active']; ?></h4>
                                <p class="text-gray-600 mb-0 text-sm">Active Users</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Inactive Users -->
                <div class="col-md-6 col-lg-3 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="fas fa-user-slash fa-2x text-danger"></i>
                            </div>
                            <div>
                                <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['all']['inactive']; ?></h4>
                                <p class="text-gray-600 mb-0 text-sm">Inactive Users</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Locked Users -->
                <div class="col-md-6 col-lg-3 mb-4">
                    <div class="stat-card">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                <i class="fas fa-lock fa-2x text-warning"></i>
                            </div>
                            <div>
                                <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['all']['locked']; ?></h4>
                                <p class="text-gray-600 mb-0 text-sm">Locked Accounts</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Role Statistics -->
            <div class="row mb-6">
                <div class="col-md-4 col-lg-2 mb-4">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="mb-2">
                                <i class="fas fa-crown fa-2x" style="color: var(--admin-color);"></i>
                            </div>
                            <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['admin']['count'] ?? 0; ?></h4>
                            <span class="role-badge badge-admin">Admins</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 col-lg-2 mb-4">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="mb-2">
                                <i class="fas fa-chalkboard-teacher fa-2x" style="color: var(--mentor-color);"></i>
                            </div>
                            <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['mentor']['count'] ?? 0; ?></h4>
                            <span class="role-badge badge-mentor">Mentors</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 col-lg-2 mb-4">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="mb-2">
                                <i class="fas fa-graduation-cap fa-2x" style="color: var(--student-color);"></i>
                            </div>
                            <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['student']['count'] ?? 0; ?></h4>
                            <span class="role-badge badge-student">Students</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 col-lg-2 mb-4">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="mb-2">
                                <i class="fas fa-chart-line fa-2x" style="color: var(--sales-color);"></i>
                            </div>
                            <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['sales']['count'] ?? 0; ?></h4>
                            <span class="role-badge badge-sales">Sales</span>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4 col-lg-2 mb-4">
                    <div class="stat-card">
                        <div class="text-center">
                            <div class="mb-2">
                                <i class="fas fa-calculator fa-2x" style="color: var(--accounts-color);"></i>
                            </div>
                            <h4 class="mb-1 fw-bold fs-4"><?php echo $total_stats['accounts']['count'] ?? 0; ?></h4>
                            <span class="role-badge badge-accounts">Accounts</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-card mb-6">
                <form method="GET" class="row g-3">
                    <div class="col-md-12 col-lg-4">
                        <label class="form-label">Search Users</label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="fas fa-search"></i>
                            </span>
                            <input type="text" class="form-control" name="search" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label">Filter by Role</label>
                        <select class="form-select" name="role">
                            <option value="all" <?php echo $role_filter == 'all' ? 'selected' : ''; ?>>All Roles</option>
                            <option value="admin" <?php echo $role_filter == 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="mentor" <?php echo $role_filter == 'mentor' ? 'selected' : ''; ?>>Mentor</option>
                            <option value="student" <?php echo $role_filter == 'student' ? 'selected' : ''; ?>>Student</option>
                            <option value="sales" <?php echo $role_filter == 'sales' ? 'selected' : ''; ?>>Sales</option>
                            <option value="accounts" <?php echo $role_filter == 'accounts' ? 'selected' : ''; ?>>Accounts</option>
                        </select>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <label class="form-label">Filter by Status</label>
                        <select class="form-select" name="status">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="col-md-12 col-lg-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-filter me-2"></i>Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- Users Table -->
            <div class="data-table mb-6">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Last Login</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): 
                                $initials = strtoupper(substr($user['name'], 0, 2));
                                $role_class = "badge-" . $user['role'];
                                $status_class = $user['status'] == 'active' ? 'status-active' : 'status-inactive';
                                $last_login = $user['last_login'] ? date('M d, Y H:i', strtotime($user['last_login'])) : 'Never';
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="user-avatar me-3"><?php echo $initials; ?></div>
                                        <div>
                                            <div class="fw-semibold text-sm"><?php echo htmlspecialchars($user['name']); ?></div>
                                            <small class="text-gray-500">ID: <?php echo $user['id']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-sm"><?php echo htmlspecialchars($user['email']); ?></div>
                                </td>
                                <td>
                                    <span class="role-badge <?php echo $role_class; ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="status-dot <?php echo $status_class; ?>"></span>
                                        <span class="text-sm"><?php echo ucfirst($user['status']); ?></span>
                                        <?php if ($user['account_locked']): ?>
                                            <span class="badge bg-warning ms-2">Locked</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="text-sm"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></div>
                                </td>
                                <td>
                                    <div class="text-sm"><?php echo $last_login; ?></div>
                                </td>
                                <td>
                                    <div class="d-flex">
                                        <button class="action-btn btn btn-outline-primary btn-sm" onclick="editUser(<?php echo $user['id']; ?>)">
                                            <i class="fas fa-edit fa-xs"></i>
                                        </button>
                                        <button class="action-btn btn btn-outline-<?php echo $user['status'] == 'active' ? 'warning' : 'success'; ?> btn-sm"
                                                onclick="toggleStatus(<?php echo $user['id']; ?>)">
                                            <i class="fas fa-<?php echo $user['status'] == 'active' ? 'ban' : 'check'; ?> fa-xs"></i>
                                        </button>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <button class="action-btn btn btn-outline-danger btn-sm" onclick="confirmDelete(<?php echo $user['id']; ?>)">
                                            <i class="fas fa-trash fa-xs"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7">
                                    <div class="empty-state">
                                        <i class="fas fa-users fa-3x text-gray-300 mb-3"></i>
                                        <h5 class="text-gray-500">No users found</h5>
                                        <p class="text-gray-400">Try changing your filters or add new users</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_users > $limit): ?>
            <nav aria-label="Page navigation" class="d-flex justify-content-center">
                <ul class="pagination">
                    <?php
                    $total_pages = ceil($total_users / $limit);
                    $prev_page = max(1, $page - 1);
                    $next_page = min($total_pages, $page + 1);
                    ?>
                    <li class="page-item <?php echo $page == 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $prev_page; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo $page == $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $next_page; ?>&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addUserForm" onsubmit="return validateAddUserForm()">
                    <input type="hidden" name="add_user" value="1">
                    <input type="hidden" id="generated_password" name="generated_password" value="">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php if (!empty($errors)): ?>
                        <div class="alert alert-danger">
                            <strong>Please fix the following errors:</strong>
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Full Name *</label>
                                <input type="text" class="form-control" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email Address *</label>
                                <input type="email" class="form-control" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Role *</label>
                                <select class="form-select" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin" <?php echo ($_POST['role'] ?? '') == 'admin' ? 'selected' : ''; ?>>Administrator</option>
                                    <option value="mentor" <?php echo ($_POST['role'] ?? '') == 'mentor' ? 'selected' : ''; ?>>Mentor/Trainer</option>
                                    <option value="student" <?php echo ($_POST['role'] ?? '') == 'student' ? 'selected' : ''; ?>>Student</option>
                                    <option value="sales" <?php echo ($_POST['role'] ?? '') == 'sales' ? 'selected' : ''; ?>>Sales Staff</option>
                                    <option value="accounts" <?php echo ($_POST['role'] ?? '') == 'accounts' ? 'selected' : ''; ?>>Accounts Staff</option>
                                </select>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Password</label>
                                <div class="position-relative">
                                    <input type="text" id="password" name="password" class="form-control" 
                                           placeholder="Leave empty to generate random password" 
                                           value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>"
                                           oninput="updatePasswordStrength(this.value)">
                                    <button type="button" id="generatePassword" class="btn btn-sm btn-primary position-absolute" style="right: 5px; top: 5px;">
                                        <i class="fas fa-sync-alt me-1"></i>Generate
                                    </button>
                                </div>
                                <div class="password-strength mt-2">
                                    <div id="passwordStrengthBar" class="password-strength-bar"></div>
                                </div>
                                <div class="form-text">Minimum 8 characters with letters and numbers. If empty, a random password will be generated.</div>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" class="form-control" name="confirm_password" value="<?php echo htmlspecialchars($_POST['confirm_password'] ?? ''); ?>">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label d-block mb-2">Email Notification</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" id="send_email_yes" name="send_email" value="yes" <?php echo ($_POST['send_email'] ?? 'yes') == 'yes' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="send_email_yes">
                                            <i class="fas fa-paper-plane text-success me-1"></i>
                                            Send welcome email
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" id="send_email_no" name="send_email" value="no" <?php echo ($_POST['send_email'] ?? '') == 'no' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="send_email_no">
                                            <i class="fas fa-ban text-danger me-1"></i>
                                            Do not send email
                                        </label>
                                    </div>
                                </div>
                                <div class="alert alert-info mt-3 p-2 text-sm" id="emailInfo">
                                    <i class="fas fa-info-circle me-2"></i>
                                    If email is sent, user will receive login credentials via email. 
                                    If not sent, you'll need to share the password manually.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal fade" id="editUserModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="editUserForm" onsubmit="return validateEditUserForm()">
                    <input type="hidden" name="update_user" value="1">
                    <input type="hidden" name="user_id" id="edit_user_id">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit User</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" class="form-control" name="email" id="edit_email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Role *</label>
                            <select class="form-select" name="role" id="edit_role" required>
                                <option value="admin">Administrator</option>
                                <option value="mentor">Mentor/Trainer</option>
                                <option value="student">Student</option>
                                <option value="sales">Sales Staff</option>
                                <option value="accounts">Accounts Staff</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status *</label>
                            <select class="form-select" name="status" id="edit_status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <hr>
                        <h6 class="text-muted mb-3">Reset Password (Optional)</h6>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="password" id="edit_password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password" id="edit_confirm_password">
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="send_reset_email" value="yes" id="send_reset_email">
                                <label class="form-check-label" for="send_reset_email">
                                    Send password reset email to user
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Update User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>User Created Successfully</h5>
                </div>
                <div class="modal-body">
                    <?php if ($show_success_modal): ?>
                        <?php if ($send_email): ?>
                            <?php if ($email_sent): ?>
                                <div class="alert alert-success mb-3">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Welcome email has been sent to <?php echo htmlspecialchars($user_email ?? ''); ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning mb-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Email could not be sent. Please share password manually.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-info mb-3">
                                <i class="fas fa-info-circle me-2"></i>
                                Email was not sent. Please share password manually with the user.
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-secondary mb-3 p-3">
                            <strong>User Details:</strong><br>
                            <strong>Name:</strong> <?php echo htmlspecialchars($user_name ?? ''); ?><br>
                            <strong>Email:</strong> <?php echo htmlspecialchars($user_email ?? ''); ?><br>
                            <strong>Role:</strong> <?php echo htmlspecialchars(ucfirst($user_role ?? '')); ?><br>
                            <strong>User ID:</strong> <?php echo htmlspecialchars($user_id ?? ''); ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Password (copy this for sharing):</label>
                            <div class="p-3 bg-light border rounded font-monospace text-center fs-5">
                                <?php echo htmlspecialchars($generated_password ?? ''); ?>
                            </div>
                            <div class="form-text text-danger mt-2">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                This password will not be shown again. Please save it now.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" id="copyPasswordBtn" class="btn btn-primary">
                        <i class="fas fa-copy me-2"></i>Copy Password
                    </button>
                    <button type="button" class="btn btn-success" data-bs-dismiss="modal">Continue</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        <?php if ($show_success_modal): ?>
        document.addEventListener('DOMContentLoaded', function() {
            var successModal = new bootstrap.Modal(document.getElementById('successModal'));
            successModal.show();
        });
        <?php endif; ?>

        function editUser(userId) {
            // Fetch user data via AJAX
            fetch('get_user.php?id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('edit_user_id').value = data.user.id;
                        document.getElementById('edit_name').value = data.user.name;
                        document.getElementById('edit_email').value = data.user.email;
                        document.getElementById('edit_role').value = data.user.role;
                        document.getElementById('edit_status').value = data.user.status;
                        document.getElementById('edit_password').value = '';
                        document.getElementById('edit_confirm_password').value = '';
                        document.getElementById('send_reset_email').checked = false;
                        
                        var editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
                        editModal.show();
                    } else {
                        alert('Error loading user data');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading user data');
                });
        }
        
        function toggleStatus(userId) {
            if (confirm('Are you sure you want to toggle user status?')) {
                window.location.href = '?toggle_status=' + userId + '&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&page=<?php echo $page; ?>';
            }
        }
        
        function confirmDelete(userId) {
            if (confirm('Are you sure you want to delete this user? This action cannot be undone!')) {
                window.location.href = '?delete=' + userId + '&search=<?php echo urlencode($search); ?>&role=<?php echo $role_filter; ?>&status=<?php echo $status_filter; ?>&page=<?php echo $page; ?>';
            }
        }
        
        // Password generation functionality
        document.getElementById('generatePassword').addEventListener('click', function() {
            // Generate a random password
            const length = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()";
            let password = "";
            
            // Ensure at least one of each type
            password += getRandomChar("abcdefghijklmnopqrstuvwxyz");
            password += getRandomChar("ABCDEFGHIJKLMNOPQRSTUVWXYZ");
            password += getRandomChar("0123456789");
            password += getRandomChar("!@#$%^&*()");
            
            // Fill the rest randomly
            for (let i = 4; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            
            // Shuffle the password
            password = shuffleString(password);
            
            // Set the password in the input field
            const passwordInput = document.getElementById('password');
            passwordInput.value = password;
            
            // Also set in hidden field for form submission
            document.getElementById('generated_password').value = password;
            
            // Update strength indicator
            updatePasswordStrength(password);
        });

        // Helper function to get random character from a string
        function getRandomChar(chars) {
            return chars.charAt(Math.floor(Math.random() * chars.length));
        }

        // Helper function to shuffle a string
        function shuffleString(str) {
            const array = str.split('');
            for (let i = array.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [array[i], array[j]] = [array[j], array[i]];
            }
            return array.join('');
        }

        // Update password strength indicator
        function updatePasswordStrength(password) {
            let strength = 0;
            const bar = document.getElementById('passwordStrengthBar');
            
            if (password.length >= 8) strength++;
            if (/[a-z]/.test(password)) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            const width = (strength / 5) * 100;
            bar.style.width = width + '%';
            
            // Set color based on strength
            if (width < 40) {
                bar.style.background = '#ef4444';
            } else if (width < 70) {
                bar.style.background = '#f59e0b';
            } else if (width < 90) {
                bar.style.background = '#3b82f6';
            } else {
                bar.style.background = '#10b981';
            }
        }

        // Monitor password input for strength updates
        document.getElementById('password').addEventListener('input', function() {
            updatePasswordStrength(this.value);
            // If user manually types, update hidden field
            if (this.value) {
                document.getElementById('generated_password').value = this.value;
            }
        });

        // Copy password to clipboard in success modal
        document.getElementById('copyPasswordBtn').addEventListener('click', function() {
            const passwordText = document.querySelector('.modal-body .font-monospace').textContent.trim();
            if (passwordText) {
                navigator.clipboard.writeText(passwordText).then(function() {
                    const button = document.getElementById('copyPasswordBtn');
                    const originalText = button.innerHTML;
                    button.innerHTML = '<i class="fas fa-check me-2"></i>Copied!';
                    button.classList.remove('btn-primary');
                    button.classList.add('btn-success');
                    
                    setTimeout(() => {
                        button.innerHTML = originalText;
                        button.classList.remove('btn-success');
                        button.classList.add('btn-primary');
                    }, 2000);
                });
            }
        });

        // Form validation for add user
        function validateAddUserForm() {
            const password = document.getElementById('password').value;
            const confirmPassword = document.querySelector('input[name="confirm_password"]').value;
            const generatedPassword = document.getElementById('generated_password').value;
            
            // If user entered password, validate it
            if (password && password.length < 8) {
                alert('Password must be at least 8 characters long');
                return false;
            }
            
            // If user entered password and confirm password, check if they match
            if (password && confirmPassword && password !== confirmPassword) {
                alert('Passwords do not match');
                return false;
            }
            
            // If password is empty but generated password exists, use generated password
            if (!password && generatedPassword) {
                document.getElementById('password').value = generatedPassword;
            }
            
            return true;
        }

        // Form validation for edit user
        function validateEditUserForm() {
            const password = document.getElementById('edit_password').value;
            const confirmPassword = document.getElementById('edit_confirm_password').value;
            
            // If password is entered, validate it
            if (password) {
                if (password.length < 8) {
                    alert('Password must be at least 8 characters long');
                    return false;
                }
                
                if (password !== confirmPassword) {
                    alert('Passwords do not match');
                    return false;
                }
            }
            
            return true;
        }

        // Update email option info
        function updateEmailOptionInfo() {
            const sendEmailYes = document.getElementById('send_email_yes').checked;
            const passwordField = document.getElementById('password');
            const emailInfo = document.getElementById('emailInfo');
            
            if (sendEmailYes) {
                emailInfo.innerHTML = `
                    <i class="fas fa-info-circle me-2"></i>
                    If email is sent, user will receive login credentials via email. 
                    If not sent, you'll need to share the password manually.
                `;
                if (!passwordField.value) {
                    passwordField.placeholder = "Leave empty to generate random password";
                }
            } else {
                emailInfo.innerHTML = `
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Important:</strong> Email will not be sent. You must manually share the password with the user.
                    Consider generating a strong password and copying it for manual sharing.
                `;
                if (!passwordField.value) {
                    passwordField.placeholder = "Enter password (required since email won't be sent)";
                }
            }
        }
        
        // Listen to email option changes
        document.getElementById('send_email_yes').addEventListener('change', updateEmailOptionInfo);
        document.getElementById('send_email_no').addEventListener('change', updateEmailOptionInfo);
        
        // Initialize email option info
        updateEmailOptionInfo();
    </script>
</body>
</html>