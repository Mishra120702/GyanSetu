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

// Get all batches for dropdown
try {
    $stmt = $db->prepare("SELECT batch_id, batch_name FROM batches ORDER BY start_date DESC");
    $stmt->execute();
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare("SELECT id, name FROM courses ORDER BY name");
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $errors[] = 'Database error: ' . $e->getMessage();
}

$email_sent = false;
$email_error = null;
$generated_password = null;
$show_password_modal = false;
$send_email = true;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
    $send_email = isset($_POST['send_email']) && $_POST['send_email'] === 'yes';
    
    $studentData = [
        'first_name' => $_POST['first_name'],
        'last_name' => $_POST['last_name'],
        'email' => $_POST['email'],
        'phone_number' => $_POST['phone_number'] ?? null,
        'date_of_birth' => $_POST['date_of_birth'] ?? null,
        'enrollment_date' => $_POST['enrollment_date'] ?? date('Y-m-d'),
        'father_name' => $_POST['father_name'] ?? null,
        'father_phone_number' => $_POST['father_phone_number'] ?? null,
        'father_email' => $_POST['father_email'] ?? null,
        'state' => $_POST['state'] ?? null,
        'profile_picture' => null,
        'batch_name' => $_POST['batch_name'] ?? null,
        'course' => $_POST['course'] ?? null,
        'send_email' => $send_email
    ];
    
    if (!empty($_POST['generated_password'])) {
        $generated_password = $_POST['generated_password'];
        $studentData['password'] = password_hash($generated_password, PASSWORD_DEFAULT);
    } else if (!empty($_POST['password'])) {
        $generated_password = $_POST['password'];
        $studentData['password'] = password_hash($generated_password, PASSWORD_DEFAULT);
    } else {
        $generated_password = generateRandomPassword(12);
        $studentData['password'] = password_hash($generated_password, PASSWORD_DEFAULT);
    }
    
    $new_course_name = trim($_POST['new_course_name'] ?? '');
    if (!empty($new_course_name)) {
        $stmt = $db->prepare("SELECT id FROM courses WHERE name = ?");
        $stmt->execute([$new_course_name]);
        $existing_course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_course) {
            $studentData['course'] = $existing_course['id'];
        } else {
            $stmt = $db->prepare("INSERT INTO courses (name) VALUES (?)");
            $stmt->execute([$new_course_name]);
            $studentData['course'] = $db->lastInsertId();
        }
    }

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $target_dir = "../uploads/profile_pictures/";
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);
        
        $file_extension = pathinfo($_FILES["profile_picture"]["name"], PATHINFO_EXTENSION);
        $new_filename = "student_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        $check = getimagesize($_FILES["profile_picture"]["tmp_name"]);
        if ($check !== false) {
            if (move_uploaded_file($_FILES["profile_picture"]["tmp_name"], $target_file)) {
                $studentData['profile_picture'] = $target_file;
            }
        }
    }
    
    $errors = validateStudentData($studentData);
    
    if (empty($errors)) {
        $new_student_id = generateStudentId($db);
        $studentData['student_id'] = $new_student_id;
        
        if (createStudent($db, $studentData, $new_student_id)) {
            $email_sent = false;
            if ($send_email) {
                $email_sent = sendWelcomeEmail($studentData, $new_student_id, $generated_password);
            }
            
            $_SESSION['student_created'] = true;
            $_SESSION['generated_password'] = $generated_password;
            $_SESSION['student_email'] = $studentData['email'];
            $_SESSION['student_name'] = $studentData['first_name'] . ' ' . $studentData['last_name'];
            $_SESSION['student_id'] = $new_student_id;
            $_SESSION['enrollment_date'] = $studentData['enrollment_date'];
            $_SESSION['send_email'] = $send_email;
            $_SESSION['email_sent'] = $email_sent;
            $_SESSION['email_error'] = $email_sent ? null : ($send_email ? "Email could not be sent." : null);
            
            header("Location: add_student.php?show_password=true&send_email=" . ($send_email ? '1' : '0') . "&email_sent=" . ($email_sent ? '1' : '0'));
            exit();
        } else {
            $errors[] = 'Failed to create student. Please try again.';
        }
    }
}

