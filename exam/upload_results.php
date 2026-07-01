<?php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : '';
$message = '';
$error = '';

// Get exam details with batch info
$exam = null;
if (!empty($exam_id)) {
    $stmt = $db->prepare("
        SELECT e.*, b.batch_name 
        FROM exams e 
        JOIN batches b ON e.batch_id = b.batch_id 
        WHERE e.exam_id = ?
    ");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$exam) {
    header("Location: exams.php");
    exit();
}

// Get ALL eligible students
$students = $db->prepare("
    SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.email, s.phone_number,
           CASE 
               WHEN s.batch_name = ? THEN 'Primary Batch'
               WHEN s.batch_name_2 = ? THEN 'Secondary Batch 2'
               WHEN s.batch_name_3 = ? THEN 'Secondary Batch 3'
               WHEN s.batch_name_4 = ? THEN 'Secondary Batch 4'
           END as batch_assignment,
           er.obtained_marks as existing_marks,
           er.grade as existing_grade,
           er.mcq_marks as existing_mcq,
           er.project_marks as existing_project,
           er.viva_marks as existing_viva,
           er.remarks as existing_remarks,
           CASE WHEN er.id IS NOT NULL THEN true ELSE false END as has_result
    FROM students s
    LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
    WHERE (s.batch_name = ? 
           OR s.batch_name_2 = ? 
           OR s.batch_name_3 = ? 
           OR s.batch_name_4 = ?)
      AND s.current_status = 'active'
    ORDER BY s.first_name, s.last_name
");
$students->execute([$exam['batch_id'], $exam['batch_id'], $exam['batch_id'], $exam['batch_id'], $exam_id, $exam['batch_id'], $exam['batch_id'], $exam['batch_id'], $exam['batch_id']]);
$students = $students->fetchAll(PDO::FETCH_ASSOC);

// CSV template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="exam_results_' . $exam_id . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    $header = ['Student ID', 'Student Name'];
    $exam_components = !empty($exam['exam_components']) ? explode(',', $exam['exam_components']) : [];
    foreach ($exam_components as $component) {
        $header[] = ucfirst($component) . ' Marks';
    }
    $header[] = 'Obtained Marks';
    $header[] = 'Remarks';
    fputcsv($output, $header);
    
    foreach ($students as $student) {
        $row = [$student['student_id'], $student['first_name'] . ' ' . $student['last_name']];
        foreach ($exam_components as $component) {
            $existing_field = 'existing_' . $component;
            $row[] = $student[$existing_field] ?? '';
        }
        $row[] = $student['existing_marks'] ?? '';
        $row[] = $student['existing_remarks'] ?? '';
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// Form submission handling
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Single save (AJAX)
    if (isset($_POST['save_single'])) {
        $student_id = $_POST['student_id'];
        $obtained_marks = $_POST['obtained_marks'] ?? null;
        $remarks = $_POST['remarks'] ?? '';
        $mcq_marks = isset($_POST['mcq_marks']) && $_POST['mcq_marks'] !== '' ? $_POST['mcq_marks'] : null;
        $project_marks = isset($_POST['project_marks']) && $_POST['project_marks'] !== '' ? $_POST['project_marks'] : null;
        $viva_marks = isset($_POST['viva_marks']) && $_POST['viva_marks'] !== '' ? $_POST['viva_marks'] : null;
        
        if ($obtained_marks === null || $obtained_marks === '') {
            echo json_encode(['success' => false, 'error' => 'Obtained marks required']);
            exit();
        }
        
        $check = $db->prepare("SELECT student_id FROM students WHERE student_id = ? AND (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) AND current_status = 'active'");
        $check->execute([$student_id, $exam['batch_id'], $exam['batch_id'], $exam['batch_id'], $exam['batch_id']]);
        if (!$check->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Student not in batch']);
            exit();
        }
        
        $percentage = ($obtained_marks / $exam['total_marks']) * 100;
        $grade = calculateGrade($percentage);
        
        $check_stmt = $db->prepare("SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ?");
        $check_stmt->execute([$exam_id, $student_id]);
        if ($check_stmt->fetch()) {
            $stmt = $db->prepare("UPDATE exam_results SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ?, mcq_marks = ?, project_marks = ?, viva_marks = ?, uploaded_at = NOW() WHERE exam_id = ? AND student_id = ?");
            $result = $stmt->execute([$obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks, $exam_id, $student_id]);
        } else {
            $stmt = $db->prepare("INSERT INTO exam_results (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by, mcq_marks, project_marks, viva_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([$exam_id, $student_id, $obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks]);
        }
        
        if ($result) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        exit();
    }
    
    // Bulk save
    if (isset($_POST['save_all_results'])) {
        $success_count = 0;
        $error_count = 0;
        foreach ($_POST['results'] as $student_id => $data) {
            if (empty($data['obtained_marks'])) continue;
            $obtained_marks = $data['obtained_marks'];
            $remarks = $data['remarks'] ?? '';
            $mcq_marks = isset($data['mcq_marks']) && $data['mcq_marks'] !== '' ? $data['mcq_marks'] : null;
            $project_marks = isset($data['project_marks']) && $data['project_marks'] !== '' ? $data['project_marks'] : null;
            $viva_marks = isset($data['viva_marks']) && $data['viva_marks'] !== '' ? $data['viva_marks'] : null;
            
            $percentage = ($obtained_marks / $exam['total_marks']) * 100;
            $grade = calculateGrade($percentage);
            
            $check_stmt = $db->prepare("SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ?");
            $check_stmt->execute([$exam_id, $student_id]);
            if ($check_stmt->fetch()) {
                $stmt = $db->prepare("UPDATE exam_results SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ?, mcq_marks = ?, project_marks = ?, viva_marks = ?, uploaded_at = NOW() WHERE exam_id = ? AND student_id = ?");
                $result = $stmt->execute([$obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks, $exam_id, $student_id]);
            } else {
                $stmt = $db->prepare("INSERT INTO exam_results (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by, mcq_marks, project_marks, viva_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([$exam_id, $student_id, $obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks]);
            }
            if ($result) $success_count++; else $error_count++;
        }
        $message = "Results saved: $success_count students updated successfully" . ($error_count > 0 ? ", $error_count errors" : "");
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, "r");
        if ($handle === FALSE) {
            $error = "Failed to open uploaded file.";
        } else {
            $header = fgetcsv($handle);
            $success_count = 0;
            $error_count = 0;
            $exam_components = !empty($exam['exam_components']) ? explode(',', $exam['exam_components']) : [];
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if (empty($data[0])) continue;
                $student_id = trim($data[0]);
                $component_start_index = 2;
                $total_marks_index = $component_start_index + count($exam_components);
                $remarks_index = $total_marks_index + 1;
                $mcq_marks = $project_marks = $viva_marks = null;
                foreach ($exam_components as $idx => $component) {
                    $value = isset($data[$component_start_index + $idx]) && $data[$component_start_index + $idx] !== '' ? trim($data[$component_start_index + $idx]) : null;
                    switch($component) {
                        case 'mcq': $mcq_marks = $value; break;
                        case 'project': $project_marks = $value; break;
                        case 'viva': $viva_marks = $value; break;
                    }
                }
                $obtained_marks = isset($data[$total_marks_index]) && $data[$total_marks_index] !== '' ? trim($data[$total_marks_index]) : null;
                $remarks = isset($data[$remarks_index]) ? trim($data[$remarks_index]) : null;
                if ($obtained_marks === null) { $error_count++; continue; }
                $check_student = $db->prepare("SELECT student_id FROM students WHERE student_id = ? AND (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) AND current_status = 'active'");
                $check_student->execute([$student_id, $exam['batch_id'], $exam['batch_id'], $exam['batch_id'], $exam['batch_id']]);
                if (!$check_student->fetch()) { $error_count++; continue; }
                $percentage = ($obtained_marks / $exam['total_marks']) * 100;
                $grade = calculateGrade($percentage);
                $check_stmt = $db->prepare("SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ?");
                $check_stmt->execute([$exam_id, $student_id]);
                if ($check_stmt->fetch()) {
                    $stmt = $db->prepare("UPDATE exam_results SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ?, mcq_marks = ?, project_marks = ?, viva_marks = ?, uploaded_at = NOW() WHERE exam_id = ? AND student_id = ?");
                    $result = $stmt->execute([$obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks, $exam_id, $student_id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO exam_results (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by, mcq_marks, project_marks, viva_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $result = $stmt->execute([$exam_id, $student_id, $obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks]);
                }
                if ($result) $success_count++; else $error_count++;
            }
            fclose($handle);
            $message = "Bulk upload completed: $success_count records processed successfully, $error_count errors.";
            header("Location: upload_results.php?exam_id=" . $exam_id . "&success=1&count=" . $success_count);
            exit();
        }
    }
}

function calculateGrade($percentage) {
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    return 'F';
}

$exam_components = !empty($exam['exam_components']) ? explode(',', $exam['exam_components']) : [];

$total_students = count($students);
$results_uploaded = count(array_filter($students, fn($s) => $s['has_result']));
$avg_marks = $results_uploaded > 0 ? array_sum(array_column(array_filter($students, fn($s) => $s['has_result']), 'existing_marks')) / $results_uploaded : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ================================================================
           BRAND PALETTE (locked)
           #1B3C53 — deepest navy-teal
           #234C6A — dark steel blue
           #456882 — mid steel blue
           #D2C1B6 — warm sand
           #A4C4D4 — soft sky/ice accent
           ================================================================ */
        :root {
            --color-navy: #1B3C53;
            --color-steel-dark: #234C6A;
            --color-steel-mid: #456882;
            --color-sand: #D2C1B6;
            --color-sky: #A4C4D4;
            
            --color-bg-body: #e8e2db;
            --color-card-bg: #ffffff;
            --color-text-primary: #1B3C53;
            --color-text-secondary: #234C6A;
            --color-text-muted: #8B7E74;
            --color-border: #E8E3DC;
            
            --shadow-card: 0 4px 20px rgba(27, 60, 83, 0.13);
            --shadow-hover: 0 8px 32px rgba(27, 60, 83, 0.18);
            --radius-card: 16px;
            --radius-btn: 9999px;
            
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ================================================================
           GLOBAL RESET & BASE
           ================================================================ */
        * {
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }
        html {
            scroll-behavior: smooth;
        }
        body {
            background:
                radial-gradient(1100px 500px at 100% -8%, rgba(69,104,130,.22), transparent 55%),
                radial-gradient(900px 450px at -10% 108%, rgba(27,60,83,.16), transparent 55%),
                radial-gradient(rgba(27,60,83,.045) 1px, transparent 1px) 0 0 / 22px 22px,
                linear-gradient(165deg, #e8e2db 0%, #e4ddd5 44%, #d9e3ec 100%);
            background-attachment: fixed;
            color: var(--color-text-primary);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            margin: 0;
            padding: 0;
            line-height: 1.6;
        }

        .main-content {
            margin-left: 16rem;
            padding: 24px 28px;
            min-height: 100vh;
            background: transparent;
            opacity: 0;
            animation: contentFadeIn 0.5s ease-out 0.1s forwards;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 16px;
            }
        }

        @keyframes contentFadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-6px); }
        }

        @keyframes glow {
            0%, 100% { box-shadow: 0 0 5px rgba(27, 60, 83, 0.2); }
            50% { box-shadow: 0 0 20px rgba(27, 60, 83, 0.4); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        @keyframes conicGlow {
            0% { border-color: #1B3C53; }
            25% { border-color: #234C6A; }
            50% { border-color: #456882; }
            75% { border-color: #D2C1B6; }
            100% { border-color: #1B3C53; }
        }

        /* ================================================================
           SCROLLBAR
           ================================================================ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #D2C1B6;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #456882, #234C6A);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, #234C6A, #1B3C53);
        }

        /* ================================================================
           HEADER (navbar)
           ================================================================ */
        .navbar, .app-header, .main-header, .top-header, .header-top, header {
            background: linear-gradient(90deg, #1B3C53 0%, #234C6A 35%, #456882 70%, #D2C1B6 100%) !important;
            border-bottom: none !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 1030 !important;
            box-shadow: 0 2px 16px rgba(27, 60, 53, 0.2);
        }
        .navbar-brand, .navbar .nav-link, .navbar .dropdown-toggle, .navbar .navbar-text,
        .navbar .dropdown-item, .navbar .form-control, .navbar .btn-link,
        .navbar .nav-link i, .navbar .dropdown-item i {
            color: #ffffff !important;
        }
        .navbar .nav-link:hover, .navbar .dropdown-item:hover {
            background: rgba(255,255,255,0.12) !important;
            color: #ffffff !important;
        }
        .navbar .dropdown-menu {
            background: #ffffff !important;
            border: 1px solid var(--color-border) !important;
            box-shadow: var(--shadow-card) !important;
            animation: scaleIn 0.2s ease-out;
        }

        /* ================================================================
           HERO BANNER
           ================================================================ */
        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 45%, #456882 100%);
            border-radius: var(--radius-card);
            padding: 1.5rem 2rem;
            color: #ffffff;
            box-shadow: var(--shadow-card);
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .hero-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: rgba(210, 193, 182, 0.08);
            pointer-events: none;
        }
        .hero-banner .h1,
        .hero-banner .h2 {
            color: #ffffff;
        }
        .hero-banner .text-muted {
            color: rgba(255,255,255,0.8) !important;
        }
        .hero-banner .btn-primary {
            background: linear-gradient(135deg, #f59e0b, #C97B50);
            box-shadow: 0 4px 20px rgba(245, 158, 11, 0.35);
        }
        .hero-banner .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(245, 158, 11, 0.45);
        }
        .hero-banner .btn-outline-light {
            border-color: rgba(255,255,255,0.4);
            color: #ffffff;
        }
        .hero-banner .btn-outline-light:hover {
            background: rgba(255,255,255,0.12);
            border-color: rgba(255,255,255,0.6);
            color: #ffffff;
            transform: translateY(-3px);
        }

        /* ================================================================
           BREADCRUMB
           ================================================================ */
        .breadcrumb-nav {
            color: var(--color-text-muted);
        }
        .breadcrumb-nav a {
            color: var(--color-steel-dark);
            text-decoration: none;
            font-weight: 500;
        }
        .breadcrumb-nav a:hover {
            color: var(--color-navy);
        }
        .breadcrumb-nav span {
            color: var(--color-navy);
            font-weight: 600;
        }

        /* ================================================================
           CARDS
           ================================================================ */
        .card {
            background: #ffffff;
            border: none;
            border-radius: var(--radius-card);
            border-left: 4px solid var(--color-steel-mid);
            box-shadow: var(--shadow-card);
            margin-bottom: 24px;
            transition: var(--transition-smooth);
            overflow: hidden;
            color: var(--color-text-primary);
            position: relative;
        }
        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-hover);
            border-left-color: var(--color-sky);
        }
        .card::after {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            border-radius: var(--radius-card);
            border: 2px solid transparent;
            transition: var(--transition-smooth);
            pointer-events: none;
            z-index: -1;
        }
        .card:hover::after {
            animation: conicGlow 3s ease-in-out infinite;
        }
        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--color-border);
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 1.1rem;
            letter-spacing: -0.01em;
            color: var(--color-text-secondary);
        }
        .card-body {
            padding: 1.5rem;
        }
        .card-footer {
            background: transparent;
            border-top: 1px solid var(--color-border);
            padding: 1rem 1.5rem;
        }

        /* Card variants */
        .card-upload {
            border-left-color: #7C5CBF;
        }
        .card-create {
            border-left-color: #C97B50;
        }
        .card-delete {
            border-left-color: #C0392B;
        }
        .card-default {
            border-left-color: var(--color-steel-mid);
        }

        /* ================================================================
           STAT CARDS
           ================================================================ */
        .stat-card {
            background: #ffffff;
            border: none;
            border-radius: 14px;
            padding: 1.5rem 1.75rem;
            box-shadow: var(--shadow-card);
            transition: var(--transition-smooth);
            position: relative;
            overflow: hidden;
            cursor: default;
            animation: slideInRight 0.5s ease-out;
        }
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }

        .stat-card .top-stripe {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }
        .stat-card .top-stripe.blue { background: linear-gradient(90deg, #1B3C53, #456882); }
        .stat-card .top-stripe.green { background: linear-gradient(90deg, #456882, #234C6A); }
        .stat-card .top-stripe.red { background: linear-gradient(90deg, #C0392B, #ef4444); }
        .stat-card .top-stripe.amber { background: linear-gradient(90deg, #C97B50, #f59e0b); }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-hover);
        }

        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: var(--color-text-primary);
            margin-bottom: 0.2rem;
            transition: var(--transition-smooth);
        }
        .stat-card:hover .stat-number {
            transform: scale(1.05);
        }
        .stat-card .stat-label {
            color: var(--color-text-muted) !important;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-card .icon-box {
            border-radius: 12px;
            padding: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition-smooth);
            width: 52px;
            height: 52px;
        }
        .stat-card .icon-box.blue {
            background: linear-gradient(135deg, rgba(27,60,83,0.12), rgba(69,104,130,0.08));
            color: var(--color-navy);
        }
        .stat-card .icon-box.green {
            background: linear-gradient(135deg, rgba(69,104,130,0.12), rgba(35,76,106,0.08));
            color: var(--color-steel-dark);
        }
        .stat-card .icon-box.amber {
            background: linear-gradient(135deg, rgba(201,123,80,0.15), rgba(245,158,11,0.08));
            color: #C97B50;
        }
        .stat-card .icon-box.steel {
            background: linear-gradient(135deg, rgba(210,193,182,0.20), rgba(164,196,212,0.12));
            color: var(--color-steel-mid);
        }
        .stat-card:hover .icon-box {
            transform: scale(1.08) rotate(3deg);
        }

        .stat-card .progress {
            height: 4px;
            border-radius: 4px;
            background: #f0ede8;
            margin-top: 8px;
            overflow: hidden;
        }
        .stat-card .progress-bar {
            background: linear-gradient(90deg, #1B3C53, #456882);
            border-radius: 4px;
        }

        /* ================================================================
           TABLES
           ================================================================ */
        .table-wrapper {
            background: #ffffff;
            border-radius: var(--radius-card);
            overflow: hidden;
            box-shadow: var(--shadow-card);
        }
        .table {
            --bs-table-bg: transparent;
            border-collapse: separate;
            border-spacing: 0;
            color: var(--color-text-primary);
            margin-bottom: 0;
        }
        .table thead th {
            background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            color: #ffffff;
            border: none;
            font-weight: 700;
            padding: 0.85rem 1rem;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .table tbody tr {
            background: #ffffff;
            transition: var(--transition-smooth);
            cursor: default;
        }
        .table tbody tr:nth-child(even) {
            background: #f4ede7;
        }
        .table tbody tr:hover {
            background: #e8dfd8;
            transform: translateX(2px);
        }
        .table td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
            border: none;
            color: var(--color-text-primary);
            font-size: 0.92rem;
        }

        /* ================================================================
           FORMS
           ================================================================ */
        .form-control, .form-select {
            background: #ffffff;
            border: 2px solid var(--color-border);
            border-radius: 10px;
            padding: 0.5rem 0.85rem;
            transition: var(--transition-smooth);
            font-size: 0.9rem;
            color: var(--color-text-primary);
            height: 40px;
        }
        .form-control::placeholder {
            color: var(--color-text-muted);
            opacity: 0.7;
        }
        .form-control:focus, .form-select:focus {
            background: #ffffff;
            border-color: var(--color-steel-mid);
            box-shadow: 0 0 0 4px rgba(69,104,130,0.10);
            color: var(--color-text-primary);
            transform: translateY(-1px);
        }
        .form-control-sm {
            height: 34px;
            font-size: 0.82rem;
            border-radius: 8px;
            padding: 0.35rem 0.7rem;
        }
        .form-label {
            color: var(--color-text-secondary);
            font-weight: 700;
            margin-bottom: 0.3rem;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            transition: var(--transition-smooth);
        }
        .form-text {
            color: var(--color-text-muted) !important;
            font-size: 0.85rem;
        }

        /* ================================================================
           BUTTONS
           ================================================================ */
        .btn {
            border-radius: var(--radius-btn);
            padding: 0.6rem 1.6rem;
            font-weight: 600;
            transition: var(--transition-smooth);
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            border: none;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.02em;
        }
        .btn-sm {
            height: 36px;
            padding: 0 1.2rem;
            font-size: 0.8rem;
            border-radius: 9999px;
        }
        .btn-lg {
            height: 52px;
            padding: 0 2rem;
            font-size: 1rem;
        }
        .btn-primary {
            background: linear-gradient(135deg, #f59e0b, #C97B50);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(245, 158, 11, 0.30);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(245, 158, 11, 0.40);
            color: #ffffff;
        }
        .btn-primary:active {
            transform: translateY(-1px);
        }

        .btn-success {
            background: linear-gradient(135deg, #456882, #234C6A);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(69,104,130,0.25);
        }
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(69,104,130,0.35);
            color: #ffffff;
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444, #C0392B);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(239, 68, 68, 0.25);
        }
        .btn-danger:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(239, 68, 68, 0.35);
            color: #ffffff;
        }

        .btn-secondary {
            background: linear-gradient(135deg, #EAE4E0, #D2C1B6);
            color: var(--color-text-primary);
            box-shadow: 0 4px 12px rgba(210,193,182,0.20);
        }
        .btn-secondary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(210,193,182,0.30);
            color: var(--color-text-primary);
        }

        .btn-outline-secondary {
            border: 2px solid #D2C1B6;
            color: var(--color-text-muted);
            background: transparent;
        }
        .btn-outline-secondary:hover {
            background: linear-gradient(135deg, #EAE4E0, #D2C1B6);
            color: var(--color-text-primary);
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(210,193,182,0.20);
        }

        /* ================================================================
           BADGES
           ================================================================ */
        .badge {
            padding: 0.3rem 0.9rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.7rem;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            height: 26px;
            transition: var(--transition-smooth);
        }
        .badge:hover {
            transform: scale(1.05);
        }

        .badge-status {
            padding: 0.3rem 0.9rem;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.7rem;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .badge-saved {
            background: #dcfce7;
            color: #166534;
        }
        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-saving {
            background: #fef3c7;
            color: #92400e;
        }
        .badge-error {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Component badges */
        .badge-component {
            padding: 0.35rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            border: 1px solid transparent;
        }
        .badge-component-mcq {
            background: rgba(27,60,83,0.08);
            color: #1B3C53;
            border-color: rgba(27,60,83,0.15);
        }
        .badge-component-project {
            background: rgba(69,104,130,0.08);
            color: #234C6A;
            border-color: rgba(69,104,130,0.15);
        }
        .badge-component-viva {
            background: rgba(210,193,182,0.20);
            color: #456882;
            border-color: rgba(210,193,182,0.30);
        }
        .badge-component-secondary {
            background: #f5f2ef;
            color: var(--color-text-secondary);
            border-color: var(--color-border);
        }

        /* ================================================================
           ALERTS
           ================================================================ */
        .alert {
            border-radius: var(--radius-card);
            border: none;
            border-left: 4px solid transparent;
            padding: 1rem 1.5rem;
            margin-bottom: 24px;
        }
        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border-left-color: #166534;
            color: #064e3b;
        }
        .alert-danger {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-left-color: #991b1b;
            color: #7f1d1d;
        }
        .alert-warning {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-left-color: #92400e;
            color: #78350f;
        }
        .alert-info {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border-left-color: #1e40af;
            color: #1e3a8a;
        }

        /* ================================================================
           CODE INLINE / KBD
           ================================================================ */
        .code-inline {
            font-family: 'SF Mono', 'Fira Code', monospace;
            background: #f5f2ef;
            padding: 0.15rem 0.5rem;
            border-radius: 4px;
            font-size: 0.82rem;
            color: var(--color-steel-dark);
        }
        kbd {
            background: #f5f2ef;
            color: var(--color-text-primary);
            border: 1px solid #D2C1B6;
            border-radius: 4px;
            padding: 0.1rem 0.5rem;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* ================================================================
           AVATAR
           ================================================================ */
        .avatar-circle {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(27,60,83,0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.75rem;
            color: var(--color-navy);
            flex-shrink: 0;
        }

        /* ================================================================
           BORDER DASHED
           ================================================================ */
        .border-dashed {
            border: 2px dashed var(--color-border);
            border-radius: 14px;
            transition: border-color 0.3s, background 0.3s, transform 0.3s;
            cursor: pointer;
        }
        .border-dashed:hover {
            border-color: var(--color-steel-mid);
            background: rgba(69,104,130,0.02);
            transform: scale(1.01);
        }

        /* ================================================================
           SECTION HEADER BAR
           ================================================================ */
        .section-header-bar {
            background: #faf9f7;
            border: 1px solid var(--color-border);
            border-radius: 14px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 0;
        }

        /* ================================================================
           BG GREEN-50
           ================================================================ */
        .bg-green-50 {
            background: rgba(45,106,79,0.04) !important;
        }

        /* ================================================================
           UTILITIES
           ================================================================ */
        .text-muted { color: var(--color-text-muted) !important; }
        .fw-semibold { font-weight: 600; }
        .fw-bold { font-weight: 700; }
        .text-xs { font-size: 0.75rem; }
        .gradient-text {
            background: linear-gradient(135deg, #1B3C53, #234C6A, #456882);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 800;
        }

        /* ================================================================
           RESPONSIVE
           ================================================================ */
        @media (max-width: 767px) {
            .main-content {
                padding: 12px;
                margin-left: 0;
            }
            .stat-card {
                padding: 1.25rem 1.5rem;
                margin-bottom: 16px;
            }
            .stat-card .stat-number {
                font-size: 1.6rem;
            }
            .card-body {
                padding: 1.25rem;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
            .btn-sm {
                width: auto;
            }
            .table thead th {
                font-size: 0.6rem;
                padding: 0.5rem 0.5rem;
            }
            .table td {
                padding: 0.5rem 0.5rem;
                font-size: 0.8rem;
            }
            .hero-banner {
                padding: 1.25rem;
            }
            .hero-banner .h1 {
                font-size: 1.5rem;
            }
            .section-header-bar {
                padding: 1rem;
            }
        }

        @media (min-width: 768px) and (max-width: 1024px) {
            .main-content {
                padding: 20px;
            }
            .stat-card {
                padding: 1.25rem 1.5rem;
            }
        }

        /* ================================================================
           ANIMATIONS
           ================================================================ */
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes shakeX {
            from, to { transform: translateX(0); }
            10% { transform: translateX(-8px); }
            20% { transform: translateX(8px); }
            30% { transform: translateX(-8px); }
            40% { transform: translateX(8px); }
            50% { transform: translateX(-4px); }
            60% { transform: translateX(4px); }
            70% { transform: translateX(-4px); }
            80% { transform: translateX(4px); }
            90% { transform: translateX(-2px); }
            100% { transform: translateX(0); }
        }
        .animate__animated { animation-duration: 0.5s; }
        .animate__fadeInDown { animation-name: fadeInDown; }
        .animate__shakeX { animation-name: shakeX; }
    </style>
</head>
<body>
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>

    <div class="main-content">
        <!-- Breadcrumb -->
        <nav class="breadcrumb-nav d-flex align-items-center text-sm mb-4">
            <a href="exams.php">Exams</a>
            <i class="fas fa-chevron-right mx-2 text-xs" style="color: var(--color-text-muted);"></i>
            <span>Upload Results</span>
        </nav>

        <!-- Hero Banner -->
        <div class="hero-banner">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="h2 mb-1" style="color: #ffffff; font-weight: 800;">
                        <i class="fas fa-upload me-3" style="color: rgba(255,255,255,0.6);"></i>Upload Exam Results
                    </h1>
                    <div class="d-flex flex-wrap align-items-center gap-3" style="color: rgba(255,255,255,0.85);">
                        <span class="fw-semibold" style="font-size: 1.1rem;"><?= htmlspecialchars($exam['exam_name']) ?></span>
                        <span style="color: rgba(255,255,255,0.3);">•</span>
                        <span><?= htmlspecialchars($exam['batch_name']) ?></span>
                    </div>
                </div>
                <div class="col-auto">
                    <a href="exam_details.php?id=<?= $exam_id ?>" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Exam Details
                    </a>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message || isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle fa-lg me-3"></i>
                    <div class="fw-semibold"><?= $message ?? 'Results saved successfully!' ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-circle fa-lg me-3"></i>
                    <div class="fw-semibold"><?= $error ?></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="top-stripe blue"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label mb-1">Total Students</div>
                            <div class="stat-number"><?= $total_students ?></div>
                        </div>
                        <div class="icon-box blue">
                            <i class="fas fa-users fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="top-stripe green"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label mb-1">Results Uploaded</div>
                            <div class="stat-number"><?= $results_uploaded ?></div>
                            <div class="progress">
                                <div class="progress-bar" role="progressbar" style="width: <?= $total_students > 0 ? ($results_uploaded / $total_students) * 100 : 0 ?>%;"></div>
                            </div>
                        </div>
                        <div class="icon-box green">
                            <i class="fas fa-check-circle fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="top-stripe amber"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label mb-1">Average Marks</div>
                            <div class="stat-number"><?= number_format($avg_marks, 2) ?></div>
                            <div class="text-muted small" style="margin-top: 4px;">Out of <?= $exam['total_marks'] ?></div>
                        </div>
                        <div class="icon-box amber">
                            <i class="fas fa-chart-line fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="top-stripe red"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label mb-1">Pending</div>
                            <div class="stat-number"><?= $total_students - $results_uploaded ?></div>
                        </div>
                        <div class="icon-box steel">
                            <i class="fas fa-clock fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Exam Info Card -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-2">
                        <span class="text-muted small d-block mb-2" style="font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.7rem;">Exam ID</span>
                        <strong class="code-inline" style="font-size: 0.9rem;"><?= htmlspecialchars($exam['exam_id']) ?></strong>
                    </div>
                    <div class="col-6 col-md-2">
                        <span class="text-muted small d-block mb-2" style="font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.7rem;">Date</span>
                        <strong style="font-size: 0.95rem;"><?= date('d M Y', strtotime($exam['exam_date'])) ?></strong>
                    </div>
                    <div class="col-6 col-md-2">
                        <span class="text-muted small d-block mb-2" style="font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.7rem;">Total Marks</span>
                        <strong style="font-size: 0.95rem;"><?= $exam['total_marks'] ?></strong>
                    </div>
                    <div class="col-6 col-md-2">
                        <span class="text-muted small d-block mb-2" style="font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.7rem;">Passing Marks</span>
                        <strong style="font-size: 0.95rem;"><?= $exam['passing_marks'] ?></strong>
                    </div>
                    <div class="col-6 col-md-2">
                        <span class="text-muted small d-block mb-2" style="font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.7rem;">Type</span>
                        <strong style="font-size: 0.95rem;"><?= ucfirst(str_replace('_', ' ', $exam['exam_type'])) ?></strong>
                    </div>
                    <?php if (!empty($exam_components)): ?>
                        <div class="col-12 mt-3 pt-3 border-top">
                            <span class="text-muted small d-block mb-2" style="font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; font-size: 0.7rem;">Components:</span>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($exam_components as $comp): 
                                    $badgeClass = '';
                                    switch($comp) {
                                        case 'mcq': $badgeClass = 'badge-component-mcq'; break;
                                        case 'project': $badgeClass = 'badge-component-project'; break;
                                        case 'viva': $badgeClass = 'badge-component-viva'; break;
                                        default: $badgeClass = 'badge-component-secondary';
                                    }
                                ?>
                                    <span class="badge-component <?= $badgeClass ?>" style="padding: 0.4rem 1rem; font-size: 0.85rem;">
                                        <?= strtoupper($comp) ?>: <?= $exam[$comp . '_marks'] ?? 0 ?> marks
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bulk Upload -->
        <div class="card card-upload mb-4">
            <div class="card-header">
                <i class="fas fa-cloud-upload-alt me-2" style="color: #7C5CBF;"></i>
                Bulk Upload via CSV
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        <div class="section-header-bar">
                            <h5 class="fw-bold mb-3" style="color: var(--color-primary);">
                                <i class="fas fa-info-circle me-2" style="color: var(--color-steel-mid);"></i>CSV Format Instructions
                            </h5>
                            <ul class="text-muted small" style="list-style:none; padding-left:0; margin-bottom:0;">
                                <li class="mb-3"><i class="fas fa-check-circle me-2" style="color: #166534;"></i>The CSV file should have these columns:</li>
                                <li class="ms-4 mb-3" style="color: var(--color-text-secondary);">Student ID, Student Name<?= !empty($exam_components) ? ', ' . implode(', ', array_map('ucfirst', $exam_components)) . ' Marks' : '' ?>, Obtained Marks, Remarks</li>
                                <li class="mb-3"><i class="fas fa-check-circle me-2" style="color: #166534;"></i>Download the template below for exact format</li>
                                <li class="mb-3"><i class="fas fa-check-circle me-2" style="color: #166534;"></i>Empty rows will be skipped automatically</li>
                                <li><i class="fas fa-check-circle me-2" style="color: #166534;"></i>Student Name column is optional (only Student ID is required)</li>
                            </ul>
                        </div>
                        <form method="POST" enctype="multipart/form-data" class="mt-3">
                            <div class="border-dashed rounded p-4 text-center" onclick="document.getElementById('csv_file').click()" style="cursor:pointer; padding: 2rem !important;">
                                <input type="file" name="csv_file" id="csv_file" accept=".csv" class="d-none" onchange="updateFileName(this)">
                                <i class="fas fa-file-csv fa-3x mb-3" style="color: var(--color-steel-mid); opacity: 0.6;"></i>
                                <p class="text-muted mb-2 fw-medium">Drag & drop your CSV file here or <span style="color: var(--color-navy); text-decoration:underline; font-weight:600;">browse</span></p>
                                <p class="text-muted small mb-0" id="file_name">No file selected</p>
                            </div>
                            <div class="d-flex flex-column flex-sm-row gap-3 mt-4">
                                <button type="submit" class="btn btn-primary flex-grow-1">
                                    <i class="fas fa-upload me-2"></i>Upload & Process
                                </button>
                                <a href="?exam_id=<?= $exam_id ?>&download_template=1" class="btn btn-success flex-grow-1 flex-sm-grow-0">
                                    <i class="fas fa-download me-2"></i>Template
                                </a>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <div class="section-header-bar h-100">
                            <h5 class="fw-bold mb-3" style="color: var(--color-primary);">
                                <i class="fas fa-lightbulb me-2" style="color: #C97B50;"></i>Quick Tips
                            </h5>
                            <ul class="text-muted small" style="list-style:none; padding-left:0; margin-bottom:0;">
                                <li class="mb-3"><i class="fas fa-chevron-right me-2" style="color: var(--color-steel-mid);"></i>Use <strong>Tab</strong> key to quickly navigate between fields</li>
                                <li class="mb-3"><i class="fas fa-chevron-right me-2" style="color: var(--color-steel-mid);"></i>Press <strong>Enter</strong> to save current row and move to next</li>
                                <li class="mb-3"><i class="fas fa-chevron-right me-2" style="color: var(--color-steel-mid);"></i>Type "pass" or "fail" in remarks for auto-categorization</li>
                                <li><i class="fas fa-chevron-right me-2" style="color: var(--color-steel-mid);"></i>Component marks auto-calculate total marks</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Entry Table -->
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span><i class="fas fa-pen-alt me-2" style="color: var(--color-steel-mid);"></i>Quick Marks Entry</span>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <span class="text-muted small"><i class="fas fa-sync-alt me-1"></i>Auto-saves on row change</span>
                    <button onclick="document.getElementById('marksForm').submit()" class="btn btn-primary btn-sm">
                        <i class="fas fa-save me-1"></i>Save All
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <form method="POST" id="marksForm">
                    <div class="table-responsive">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>ID</th>
                                    <?php foreach ($exam_components as $comp): ?>
                                        <th><?= strtoupper($comp) ?> (<?= $exam[$comp . '_marks'] ?? 0 ?>)</th>
                                    <?php endforeach; ?>
                                    <th>Total (<?= $exam['total_marks'] ?>)</th>
                                    <th>Remarks</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): 
                                    $rowClass = $student['has_result'] ? 'bg-green-50' : '';
                                ?>
                                <tr id="row_<?= $student['student_id'] ?>" class="<?= $rowClass ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar-circle">
                                                <?= strtoupper(substr($student['first_name'],0,1).substr($student['last_name'],0,1)) ?>
                                            </div>
                                            <span class="fw-medium"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></span>
                                        </div>
                                    </td>
                                    <td><code class="code-inline"><?= htmlspecialchars($student['student_id']) ?></code></td>
                                    <?php foreach ($exam_components as $comp): ?>
                                        <td>
                                            <input type="number" 
                                                   name="results[<?= $student['student_id'] ?>][<?= $comp ?>_marks]"
                                                   value="<?= $student['existing_' . $comp] ?? '' ?>"
                                                   data-student="<?= $student['student_id'] ?>"
                                                   data-component="<?= $comp ?>"
                                                   data-max="<?= $exam[$comp . '_marks'] ?? 0 ?>"
                                                   class="form-control form-control-sm component-input"
                                                   step="0.01" min="0" max="<?= $exam[$comp . '_marks'] ?? 0 ?>"
                                                   style="width:80px;"
                                                   onchange="updateTotal('<?= $student['student_id'] ?>')"
                                                   onkeyup="updateTotal('<?= $student['student_id'] ?>')">
                                        </td>
                                    <?php endforeach; ?>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <input type="number" 
                                                   name="results[<?= $student['student_id'] ?>][obtained_marks]"
                                                   id="total_<?= $student['student_id'] ?>"
                                                   value="<?= $student['existing_marks'] ?? '' ?>"
                                                   data-max="<?= $exam['total_marks'] ?>"
                                                   class="form-control form-control-sm total-input"
                                                   step="0.01" min="0" max="<?= $exam['total_marks'] ?>"
                                                   style="width:80px;"
                                                   onchange="validateTotal('<?= $student['student_id'] ?>')">
                                            <span class="text-muted small">/ <?= $exam['total_marks'] ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="text" 
                                               name="results[<?= $student['student_id'] ?>][remarks]"
                                               value="<?= htmlspecialchars($student['existing_remarks'] ?? '') ?>"
                                               class="form-control form-control-sm"
                                               style="width:120px;"
                                               placeholder="Remarks">
                                    </td>
                                    <td>
                                        <span id="status_<?= $student['student_id'] ?>">
                                            <?php if ($student['has_result']): ?>
                                                <span class="badge-status badge-saved"><i class="fas fa-check-circle me-1"></i>Saved</span>
                                            <?php else: ?>
                                                <span class="badge-status badge-pending"><i class="fas fa-clock me-1"></i>Pending</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div class="text-muted small">
                            <i class="fas fa-info-circle me-1" style="color: var(--color-steel-mid);"></i>
                            Press <kbd>Tab</kbd> to navigate · 
                            <kbd>Enter</kbd> to save row · 
                            <kbd>Ctrl+S</kbd> to save all
                        </div>
                        <button type="submit" name="save_all_results" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Save All Results
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ===== AUTO-SAVE LOGIC =====
        let autoSaveTimeout;
        const autoSaveDelay = 1000;

        function autoSave(studentId) {
            clearTimeout(autoSaveTimeout);
            autoSaveTimeout = setTimeout(() => {
                saveStudentResult(studentId);
            }, autoSaveDelay);
        }

        function saveStudentResult(studentId) {
            const formData = new FormData();
            formData.append('save_single', '1');
            formData.append('student_id', studentId);
            
            <?php foreach ($exam_components as $comp): ?>
            const <?= $comp ?>Input = document.querySelector(`input[name="results[${studentId}][<?= $comp ?>_marks]"]`);
            if (<?= $comp ?>Input) {
                formData.append('<?= $comp ?>_marks', <?= $comp ?>Input.value);
            }
            <?php endforeach; ?>
            
            const totalInput = document.getElementById(`total_${studentId}`);
            const remarksInput = document.querySelector(`input[name="results[${studentId}][remarks]"]`);
            
            formData.append('obtained_marks', totalInput.value);
            formData.append('remarks', remarksInput.value);
            
            const statusEl = document.getElementById(`status_${studentId}`);
            statusEl.innerHTML = '<span class="badge-status badge-saving"><i class="fas fa-spinner fa-spin me-1"></i>Saving...</span>';
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    statusEl.innerHTML = '<span class="badge-status badge-saved"><i class="fas fa-check-circle me-1"></i>Saved</span>';
                    document.getElementById(`row_${studentId}`).classList.add('bg-green-50');
                } else {
                    statusEl.innerHTML = '<span class="badge-status badge-error"><i class="fas fa-exclamation-circle me-1"></i>Error</span>';
                }
            })
            .catch(() => {
                statusEl.innerHTML = '<span class="badge-status badge-error"><i class="fas fa-exclamation-circle me-1"></i>Failed</span>';
            });
        }

        function updateTotal(studentId) {
            let total = 0;
            <?php foreach ($exam_components as $comp): ?>
            const <?= $comp ?>Val = parseFloat(document.querySelector(`input[name="results[${studentId}][<?= $comp ?>_marks]"]`).value) || 0;
            total += <?= $comp ?>Val;
            <?php endforeach; ?>
            document.getElementById(`total_${studentId}`).value = total.toFixed(2);
            autoSave(studentId);
        }

        function validateTotal(studentId) {
            const totalInput = document.getElementById(`total_${studentId}`);
            const max = parseFloat(totalInput.dataset.max);
            let value = parseFloat(totalInput.value) || 0;
            if (value > max) { totalInput.value = max; value = max; }
            autoSave(studentId);
        }

        function updateFileName(input) {
            document.getElementById('file_name').textContent = input.files[0]?.name || 'No file selected';
        }

        // Drag and drop support for CSV upload
        const dropZone = document.querySelector('.border-dashed');
        if (dropZone) {
            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--color-steel-mid)';
                this.style.background = 'rgba(69,104,130,0.03)';
            });
            dropZone.addEventListener('dragleave', function() {
                this.style.borderColor = 'var(--color-border)';
                this.style.background = 'transparent';
            });
            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--color-border)';
                this.style.background = 'transparent';
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    document.getElementById('csv_file').files = files;
                    updateFileName({ files: files });
                }
            });
        }

        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 's') { e.preventDefault(); document.getElementById('marksForm').submit(); }
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                const row = e.target.closest('tr');
                if (row) {
                    const studentId = row.id.replace('row_', '');
                    saveStudentResult(studentId);
                    const nextRow = row.nextElementSibling;
                    if (nextRow) {
                        const firstInput = nextRow.querySelector('input');
                        if (firstInput) firstInput.focus();
                    }
                }
            }
        });

        document.querySelectorAll('.component-input').forEach(el => {
            el.addEventListener('change', function() { updateTotal(this.dataset.student); });
        });
        document.querySelectorAll('.total-input').forEach(el => {
            el.addEventListener('change', function() { validateTotal(this.id.replace('total_', '')); });
        });
        document.querySelectorAll('input').forEach(el => {
            el.addEventListener('blur', function() {
                const row = this.closest('tr');
                if (row) {
                    const studentId = row.id.replace('row_', '');
                    autoSave(studentId);
                }
            });
        });
    </script>
</body>
</html>