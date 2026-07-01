<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require '../vendor/autoload.php';

$student_id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$student_id) {
    header("Location: ../student/student_list.php");
    exit();
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get student details with profile picture
    $stmt = $db->prepare("
        SELECT s.*, u.password_hash, u.email as user_email, u.name as user_name
        FROM students s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        header("Location: ../student/student_list.php");
        exit();
    }
    
    // Get all batches for dropdown
    $stmt = $db->prepare("SELECT batch_id, batch_name FROM batches ORDER BY start_date DESC");
    $stmt->execute();
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all courses for dropdown
    $stmt = $db->prepare("SELECT id, name FROM courses ORDER BY name");
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Variables for email notification
    $email_sent = false;
    $email_error = null;
    $generated_password = null;
    $student_email = $student['email'];
    $student_name = $student['first_name'] . ' ' . $student['last_name'];
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = $_POST['email'];
        $phone_number = $_POST['phone_number'];
        $date_of_birth = $_POST['date_of_birth'];
        $enrollment_date = $_POST['enrollment_date'];
        $current_status = $_POST['current_status'];
        $batch_name = $_POST['batch_name'];
        $batch_name_2 = $_POST['batch_name_2'];
        $batch_name_3 = $_POST['batch_name_3'];
        $batch_name_4 = $_POST['batch_name_4'];
        $father_name = $_POST['father_name'];
        $father_phone = $_POST['father_phone'];
        $father_email = $_POST['father_email'];
        $state = $_POST['state'];
        
        // Handle course selection
        $course_enrolled = $_POST['course'];
        $new_course_name = trim($_POST['new_course_name'] ?? '');
        
        if (!empty($new_course_name)) {
            $stmt = $db->prepare("SELECT id FROM courses WHERE name = ?");
            $stmt->execute([$new_course_name]);
            $existing_course = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_course) {
                $course_enrolled = $existing_course['id'];
            } else {
                $stmt = $db->prepare("INSERT INTO courses (name) VALUES (?)");
                $stmt->execute([$new_course_name]);
                $course_enrolled = $db->lastInsertId();
            }
        }
        
        // Handle password change
        $password_changed = false;
        $new_password = null;
        $generated_password = null;
        
        if (!empty($_POST['new_password'])) {
            $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $password_changed = true;
            $generated_password = $_POST['new_password'];
        }
        
        // Handle on_hold fields
        $on_hold_date = $student['on_hold_date'];
        $on_hold_reason = $student['on_hold_reason'];
        
        if ($current_status === 'on hold') {
            $on_hold_date = $_POST['on_hold_date'] ?? date('Y-m-d');
            $on_hold_reason = $_POST['on_hold_reason'] ?? '';
            
            if ($student['current_status'] === 'on hold' && $current_status !== 'on hold') {
                $on_hold_date = null;
                $on_hold_reason = null;
            }
        } elseif ($current_status !== 'on hold' && $student['current_status'] === 'on hold') {
            $on_hold_date = null;
            $on_hold_reason = null;
        }
        
        // Handle dropout fields
        $dropout_date = $student['dropout_date'];
        $dropout_reason = $student['dropout_reason'];
        
        if ($current_status === 'dropped') {
            $dropout_date = $_POST['dropout_date'] ?? date('Y-m-d');
            $dropout_reason = $_POST['dropout_reason'] ?? '';
            
            if ($student['current_status'] === 'dropped' && $current_status !== 'dropped') {
                $dropout_date = null;
                $dropout_reason = null;
            }
        } elseif ($current_status !== 'dropped' && $student['current_status'] === 'dropped') {
            $dropout_date = null;
            $dropout_reason = null;
        }
        
        // Handle profile picture upload
        $profile_picture = $student['profile_picture'];
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $target_dir = "../uploads/profile_pictures/";
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
            
            $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
            $new_filename = "student_" . $student_id . "_" . time() . "." . $file_extension;
            $target_file = $target_dir . $new_filename;
            
            $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
            if ($check !== false) {
                if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                    if (!empty($profile_picture) && file_exists($profile_picture)) {
                        unlink($profile_picture);
                    }
                    $profile_picture = $target_file;
                }
            }
        }
        
        // Update student record
        $stmt = $db->prepare("UPDATE students SET 
                              first_name = ?, 
                              last_name = ?, 
                              email = ?, 
                              phone_number = ?, 
                              date_of_birth = ?, 
                              enrollment_date = ?,
                              current_status = ?, 
                              batch_name = ?, 
                              batch_name_2 = ?,
                              batch_name_3 = ?,
                              batch_name_4 = ?,
                              course = ?,
                              father_name = ?, 
                              father_phone_number = ?, 
                              father_email = ?,
                              state = ?,
                              dropout_date = ?,
                              dropout_reason = ?,
                              on_hold_date = ?,
                              on_hold_reason = ?,
                              profile_picture = ?
                              WHERE student_id = ?");
        
        $stmt->execute([
            $first_name,
            $last_name,
            $email,
            $phone_number,
            $date_of_birth,
            $enrollment_date,
            $current_status,
            $batch_name,
            $batch_name_2,
            $batch_name_3,
            $batch_name_4,
            $course_enrolled,
            $father_name,
            $father_phone,
            $father_email,
            $state,
            $dropout_date,
            $dropout_reason,
            $on_hold_date,
            $on_hold_reason,
            $profile_picture,
            $student_id
        ]);
        
        // Update user information
        if (!empty($student['user_id'])) {
            $updates = [];
            $params = [];
            
            $new_full_name = $first_name . ' ' . $last_name;
            if ($new_full_name !== $student['user_name']) {
                $updates[] = "name = ?";
                $params[] = $new_full_name;
            }
            
            if ($email !== $student['user_email']) {
                $updates[] = "email = ?";
                $params[] = $email;
            }
            
            if ($password_changed) {
                $updates[] = "password_hash = ?";
                $params[] = $new_password;
            }
            
            if (!empty($updates)) {
                $params[] = $student['user_id'];
                $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }
        }
        
        // Update attendance records if batch changed
        if ($batch_name !== 'None' && $batch_name !== $student['batch_name']) {
            $student_name = $first_name . ' ' . $last_name;
            $stmt = $db->prepare("UPDATE attendance SET batch_id = ? WHERE student_name = ? AND batch_id = ?");
            $stmt->execute([$batch_name, $student_name, $student['batch_name']]);
        }
        
        // Log status change
        if ($current_status !== $student['current_status']) {
            $action = '';
            $reason = '';
            
            if ($current_status === 'dropped') {
                $action = 'dropped';
                $reason = $dropout_reason;
            } elseif ($current_status === 'on hold') {
                $action = 'on_hold';
                $reason = $on_hold_reason;
            } elseif ($current_status === 'active' && $student['current_status'] === 'dropped') {
                $action = 'reactivated';
                $reason = 'Student reactivated';
            } elseif ($current_status === 'active' && $student['current_status'] === 'on hold') {
                $action = 'resumed';
                $reason = 'Student taken off hold';
            }
            
            if (!empty($action)) {
                $stmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([$student_id, $action, $reason, $_SESSION['user_id']]);
            }
        }
        
        // Send email notification if password was changed
        if ($password_changed && !empty($generated_password)) {
            $email_sent = sendPasswordUpdateEmail($email, $first_name . ' ' . $last_name, $generated_password, $student_id, $enrollment_date);
            
            if ($email_sent) {
                $_SESSION['password_email_sent'] = true;
                $_SESSION['student_email'] = $email;
            } else {
                $_SESSION['email_error'] = $email_error;
            }
        }
        
        // Store generated password in session for modal display
        if (!empty($generated_password)) {
            $_SESSION['generated_password'] = $generated_password;
            $_SESSION['student_email'] = $email;
            $_SESSION['student_name'] = $first_name . ' ' . $last_name;
            $_SESSION['student_id'] = $student_id;
            $_SESSION['enrollment_date'] = $enrollment_date;
            $_SESSION['email_sent'] = $email_sent;
            
            header("Location: student_view.php?id=$student_id&show_password=true&email_sent=" . ($email_sent ? '1' : '0'));
            exit();
        } else {
            header("Location: student_view.php?id=$student_id");
            exit();
        }
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

function sendPasswordUpdateEmail(string $email, string $studentName, string $password, string $studentId, string $enrollmentDate): bool {
    global $email_error;
    
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'training@asdacademy.in';
        $mail->Password   = 'yvdq craf dkpu dttc';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('noreply@asdacademy.com', 'ASD Academy');
        $mail->addAddress($email, $studentName);
        
        $mail->isHTML(true);
        $mail->Subject = 'ASD Academy - Your Account Password Has Been Updated';
        
        $enrollment_date_formatted = date('F j, Y', strtotime($enrollmentDate));
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1B3C53 0%, #456882 100%); color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #ddd; }
                .password-box { background: #fff; border: 2px dashed #234C6A; padding: 15px; margin: 20px 0; text-align: center; font-family: monospace; font-size: 18px; font-weight: bold; color: #1B3C53; }
                .warning { background: #fff3cd; border: 1px solid #ffc107; padding: 10px; border-radius: 5px; margin: 20px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
                .info-box { background: #F8F6F3; border-left: 4px solid #456882; padding: 15px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>ASD Academy</h1>
                    <p>Your Account Password Has Been Updated</p>
                </div>
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($studentName) . ',</h2>
                    <p>Your account password has been successfully updated by the administrator.</p>
                    
                    <div class="info-box">
                        <h3>Your Account Details:</h3>
                        <ul>
                            <li><strong>Student ID:</strong> ' . htmlspecialchars($studentId) . '</li>
                            <li><strong>Full Name:</strong> ' . htmlspecialchars($studentName) . '</li>
                            <li><strong>Email/Username:</strong> ' . htmlspecialchars($email) . '</li>
                            <li><strong>Enrollment Date:</strong> ' . htmlspecialchars($enrollment_date_formatted) . '</li>
                        </ul>
                    </div>
                    
                    <h3>Your New Password:</h3>
                    <div class="password-box">' . htmlspecialchars($password) . '</div>
                    
                    <div class="warning">
                        <strong>⚠️ Important Security Information:</strong>
                        <ul>
                            <li>This is your new password</li>
                            <li>Never share your password with anyone</li>
                            <li>This email contains sensitive information - keep it secure</li>
                        </ul>
                    </div>
                    
                    <p><strong>Login URL:</strong> <a href="http://sthub.co.in/login.php" style="color: #234C6A;">Gyan Setu</a></p>
                    
                    <h3>Next Steps:</h3>
                    <ol>
                        <li>Login using your email and the password above</li>
                        <li>Check your course schedule and materials</li>
                    </ol>
                    
                    <p>If you did not request this change or have any concerns, please contact our support team immediately.</p>
                    
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
        
        $mail->AltBody = "Hello " . $studentName . ",\n\n" .
                        "Your ASD Academy account password has been updated.\n\n" .
                        "Student ID: " . $studentId . "\n" .
                        "Email/Username: " . $email . "\n" .
                        "Enrollment Date: " . $enrollment_date_formatted . "\n" .
                        "New Password: " . $password . "\n\n" .
                        "Login URL: http://sthub.co.in/login.php\n\n" .
                        "Best regards,\nASD Academy Administration";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        $email_error = "Email could not be sent. Error: " . ($mail->ErrorInfo ?? $e->getMessage());
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

if (isset($_GET['show_password']) && $_GET['show_password'] === 'true' && isset($_SESSION['generated_password'])) {
    $show_password_modal = true;
    $generated_password = $_SESSION['generated_password'];
    $student_email = $_SESSION['student_email'];
    $student_name = $_SESSION['student_name'];
    $student_id = $_SESSION['student_id'];
    $enrollment_date = $_SESSION['enrollment_date'];
    $email_sent = $_SESSION['email_sent'];
    
    unset($_SESSION['generated_password'], $_SESSION['student_email'], 
          $_SESSION['student_name'], $_SESSION['student_id'], $_SESSION['enrollment_date'], 
          $_SESSION['email_sent']);
} else {
    $show_password_modal = false;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?> | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ========== THEME COLORS ========== */
        :root {
            --primary-dark: #1B3C53;
            --primary: #234C6A;
            --primary-light: #456882;
            --accent-warm: #D2C1B6;
            --accent-warm-light: #E5D9D0;
            --accent-warm-dark: #B8A898;
            --gold: #C4A962;
            --gold-light: #D4BC7E;
            --white: #FFFFFF;
            --off-white: #F8F6F3;
            --gray-50: #F9FAFB;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-400: #9CA3AF;
            --gray-500: #6B7280;
            --gray-600: #4B5563;
            --gray-700: #374151;
            --gray-800: #1F2937;
            --gray-900: #111827;
            --success: #059669;
            --danger: #DC2626;
            --warning: #D97706;
            --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.07), 0 2px 4px -1px rgba(0, 0, 0, 0.04);
            --card-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, var(--off-white) 0%, var(--accent-warm-light) 100%);
            color: var(--gray-800);
            min-height: 100vh;
            position: relative;
        }

        /* ========== DECORATIVE BACKGROUND ========== */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 70% 30%, rgba(35, 76, 106, 0.03) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        body::after {
            content: '';
            position: fixed;
            bottom: -50%;
            left: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 30% 70%, rgba(210, 193, 182, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* ========== SCROLLBAR ========== */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: var(--gray-100); border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(135deg, var(--primary-light), var(--primary)); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); }

        /* ========== MAIN CONTENT ========== */
        .main-content {
            margin-left: 16rem;
            transition: margin 0.3s ease;
            position: relative;
            z-index: 1;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }

        /* ========== HEADER ========== */
        .header-glass {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(27, 60, 83, 0.08);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        /* ========== HERO SECTION ========== */
        .hero-section {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 30%, var(--primary-light) 70%, var(--accent-warm-dark) 100%);
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            animation: patternMove 20s linear infinite;
        }

        @keyframes patternMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(60px, 60px); }
        }

        .hero-section::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 50%, rgba(255, 255, 255, 0.08) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(210, 193, 182, 0.12) 0%, transparent 50%);
            pointer-events: none;
        }

        /* ========== PROFILE PICTURE ========== */
        .profile-picture {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 4px solid rgba(255, 255, 255, 0.9);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2), 0 0 60px rgba(210, 193, 182, 0.25);
            object-fit: cover;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            background: white;
        }

        .profile-picture:hover {
            transform: scale(1.05) rotate(2deg);
            border-color: var(--accent-warm);
        }

        /* ========== CARD CONTAINER ========== */
        .card-container {
            transform: translateY(-60px);
        }

        /* ========== GLASS CARD ========== */
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(27, 60, 83, 0.08);
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
        }

        /* ========== STAT CARDS ========== */
        .stat-card {
            background: white;
            border-radius: 1.25rem;
            padding: 1.5rem;
            border: 1px solid rgba(27, 60, 83, 0.08);
            box-shadow: var(--card-shadow);
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-dark), var(--primary-light), var(--accent-warm));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-icon {
            width: 3rem;
            height: 3rem;
            border-radius: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.3s;
        }

        .stat-card:hover .stat-icon { transform: scale(1.1) rotate(5deg); }

        /* ========== FORM INPUTS ========== */
        .form-input {
            transition: all 0.3s ease;
            border: 1.5px solid rgba(27, 60, 83, 0.12);
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            width: 100%;
            background: white;
            color: var(--gray-800);
            font-size: 0.95rem;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(35, 76, 106, 0.08);
            transform: scale(1.005);
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            letter-spacing: 0.3px;
        }

        /* ========== BATCH BADGES ========== */
        .batch-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 16px;
            background: linear-gradient(135deg, rgba(35, 76, 106, 0.06), rgba(69, 104, 130, 0.03));
            color: var(--primary);
            border: 1px solid rgba(35, 76, 106, 0.15);
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            transition: var(--transition-smooth);
        }

        .batch-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(35, 76, 106, 0.1);
        }

        .batch-badge-primary { background: rgba(35, 76, 106, 0.08); color: var(--primary-dark); border-color: rgba(35, 76, 106, 0.2); }
        .batch-badge-secondary { background: rgba(69, 104, 130, 0.06); color: var(--primary-light); border-color: rgba(69, 104, 130, 0.2); }
        .batch-badge-tertiary { background: rgba(210, 193, 182, 0.15); color: var(--accent-warm-dark); border-color: rgba(210, 193, 182, 0.3); }
        .batch-badge-quaternary { background: rgba(196, 169, 98, 0.1); color: var(--gold); border-color: rgba(196, 169, 98, 0.2); }

        /* ========== STATUS BADGE ========== */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: var(--transition-smooth);
        }

        .status-badge.active { background: rgba(5, 150, 105, 0.08); color: var(--success); border: 1px solid rgba(5, 150, 105, 0.2); }
        .status-badge.onhold { background: rgba(217, 119, 6, 0.08); color: var(--warning); border: 1px solid rgba(217, 119, 6, 0.2); }
        .status-badge.dropped { background: rgba(220, 38, 38, 0.08); color: var(--danger); border: 1px solid rgba(220, 38, 38, 0.2); }
        .status-badge.completed { background: rgba(35, 76, 106, 0.08); color: var(--primary); border: 1px solid rgba(35, 76, 106, 0.2); }

        /* ========== PASSWORD GENERATOR ========== */
        .password-generator {
            position: relative;
        }

        .password-generator .generate-btn {
            position: absolute;
            right: 0.5rem;
            top: 2.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: var(--transition-smooth);
            font-size: 0.85rem;
            font-weight: 500;
        }

        .password-generator .generate-btn:hover {
            background: var(--primary-dark);
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.3);
        }

        .password-generator .copy-btn {
            position: absolute;
            right: 7.5rem;
            top: 2.5rem;
            background: var(--gray-500);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            cursor: pointer;
            transition: var(--transition-smooth);
            font-size: 0.85rem;
        }

        .password-generator .copy-btn:hover {
            background: var(--gray-600);
            transform: scale(1.05);
        }

        .password-strength {
            height: 5px;
            background: var(--gray-200);
            border-radius: 3px;
            margin-top: 0.5rem;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.5s ease, background 0.3s;
            border-radius: 3px;
        }

        /* ========== MODAL ========== */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(27, 60, 83, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal.show { display: flex; }

        .modal-content {
            background: white;
            border-radius: 1.5rem;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.2);
            animation: modalPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.8) translateY(-30px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .password-display {
            font-family: 'Courier New', monospace;
            font-size: 1.25rem;
            letter-spacing: 2px;
            background: var(--off-white);
            border: 2px dashed var(--primary);
            padding: 1rem;
            border-radius: 0.75rem;
            text-align: center;
            margin: 1rem 0;
            color: var(--primary-dark);
            font-weight: 700;
        }

        /* ========== TOAST ========== */
        .toast {
            position: fixed;
            top: 1.5rem;
            right: 1.5rem;
            z-index: 2000;
            padding: 1rem 1.5rem;
            border-radius: 0.75rem;
            color: white;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            animation: slideIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            max-width: 400px;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(60px) scale(0.9); }
            to { opacity: 1; transform: translateX(0) scale(1); }
        }

        .toast.success { background: var(--success); }
        .toast.error { background: var(--danger); }
        .toast.warning { background: var(--warning); }

        /* ========== SCROLL TO TOP ========== */
        #scrollToTop {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            z-index: 50;
            width: 3.5rem;
            height: 3.5rem;
            border-radius: 9999px;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            border: none;
            box-shadow: 0 4px 14px rgba(27, 60, 83, 0.3);
            cursor: pointer;
            transition: var(--transition-smooth);
            opacity: 0;
            visibility: hidden;
            transform: translateY(30px) scale(0.8);
        }

        #scrollToTop.visible {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }

        #scrollToTop:hover {
            transform: scale(1.15);
            box-shadow: 0 8px 30px rgba(27, 60, 83, 0.4);
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
        }

        /* ========== SCROLL REVEAL ========== */
        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }

        /* ========== CONDITIONAL SECTIONS ========== */
        .conditional-section {
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            overflow: hidden;
        }

        .conditional-section.hidden {
            max-height: 0 !important;
            opacity: 0;
            padding: 0 !important;
            margin: 0 !important;
            border: 0 !important;
        }

        .conditional-section:not(.hidden) {
            max-height: 800px;
        }

        /* ========== REASON CHIPS ========== */
        .reason-chip {
            transition: var(--transition-smooth);
            cursor: pointer;
        }

        .reason-chip:hover {
            transform: scale(1.05);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        /* ========== BUTTONS ========== */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: var(--transition-smooth);
            box-shadow: 0 4px 12px rgba(27, 60, 83, 0.2);
            letter-spacing: 0.3px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(27, 60, 83, 0.3);
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
        }

        .btn-secondary {
            background: white;
            color: var(--primary);
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 600;
            border: 2px solid rgba(27, 60, 83, 0.15);
            cursor: pointer;
            transition: var(--transition-smooth);
            letter-spacing: 0.3px;
        }

        .btn-secondary:hover {
            border-color: var(--primary);
            background: rgba(35, 76, 106, 0.04);
            transform: translateY(-2px);
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 640px) {
            .profile-picture { width: 120px; height: 120px; }
            .card-container { transform: translateY(-40px); }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <?php include '../sidebar.php'; ?>
    
    <!-- Mobile header -->
    <div class="md:hidden bg-white shadow-sm fixed w-full z-30 border-b border-gray-100">
        <div class="flex items-center justify-between p-4">
            <button id="mobileSidebarToggle" class="text-gray-700 hover:text-[#234C6A] transition">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-xl font-bold text-[#1B3C53]">ASD Academy</h1>
        </div>
    </div>

    <!-- Main content wrapper -->
    <div class="flex flex-col min-h-screen main-content">
        <!-- Sticky Header -->
        <header class="sticky top-0 z-30 header-glass px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <button class="md:hidden text-2xl text-gray-600 hover:text-[#234C6A] transition" onclick="toggleSidebarMobile()" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-xl flex items-center justify-center shadow-md" 
                         style="background: linear-gradient(135deg, #1B3C53, #456882);">
                        <i class="fas fa-user-edit text-white text-lg"></i>
                    </div>
                    <div>
                        <nav class="text-xs font-semibold text-gray-500 mb-0.5" aria-label="Breadcrumb">
                            <a href="#" class="hover:text-[#234C6A] transition">Dashboard</a> /
                            <a href="../student_list.php" class="hover:text-[#234C6A] transition">Students</a> /
                            <span class="text-gray-800">Edit <?= htmlspecialchars($student['first_name']) ?></span>
                        </nav>
                        <h1 class="text-2xl font-bold tracking-tight" style="background: linear-gradient(135deg, #1B3C53, #456882); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                            Edit Student
                        </h1>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="px-4 py-2 rounded-full text-sm font-semibold border" 
                      style="background: rgba(35, 76, 106, 0.06); color: #234C6A; border-color: rgba(35, 76, 106, 0.15);">
                    <i class="fas fa-id-badge mr-1"></i> <?= htmlspecialchars($student['student_id']) ?>
                </span>
                <a href="student_view.php?id=<?= htmlspecialchars($student_id) ?>" 
                   class="btn-secondary inline-flex items-center gap-2">
                    <i class="fas fa-eye"></i> <span class="hidden sm:inline">View</span>
                </a>
            </div>
        </header>

        <!-- Hero Section -->
        <div class="hero-section text-white flex justify-center items-center relative py-16 md:py-20">
            <div class="container mx-auto px-6 md:px-12 flex flex-col md:flex-row items-center space-y-6 md:space-y-0 md:space-x-10 relative z-10">
                <!-- Profile Picture -->
                <div class="relative">
                    <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars($student['profile_picture']) ?>" 
                             alt="Profile Picture" 
                             class="profile-picture">
                    <?php else: ?>
                        <div class="profile-picture bg-white/20 backdrop-blur-sm flex items-center justify-center">
                            <i class="fas fa-user text-5xl text-[#D2C1B6]"></i>
                        </div>
                    <?php endif; ?>
                    <label for="profile_picture" class="absolute bottom-0 right-0 rounded-full p-3 cursor-pointer shadow-lg transition-all hover:scale-110"
                           style="background: linear-gradient(135deg, #234C6A, #456882);">
                        <i class="fas fa-camera text-white"></i>
                    </label>
                    <input type="file" id="profile_picture" name="profile_picture" class="hidden" accept="image/*" form="editStudentForm">
                </div>
                <!-- Profile Info -->
                <div class="text-center md:text-left">
                    <h1 class="text-3xl md:text-4xl font-extrabold drop-shadow-lg" style="font-family: 'Playfair Display', serif;">
                        Edit <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                    </h1>
                    <p class="text-lg font-medium drop-shadow" style="color: #D2C1B6;"><?= htmlspecialchars($student['student_id']) ?></p>
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-2 mt-2">
                        <span class="status-badge <?= $student['current_status'] === 'active' ? 'active' : ($student['current_status'] === 'on hold' ? 'onhold' : ($student['current_status'] === 'dropped' ? 'dropped' : 'completed')) ?>">
                            <i class="fas fa-circle text-xs mr-1"></i>
                            <?= ucfirst(htmlspecialchars($student['current_status'])) ?>
                        </span>
                        <?php if ($student['current_status'] === 'on hold' && !empty($student['on_hold_reason'])): ?>
                            <span class="text-sm" style="color: #E5D9D0;">
                                <i class="fas fa-info-circle mr-1"></i>
                                Reason: <?= htmlspecialchars(substr($student['on_hold_reason'], 0, 30)) ?>...
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap gap-1 mt-2 justify-center md:justify-start">
                        <?php 
                        $batch_stmt = $db->prepare("SELECT batch_name FROM batches WHERE batch_id = ?");
                        $batch_fields = ['batch_name', 'batch_name_2', 'batch_name_3', 'batch_name_4'];
                        $badge_classes = ['batch-badge-primary', 'batch-badge-secondary', 'batch-badge-tertiary', 'batch-badge-quaternary'];
                        $badge_labels = ['Primary', 'Batch 2', 'Batch 3', 'Batch 4'];
                        foreach ($batch_fields as $idx => $field) {
                            if (!empty($student[$field])) {
                                $batch_stmt->execute([$student[$field]]);
                                $batch_info = $batch_stmt->fetch(PDO::FETCH_ASSOC);
                                $display_name = $batch_info['batch_name'] ?? $student[$field];
                                echo '<span class="batch-badge ' . $badge_classes[$idx] . '"><i class="fas fa-users mr-1"></i>' . $badge_labels[$idx] . ': ' . htmlspecialchars($display_name) . '</span>';
                            }
                        }
                        ?>
                    </div>
                    <p class="mt-2 text-sm" style="color: #E5D9D0;">
                        <i class="fas fa-calendar-alt mr-1"></i>
                        Enrolled: <?= htmlspecialchars(date('F j, Y', strtotime($student['enrollment_date']))) ?>
                    </p>
                </div>
            </div>
            <div class="absolute inset-0 bg-gradient-to-b from-transparent to-[#1B3C53]/60 pointer-events-none"></div>
        </div>

        <!-- Main content area -->
        <div class="container mx-auto px-6 md:px-12 card-container">
            <div class="glass-card rounded-3xl p-6 md:p-10 mb-10 reveal" data-reveal>
                <!-- Email Notification Note -->
                <div class="border-l-4 p-4 mb-6 rounded-xl" style="background: rgba(35, 76, 106, 0.05); border-color: #234C6A;">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-xl" style="color: #234C6A;"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm" style="color: #1B3C53;">
                                <strong>Note:</strong> If you change the password, it will be automatically emailed to the student using the same email system as new student registration.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <form method="POST" enctype="multipart/form-data" class="space-y-8" id="editStudentForm">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <!-- Personal Information -->
                        <div class="space-y-6">
                            <h2 class="text-xl font-bold border-b pb-2 flex items-center" style="color: #1B3C53; border-color: rgba(27, 60, 83, 0.1);">
                                <i class="fas fa-user-circle mr-2" style="color: #456882;"></i>
                                Personal Information
                            </h2>
                            
                            <div>
                                <label for="first_name" class="form-label">First Name</label>
                                <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($student['first_name']) ?>" 
                                       class="form-input" required>
                            </div>
                            
                            <div>
                                <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($student['last_name']) ?>" 
                                       class="form-input" required>
                            </div>
                            
                            <div>
                                <label for="email" class="form-label">Email</label>
                                <input type="email" id="email" name="email" value="<?= htmlspecialchars($student['email']) ?>" 
                                       class="form-input" required>
                            </div>
                            
                            <div>
                                <label for="phone_number" class="form-label">Phone Number</label>
                                <input type="tel" id="phone_number" name="phone_number" value="<?= htmlspecialchars($student['phone_number']) ?>" 
                                       class="form-input" required>
                            </div>
                            
                            <div>
                                <label for="date_of_birth" class="form-label">Date of Birth</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" value="<?= htmlspecialchars($student['date_of_birth']) ?>" 
                                       class="form-input" required>
                            </div>
                            
                            <div>
                                <label for="enrollment_date" class="form-label">Enrollment Date</label>
                                <input type="date" id="enrollment_date" name="enrollment_date" 
                                       value="<?= htmlspecialchars($student['enrollment_date']) ?>" 
                                       class="form-input" required>
                                <p class="text-xs text-gray-500 mt-1">Date when student joined the academy</p>
                            </div>
                            
                            <div>
                                <label for="state" class="form-label">State</label>
                                <input type="text" id="state" name="state" value="<?= htmlspecialchars($student['state']) ?>" 
                                       class="form-input" placeholder="Enter state">
                            </div>
                            
                            <div>
                                <label for="current_status" class="form-label">Current Status</label>
                                <select id="current_status" name="current_status" class="form-input" required>
                                    <option value="active" <?= $student['current_status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="on hold" <?= $student['current_status'] === 'on hold' ? 'selected' : '' ?>>On Hold</option>
                                    <option value="dropped" <?= $student['current_status'] === 'dropped' ? 'selected' : '' ?>>Dropped</option>
                                    <option value="completed" <?= $student['current_status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Academic Information -->
                        <div class="space-y-6">
                            <h2 class="text-xl font-bold border-b pb-2 flex items-center" style="color: #1B3C53; border-color: rgba(27, 60, 83, 0.1);">
                                <i class="fas fa-graduation-cap mr-2" style="color: #456882;"></i>
                                Academic Information
                            </h2>
                            
                            <!-- Primary Batch -->
                            <div>
                                <label for="batch_name" class="form-label">Primary Batch</label>
                                <select id="batch_name" name="batch_name" class="form-input" required>
                                    <option value="None" <?= empty($student['batch_name']) || $student['batch_name'] === 'None' ? 'selected' : '' ?>>None</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                            <?= $batch['batch_id'] == $student['batch_name'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($batch['batch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Main batch for attendance and primary course</p>
                            </div>
                            
                            <!-- Additional Batch 1 -->
                            <div>
                                <label for="batch_name_2" class="form-label">Additional Batch 1 (Optional)</label>
                                <select id="batch_name_2" name="batch_name_2" class="form-input">
                                    <option value="">None</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                            <?= $batch['batch_id'] == $student['batch_name_2'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($batch['batch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Additional Batch 2 -->
                            <div>
                                <label for="batch_name_3" class="form-label">Additional Batch 2 (Optional)</label>
                                <select id="batch_name_3" name="batch_name_3" class="form-input">
                                    <option value="">None</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                            <?= $batch['batch_id'] == $student['batch_name_3'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($batch['batch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Additional Batch 3 -->
                            <div>
                                <label for="batch_name_4" class="form-label">Additional Batch 3 (Optional)</label>
                                <select id="batch_name_4" name="batch_name_4" class="form-input">
                                    <option value="">None</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                            <?= $batch['batch_id'] == $student['batch_name_4'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($batch['batch_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="course" class="form-label">Course</label>
                                <select id="course" name="course" class="form-input">
                                    <option value="0" <?= empty($student['course']) || $student['course'] == 0 ? 'selected' : '' ?>>None</option>
                                    <?php foreach ($courses as $course): ?>
                                        <option value="<?= htmlspecialchars($course['id']) ?>" 
                                            <?= $course['id'] == $student['course'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($course['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="new_course_name" class="form-label">Or Add New Course</label>
                                <input type="text" id="new_course_name" name="new_course_name" 
                                       placeholder="Enter new course name" class="form-input">
                            </div>
                            
                            <!-- Password Section -->
                            <div class="password-generator">
                                <label for="new_password" class="form-label">New Password (Leave blank to keep current)</label>
                                <input type="text" id="new_password" name="new_password" 
                                       class="form-input" placeholder="Click generate or type manually">
                                <input type="hidden" id="password_generated" name="password_generated" value="0">
                                
                                <button type="button" id="generatePassword" class="generate-btn">
                                    <i class="fas fa-sync-alt mr-2"></i>Generate
                                </button>
                                
                                <button type="button" id="copyPassword" class="copy-btn" style="display: none;">
                                    <i class="fas fa-copy mr-2"></i>Copy
                                </button>
                                
                                <div class="password-strength">
                                    <div id="passwordStrengthBar" class="password-strength-bar"></div>
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Password will be automatically emailed to student</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- On Hold Information -->
                    <div id="onhold-section" class="conditional-section <?= $student['current_status'] !== 'on hold' ? 'hidden' : '' ?>">
                        <div class="mt-6 pt-6 border-t" style="border-color: rgba(27, 60, 83, 0.1);">
                            <h2 class="text-xl font-bold mb-6 flex items-center" style="color: #1B3C53;">
                                <i class="fas fa-pause-circle mr-2" style="color: #D97706;"></i>
                                On Hold Information
                            </h2>
                            
                            <div class="border-l-4 p-4 mb-6 rounded-xl" style="background: rgba(217, 119, 6, 0.05); border-color: #D97706;">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle" style="color: #D97706;"></i>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm" style="color: #92400E;">
                                            <strong>Note:</strong> Please provide a reason for placing this student on hold.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label for="on_hold_date" class="form-label">On Hold Date</label>
                                    <input type="date" id="on_hold_date" name="on_hold_date" 
                                           value="<?= htmlspecialchars($student['on_hold_date'] ?? date('Y-m-d')) ?>" 
                                           class="form-input">
                                </div>
                                
                                <div>
                                    <label for="on_hold_reason" class="form-label">Reason for On Hold</label>
                                    <textarea id="on_hold_reason" name="on_hold_reason" 
                                              class="form-input" rows="3" 
                                              placeholder="Enter reason..."><?= htmlspecialchars($student['on_hold_reason'] ?? '') ?></textarea>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <label class="text-sm text-gray-600 font-medium">Common Reasons:</label>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    <button type="button" class="reason-chip px-3 py-1.5 rounded-full text-sm font-medium border transition"
                                            style="background: rgba(217, 119, 6, 0.08); color: #92400E; border-color: rgba(217, 119, 6, 0.2);"
                                            data-reason="Medical Leave">Medical Leave</button>
                                    <button type="button" class="reason-chip px-3 py-1.5 rounded-full text-sm font-medium border transition"
                                            style="background: rgba(217, 119, 6, 0.08); color: #92400E; border-color: rgba(217, 119, 6, 0.2);"
                                            data-reason="Financial Issues">Financial Issues</button>
                                    <button type="button" class="reason-chip px-3 py-1.5 rounded-full text-sm font-medium border transition"
                                            style="background: rgba(217, 119, 6, 0.08); color: #92400E; border-color: rgba(217, 119, 6, 0.2);"
                                            data-reason="Personal Reasons">Personal Reasons</button>
                                    <button type="button" class="reason-chip px-3 py-1.5 rounded-full text-sm font-medium border transition"
                                            style="background: rgba(217, 119, 6, 0.08); color: #92400E; border-color: rgba(217, 119, 6, 0.2);"
                                            data-reason="Academic Break">Academic Break</button>
                                    <button type="button" class="reason-chip px-3 py-1.5 rounded-full text-sm font-medium border transition"
                                            style="background: rgba(217, 119, 6, 0.08); color: #92400E; border-color: rgba(217, 119, 6, 0.2);"
                                            data-reason="Waiting for Batch">Waiting for Batch</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dropout Information -->
                    <div id="dropout-section" class="conditional-section <?= $student['current_status'] !== 'dropped' ? 'hidden' : '' ?>">
                        <div class="mt-6 pt-6 border-t" style="border-color: rgba(27, 60, 83, 0.1);">
                            <h2 class="text-xl font-bold mb-6 flex items-center" style="color: #1B3C53;">
                                <i class="fas fa-exclamation-triangle mr-2" style="color: #DC2626;"></i>
                                Dropout Information
                            </h2>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label for="dropout_date" class="form-label">Dropout Date</label>
                                    <input type="date" id="dropout_date" name="dropout_date" 
                                           value="<?= htmlspecialchars($student['dropout_date'] ?? date('Y-m-d')) ?>" 
                                           class="form-input">
                                </div>
                                
                                <div>
                                    <label for="dropout_reason" class="form-label">Dropout Reason</label>
                                    <textarea id="dropout_reason" name="dropout_reason" 
                                              class="form-input" rows="3"><?= htmlspecialchars($student['dropout_reason'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Parent/Guardian Information -->
                    <div class="mt-6 pt-6 border-t" style="border-color: rgba(27, 60, 83, 0.1);">
                        <h2 class="text-xl font-bold mb-6 flex items-center" style="color: #1B3C53;">
                            <i class="fas fa-users mr-2" style="color: #456882;"></i>
                            Parent/Guardian Information
                        </h2>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div>
                                <label for="father_name" class="form-label">Father's Name</label>
                                <input type="text" id="father_name" name="father_name" value="<?= htmlspecialchars($student['father_name']) ?>" 
                                       class="form-input">
                            </div>
                            
                            <div>
                                <label for="father_phone" class="form-label">Father's Phone</label>
                                <input type="tel" id="father_phone" name="father_phone" value="<?= htmlspecialchars($student['father_phone_number']) ?>" 
                                       class="form-input">
                            </div>
                            
                            <div>
                                <label for="father_email" class="form-label">Father's Email</label>
                                <input type="email" id="father_email" name="father_email" value="<?= htmlspecialchars($student['father_email']) ?>" 
                                       class="form-input">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="flex flex-col md:flex-row justify-end space-y-4 md:space-y-0 md:space-x-4 mt-8 pt-8 border-t" style="border-color: rgba(27, 60, 83, 0.1);">
                        <a href="student_view.php?id=<?= htmlspecialchars($student_id) ?>" 
                           class="btn-secondary text-center">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save mr-2"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Password Preview Modal -->
    <div id="passwordModal" class="modal <?= $show_password_modal ? 'show' : '' ?>">
        <div class="modal-content p-6">
            <h2 class="text-2xl font-bold mb-4 flex items-center" style="color: #1B3C53;">
                <i class="fas fa-key mr-2" style="color: #059669;"></i>
                Password Updated Successfully
            </h2>
            
            <div id="emailStatus" class="mb-4">
                <?php if ($email_sent): ?>
                    <div class="border-l-4 p-3 rounded-xl" style="background: rgba(5, 150, 105, 0.06); border-color: #059669; color: #065F46;">
                        <div class="flex items-center">
                            <i class="fas fa-check-circle mr-2" style="color: #059669;"></i>
                            <div>
                                <p class="font-bold">✓ Email Sent Successfully</p>
                                <p class="text-sm">Password update email has been sent to <?= htmlspecialchars($student_email) ?></p>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="border-l-4 p-3 rounded-xl" style="background: rgba(217, 119, 6, 0.06); border-color: #D97706; color: #92400E;">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2" style="color: #D97706;"></i>
                            <div>
                                <p class="font-bold">⚠️ Email Not Sent</p>
                                <p class="text-sm">Password was not sent via email. Please share manually.</p>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <p class="mb-4 text-gray-600">A new password has been generated for:</p>
            
            <div class="p-4 rounded-xl mb-4 border" style="background: #F8F6F3; border-color: rgba(27, 60, 83, 0.1);">
                <p class="font-semibold" style="color: #1B3C53;">
                    <i class="fas fa-user mr-2"></i>
                    <?= htmlspecialchars($student_name ?? '') ?>
                </p>
                <p class="text-gray-600">
                    <i class="fas fa-id-card mr-2"></i>
                    <?= htmlspecialchars($student_id ?? '') ?>
                </p>
                <p class="text-gray-600">
                    <i class="fas fa-envelope mr-2"></i>
                    <?= htmlspecialchars($student_email ?? '') ?>
                </p>
                <p class="text-gray-600">
                    <i class="fas fa-calendar-alt mr-2"></i>
                    Enrollment Date: <?= !empty($enrollment_date) ? date('F j, Y', strtotime($enrollment_date)) : '' ?>
                </p>
            </div>
            
            <p class="mb-2 text-gray-600">Please copy this password and share it securely:</p>
            
            <div class="password-display">
                <?= htmlspecialchars($generated_password ?? '') ?>
            </div>
            
            <p class="text-sm mb-4" style="color: #DC2626;">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                This password will not be shown again. Please save it now.
            </p>
            
            <div class="flex flex-wrap justify-end gap-3">
                <button type="button" id="copyGeneratedPassword" class="px-4 py-2 rounded-lg text-white transition shadow-sm"
                        style="background: #234C6A;" onmouseover="this.style.background='#1B3C53'" onmouseout="this.style.background='#234C6A'">
                    <i class="fas fa-copy mr-2"></i>Copy Password
                </button>
                <a href="student_view.php?id=<?= htmlspecialchars($student_id ?? '') ?>" class="px-4 py-2 rounded-lg text-white transition shadow-sm"
                   style="background: #456882;" onmouseover="this.style.background='#234C6A'" onmouseout="this.style.background='#456882'">
                    <i class="fas fa-eye mr-2"></i>View Student
                </a>
                <button type="button" id="closeModal" class="btn-secondary">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Back to Top -->
    <button id="scrollToTop" aria-label="Back to top">
        <i class="fas fa-chevron-up"></i>
    </button>

    <!-- Toast Messages -->
    <?php if (isset($_SESSION['password_email_sent'])): ?>
        <div class="toast success" id="toast">
            <i class="fas fa-check-circle"></i>
            <span>Email sent successfully to <?= htmlspecialchars($_SESSION['student_email']) ?></span>
        </div>
        <?php unset($_SESSION['password_email_sent']); ?>
    <?php elseif (isset($_SESSION['email_error'])): ?>
        <div class="toast error" id="toast">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= htmlspecialchars($_SESSION['email_error']) ?></span>
        </div>
        <?php unset($_SESSION['email_error']); ?>
    <?php endif; ?>

    <script>
        // ========== SIDEBAR TOGGLE ==========
        function toggleSidebarMobile() {
            const sidebar = document.querySelector('aside, .sidebar');
            if (sidebar) sidebar.classList.toggle('-translate-x-full');
        }
        document.getElementById('mobileSidebarToggle')?.addEventListener('click', toggleSidebarMobile);

        // ========== SHOW/HIDE CONDITIONAL SECTIONS ==========
        const statusSelect = document.getElementById('current_status');
        const onholdSection = document.getElementById('onhold-section');
        const dropoutSection = document.getElementById('dropout-section');

        function toggleSections() {
            const val = statusSelect.value;
            if (val === 'on hold') {
                onholdSection.classList.remove('hidden');
                dropoutSection.classList.add('hidden');
                if (!document.getElementById('on_hold_date').value) {
                    document.getElementById('on_hold_date').value = new Date().toISOString().split('T')[0];
                }
            } else if (val === 'dropped') {
                onholdSection.classList.add('hidden');
                dropoutSection.classList.remove('hidden');
                if (!document.getElementById('dropout_date').value) {
                    document.getElementById('dropout_date').value = new Date().toISOString().split('T')[0];
                }
            } else {
                onholdSection.classList.add('hidden');
                dropoutSection.classList.add('hidden');
            }
        }
        statusSelect.addEventListener('change', toggleSections);
        toggleSections();

        // ========== PROFILE PICTURE PREVIEW ==========
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.querySelector('.profile-picture');
                    if (img) {
                        if (img.tagName === 'IMG') {
                            img.src = e.target.result;
                        } else {
                            img.innerHTML = '<img src="' + e.target.result + '" class="w-full h-full rounded-full object-cover">';
                            img.classList.remove('flex', 'items-center', 'justify-center');
                            img.style.padding = '0';
                        }
                    }
                }
                reader.readAsDataURL(this.files[0]);
            }
        });

        // ========== PASSWORD GENERATOR ==========
        function shuffleString(str) {
            const arr = str.split('');
            for (let i = arr.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [arr[i], arr[j]] = [arr[j], arr[i]];
            }
            return arr.join('');
        }

        function getRandomChar(chars) {
            return chars.charAt(Math.floor(Math.random() * chars.length));
        }

        document.getElementById('generatePassword').addEventListener('click', function() {
            const length = 12;
            const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()";
            let password = "";
            password += getRandomChar("abcdefghijklmnopqrstuvwxyz");
            password += getRandomChar("ABCDEFGHIJKLMNOPQRSTUVWXYZ");
            password += getRandomChar("0123456789");
            password += getRandomChar("!@#$%^&*()");
            for (let i = 4; i < length; i++) {
                password += charset.charAt(Math.floor(Math.random() * charset.length));
            }
            password = shuffleString(password);
            
            const input = document.getElementById('new_password');
            input.value = password;
            document.getElementById('password_generated').value = '1';
            document.getElementById('copyPassword').style.display = 'block';
            updateStrength(password);
        });

        document.getElementById('copyPassword').addEventListener('click', function() {
            const input = document.getElementById('new_password');
            if (input.value) {
                navigator.clipboard.writeText(input.value).then(() => {
                    const orig = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check mr-2"></i>Copied!';
                    this.style.background = '#059669';
                    setTimeout(() => {
                        this.innerHTML = orig;
                        this.style.background = '#6B7280';
                    }, 2000);
                });
            }
        });

        function updateStrength(password) {
            const bar = document.getElementById('passwordStrengthBar');
            let score = 0;
            if (password.length >= 8) score++;
            if (/[a-z]/.test(password)) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/[0-9]/.test(password)) score++;
            if (/[^A-Za-z0-9]/.test(password)) score++;
            const pct = (score / 5) * 100;
            bar.style.width = pct + '%';
            if (pct < 40) bar.style.background = '#DC2626';
            else if (pct < 70) bar.style.background = '#D97706';
            else if (pct < 90) bar.style.background = '#234C6A';
            else bar.style.background = '#059669';
        }

        document.getElementById('new_password').addEventListener('input', function() {
            updateStrength(this.value);
            if (this.value) document.getElementById('password_generated').value = '0';
        });

        // ========== MODAL ==========
        const modal = document.getElementById('passwordModal');
        const closeModalBtn = document.getElementById('closeModal');

        closeModalBtn.addEventListener('click', function() {
            modal.classList.remove('show');
            const url = new URL(window.location);
            url.searchParams.delete('show_password');
            url.searchParams.delete('email_sent');
            window.history.replaceState({}, '', url);
        });

        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                modal.classList.remove('show');
                const url = new URL(window.location);
                url.searchParams.delete('show_password');
                url.searchParams.delete('email_sent');
                window.history.replaceState({}, '', url);
            }
        });

        document.getElementById('copyGeneratedPassword').addEventListener('click', function() {
            const text = document.querySelector('.password-display').textContent.trim();
            if (text) {
                navigator.clipboard.writeText(text).then(() => {
                    const orig = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-check mr-2"></i>Copied!';
                    this.style.background = '#059669';
                    setTimeout(() => {
                        this.innerHTML = orig;
                        this.style.background = '#234C6A';
                    }, 2000);
                });
            }
        });

        // ========== REASON CHIPS ==========
        document.querySelectorAll('.reason-chip').forEach(chip => {
            chip.addEventListener('click', function() {
                const ta = document.getElementById('on_hold_reason');
                const val = this.getAttribute('data-reason');
                const current = ta.value.trim();
                if (current === '') {
                    ta.value = val;
                } else if (!current.includes(val)) {
                    ta.value = current + ', ' + val;
                }
                this.style.background = 'rgba(217, 119, 6, 0.2)';
                setTimeout(() => {
                    this.style.background = 'rgba(217, 119, 6, 0.08)';
                }, 1000);
            });
        });

        // ========== FORM VALIDATION ==========
        document.getElementById('editStudentForm').addEventListener('submit', function(e) {
            const status = document.getElementById('current_status').value;
            
            if (status === 'on hold') {
                const reason = document.getElementById('on_hold_reason');
                const date = document.getElementById('on_hold_date');
                if (!reason.value.trim()) {
                    e.preventDefault();
                    reason.focus();
                    reason.style.borderColor = '#DC2626';
                    setTimeout(() => reason.style.borderColor = '', 3000);
                    alert('Please provide a reason for placing the student on hold.');
                    return false;
                }
                if (!date.value) {
                    e.preventDefault();
                    date.focus();
                    date.style.borderColor = '#DC2626';
                    setTimeout(() => date.style.borderColor = '', 3000);
                    alert('Please select an on hold date.');
                    return false;
                }
            }
            
            if (status === 'dropped') {
                const reason = document.getElementById('dropout_reason');
                const date = document.getElementById('dropout_date');
                if (!reason.value.trim()) {
                    e.preventDefault();
                    reason.focus();
                    reason.style.borderColor = '#DC2626';
                    setTimeout(() => reason.style.borderColor = '', 3000);
                    alert('Please provide a reason for dropping the student.');
                    return false;
                }
                if (!date.value) {
                    e.preventDefault();
                    date.focus();
                    date.style.borderColor = '#DC2626';
                    setTimeout(() => date.style.borderColor = '', 3000);
                    alert('Please select a dropout date.');
                    return false;
                }
            }
            
            const b1 = document.getElementById('batch_name').value;
            const b2 = document.getElementById('batch_name_2').value;
            const b3 = document.getElementById('batch_name_3').value;
            const b4 = document.getElementById('batch_name_4').value;
            const all = [b1, b2, b3, b4].filter(b => b && b !== 'None');
            if (new Set(all).size !== all.length) {
                e.preventDefault();
                alert('Duplicate batches detected. Please ensure all selected batches are unique.');
                return false;
            }
            
            return true;
        });

        // ========== BATCH DUPLICATE CHECK ==========
        document.querySelectorAll('select[name^="batch_name"]').forEach(sel => {
            sel.addEventListener('change', function() {
                const b1 = document.getElementById('batch_name').value;
                const b2 = document.getElementById('batch_name_2').value;
                const b3 = document.getElementById('batch_name_3').value;
                const b4 = document.getElementById('batch_name_4').value;
                document.querySelectorAll('select[name^="batch_name"]').forEach(s => {
                    s.style.borderColor = '';
                });
                const all = [b1, b2, b3, b4];
                const duplicates = all.filter((v, i) => v && v !== 'None' && all.indexOf(v) !== i);
                if (duplicates.length) {
                    document.querySelectorAll('select[name^="batch_name"]').forEach(s => {
                        if (s.value && s.value !== 'None' && all.filter(v => v === s.value).length > 1) {
                            s.style.borderColor = '#DC2626';
                        }
                    });
                }
            });
        });

        // ========== SCROLL REVEAL ==========
        const revealEls = document.querySelectorAll('.reveal');
        const revealObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                }
            });
        }, { threshold: 0.15 });
        revealEls.forEach(el => revealObserver.observe(el));

        // ========== SCROLL TO TOP ==========
        const scrollBtn = document.getElementById('scrollToTop');
        window.addEventListener('scroll', function() {
            if (window.scrollY > 400) scrollBtn.classList.add('visible');
            else scrollBtn.classList.remove('visible');
        });
        scrollBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

        // ========== HIDE TOAST AFTER 5s ==========
        setTimeout(() => {
            const t = document.getElementById('toast');
            if (t) {
                t.style.opacity = '0';
                t.style.transform = 'translateX(60px)';
                setTimeout(() => t?.remove(), 300);
            }
        }, 5000);

        // ========== DATE LIMITS ==========
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('enrollment_date').max = today;
        document.getElementById('on_hold_date').max = today;
        document.getElementById('dropout_date').max = today;
        
        const dob = document.getElementById('date_of_birth');
        const maxDob = new Date();
        maxDob.setFullYear(maxDob.getFullYear() - 10);
        dob.max = maxDob.toISOString().split('T')[0];
        const minDob = new Date();
        minDob.setFullYear(minDob.getFullYear() - 70);
        dob.min = minDob.toISOString().split('T')[0];
    </script>
</body>
</html>