function sendWelcomeEmail(array $studentData, string $studentId, string $password): bool {
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
        $mail->addAddress($studentData['email'], $studentData['first_name'] . ' ' . $studentData['last_name']);
        
        $mail->isHTML(true);
        $mail->Subject = 'Welcome to ASD Academy - Your Account Has Been Created';
        
        $enrollment_date_formatted = date('F j, Y', strtotime($studentData['enrollment_date']));
        
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #1B3C53; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%); color: #D2C1B6; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #D2C1B6; }
                .password-box { background: #fff; border: 2px dashed #456882; padding: 15px; margin: 20px 0; text-align: center; font-family: monospace; font-size: 18px; font-weight: bold; color: #1B3C53; }
                .warning { background: #D2C1B6; border: 1px solid #456882; padding: 10px; border-radius: 5px; margin: 20px 0; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #D2C1B6; font-size: 12px; color: #456882; }
                .info-box { background: rgba(210, 193, 182, 0.2); border-left: 4px solid #234C6A; padding: 15px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header"><h1>Welcome to ASD Academy</h1><p>Your Student Account Has Been Created</p></div>
                <div class="content">
                    <h2>Hello ' . htmlspecialchars($studentData['first_name'] . ' ' . $studentData['last_name']) . ',</h2>
                    <p>We are delighted to welcome you to ASD Academy! Your student account has been successfully created.</p>
                    <div class="info-box">
                        <h3>Your Account Details:</h3>
                        <ul>
                            <li><strong>Student ID:</strong> ' . htmlspecialchars($studentId) . '</li>
                            <li><strong>Full Name:</strong> ' . htmlspecialchars($studentData['first_name'] . ' ' . $studentData['last_name']) . '</li>
                            <li><strong>Email/Username:</strong> ' . htmlspecialchars($studentData['email']) . '</li>
                            <li><strong>Enrollment Date:</strong> ' . htmlspecialchars($enrollment_date_formatted) . '</li>
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
                        </ul>
                    </div>
                    <p><strong>Login URL:</strong> <a href="http://sthub.co.in/login.php">Gyan Setu</a></p>
                    <p>Best regards,<br><strong>ASD Academy Administration</strong></p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->AltBody = "Hello " . $studentData['first_name'] . " " . $studentData['last_name'] . ",\n\nWelcome to ASD Academy!\n\nStudent ID: " . $studentId . "\nEmail: " . $studentData['email'] . "\nPassword: " . $password . "\n\nLogin: http://sthub.co.in/login.php";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        $email_error = "Email could not be sent. Error: " . $mail->ErrorInfo;
        return false;
    }
}

function generateStudentId(PDO $db): string {
    $lastStudent = $db->query("SELECT student_id FROM students ORDER BY student_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($lastStudent) {
        $lastNumber = (int) substr($lastStudent['student_id'], 3);
        return 'STD' . str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
    }
    return 'STD001';
}

function createStudent(PDO $db, array $studentData, string $studentId): bool {
    try {
        $db->beginTransaction();
        
        $userStmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'student')");
        $userStmt->execute([$studentData['first_name'] . ' ' . $studentData['last_name'], $studentData['email'], $studentData['password']]);
        $userId = $db->lastInsertId();
        
        $studentStmt = $db->prepare("INSERT INTO students (
            student_id, user_id, first_name, last_name, email, phone_number, 
            date_of_birth, enrollment_date, current_status, password_hash,
            father_name, father_phone_number, father_email, state, profile_picture,
            batch_name, course
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $result = $studentStmt->execute([
            $studentId, $userId, $studentData['first_name'], $studentData['last_name'],
            $studentData['email'], $studentData['phone_number'], $studentData['date_of_birth'],
            $studentData['enrollment_date'], $studentData['password'], $studentData['father_name'],
            $studentData['father_phone_number'], $studentData['father_email'], $studentData['state'],
            $studentData['profile_picture'], $studentData['batch_name'], $studentData['course']
        ]);
        
        $db->commit();
        return $result;
    } catch (PDOException $e) {
        $db->rollBack();
        return false;
    }
}

function validateStudentData(array $data): array {
    $errors = [];
    if (empty($data['first_name'])) $errors[] = 'First name is required';
    if (empty($data['last_name'])) $errors[] = 'Last name is required';
    if (empty($data['email'])) $errors[] = 'Email is required';
    elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format';
    
    if (!empty($data['enrollment_date'])) {
        $enrollment_date = DateTime::createFromFormat('Y-m-d', $data['enrollment_date']);
        $today = new DateTime();
        if (!$enrollment_date) $errors[] = 'Invalid enrollment date format';
        elseif ($enrollment_date > $today) $errors[] = 'Enrollment date cannot be in the future';
    }
    return $errors;
}

function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    $password .= $chars[rand(0, 25)]; // lowercase
    $password .= $chars[rand(26, 51)]; // uppercase
    $password .= $chars[rand(52, 61)]; // number
    $password .= $chars[rand(62, 69)]; // special
    for ($i = 4; $i < $length; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return str_shuffle($password);
}

$nextStudentId = generateStudentId($db);

if (isset($_GET['show_password']) && $_GET['show_password'] === 'true' && isset($_SESSION['student_created'])) {
    $show_password_modal = true;
    $generated_password = $_SESSION['generated_password'];
    $student_email = $_SESSION['student_email'];
    $student_name = $_SESSION['student_name'];
    $student_id = $_SESSION['student_id'];
    $enrollment_date = $_SESSION['enrollment_date'];
    $send_email = $_SESSION['send_email'];
    $email_sent = $_SESSION['email_sent'];
    $email_error = $_SESSION['email_error'] ?? null;
    
    unset($_SESSION['student_created'], $_SESSION['generated_password'], $_SESSION['student_email'], 
          $_SESSION['student_name'], $_SESSION['student_id'], $_SESSION['enrollment_date'], 
          $_SESSION['send_email'], $_SESSION['email_sent'], $_SESSION['email_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Student | ASD Academy</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --deep-navy: #1B3C53;
            --navy: #234C6A;
            --steel-blue: #456882;
            --warm-sand: #D2C1B6;
            
            --bg-primary: #F8FAFC;
            --bg-secondary: #FFFFFF;
            --bg-card: #FFFFFF;
            --bg-input: #F8FAFC;
            --bg-hover: rgba(27, 60, 83, 0.05);
            
            --text-primary: #1B3C53;
            --text-secondary: #234C6A;
            --text-muted: #456882;
            --text-heading: #1B3C53;
            
            --border-color: rgba(27, 60, 83, 0.1);
            --border-focus: #456882;
            
            --shadow-sm: 0 1px 3px rgba(27, 60, 83, 0.08);
            --shadow-md: 0 4px 6px rgba(27, 60, 83, 0.1);
            --shadow-lg: 0 10px 25px rgba(27, 60, 83, 0.12);
            --shadow-xl: 0 20px 40px rgba(27, 60, 83, 0.15);
            
            --gradient-primary: linear-gradient(135deg, #1B3C53, #234C6A);
            --gradient-accent: linear-gradient(135deg, #234C6A, #456882);
            --gradient-light: linear-gradient(135deg, #D2C1B6, #E5D9CF);
            
            --radius-sm: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.25rem;
            --radius-2xl: 1.5rem;
            
            --transition-fast: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-normal: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--steel-blue); border-radius: 10px; }

        .main-content {
            position: relative;
            z-index: 1;
            margin-left: 16rem;
            padding: 2rem 2.5rem;
            min-height: 100vh;
            width: calc(100% - 16rem);
        }

        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 1.5rem;
            }
        }

        /* Form Styles */
        .form-card {
            background: var(--bg-card);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(210, 193, 182, 0.3);
            transition: all var(--transition-normal);
        }

        .form-card:hover {
            box-shadow: var(--shadow-xl);
            border-color: var(--steel-blue);
        }

        .input-field {
            width: 100%;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            border: 2px solid rgba(210, 193, 182, 0.3);
            background: var(--bg-input);
            color: var(--text-primary);
            font-size: 0.9rem;
            font-family: inherit;
            transition: all var(--transition-fast);
        }

        .input-field:focus {
            outline: none;
            border-color: var(--steel-blue);
            box-shadow: 0 0 0 4px rgba(69, 104, 130, 0.1);
        }

        .input-field::placeholder {
            color: rgba(69, 104, 130, 0.5);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: var(--radius-lg);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all var(--transition-normal);
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(27, 60, 83, 0.4);
        }

        .btn-secondary {
            background: var(--gradient-light);
            color: var(--deep-navy);
            border: 1px solid var(--warm-sand);
            padding: 0.65rem 1.5rem;
            border-radius: var(--radius-lg);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-normal);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .section-title {
            color: var(--text-heading);
            font-weight: 700;
            font-size: 1.2rem;
            position: relative;
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 3px;
            background: var(--gradient-primary);
            border-radius: 3px;
        }

        .password-display {
            font-family: 'Courier New', monospace;
            font-size: 1.3rem;
            letter-spacing: 3px;
            background: rgba(210, 193, 182, 0.2);
            border: 2px dashed var(--steel-blue);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-lg);
            text-align: center;
            color: var(--deep-navy);
            font-weight: 700;
        }

        .modal-overlay {
            display: flex;
            position: fixed;
            inset: 0;
            background: rgba(27, 60, 83, 0.7);
            backdrop-filter: blur(8px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal-content {
            background: white;
            border: 2px solid var(--warm-sand);
            border-radius: var(--radius-2xl);
            padding: 2rem;
            max-width: 520px;
            width: 90%;
            box-shadow: var(--shadow-xl);
            animation: scaleIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.85rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-primary {
            background: rgba(27, 60, 83, 0.1);
            color: var(--deep-navy);
            border: 1px solid rgba(27, 60, 83, 0.2);
        }

        .badge-success {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
    </style>
</head>
<body>
    <?php include '../sidebar.php'; ?>
    <?php include '../header.php'; ?>

    <main class="main-content">
        <!-- Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-8">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center text-white text-2xl shadow-lg flex-shrink-0" style="background: var(--gradient-primary);">
                    <i class="fas fa-user-plus"></i>
                </div>
                <div>
                    <h1 class="text-3xl lg:text-4xl font-extrabold tracking-tight" style="color: var(--deep-navy);">
                        Add New Student
                    </h1>
                    <p class="text-sm mt-1" style="color: var(--steel-blue);">Create a new student account with email notification</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="upload_student.php" class="btn-secondary text-sm">
                    <i class="fas fa-file-import mr-2"></i> Upload Excel
                </a>
            </div>
        </div>

        <!-- Errors -->
        <?php if (!empty($errors)): ?>
        <div class="mb-6 p-4 rounded-xl" style="background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.25);">
            <div class="flex items-center gap-3">
                <i class="fas fa-exclamation-circle text-xl" style="color: #ef4444;"></i>
                <div>
                    <p class="font-bold" style="color: #ef4444;">Please fix the following errors:</p>
                    <ul class="mt-1 text-sm list-disc list-inside" style="color: #f87171;">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="form-card p-8">
            <form id="studentForm" method="post" enctype="multipart/form-data">
                <input type="hidden" name="add_student" value="1">
                <input type="hidden" id="generated_password" name="generated_password" value="">

                <!-- Profile Picture + Student ID -->
                <div class="flex flex-col md:flex-row items-start gap-8 mb-10">
                    <div class="relative group flex-shrink-0">
                        <div class="w-28 h-28 rounded-full flex items-center justify-center overflow-hidden shadow-lg transition-all duration-500 group-hover:scale-105" 
                             style="background: linear-gradient(135deg, rgba(210,193,182,0.3), rgba(69,104,130,0.2));" id="avatarContainer">
                            <img id="profilePreview" class="hidden w-full h-full object-cover" src="" alt="Preview">
                            <i class="fas fa-user-graduate text-5xl" id="defaultAvatar" style="color: var(--steel-blue);"></i>
                        </div>
                        <label for="profile_picture" class="absolute -bottom-1 -right-1 text-white rounded-full p-3 cursor-pointer transition-all duration-300 transform hover:scale-110 shadow-lg z-10"
                               style="background: var(--gradient-primary);">
                            <i class="fas fa-camera text-sm"></i>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" class="hidden" onchange="previewImage(event)">
                        </label>
                    </div>
                    <div class="flex-1">
                        <div class="p-4 rounded-xl" style="background: rgba(210,193,182,0.15); border: 1px solid rgba(210,193,182,0.3);">
                            <div class="flex items-center gap-4">
                                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-xl" style="background: rgba(27,60,83,0.1); color: var(--deep-navy);">
                                    <i class="fas fa-id-card"></i>
                                </div>
                                <div>
                                    <p class="text-xs uppercase tracking-wider font-bold" style="color: var(--steel-blue);">Auto-Generated Student ID</p>
                                    <p class="text-2xl font-extrabold" style="color: var(--deep-navy);"><?= $nextStudentId ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Personal Information -->
                <h3 class="section-title"><i class="fas fa-user mr-2"></i>Personal Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">First Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="first_name" class="input-field" placeholder="Enter first name" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Last Name <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="last_name" class="input-field" placeholder="Enter last name" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Email <span style="color:#ef4444;">*</span></label>
                        <input type="email" name="email" class="input-field" placeholder="student@example.com" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Phone</label>
                        <input type="tel" name="phone_number" class="input-field" placeholder="+91 98765 43210">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Date of Birth</label>
                        <input type="date" name="date_of_birth" class="input-field">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Enrollment Date</label>
                        <input type="date" name="enrollment_date" class="input-field" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">State</label>
                        <input type="text" name="state" class="input-field" placeholder="Enter state">
                    </div>
                </div>

                <!-- Password -->
                <h3 class="section-title"><i class="fas fa-key mr-2"></i>Account Password</h3>
                <div class="mb-8">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Password <span class="font-normal">(auto-generated if empty)</span></label>
                            <div class="flex gap-2">
                                <input type="text" id="password" name="password" class="input-field flex-1" placeholder="Leave empty to auto-generate">
                                <button type="button" id="generatePassword" class="btn-secondary !px-3 flex-shrink-0" title="Generate Random Password">
                                    <i class="fas fa-sync-alt"></i>
                                </button>
                                <button type="button" id="copyPassword" class="btn-secondary !px-3 flex-shrink-0 hidden" title="Copy Password">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                            <div class="h-1.5 rounded-full mt-2 overflow-hidden" style="background: rgba(210,193,182,0.2);">
                                <div id="passwordStrengthBar" class="h-full rounded-full transition-all duration-500" style="width:0%;"></div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Email Notification</label>
                            <div class="flex gap-4 p-3 rounded-xl" style="background: rgba(210,193,182,0.15);">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="send_email" value="yes" checked class="w-4 h-4" style="accent-color: var(--deep-navy);">
                                    <span class="text-sm font-medium" style="color: var(--deep-navy);"><i class="fas fa-paper-plane text-green-500 mr-1"></i> Send Email</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="radio" name="send_email" value="no" class="w-4 h-4" style="accent-color: var(--deep-navy);">
                                    <span class="text-sm font-medium" style="color: var(--deep-navy);"><i class="fas fa-ban text-red-500 mr-1"></i> Don't Send</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Parent Information -->
                <h3 class="section-title"><i class="fas fa-users mr-2"></i>Parent/Guardian Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Parent Name</label>
                        <input type="text" name="father_name" class="input-field" placeholder="Enter parent name">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Parent Phone</label>
                        <input type="tel" name="father_phone_number" class="input-field" placeholder="+91 98765 43210">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Parent Email</label>
                        <input type="email" name="father_email" class="input-field" placeholder="parent@example.com">
                    </div>
                </div>

                <!-- Academic Information -->
                <h3 class="section-title"><i class="fas fa-graduation-cap mr-2"></i>Academic Information</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-8">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Batch</label>
                        <select name="batch_name" class="input-field">
                            <option value="">Select Batch</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>"><?= htmlspecialchars($batch['batch_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Course</label>
                        <select name="course" class="input-field">
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= htmlspecialchars($course['id']) ?>"><?= htmlspecialchars($course['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider mb-1.5" style="color: var(--steel-blue);">Or New Course</label>
                        <input type="text" name="new_course_name" class="input-field" placeholder="Enter new course name">
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex justify-end gap-4 pt-6" style="border-top: 1px solid rgba(210,193,182,0.3);">
                    <a href="../dashboard/dashboard.php" class="btn-secondary">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </a>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-user-plus mr-2"></i> Add Student
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Success Modal -->
    <?php if ($show_password_modal): ?>
    <div class="modal-overlay" id="passwordModal" onclick="if(event.target===this)closeModal()">
        <div class="modal-content">
            <div class="text-center mb-4">
                <div class="w-16 h-16 rounded-full mx-auto mb-3 flex items-center justify-center text-3xl" style="background: rgba(34,197,94,0.12); color: #22c55e;">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2 class="text-xl font-extrabold" style="color: var(--deep-navy);">Student Created!</h2>
            </div>

            <?php if ($send_email): ?>
                <?php if ($email_sent): ?>
                    <div class="p-3 rounded-xl mb-4" style="background: rgba(34,197,94,0.08); border: 1px solid rgba(34,197,94,0.2);">
                        <p class="text-sm font-bold" style="color: #22c55e;"><i class="fas fa-check-circle mr-1"></i> Email Sent Successfully</p>
                        <p class="text-xs mt-1" style="color: var(--steel-blue);">Welcome email sent to <?= htmlspecialchars($student_email) ?></p>
                    </div>
                <?php else: ?>
                    <div class="p-3 rounded-xl mb-4" style="background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.2);">
                        <p class="text-sm font-bold" style="color: #f59e0b;"><i class="fas fa-exclamation-triangle mr-1"></i> Email Failed</p>
                        <p class="text-xs mt-1" style="color: var(--steel-blue);">Please share password manually.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="p-3 rounded-xl mb-4" style="background: rgba(59,130,246,0.08); border: 1px solid rgba(59,130,246,0.2);">
                    <p class="text-sm font-bold" style="color: #3b82f6;"><i class="fas fa-info-circle mr-1"></i> Email Not Sent</p>
                    <p class="text-xs mt-1" style="color: var(--steel-blue);">Share password manually with the student.</p>
                </div>
            <?php endif; ?>

            <div class="p-4 rounded-xl mb-4" style="background: rgba(210,193,182,0.15);">
                <div class="flex justify-between text-sm mb-1">
                    <span style="color: var(--steel-blue);">Student:</span>
                    <span class="font-semibold" style="color: var(--deep-navy);"><?= htmlspecialchars($student_name) ?></span>
                </div>
                <div class="flex justify-between text-sm mb-1">
                    <span style="color: var(--steel-blue);">ID:</span>
                    <span class="font-semibold" style="color: var(--deep-navy);"><?= htmlspecialchars($student_id) ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span style="color: var(--steel-blue);">Email:</span>
                    <span class="font-semibold" style="color: var(--deep-navy);"><?= htmlspecialchars($student_email) ?></span>
                </div>
            </div>

            <p class="text-xs mb-3 text-center" style="color: var(--steel-blue);">Copy this password — it won't be shown again!</p>
            <div class="password-display mb-4" id="generatedPasswordText"><?= htmlspecialchars($generated_password) ?></div>

            <div class="flex gap-3">
                <button onclick="copyGeneratedPassword()" class="btn-primary flex-1 !text-sm">
                    <i class="fas fa-copy mr-1"></i> Copy Password
                </button>
                <a href="student_view.php?id=<?= htmlspecialchars($student_id) ?>" class="btn-secondary flex-1 !text-sm text-center">
                    <i class="fas fa-eye mr-1"></i> View Student
                </a>
            </div>
            <button onclick="closeModal()" class="w-full mt-3 text-sm transition-colors" style="color: var(--steel-blue);">Close</button>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Profile Preview
        function previewImage(event) {
            const reader = new FileReader();
            reader.onload = function() {
                const output = document.getElementById('profilePreview');
                output.src = reader.result;
                output.classList.remove('hidden');
                document.getElementById('defaultAvatar').classList.add('hidden');
            };
            reader.readAsDataURL(event.target.files[0]);
        }

        // Password Generator
        function generatePassword() {
            const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
            let pwd = chars[Math.floor(Math.random()*26)] + chars[Math.floor(Math.random()*26)+26] + chars[Math.floor(Math.random()*10)+52] + chars[Math.floor(Math.random()*8)+62];
            for (let i = 4; i < 12; i++) pwd += chars[Math.floor(Math.random() * chars.length)];
            return pwd.split('').sort(() => Math.random() - 0.5).join('');
        }

        document.getElementById('generatePassword').addEventListener('click', function() {
            const pwd = generatePassword();
            document.getElementById('password').value = pwd;
            document.getElementById('generated_password').value = pwd;
            document.getElementById('copyPassword').classList.remove('hidden');
            updateStrength(pwd);
        });

        document.getElementById('copyPassword').addEventListener('click', function() {
            const pwd = document.getElementById('password');
            if (pwd.value) {
                navigator.clipboard.writeText(pwd.value);
                this.innerHTML = '<i class="fas fa-check text-green-500"></i>';
                setTimeout(() => { this.innerHTML = '<i class="fas fa-copy"></i>'; }, 2000);
            }
        });

        document.getElementById('password').addEventListener('input', function() {
            updateStrength(this.value);
            document.getElementById('generated_password').value = this.value;
            if (this.value) document.getElementById('copyPassword').classList.remove('hidden');
        });

        function updateStrength(pwd) {
            const bar = document.getElementById('passwordStrengthBar');
            let score = 0;
            if (pwd.length >= 8) score++;
            if (/[a-z]/.test(pwd)) score++;
            if (/[A-Z]/.test(pwd)) score++;
            if (/[0-9]/.test(pwd)) score++;
            if (/[^A-Za-z0-9]/.test(pwd)) score++;
            const w = (score/5)*100;
            bar.style.width = w + '%';
            bar.style.background = w < 40 ? '#ef4444' : w < 70 ? '#f59e0b' : w < 90 ? '#456882' : '#16a34a';
        }

        // Modal
        function copyGeneratedPassword() {
            const text = document.getElementById('generatedPasswordText').textContent;
            navigator.clipboard.writeText(text);
            const btn = event.target;
            btn.innerHTML = '<i class="fas fa-check mr-1"></i> Copied!';
            btn.style.background = 'linear-gradient(135deg, #16a34a, #22c55e)';
            setTimeout(() => { 
                btn.innerHTML = '<i class="fas fa-copy mr-1"></i> Copy Password'; 
                btn.style.background = '';
            }, 2500);
        }

        function closeModal() {
            document.getElementById('passwordModal').style.display = 'none';
            window.history.replaceState({}, '', window.location.pathname);
        }

        // Keyboard shortcut to close modal
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                const m = document.getElementById('passwordModal');
                if (m) m.style.display = 'none';
            }
        });
    </script>
</body>
</html>