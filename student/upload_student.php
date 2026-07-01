<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$message = '';
$error = '';
$successCount = 0;
$skipped = [];

if (isset($_POST['upload'])) {
    if (isset($_FILES['csv_file'])) {
        $file = $_FILES['csv_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = "Error uploading file. Error code: " . $file['error'];
        } else {
            $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($fileType != 'csv') {
                $error = "Only CSV files are allowed.";
            } else {
                try {
                    $handle = fopen($file['tmp_name'], 'r');
                    $header = fgetcsv($handle);
                    $rowNumber = 1;
                    
                    while (($row = fgetcsv($handle)) !== FALSE) {
                        $rowNumber++;
                        
                        if (count($row) < 13) {
                            $skipped[] = "Row $rowNumber: Insufficient data columns (need 13 fields)";
                            continue;
                        }
                        
                        $studentId = trim($row[0] ?? '');
                        $firstName = trim($row[1] ?? '');
                        $lastName = trim($row[2] ?? '');
                        $email = trim($row[3] ?? '');
                        $phone = trim($row[4] ?? '');
                        $dob = trim($row[5] ?? '');
                        $batchId = trim($row[6] ?? '');
                        $courseName = trim($row[7] ?? '');
                        $enrollmentDate = trim($row[8] ?? date('Y-m-d'));
                        $status = strtolower(trim($row[9] ?? 'active'));
                        $fatherName = trim($row[10] ?? '');
                        $fatherPhone = trim($row[11] ?? '');
                        $password = trim($row[12] ?? '');
                        
                        $validationErrors = [];
                        if (empty($studentId)) $validationErrors[] = "Student ID required";
                        if (empty($firstName)) $validationErrors[] = "First name required";
                        if (empty($lastName)) $validationErrors[] = "Last name required";
                        if (empty($email)) $validationErrors[] = "Email required";
                        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $validationErrors[] = "Invalid email";
                        if (empty($password)) $validationErrors[] = "Password required";
                        if (empty($courseName)) $validationErrors[] = "Course name required";
                        
                        if (!empty($validationErrors)) {
                            $skipped[] = "Row $rowNumber: " . implode(", ", $validationErrors);
                            continue;
                        }
                        
                        $batchCheck = $db->prepare("SELECT batch_id FROM batches WHERE batch_id = ?");
                        $batchCheck->execute([$batchId]);
                        if ($batchCheck->rowCount() === 0) {
                            $skipped[] = "Row $rowNumber: Batch ID $batchId not found";
                            continue;
                        }
                        
                        $courseCheck = $db->prepare("SELECT id FROM courses WHERE name = ?");
                        $courseCheck->execute([$courseName]);
                        $courseId = null;
                        if ($courseCheck->rowCount() === 0) {
                            $insertCourse = $db->prepare("INSERT INTO courses (name) VALUES (?)");
                            if ($insertCourse->execute([$courseName])) {
                                $courseId = $db->lastInsertId();
                            } else {
                                $skipped[] = "Row $rowNumber: Failed to create course '$courseName'";
                                continue;
                            }
                        } else {
                            $courseData = $courseCheck->fetch(PDO::FETCH_ASSOC);
                            $courseId = $courseData['id'];
                        }
                        
                        if (!in_array($status, ['active', 'dropped', 'transferred', 'completed'])) {
                            $status = 'active';
                        }
                        
                        $dobFormatted = null;
                        if (!empty($dob)) {
                            $dateParts = preg_split('[-/.]', $dob);
                            if (count($dateParts) === 3) {
                                $dobFormatted = strlen($dateParts[0]) === 4 ? $dob : $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                            } else {
                                $timestamp = strtotime($dob);
                                if ($timestamp !== false) $dobFormatted = date('Y-m-d', $timestamp);
                                else { $skipped[] = "Row $rowNumber: Invalid DOB: $dob"; continue; }
                            }
                            if (!DateTime::createFromFormat('Y-m-d', $dobFormatted)) {
                                $skipped[] = "Row $rowNumber: Invalid DOB format: $dob"; continue;
                            }
                        }
                        
                        $enrollmentDateFormatted = date('Y-m-d');
                        if (!empty($enrollmentDate)) {
                            $dateParts = preg_split('[-/.]', $enrollmentDate);
                            if (count($dateParts) === 3) {
                                $enrollmentDateFormatted = strlen($dateParts[0]) === 4 ? $enrollmentDate : $dateParts[2] . '-' . $dateParts[1] . '-' . $dateParts[0];
                            } else {
                                $timestamp = strtotime($enrollmentDate);
                                if ($timestamp !== false) $enrollmentDateFormatted = date('Y-m-d', $timestamp);
                                else { $skipped[] = "Row $rowNumber: Invalid enrollment date"; continue; }
                            }
                        }
                        
                        $studentCheck = $db->prepare("SELECT student_id FROM students WHERE student_id = ? OR email = ?");
                        $studentCheck->execute([$studentId, $email]);
                        if ($studentCheck->rowCount() > 0) {
                            $skipped[] = "Row $rowNumber: Student ID $studentId or email $email already exists";
                            continue;
                        }
                        
                        $userCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
                        $userCheck->execute([$email]);
                        if ($userCheck->rowCount() > 0) {
                            $skipped[] = "Row $rowNumber: User with email $email already exists";
                            continue;
                        }
                        
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $userStmt = $db->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES (?, ?, ?, 'student', 'active')");
                        $fullName = $firstName . ' ' . $lastName;
                        
                        if ($userStmt->execute([$fullName, $email, $passwordHash])) {
                            $userId = $db->lastInsertId();
                            
                            $stmt = $db->prepare("INSERT INTO students (student_id, user_id, first_name, last_name, email, phone_number, date_of_birth, enrollment_date, current_status, batch_name, father_name, father_phone_number, course) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            
                            if ($stmt->execute([$studentId, $userId, $firstName, $lastName, $email, $phone, $dobFormatted, $enrollmentDateFormatted, $status, $batchId, $fatherName, $fatherPhone, $courseName])) {
                                $successCount++;
                                $updateBatch = $db->prepare("UPDATE batches SET current_enrollment = current_enrollment + 1 WHERE batch_id = ?");
                                $updateBatch->execute([$batchId]);
                            } else {
                                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
                                $skipped[] = "Row $rowNumber: Failed to insert student record";
                            }
                        } else {
                            $skipped[] = "Row $rowNumber: Failed to create user account";
                        }
                    }
                    fclose($handle);
                    
                    if ($successCount > 0) {
                        $message = "Successfully imported $successCount student records.";
                    } else {
                        $message = "No students were imported.";
                    }
                    
                    if (!empty($skipped)) {
                        $message .= " Skipped: " . count($skipped) . " rows.";
                    }
                    
                    $_SESSION['upload_message'] = $message;
                    $_SESSION['upload_success_count'] = $successCount;
                    $_SESSION['upload_skipped_rows'] = $skipped;
                    header("Location: students_list.php?upload_success=true");
                    exit;
                    
                } catch (Exception $e) {
                    $error = "Error processing file: " . $e->getMessage();
                }
            }
        }
    } else {
        $error = "No file uploaded.";
    }
}

$batches = $db->query("SELECT batch_id, batch_name FROM batches WHERE status IN ('upcoming', 'ongoing') ORDER BY batch_id")->fetchAll(PDO::FETCH_ASSOC);
$courses_list = $db->query("SELECT id, name FROM courses ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$totalStudents = $db->query("SELECT COUNT(*) as count FROM students")->fetch(PDO::FETCH_ASSOC)['count'];
$totalBatches = count($batches);
$totalCourses = count($courses_list);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Students | ASD Academy</title>

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
            --bg-hover: rgba(210, 193, 182, 0.15);
            --bg-drop: #F8FAFC;
            --bg-format-item: #FFFFFF;
            --bg-sidebar: #D2C1B6;
            
            --text-primary: #1B3C53;
            --text-secondary: #234C6A;
            --text-muted: #456882;
            --text-heading: #1B3C53;
            
            --border-color: rgba(210, 193, 182, 0.4);
            --border-dashed: rgba(210, 193, 182, 0.5);
            --border-focus: #456882;
            
            --shadow-sm: 0 1px 3px rgba(27, 60, 83, 0.08);
            --shadow-md: 0 4px 6px rgba(27, 60, 83, 0.1);
            --shadow-lg: 0 10px 25px rgba(27, 60, 83, 0.12);
            --shadow-xl: 0 20px 40px rgba(27, 60, 83, 0.15);
            
            --gradient-primary: linear-gradient(135deg, #1B3C53, #234C6A);
            --gradient-accent: linear-gradient(135deg, #234C6A, #456882);
            --gradient-light: linear-gradient(135deg, #D2C1B6, #E5D9CF);
            --gradient-upload: linear-gradient(135deg, #1B3C53, #456882);
            
            --radius-sm: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.25rem;
            --radius-2xl: 1.5rem;
            --radius-full: 9999px;
            
            --transition-fast: 0.2s ease;
            --transition-normal: 0.3s ease;
            --transition-slow: 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
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
            .main-content { margin-left: 0; width: 100%; padding: 1.5rem; }
        }

        .glass-card {
            background: var(--bg-card);
            border: 1px solid rgba(210, 193, 182, 0.3);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-lg);
            transition: all var(--transition-slow);
        }

        .glass-card:hover {
            box-shadow: var(--shadow-xl);
            border-color: var(--steel-blue);
        }

        .stat-card {
            background: var(--bg-card);
            border-radius: var(--radius-2xl);
            padding: 1rem;
            box-shadow: var(--shadow-lg);
            transition: all var(--transition-slow);
            border: 1px solid rgba(210, 193, 182, 0.3);
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-xl);
            border-color: var(--steel-blue);
        }

        .btn-primary {
            background: var(--gradient-primary);
            color: white;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: var(--radius-lg);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            transition: all var(--transition-slow);
            box-shadow: 0 4px 15px rgba(27, 60, 83, 0.3);
            width: 100%;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(27, 60, 83, 0.4);
        }

        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .btn-outline {
            background: white;
            border: 1px solid rgba(210, 193, 182, 0.3);
            color: var(--deep-navy);
            padding: 0.4rem 1rem;
            border-radius: var(--radius-lg);
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition-slow);
            box-shadow: var(--shadow-sm);
            font-size: 0.75rem;
        }

        .btn-outline:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--steel-blue);
        }

        /* Drop Zone */
        .drop-zone {
            border: 2px dashed rgba(210, 193, 182, 0.5);
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            text-align: center;
            cursor: pointer;
            transition: all var(--transition-normal);
            background: rgba(210, 193, 182, 0.08);
        }

        .drop-zone:hover, .drop-zone.active {
            border-color: var(--steel-blue);
            background: rgba(210, 193, 182, 0.15);
        }

        .drop-zone .drop-icon {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 50%;
            background: rgba(27, 60, 83, 0.1);
            color: var(--deep-navy);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            margin: 0 auto 0.4rem;
            transition: all var(--transition-slow);
        }

        .drop-zone:hover .drop-icon {
            transform: scale(1.1);
            background: rgba(27, 60, 83, 0.15);
        }

        /* Progress Bar */
        .progress-container {
            height: 6px;
            border-radius: var(--radius-full);
            background: rgba(210, 193, 182, 0.2);
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            border-radius: var(--radius-full);
            background: var(--gradient-primary);
            transition: width 0.5s ease;
        }

        /* Tags */
        .tag {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.15rem 0.6rem;
            border-radius: var(--radius-full);
            font-size: 0.65rem;
            font-weight: 600;
        }

        .tag-navy {
            background: rgba(27, 60, 83, 0.1);
            color: var(--deep-navy);
            border: 1px solid rgba(27, 60, 83, 0.2);
        }

        .tag-steel {
            background: rgba(69, 104, 130, 0.1);
            color: var(--steel-blue);
            border: 1px solid rgba(69, 104, 130, 0.2);
        }

        .tag-sand {
            background: rgba(210, 193, 182, 0.2);
            color: var(--navy);
            border: 1px solid rgba(210, 193, 182, 0.4);
        }

        .tag-success {
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        /* Format Item */
        .format-item {
            display: flex;
            gap: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-lg);
            transition: all var(--transition-fast);
            background: rgba(210, 193, 182, 0.05);
            border: 1px solid transparent;
            align-items: flex-start;
        }

        .format-item:hover {
            background: rgba(210, 193, 182, 0.15);
            border-color: rgba(210, 193, 182, 0.3);
            transform: translateX(3px);
        }

        .format-number {
            width: 1.5rem;
            height: 1.5rem;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            font-weight: 700;
            flex-shrink: 0;
            background: rgba(27, 60, 83, 0.1);
            color: var(--deep-navy);
        }

        .format-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--deep-navy);
            margin-bottom: 0.1rem;
        }

        .format-desc {
            font-size: 0.65rem;
            color: var(--steel-blue);
            line-height: 1.3;
        }

        /* Tips */
        .tip-item {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            padding: 0.4rem 0.6rem;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
            border: 1px solid transparent;
            background: rgba(210, 193, 182, 0.05);
        }

        .tip-item:hover {
            background: rgba(210, 193, 182, 0.15);
            border-color: rgba(210, 193, 182, 0.3);
            transform: translateX(3px);
        }

        .tip-icon {
            width: 1.5rem;
            height: 1.5rem;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6rem;
            flex-shrink: 0;
        }

        .tip-title {
            font-size: 0.7rem;
            font-weight: 600;
            color: var(--deep-navy);
            margin-bottom: 0.1rem;
        }

        .tip-desc {
            font-size: 0.6rem;
            color: var(--steel-blue);
            line-height: 1.3;
        }

        /* Alerts */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: var(--radius-xl);
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            animation: scaleIn 0.3s ease-out;
        }

        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .alert-warning {
            background: rgba(245, 158, 11, 0.08);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        /* Table */
        .table-modern {
            width: 100%;
            border-collapse: collapse;
        }

        .table-modern thead th {
            background: rgba(210, 193, 182, 0.1);
            color: var(--steel-blue);
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 0.6rem 0.75rem;
            border-bottom: 2px solid rgba(210, 193, 182, 0.3);
            text-align: left;
        }

        .table-modern tbody td {
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid rgba(210, 193, 182, 0.2);
            color: var(--text-primary);
            font-size: 0.8rem;
        }

        .table-modern tbody tr:hover {
            background: rgba(210, 193, 182, 0.1);
        }

        .table-modern tbody tr:last-child td {
            border-bottom: none;
        }

        /* Batch & Course Items */
        .batch-item, .course-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.35rem 0.5rem;
            border-radius: var(--radius-md);
            transition: all var(--transition-fast);
            gap: 0.5rem;
        }

        .batch-item:hover, .course-item:hover {
            background: rgba(210, 193, 182, 0.15);
        }

        .batch-id {
            font-weight: 600;
            color: var(--deep-navy);
            flex-shrink: 0;
            font-size: 0.75rem;
        }

        .batch-name {
            color: var(--steel-blue);
            text-align: right;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
            flex: 1;
            font-size: 0.75rem;
        }

        .course-name {
            font-weight: 500;
            color: var(--deep-navy);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
            font-size: 0.75rem;
        }

        .quick-stat-number {
            font-size: 1.1rem;
            font-weight: 800;
            letter-spacing: -0.03em;
        }

        .quick-stat-label {
            font-size: 0.55rem;
            color: var(--steel-blue);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .skipped-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .skipped-list::-webkit-scrollbar {
            width: 4px;
        }

        .sticky-card {
            position: sticky;
            top: 20px;
            height: fit-content;
            max-height: calc(100vh - 120px);
            overflow-y: auto;
        }

        .sticky-card::-webkit-scrollbar {
            width: 3px;
        }

        .sticky-card::-webkit-scrollbar-thumb {
            background: var(--steel-blue);
            border-radius: 10px;
        }

        .page-heading {
            color: var(--deep-navy);
        }

        .page-subtitle {
            color: var(--steel-blue);
        }

        .section-title {
            color: var(--deep-navy);
        }

        .info-note {
            background: rgba(27, 60, 83, 0.04);
            border: 1px solid rgba(27, 60, 83, 0.12);
        }

        .highlight-text {
            color: var(--deep-navy);
        }

        .accent-text {
            color: #EF4444;
        }

        .success-text {
            color: #16a34a;
        }

        .link-text {
            color: var(--steel-blue);
        }
    </style>
</head>
<body>
    <?php include '../sidebar.php'; ?>
    <?php include '../header.php'; ?>

    <main class="main-content">
        
        <!-- Page Header -->
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-5">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-2xl flex items-center justify-center text-white text-lg shadow-lg flex-shrink-0" style="background: var(--gradient-primary);">
                    <i class="fas fa-upload"></i>
                </div>
                <div>
                    <h1 class="text-2xl lg:text-3xl font-extrabold tracking-tight page-heading">
                        Upload Students
                    </h1>
                    <p class="text-xs page-subtitle mt-0.5">Bulk import student data from CSV file</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="add_student.php" class="btn-outline">
                    <i class="fas fa-user-plus mr-1.5"></i> Add Single
                </a>
                <a href="students_list.php" class="btn-outline">
                    <i class="fas fa-list mr-1.5"></i> View All
                </a>
            </div>
        </div>

        <!-- Error Alert -->
        <?php if (!empty($error)): ?>
        <div class="alert alert-error mb-3">
            <i class="fas fa-exclamation-circle text-base flex-shrink-0 mt-0.5" style="color: #ef4444;"></i>
            <div>
                <p class="font-bold text-xs" style="color: #1B3C53;">Upload Error</p>
                <p class="text-xs" style="color: #456882; margin-top: 2px;"><?= htmlspecialchars($error) ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Skipped Rows -->
        <?php if (!empty($skipped)): ?>
        <div class="alert alert-warning mb-3">
            <i class="fas fa-exclamation-triangle text-base flex-shrink-0 mt-0.5" style="color: #f59e0b;"></i>
            <div class="flex-1">
                <p class="font-bold text-xs" style="color: #1B3C53;">Skipped Rows (<?= count($skipped) ?>)</p>
                <ul class="skipped-list mt-1 text-xs space-y-0.5 list-disc list-inside" style="color: #456882;">
                    <?php foreach ($skipped as $skip): ?>
                        <li><?= htmlspecialchars($skip) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <!-- MAIN LAYOUT -->
        <div class="grid grid-cols-1 lg:grid-cols-5 gap-5">
            
            <!-- LEFT: CSV Format Requirements - STICKY -->
            <div class="lg:col-span-3">
                <div class="glass-card p-5 sticky-card">
                    <h3 class="text-sm font-bold section-title mb-3 flex items-center gap-2">
                        <i class="fas fa-info-circle" style="color: #456882;"></i>
                        CSV File Format Requirements
                    </h3>
                    
                    <div class="space-y-1.5">
                        <div class="format-item">
                            <div class="format-number">1</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Student ID</div>
                                <div class="format-desc">Unique identifier (e.g., <strong style="color:#1B3C53;">STD100</strong>)</div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">2</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">First Name</div>
                                <div class="format-desc">Student's legal first name</div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">3</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Last Name</div>
                                <div class="format-desc">Student's surname or family name</div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">4</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Email</div>
                                <div class="format-desc">Valid email for <strong style="color:#1B3C53;">student login</strong></div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">5</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Phone Number</div>
                                <div class="format-desc">Contact number (optional)</div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">6</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Date of Birth</div>
                                <div class="format-desc">Format: <strong style="color:#1B3C53;">YYYY-MM-DD</strong> or <strong style="color:#1B3C53;">DD-MM-YYYY</strong></div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">7</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Batch ID</div>
                                <div class="format-desc">Must match <strong style="color:#EF4444;">existing batch</strong> in system</div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">8</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Course Name</div>
                                <div class="format-desc">Auto-created if not exists <strong style="color:#16a34a;">✓</strong></div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">9</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Enrollment Date</div>
                                <div class="format-desc">Format: <strong style="color:#1B3C53;">YYYY-MM-DD</strong> or <strong style="color:#1B3C53;">DD-MM-YYYY</strong></div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">10</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Current Status</div>
                                <div class="format-desc">
                                    <span class="tag tag-success text-[0.5rem]">active</span>
                                    <span class="tag text-[0.5rem]" style="background:rgba(239,68,68,0.1);color:#ef4444;">dropped</span>
                                    <span class="tag text-[0.5rem]" style="background:rgba(59,130,246,0.1);color:#3b82f6;">completed</span>
                                    <span class="tag text-[0.5rem]" style="background:rgba(139,92,246,0.1);color:#8b5cf6;">transferred</span>
                                    <span class="text-[0.6rem]">— defaults to <strong style="color:#16a34a;">active</strong></span>
                                </div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">11</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Father's Name</div>
                                <div class="format-desc">Parent or legal guardian full name</div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">12</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Father's Phone</div>
                                <div class="format-desc">Emergency contact number</div>
                            </div>
                        </div>
                        <div class="format-item">
                            <div class="format-number">13</div>
                            <div class="flex-1 min-w-0">
                                <div class="format-title">Password</div>
                                <div class="format-desc"><strong style="color:#EF4444;">Secure password</strong> for student account</div>
                            </div>
                        </div>
                    </div>

                    <!-- Important Note & Download -->
                    <div class="mt-4 p-3 rounded-xl info-note">
                        <div class="flex items-start gap-2.5">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0" style="background: rgba(27,60,83,0.1); color: #1B3C53;">
                                <i class="fas fa-check-circle text-xs"></i>
                            </div>
                            <div>
                                <p class="text-[0.65rem] font-semibold highlight-text">Important Notes:</p>
                                <ul class="text-[0.6rem] mt-0.5 space-y-0.5" style="color: #456882;">
                                    <li>• First row = <strong>headers</strong> (auto-skipped)</li>
                                    <li>• Duplicate <strong>Student IDs</strong> or <strong>emails</strong> = skipped</li>
                                    <li>• <strong>Batch ID</strong> must exist — create batches first</li>
                                    <li>• <strong>New courses</strong> auto-created</li>
                                </ul>
                            </div>
                        </div>
                        <button id="downloadTemplate" class="mt-2 inline-flex items-center gap-2 text-xs font-medium cursor-pointer hover:underline transition-colors link-text">
                            <i class="fas fa-download"></i> Download Template CSV
                        </button>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Upload + Tips + Stats + Batches + Courses -->
            <div class="lg:col-span-2 flex flex-col gap-4">
                
                <!-- Upload Card -->
                <div class="glass-card p-4">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div id="dropZone" class="drop-zone mb-3">
                            <input type="file" id="csv_file" name="csv_file" class="hidden" accept=".csv" required>
                            <div class="drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                            <h4 class="text-sm font-bold highlight-text mb-0.5">Drag & Drop CSV</h4>
                            <p class="text-xs mb-2" style="color: #456882;">or click to browse</p>
                            <label for="csv_file" class="btn-outline inline-flex cursor-pointer">
                                <i class="fas fa-folder-open mr-1.5"></i> Browse
                            </label>
                            <div id="fileInfo" class="mt-2 hidden">
                                <span class="tag tag-sand text-xs"><i class="fas fa-check-circle"></i> <span id="fileName"></span></span>
                                <button type="button" onclick="clearFile()" class="ml-2 text-xs hover:text-red-500 transition-colors" style="color: #456882;"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                        <div id="progressSection" class="hidden mb-2">
                            <div class="flex justify-between text-xs mb-1" style="color: #456882;">
                                <span>Processing...</span>
                                <span id="progressPercent">0%</span>
                            </div>
                            <div class="progress-container">
                                <div id="progressBar" class="progress-bar-fill" style="width:0%;"></div>
                            </div>
                        </div>
                        <button type="submit" name="upload" id="uploadBtn" class="btn-primary">
                            <i class="fas fa-upload mr-2"></i> Upload & Import
                        </button>
                    </form>
                </div>

                <!-- Upload Tips -->
                <div class="glass-card p-4">
                    <h4 class="text-sm font-bold section-title mb-2 flex items-center gap-2">
                        <i class="fas fa-lightbulb" style="color: #f59e0b;"></i>
                        Upload Tips
                    </h4>
                    <div class="space-y-1.5">
                        <div class="tip-item">
                            <div class="tip-icon" style="background:rgba(27,60,83,0.1);color:#1B3C53;"><i class="fas fa-language"></i></div>
                            <div class="flex-1">
                                <div class="tip-title">UTF-8 Encoding</div>
                                <div class="tip-desc">Use UTF-8 for special characters</div>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;"><i class="fas fa-weight-hanging"></i></div>
                            <div class="flex-1">
                                <div class="tip-title">File Size: 10MB Max</div>
                                <div class="tip-desc">Split larger files into multiple uploads</div>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon" style="background:rgba(34,197,94,0.1);color:#16a34a;"><i class="fas fa-heading"></i></div>
                            <div class="flex-1">
                                <div class="tip-title">Header Row Skipped</div>
                                <div class="tip-desc">First row = column headers (ignored)</div>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-copy"></i></div>
                            <div class="flex-1">
                                <div class="tip-title">Duplicate Detection</div>
                                <div class="tip-desc">Same email or ID = auto-skipped</div>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon" style="background:rgba(69,104,130,0.1);color:#456882;"><i class="fas fa-calendar-alt"></i></div>
                            <div class="flex-1">
                                <div class="tip-title">Date Formats</div>
                                <div class="tip-desc">YYYY-MM-DD or DD-MM-YYYY</div>
                            </div>
                        </div>
                        <div class="tip-item">
                            <div class="tip-icon" style="background:rgba(210,193,182,0.2);color:#234C6A;"><i class="fas fa-check-double"></i></div>
                            <div class="flex-1">
                                <div class="tip-title">Verify After Upload</div>
                                <div class="tip-desc">Check skipped rows & correct errors</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="glass-card p-3">
                    <div class="grid grid-cols-3 gap-2">
                        <div class="text-center" style="padding: 0.25rem 0.35rem;">
                            <p class="quick-stat-number" style="color:#1B3C53;"><?= $totalStudents ?></p>
                            <p class="quick-stat-label">Students</p>
                        </div>
                        <div class="text-center" style="padding: 0.25rem 0.35rem;">
                            <p class="quick-stat-number" style="color:#456882;"><?= $totalBatches ?></p>
                            <p class="quick-stat-label">Batches</p>
                        </div>
                        <div class="text-center" style="padding: 0.25rem 0.35rem;">
                            <p class="quick-stat-number" style="color:#16a34a;"><?= $totalCourses ?></p>
                            <p class="quick-stat-label">Courses</p>
                        </div>
                    </div>
                </div>

                <!-- Available Batches -->
                <div class="stat-card">
                    <h4 class="text-sm font-bold section-title mb-2 flex items-center gap-2">
                        <i class="fas fa-layer-group" style="color:#1B3C53;"></i> Available Batches
                        <span class="tag tag-navy text-[0.55rem]"><?= $totalBatches ?></span>
                    </h4>
                    <div class="space-y-1 max-h-72 overflow-y-auto pr-1">
                        <?php if (!empty($batches)): ?>
                            <?php foreach ($batches as $batch): ?>
                                <div class="batch-item">
                                    <span class="batch-id"><?= htmlspecialchars($batch['batch_id']) ?></span>
                                    <span class="batch-name"><?= htmlspecialchars($batch['batch_name']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-3"><p class="text-xs" style="color: #456882;">No batches available</p></div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 pt-2" style="border-top: 1px solid rgba(210,193,182,0.3);">
                        <p class="text-[0.6rem]" style="color: #456882;">
                            <i class="fas fa-info-circle mr-1" style="color:#1B3C53;"></i> Use exact <strong>Batch IDs</strong> in CSV.
                        </p>
                    </div>
                </div>

                <!-- Available Courses -->
                <div class="stat-card">
                    <h4 class="text-sm font-bold section-title mb-2 flex items-center gap-2">
                        <i class="fas fa-book" style="color:#456882;"></i> Available Courses
                        <span class="tag tag-steel text-[0.55rem]"><?= $totalCourses ?></span>
                    </h4>
                    <div class="space-y-1 max-h-72 overflow-y-auto pr-1">
                        <?php if (!empty($courses_list)): ?>
                            <?php foreach ($courses_list as $course): ?>
                                <div class="course-item">
                                    <span class="course-name"><?= htmlspecialchars($course['name']) ?></span>
                                    <span class="tag tag-steel text-[0.55rem]" style="flex-shrink: 0; margin-left: 0.5rem;">ID: <?= htmlspecialchars($course['id']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-3"><p class="text-xs" style="color: #456882;">No courses yet</p></div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 pt-2" style="border-top: 1px solid rgba(210,193,182,0.3);">
                        <p class="text-[0.6rem]" style="color: #456882;">
                            <i class="fas fa-info-circle mr-1" style="color:#456882;"></i> Unknown courses <strong>auto-created</strong>.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload History -->
        <div class="glass-card p-5 mt-5">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-sm font-bold section-title flex items-center gap-2">
                    <i class="fas fa-history" style="color:#456882;"></i> Upload History
                </h3>
                <button onclick="location.reload()" class="btn-outline">
                    <i class="fas fa-sync-alt mr-1"></i> Refresh
                </button>
            </div>
            <table class="table-modern">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>File Name</th>
                        <th>Records</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="4" class="text-center py-5">
                            <i class="fas fa-inbox text-2xl mb-1.5" style="color: var(--warm-sand); opacity: 0.5;"></i>
                            <p class="text-xs" style="color: #456882;">No upload history yet</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

    </main>

    <script>
        const dz=document.getElementById('dropZone'),fi=document.getElementById('csv_file'),fin=document.getElementById('fileInfo'),fn=document.getElementById('fileName');
        ['dragenter','dragover','dragleave','drop'].forEach(e=>{dz.addEventListener(e,ev=>ev.preventDefault());document.body.addEventListener(e,ev=>ev.preventDefault());});
        ['dragenter','dragover'].forEach(e=>dz.addEventListener(e,()=>dz.classList.add('active')));
        ['dragleave','drop'].forEach(e=>dz.addEventListener(e,()=>dz.classList.remove('active')));
        dz.addEventListener('drop',e=>{if(e.dataTransfer.files.length){fi.files=e.dataTransfer.files;sf(e.dataTransfer.files[0]);}});
        fi.addEventListener('change',function(){if(this.files.length)sf(this.files[0]);});
        function sf(f){if(!f.name.toLowerCase().endsWith('.csv')){alert('Only CSV files allowed.');cf();return;}fn.textContent=f.name+' ('+(f.size<1024?f.size+' B':f.size<1048576?(f.size/1024).toFixed(1)+' KB':(f.size/1048576).toFixed(1)+' MB')+')';fin.classList.remove('hidden');}
        function cf(){fi.value='';fin.classList.add('hidden');}

        const uf=document.getElementById('uploadForm'),ps=document.getElementById('progressSection'),pb=document.getElementById('progressBar'),pp=document.getElementById('progressPercent'),ub=document.getElementById('uploadBtn');
        uf.addEventListener('submit',function(e){if(!fi.files.length){e.preventDefault();alert('Please select a CSV file.');return;}ps.classList.remove('hidden');ub.disabled=true;ub.innerHTML='<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';let p=0;const int=setInterval(()=>{p+=Math.random()*15;if(p>=90){p=90;clearInterval(int);}pb.style.width=p+'%';pp.textContent=Math.round(p)+'%';},300);});

        document.getElementById('downloadTemplate').addEventListener('click',function(){const h=['Student ID','First Name','Last Name','Email','Phone Number','Date of Birth (YYYY-MM-DD)','Batch ID','Course Name','Enrollment Date (YYYY-MM-DD)','Current Status','Father Name','Father Phone Number','Password'];const ex=['STD100','John','Doe','john@example.com','1234567890','2000-01-01','B001','Super 30','2025-01-15','active','Robert Doe','0987654321','password123'];let csv=h.join(',')+'\n'+ex.join(',');const b=new Blob([csv],{type:'text/csv;charset=utf-8;'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='student_template.csv';a.click();});

        function hr(){const mc=document.querySelector('.main-content');if(window.innerWidth<1024){if(mc){mc.style.marginLeft='0';mc.style.width='100%';}}else{if(mc){mc.style.marginLeft='16rem';mc.style.width='calc(100% - 16rem)';}}}
        window.addEventListener('resize',hr);hr();
    </script>
</body>
</html>