<?php
require_once '../db_connection.php';
session_start();

// Check user role
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit();
}

$message = '';
$error = '';

// Handle form submissions (unchanged)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_exam'])) {
        // Create new exam
        $exam_id = 'EXM' . time() . rand(100, 999);
        $exam_name = $_POST['exam_name'];
        $batch_id = $_POST['batch_id'];
        $subject = $_POST['subject'];
        $exam_date = $_POST['exam_date'];
        $total_marks = $_POST['total_marks'];
        $passing_marks = $_POST['passing_marks'];
        $exam_type = $_POST['exam_type'];
        $description = $_POST['description'];
        
        $exam_components = isset($_POST['exam_components']) ? $_POST['exam_components'] : [];
        $exam_components_str = implode(',', $exam_components);
        
        $mcq_marks = isset($_POST['mcq_marks']) ? $_POST['mcq_marks'] : 0;
        $project_marks = isset($_POST['project_marks']) ? $_POST['project_marks'] : 0;
        $viva_marks = isset($_POST['viva_marks']) ? $_POST['viva_marks'] : 0;
        
        $component_total = $mcq_marks + $project_marks + $viva_marks;
        if ($component_total > $total_marks) {
            $error = "Sum of component marks cannot exceed total marks!";
        } elseif ($passing_marks > $total_marks) {
            $error = "Passing marks cannot exceed total marks!";
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("INSERT INTO exams (exam_id, exam_name, batch_id, subject, exam_date, total_marks, passing_marks, exam_type, description, created_by, exam_components, mcq_marks, project_marks, viva_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if ($stmt->execute([$exam_id, $exam_name, $batch_id, $subject, $exam_date, $total_marks, $passing_marks, $exam_type, $description, $_SESSION['user_id'], $exam_components_str, $mcq_marks, $project_marks, $viva_marks])) {
                    
                    $student_stmt = $db->prepare("
                        SELECT DISTINCT s.student_id 
                        FROM students s 
                        WHERE (s.batch_name = ? 
                            OR s.batch_name_2 = ? 
                            OR s.batch_name_3 = ? 
                            OR s.batch_name_4 = ?)
                            AND s.current_status = 'active'
                    ");
                    $student_stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
                    $students = $student_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $enroll_stmt = $db->prepare("INSERT INTO exam_enrollments (exam_id, student_id, enrolled_by) VALUES (?, ?, ?)");
                    $result_stmt = $db->prepare("INSERT INTO exam_results (exam_id, student_id, obtained_marks, uploaded_by) VALUES (?, ?, 0, ?)");
                    
                    $enrollment_count = 0;
                    foreach ($students as $student) {
                        $enroll_stmt->execute([$exam_id, $student['student_id'], $_SESSION['user_id']]);
                        $result_stmt->execute([$exam_id, $student['student_id'], $_SESSION['user_id']]);
                        $enrollment_count++;
                    }
                    
                    $db->commit();
                    $message = "Exam created successfully! $enrollment_count students have been enrolled.";
                } else {
                    $db->rollBack();
                    $error = "Failed to create exam: " . $stmt->errorInfo()[2];
                }
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to create exam: " . $e->getMessage();
            }
        }
    }
    
    if (isset($_POST['delete_exam'])) {
        $exam_id = $_POST['exam_id'];
        $check_stmt = $db->prepare("SELECT COUNT(*) as result_count FROM exam_results WHERE exam_id = ? AND obtained_marks > 0");
        $check_stmt->execute([$exam_id]);
        $result_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['result_count'];
        
        if ($result_count > 0) {
            $error = "Cannot delete exam because it has existing results. Please delete the results first.";
        } else {
            $db->beginTransaction();
            try {
                $delete_enrollments = $db->prepare("DELETE FROM exam_enrollments WHERE exam_id = ?");
                $delete_enrollments->execute([$exam_id]);
                $delete_results = $db->prepare("DELETE FROM exam_results WHERE exam_id = ?");
                $delete_results->execute([$exam_id]);
                $delete_exam = $db->prepare("DELETE FROM exams WHERE exam_id = ?");
                $delete_exam->execute([$exam_id]);
                $db->commit();
                $message = "Exam deleted successfully!";
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Failed to delete exam: " . $e->getMessage();
            }
        }
    }
}

// Get exams list
$exams = $db->query("
    SELECT e.*, b.batch_name,
    (SELECT COUNT(DISTINCT s.student_id) 
     FROM students s 
     WHERE (s.batch_name = e.batch_id 
            OR s.batch_name_2 = e.batch_id 
            OR s.batch_name_3 = e.batch_id 
            OR s.batch_name_4 = e.batch_id)
       AND s.current_status = 'active') as total_students,
    (SELECT COUNT(*) FROM exam_results WHERE exam_id = e.exam_id AND obtained_marks > 0) as results_uploaded
    FROM exams e 
    LEFT JOIN batches b ON e.batch_id = b.batch_id 
    ORDER BY e.exam_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Management - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
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

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
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
        .navbar .form-control {
            background: rgba(255,255,255,0.15) !important;
            border: 1px solid rgba(255,255,255,0.2) !important;
            color: #ffffff !important;
        }
        .navbar .form-control:focus {
            border-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(255,255,255,0.15);
            background: rgba(255,255,255,0.2) !important;
        }
        .navbar .form-control::placeholder {
            color: rgba(255,255,255,0.7);
        }
        .navbar .btn-close {
            filter: invert(1);
        }

        /* ================================================================
           HERO BANNER
           ================================================================ */
        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 45%, #456882 100%);
            border-radius: var(--radius-card);
            padding: 2rem 2.5rem;
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

        .stat-card .h2 {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--color-text-primary);
            margin-bottom: 0.25rem;
            transition: var(--transition-smooth);
        }
        .stat-card:hover .h2 {
            transform: scale(1.05);
        }
        .stat-card .text-muted {
            color: var(--color-text-muted) !important;
            font-size: 0.85rem;
        }

        .stat-card .icon-box {
            border-radius: 12px;
            padding: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition-smooth);
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

        /* ================================================================
           GRID CARDS — FIXED FOOTER
           ================================================================ */
        .grid-card {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            padding: 1.5rem 1.25rem 1.25rem;
            transition: var(--transition-smooth);
            position: relative;
            overflow: visible;  /* changed from hidden to prevent clipping */
            box-shadow: var(--shadow-card);
            cursor: default;
            color: var(--color-text-primary);
            display: flex;
            flex-direction: column;
            height: 100%;       /* fill the grid cell */
            min-height: 320px;  /* ensure enough space */
            animation: scaleIn 0.4s ease-out;
            animation-fill-mode: both;
        }
        .grid-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6);
            opacity: 0;
            transition: var(--transition-smooth);
            transform: scaleX(0);
        }
        .grid-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-hover);
            border-color: var(--color-sky);
        }
        .grid-card:hover::before {
            opacity: 1;
            transform: scaleX(1);
        }
        .grid-card .text-muted {
            color: var(--color-text-muted) !important;
        }
        .grid-card .border-top {
            border-top-color: var(--color-border) !important;
        }

        /* Card content fills available space */
        .grid-card .card-body-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        /* Footer stays at bottom */
        .grid-card .card-footer-actions {
            margin-top: auto;
            padding-top: 0.75rem;
            border-top: 1px solid var(--color-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        /* Ensure action buttons don't wrap unnecessarily */
        .grid-card .btn-group {
            flex-wrap: nowrap;
        }

        /* ================================================================
           TABLES
           ================================================================ */
        .table-wrapper {
            background: #ffffff;
            border-radius: var(--radius-card);
            padding: 0.25rem;
            box-shadow: var(--shadow-card);
        }
        .table {
            --bs-table-bg: transparent;
            border-collapse: separate;
            border-spacing: 0 4px;
            color: var(--color-text-primary);
            margin-bottom: 0;
        }
        .table thead th {
            background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            color: #ffffff;
            border: none;
            font-weight: 600;
            padding: 0.85rem 1rem;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.06em;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        .table thead th:first-child {
            border-radius: 12px 0 0 0;
        }
        .table thead th:last-child {
            border-radius: 0 12px 0 0;
        }
        .table tbody tr {
            background: #ffffff;
            border-radius: 8px;
            transition: var(--transition-smooth);
            box-shadow: 0 1px 4px rgba(27,60,83,0.04);
            cursor: default;
            animation: slideInRight 0.4s ease-out;
            animation-fill-mode: both;
        }
        .table tbody tr:nth-child(even) {
            background: #f4ede7;
        }
        .table tbody tr:hover {
            transform: translateX(4px);
            box-shadow: var(--shadow-hover);
            background: #e8dfd8;
        }
        .table td {
            padding: 0.85rem 1rem;
            vertical-align: middle;
            border: none;
            color: var(--color-text-primary);
        }
        .table td:first-child {
            border-radius: 8px 0 0 8px;
        }
        .table td:last-child {
            border-radius: 0 8px 8px 0;
        }

        /* ================================================================
           FORMS
           ================================================================ */
        .form-control, .form-select {
            background: #ffffff;
            border: 2px solid var(--color-border);
            border-radius: 10px;
            padding: 0.6rem 1rem;
            transition: var(--transition-smooth);
            font-size: 0.95rem;
            color: var(--color-text-primary);
            height: 44px;
        }
        .form-control::placeholder {
            color: var(--color-text-muted);
            opacity: 0.7;
        }
        .form-control:focus, .form-select:focus {
            background: #ffffff;
            border-color: var(--color-steel-mid);
            box-shadow: 0 0 0 4px rgba(69,104,130,0.12);
            color: var(--color-text-primary);
            transform: translateY(-1px);
        }
        .form-label {
            color: var(--color-text-secondary);
            font-weight: 700;
            margin-bottom: 0.3rem;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            transition: var(--transition-smooth);
        }
        .form-text {
            color: var(--color-text-muted) !important;
            font-size: 0.85rem;
        }
        .form-check-input {
            background-color: #ffffff;
            border-color: var(--color-border);
            cursor: pointer;
            transition: var(--transition-smooth);
        }
        .form-check-input:checked {
            background-color: var(--color-steel-mid);
            border-color: var(--color-steel-mid);
            animation: scaleIn 0.2s ease-out;
        }
        .form-check-label {
            color: var(--color-text-primary);
            cursor: pointer;
            font-weight: 500;
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

        .btn-outline-primary {
            border: 2px solid var(--color-navy);
            color: var(--color-navy);
            background: transparent;
        }
        .btn-outline-primary:hover {
            background: var(--color-navy);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 4px 16px rgba(27,60,83,0.20);
        }

        .btn-outline-success {
            border: 2px solid var(--color-steel-mid);
            color: var(--color-steel-mid);
            background: transparent;
        }
        .btn-outline-success:hover {
            background: var(--color-steel-mid);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 4px 16px rgba(69,104,130,0.20);
        }

        .btn-outline-warning {
            border: 2px solid #C97B50;
            color: #C97B50;
            background: transparent;
        }
        .btn-outline-warning:hover {
            background: linear-gradient(135deg, #f59e0b, #C97B50);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 4px 16px rgba(201,123,80,0.25);
        }

        .btn-outline-danger {
            border: 2px solid #C0392B;
            color: #C0392B;
            background: transparent;
        }
        .btn-outline-danger:hover {
            background: linear-gradient(135deg, #ef4444, #C0392B);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 4px 16px rgba(192,57,43,0.25);
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
        }

        .btn-group .btn {
            border-radius: 9999px;
            margin: 0 2px;
        }
        .btn-group .btn:first-child {
            border-radius: 9999px;
        }
        .btn-group .btn:last-child {
            border-radius: 9999px;
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
        .badge.bg-info { background: var(--color-steel-mid) !important; color: #fff; }
        .badge.bg-primary { background: var(--color-navy) !important; color: #fff; }
        .badge.bg-warning { background: #f59e0b !important; color: #fff; }
        .badge.bg-danger { background: #C0392B !important; color: #fff; }
        .badge.bg-success { background: var(--color-steel-dark) !important; color: #fff; }
        .badge.bg-secondary { background: #D2C1B6 !important; color: var(--color-text-primary); }

        /* Status badges */
        .badge-status-present {
            background: #dcfce7 !important;
            color: #166534 !important;
        }
        .badge-status-absent {
            background: #fee2e2 !important;
            color: #991b1b !important;
        }
        .badge-status-late {
            background: #fef3c7 !important;
            color: #92400e !important;
        }
        .badge-status-upcoming {
            background: #dbeafe !important;
            color: #1e40af !important;
        }
        .badge-status-completed {
            background: #f3f4f6 !important;
            color: #4b5563 !important;
        }

        /* ================================================================
           VIEW TOGGLE
           ================================================================ */
        .view-toggle {
            display: flex;
            gap: 4px;
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: 9999px;
            padding: 4px;
            box-shadow: var(--shadow-card);
        }
        .view-toggle-btn {
            padding: 0.35rem 1rem;
            border: none;
            border-radius: 9999px;
            background: transparent;
            color: var(--color-text-muted);
            font-weight: 500;
            transition: var(--transition-smooth);
            height: 34px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
        }
        .view-toggle-btn.active {
            background: linear-gradient(135deg, #eef6fb, #c8e0ef);
            color: var(--color-navy);
            box-shadow: 0 2px 8px rgba(27,60,83,0.10);
            transform: scale(1.04);
            font-weight: 600;
        }
        .view-toggle-btn:hover:not(.active) {
            background: var(--color-border);
            color: var(--color-text-primary);
            transform: scale(1.04);
        }

        /* ================================================================
           FILTER SECTION
           ================================================================ */
        .filter-section {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-card);
            padding: 1.25rem 1.5rem;
            margin-bottom: 24px;
            box-shadow: var(--shadow-card);
            animation: slideInRight 0.5s ease-out;
        }
        .search-bar {
            background: #ffffff;
            border: 2px solid var(--color-border);
            border-radius: 10px;
            padding: 0 0.75rem;
            transition: var(--transition-smooth);
            height: 44px;
            display: flex;
            align-items: center;
        }
        .search-bar:focus-within {
            border-color: var(--color-steel-mid);
            box-shadow: 0 0 0 4px rgba(69,104,130,0.08);
            transform: translateY(-1px);
        }
        .search-bar .input-group-text {
            background: transparent !important;
            border: none !important;
            color: var(--color-text-muted);
            padding: 0 0.5rem 0 0;
            transition: var(--transition-smooth);
        }
        .search-bar:focus-within .input-group-text {
            color: var(--color-steel-mid);
        }
        .search-bar .form-control {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            color: var(--color-text-primary);
            height: 38px;
            padding: 0 0.75rem;
        }

        /* ================================================================
           ALERTS
           ================================================================ */
        .alert-success {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border-left: 4px solid #166534;
            border-radius: var(--radius-card);
            color: #064e3b;
        }
        .alert-danger {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            border-left: 4px solid #991b1b;
            border-radius: var(--radius-card);
            color: #7f1d1d;
        }
        .alert-warning {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-left: 4px solid #92400e;
            border-radius: var(--radius-card);
            color: #78350f;
        }
        .alert-info {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border-left: 4px solid #1e40af;
            border-radius: var(--radius-card);
            color: #1e3a8a;
        }

        /* ================================================================
           MODALS
           ================================================================ */
        .modal-content {
            background: #ffffff;
            border: none;
            border-radius: var(--radius-card);
            box-shadow: var(--shadow-hover);
            animation: modalSlideIn 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            color: var(--color-text-primary);
        }
        .modal-header {
            border-bottom-color: var(--color-border);
            padding: 1.25rem 1.5rem;
        }
        .modal-footer {
            border-top-color: var(--color-border);
            padding: 1rem 1.5rem;
        }
        .btn-close {
            filter: invert(0.3);
            transition: var(--transition-smooth);
        }
        .btn-close:hover {
            transform: rotate(90deg);
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-30px) scale(0.92); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-backdrop {
            backdrop-filter: blur(6px);
            background: rgba(27, 60, 83, 0.4);
        }

        /* ================================================================
           COMPONENT SECTION
           ================================================================ */
        .component-section {
            background: rgba(27,60,83,0.02);
            border: 1px solid var(--color-border);
            border-radius: 14px;
            padding: 1.25rem;
            margin-bottom: 24px;
            transition: var(--transition-smooth);
        }
        .component-section:hover {
            background: rgba(27,60,83,0.04);
        }
        .component-fields {
            display: none;
            margin-top: 1rem;
            animation: scaleIn 0.3s ease-out;
        }
        .component-fields.active {
            display: block;
        }

        /* ================================================================
           FLOATING BUTTON
           ================================================================ */
        .floating-btn {
            position: fixed;
            bottom: 28px;
            right: 28px;
            z-index: 100;
            background: linear-gradient(135deg, #f59e0b, #C97B50);
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 28px rgba(245, 158, 11, 0.35);
            transition: var(--transition-smooth);
            color: #ffffff;
            animation: float 3s ease-in-out infinite;
        }
        .floating-btn:hover {
            transform: scale(1.15) rotate(90deg);
            box-shadow: 0 12px 40px rgba(245, 158, 11, 0.45);
            color: #ffffff;
            animation: none;
        }
        .floating-btn i {
            transition: var(--transition-smooth);
        }
        .floating-btn:hover i {
            transform: scale(1.2);
        }

        /* ================================================================
           PROGRESS
           ================================================================ */
        .progress {
            height: 8px;
            border-radius: 8px;
            background: #f0ede8;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.06);
        }
        .progress-bar {
            border-radius: 8px;
            transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
            background: linear-gradient(90deg, #1B3C53, #456882);
            position: relative;
        }
        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
            animation: shimmer 2s infinite;
        }
        .progress-small { height: 6px; border-radius: 6px; }

        /* ================================================================
           DAYS BADGE
           ================================================================ */
        .days-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.7rem;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            height: 24px;
            transition: var(--transition-smooth);
        }
        .days-secondary {
            background: #f4ede7;
            color: var(--color-text-primary);
            border-color: #D2C1B6;
        }
        .days-success {
            background: #dbeafe;
            color: #1e40af;
            border-color: #A4C4D4;
            animation: glow 2s infinite;
        }

        /* ================================================================
           STUDENT COUNT BADGE
           ================================================================ */
        .student-count-badge {
            background: linear-gradient(135deg, #456882, #234C6A);
            color: #ffffff;
            font-size: 0.65rem;
            padding: 0.1rem 0.7rem;
            border-radius: 20px;
            margin-left: 6px;
            white-space: nowrap;
            transition: var(--transition-smooth);
        }
        .student-count-badge:hover {
            transform: scale(1.05);
        }

        /* ================================================================
           STATUS LABEL
           ================================================================ */
        .status-label {
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-top: 2px;
            transition: var(--transition-smooth);
        }
        .status-label.upcoming {
            color: var(--color-steel-mid);
            animation: float 3s ease-in-out infinite;
        }
        .status-label.completed {
            color: var(--color-text-muted);
        }

        /* ================================================================
           GRADIENT TEXT
           ================================================================ */
        .gradient-text {
            background: linear-gradient(135deg, #1B3C53, #234C6A, #456882);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 800;
            transition: var(--transition-smooth);
        }
        h1.gradient-text:hover {
            transform: scale(1.02);
            letter-spacing: 0.5px;
        }

        /* ================================================================
           GRID VIEW
           ================================================================ */
        .grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }
        @media (max-width: 992px) {
            .grid-view { grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
        }
        @media (max-width: 576px) {
            .grid-view { grid-template-columns: 1fr; }
        }

        /* ================================================================
           LIST VIEW - Header text larger on switch
           ================================================================ */
        #listView .table thead th {
            font-size: 0.95rem;
            padding: 1rem 1.2rem;
            letter-spacing: 0.08em;
        }
        #listView .table td {
            font-size: 0.95rem;
            padding: 1rem 1.2rem;
        }

        /* ================================================================
           UTILITY
           ================================================================ */
        .bg-light { background: #f8f7f4 !important; }
        .bg-white { background: #ffffff !important; }
        .text-dark { color: var(--color-text-primary) !important; }
        .text-muted { color: var(--color-text-muted) !important; }
        .border { border-color: var(--color-border) !important; }
        .border-top { border-top-color: var(--color-border) !important; }
        .border-bottom { border-bottom-color: var(--color-border) !important; }

        .fw-700 { font-weight: 700; }
        .fw-800 { font-weight: 800; }

        /* ================================================================
           RESPONSIVE
           ================================================================ */
        @media (max-width: 768px) {
            .main-content { padding: 16px; }
            .floating-btn {
                bottom: 20px;
                right: 20px;
                width: 50px;
                height: 50px;
            }
            .hero-banner { padding: 1.5rem; }
            .hero-banner .h1 { font-size: 1.5rem; }
        }
        @media (max-width: 576px) {
            .filter-section .row > div { margin-bottom: 12px; }
            .search-bar { height: 40px; }
            .form-control, .form-select { height: 40px; font-size: 0.9rem; }
            .btn { height: 38px; padding: 0 1rem; font-size: 0.85rem; }
            .btn-group .btn { height: 30px; padding: 0 0.6rem; font-size: 0.75rem; }
        }

        /* ================================================================
           RIPPLE EFFECT
           ================================================================ */
        .ripple {
            position: absolute;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            transform: scale(0);
            animation: rippleAnim 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            pointer-events: none;
        }
        @keyframes rippleAnim {
            to { transform: scale(4); opacity: 0; }
        }

        /* ================================================================
           DELETE MODAL
           ================================================================ */
        .delete-modal { text-align: center; }
        .delete-icon { 
            font-size: 3.5rem; 
            color: #C0392B; 
            margin-bottom: 1rem;
            animation: float 2s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <!-- Include Sidebar and Header -->
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <!-- Hero Banner -->
            <div class="hero-banner">
                <div class="row align-items-center">
                    <div class="col">
                        <h1 class="h2 mb-1" style="color: #ffffff; font-weight: 800;">Exam Management</h1>
                        <p class="text-muted mb-0" style="color: rgba(255,255,255,0.85) !important;">Manage exams, upload results, and track performance</p>
                    </div>
                    <div class="col-auto">
                        <div class="d-flex gap-2 flex-wrap">
                            <div class="view-toggle">
                                <button class="view-toggle-btn active" data-view="grid" id="gridViewBtn">
                                    <i class="fas fa-th-large me-1"></i> Grid
                                </button>
                                <button class="view-toggle-btn" data-view="list" id="listViewBtn">
                                    <i class="fas fa-list me-1"></i> List
                                </button>
                            </div>
                            <a href="generate_marksheet.php" class="btn btn-primary pulse" style="background: linear-gradient(135deg, #f59e0b, #C97B50);">
                                <i class="fas fa-file-pdf me-1"></i> Generate Marksheet
                            </a>
                            <button type="button" class="btn btn-primary pulse" data-bs-toggle="modal" data-bs-target="#createExamModal" style="background: linear-gradient(135deg, #f59e0b, #C97B50);">
                                <i class="fas fa-plus me-1"></i> Create Exam
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-check-circle fa-lg"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="alert-heading mb-1" style="font-weight: 700;">Success!</h6>
                            <p class="mb-0"><?php echo $message; ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle fa-lg"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h6 class="alert-heading mb-1" style="font-weight: 700;">Error!</h6>
                            <p class="mb-0"><?php echo $error; ?></p>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card">
                        <div class="top-stripe blue"></div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-uppercase text-muted small fw-bold" style="color: var(--color-text-muted) !important;">Total Exams</div>
                                <div class="h2"><?php echo count($exams); ?></div>
                                <div class="small mt-1 text-muted">All created exams</div>
                            </div>
                            <div class="icon-box blue">
                                <i class="fas fa-file-alt fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card">
                        <div class="top-stripe green"></div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-uppercase text-muted small fw-bold" style="color: var(--color-text-muted) !important;">This Month</div>
                                <div class="h2">
                                    <?php
                                    $current_month = date('m');
                                    $month_exams = array_filter($exams, function($exam) use ($current_month) {
                                        return date('m', strtotime($exam['exam_date'])) == $current_month;
                                    });
                                    echo count($month_exams);
                                    ?>
                                </div>
                                <div class="small mt-1 text-muted">New exams this month</div>
                            </div>
                            <div class="icon-box green">
                                <i class="fas fa-calendar fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card">
                        <div class="top-stripe amber"></div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-uppercase text-muted small fw-bold" style="color: var(--color-text-muted) !important;">Upcoming</div>
                                <div class="h2">
                                    <?php
                                    $upcoming_exams = array_filter($exams, function($exam) {
                                        return strtotime($exam['exam_date']) > time();
                                    });
                                    echo count($upcoming_exams);
                                    ?>
                                </div>
                                <div class="small mt-1 text-muted">Scheduled exams</div>
                            </div>
                            <div class="icon-box amber">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card">
                        <div class="top-stripe steel"></div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="text-uppercase text-muted small fw-bold" style="color: var(--color-text-muted) !important;">Total Students</div>
                                <div class="h2">
                                    <?php
                                    $total_students_all = array_sum(array_column($exams, 'total_students'));
                                    echo $total_students_all;
                                    ?>
                                </div>
                                <div class="small mt-1 text-muted">Across all exams</div>
                            </div>
                            <div class="icon-box steel">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Search and Filter Section -->
            <div class="filter-section">
                <div class="row align-items-center">
                    <div class="col-md-4 mb-3 mb-md-0">
                        <div class="search-bar">
                            <div class="input-group">
                                <span class="input-group-text border-0 bg-transparent">
                                    <i class="fas fa-search text-muted"></i>
                                </span>
                                <input type="text" class="form-control border-0" id="searchInput" placeholder="Search exams...">
                                <button class="btn btn-outline-secondary border-0" type="button" id="clearSearch" style="background: transparent;">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <select class="form-select" id="batchFilter">
                                    <option value="">All Batches</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?php echo $batch['batch_id']; ?>"><?php echo htmlspecialchars($batch['batch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select" id="typeFilter">
                                    <option value="">All Types</option>
                                    <option value="unit_test">Unit Test</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="half-yearly">Half Yearly</option>
                                    <option value="final">Final</option>
                                    <option value="practice">Practice</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select" id="statusFilter">
                                    <option value="">All Status</option>
                                    <option value="Upcoming">Upcoming</option>
                                    <option value="Completed">Completed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Grid View -->
            <div id="gridView" class="grid-view">
                <?php foreach ($exams as $exam): 
                    $exam_type_badge = '';
                    switch($exam['exam_type']) {
                        case 'unit_test': $exam_type_badge = 'bg-info'; break;
                        case 'quarterly': $exam_type_badge = 'bg-primary'; break;
                        case 'half-yearly': $exam_type_badge = 'bg-warning'; break;
                        case 'final': $exam_type_badge = 'bg-danger'; break;
                        case 'practice': $exam_type_badge = 'bg-success'; break;
                        default: $exam_type_badge = 'bg-secondary';
                    }
                    
                    $exam_date = strtotime($exam['exam_date']);
                    $current_date = time();
                    $status_badge = '';
                    $status_text = '';
                    $status_class = '';
                    $is_upcoming = false;
                    if ($exam_date > $current_date) {
                        $status_badge = 'badge-status-upcoming';
                        $status_text = 'Upcoming';
                        $status_class = 'upcoming';
                        $is_upcoming = true;
                    } else {
                        $status_badge = 'badge-status-completed';
                        $status_text = 'Completed';
                        $status_class = 'completed';
                        $is_upcoming = false;
                    }
                    
                    $days_diff = floor(($exam_date - $current_date) / (60 * 60 * 24));
                    $days_text = $days_diff > 0 ? "in $days_diff Days" : ($days_diff < 0 ? abs($days_diff) . " days ago" : "Today");
                    
                    $components = [];
                    if (!empty($exam['exam_components'])) {
                        $exam_components = explode(',', $exam['exam_components']);
                        foreach ($exam_components as $component) {
                            switch($component) {
                                case 'mcq': $components[] = '<span class="badge bg-primary" style="background: var(--color-navy) !important;">MCQ</span>'; break;
                                case 'project': $components[] = '<span class="badge bg-success">Project</span>'; break;
                                case 'viva': $components[] = '<span class="badge" style="background: #C97B50; color: #fff;">Viva</span>'; break;
                            }
                        }
                    }
                    
                    $progress = $exam['total_students'] > 0 ? ($exam['results_uploaded'] / $exam['total_students']) * 100 : 0;
                ?>
                <div class="grid-card" data-batch="<?php echo htmlspecialchars($exam['batch_id']); ?>" 
                     data-type="<?php echo $exam['exam_type']; ?>" 
                     data-status="<?php echo strtolower($status_text); ?>">
                    
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="mb-1 fw-bold exam-name" title="<?php echo htmlspecialchars($exam['exam_name']); ?>" style="color: var(--color-text-primary);"><?php echo htmlspecialchars($exam['exam_name']); ?></h5>
                            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($exam['subject']); ?></p>
                        </div>
                        <div class="text-end">
                            <span class="badge <?php echo $exam_type_badge; ?> badge-exam">
                                <?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?>
                            </span>
                            <div class="status-label <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body-content">
                        <div class="mb-3">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-calendar-alt me-2" style="color: <?php echo $is_upcoming ? '#456882' : '#8B7E74'; ?>;"></i>
                                <span class="text-muted small"><?php echo date('d M Y', strtotime($exam['exam_date'])); ?></span>
                                <span class="badge days-badge days-<?php echo $status_class === 'upcoming' ? 'success' : 'secondary'; ?> ms-2">
                                    <?php echo $days_text; ?>
                                </span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-users me-2" style="color: var(--color-navy);"></i>
                                <span class="text-muted small batch-name" title="<?php echo htmlspecialchars($exam['batch_name']); ?>"><?php echo htmlspecialchars($exam['batch_name']); ?></span>
                                <span class="student-count-badge ms-2">
                                    <i class="fas fa-user-graduate me-1"></i><?php echo $exam['total_students']; ?>
                                </span>
                            </div>
                            <div class="d-flex align-items-center">
                                <i class="fas fa-chart-bar me-2" style="color: var(--color-steel-dark);"></i>
                                <span class="text-muted small">Total Marks: <?php echo $exam['total_marks']; ?></span>
                            </div>
                        </div>
                        
                        <?php if (!empty($components)): ?>
                            <div class="mb-3">
                                <div class="text-muted small mb-2">Components:</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php echo implode(' ', $components); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="text-muted small">Results Uploaded</span>
                                <span class="text-muted small"><?php echo $exam['results_uploaded']; ?>/<?php echo $exam['total_students']; ?></span>
                            </div>
                            <div class="progress progress-small">
                                <div class="progress-bar" role="progressbar" 
                                     style="width: <?php echo $progress; ?>%" 
                                     aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-footer-actions">
                        <div>
                            <span class="badge <?php echo $status_badge; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        </div>
                        <div class="btn-group btn-group-sm" role="group">
                            <a href="exam_details.php?id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-primary" data-bs-toggle="tooltip" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="upload_results.php?exam_id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-success" data-bs-toggle="tooltip" title="Upload Results">
                                <i class="fas fa-upload"></i>
                            </a>
                            <a href="edit_exam.php?id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-warning" data-bs-toggle="tooltip" title="Edit Exam">
                                <i class="fas fa-edit"></i>
                            </a>
                            <button type="button" class="btn btn-outline-danger" 
                                    onclick="showDeleteModal('<?php echo $exam['exam_id']; ?>', '<?php echo htmlspecialchars($exam['exam_name']); ?>')"
                                    data-bs-toggle="tooltip" title="Delete Exam">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- List View -->
            <div id="listView" style="display: none;">
                <div class="table-wrapper">
                    <div class="table-responsive">
                        <table class="table" id="examsTable">
                            <thead>
                                <tr>
                                    <th>Exam Name</th>
                                    <th>Batch</th>
                                    <th>Subject</th>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Students</th>
                                    <th>Results</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($exams as $exam): 
                                    $exam_type_badge = '';
                                    switch($exam['exam_type']) {
                                        case 'unit_test': $exam_type_badge = 'bg-info'; break;
                                        case 'quarterly': $exam_type_badge = 'bg-primary'; break;
                                        case 'half-yearly': $exam_type_badge = 'bg-warning'; break;
                                        case 'final': $exam_type_badge = 'bg-danger'; break;
                                        case 'practice': $exam_type_badge = 'bg-success'; break;
                                        default: $exam_type_badge = 'bg-secondary';
                                    }
                                    
                                    $exam_date = strtotime($exam['exam_date']);
                                    $current_date = time();
                                    $status_badge = '';
                                    $status_text = '';
                                    if ($exam_date > $current_date) {
                                        $status_badge = 'badge-status-upcoming';
                                        $status_text = 'Upcoming';
                                    } else {
                                        $status_badge = 'badge-status-completed';
                                        $status_text = 'Completed';
                                    }
                                ?>
                                <tr>
                                    <td class="fw-bold" style="color: var(--color-text-primary);"><?php echo htmlspecialchars($exam['exam_name']); ?></td>
                                    <td><?php echo htmlspecialchars($exam['batch_name']); ?></td>
                                    <td><?php echo htmlspecialchars($exam['subject']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($exam['exam_date'])); ?></td>
                                    <td><span class="badge <?php echo $exam_type_badge; ?> badge-exam"><?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?></span></td>
                                    <td>
                                        <span class="badge" style="background: var(--color-steel-mid); color: #fff;">
                                            <i class="fas fa-user-graduate me-1"></i><?php echo $exam['total_students']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="me-2"><?php echo $exam['results_uploaded']; ?>/<?php echo $exam['total_students']; ?></span>
                                            <div class="progress progress-small" style="width: 60px;">
                                                <div class="progress-bar" role="progressbar" 
                                                     style="width: <?php echo $exam['total_students'] > 0 ? ($exam['results_uploaded'] / $exam['total_students']) * 100 : 0; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span></td>
                                    <td>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <a href="exam_details.php?id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-primary" data-bs-toggle="tooltip" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="upload_results.php?exam_id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-success" data-bs-toggle="tooltip" title="Upload Results">
                                                <i class="fas fa-upload"></i>
                                            </a>
                                            <a href="edit_exam.php?id=<?php echo $exam['exam_id']; ?>" class="btn btn-outline-warning" data-bs-toggle="tooltip" title="Edit Exam">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" 
                                                    onclick="showDeleteModal('<?php echo $exam['exam_id']; ?>', '<?php echo htmlspecialchars($exam['exam_name']); ?>')"
                                                    data-bs-toggle="tooltip" title="Delete Exam">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Empty State -->
            <div id="emptyState" style="display: none;">
                <div class="text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-file-alt fa-4x" style="color: #D2C1B6; animation: float 3s ease-in-out infinite;"></i>
                    </div>
                    <h4 class="text-muted mb-3">No exams found</h4>
                    <p class="text-muted mb-4">Try adjusting your search or filters to find what you're looking for.</p>
                    <button class="btn btn-primary" id="clearAllFilters" style="background: linear-gradient(135deg, #f59e0b, #C97B50);">
                        <i class="fas fa-redo me-2"></i> Clear All Filters
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Exam Modal -->
    <div class="modal fade" id="createExamModal" tabindex="-1" aria-labelledby="createExamModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title gradient-text" id="createExamModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Create New Exam
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label for="exam_name" class="form-label fw-semibold">Exam Name *</label>
                                <input type="text" class="form-control" id="exam_name" name="exam_name" required placeholder="Enter exam name">
                            </div>
                            <div class="col-md-6">
                                <label for="batch_id" class="form-label fw-semibold">Batch *</label>
                                <select class="form-select" id="batch_id" name="batch_id" required>
                                    <option value="">Select Batch</option>
                                    <?php foreach ($batches as $batch): ?>
                                        <option value="<?php echo $batch['batch_id']; ?>"><?php echo htmlspecialchars($batch['batch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text mt-2">
                                    <i class="fas fa-info-circle me-1"></i> Students from primary and secondary batches will be included
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="subject" class="form-label fw-semibold">Subject *</label>
                                <input type="text" class="form-control" id="subject" name="subject" required placeholder="Enter subject">
                            </div>
                            <div class="col-md-6">
                                <label for="exam_date" class="form-label fw-semibold">Exam Date *</label>
                                <input type="date" class="form-control" id="exam_date" name="exam_date" required>
                                <div class="form-text mt-2">
                                    <i class="fas fa-info-circle me-1"></i> You can select past dates for back-dated exams
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="total_marks" class="form-label fw-semibold">Total Marks *</label>
                                <input type="number" class="form-control" id="total_marks" name="total_marks" min="1" required placeholder="e.g., 100">
                            </div>
                            <div class="col-md-6">
                                <label for="passing_marks" class="form-label fw-semibold">Passing Marks *</label>
                                <input type="number" class="form-control" id="passing_marks" name="passing_marks" min="1" required placeholder="e.g., 40">
                            </div>
                            <div class="col-md-6">
                                <label for="exam_type" class="form-label fw-semibold">Exam Type *</label>
                                <select class="form-select" id="exam_type" name="exam_type" required>
                                    <option value="">Select Type</option>
                                    <option value="unit_test">Unit Test</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="half-yearly">Half Yearly</option>
                                    <option value="final">Final</option>
                                    <option value="practice">Practice</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="description" class="form-label fw-semibold">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="2" placeholder="Optional exam description"></textarea>
                            </div>
                        </div>

                        <!-- Exam Components -->
                        <div class="component-section mt-4">
                            <h6 class="fw-semibold mb-3 gradient-text">
                                <i class="fas fa-puzzle-piece me-2"></i>Exam Components (Optional)
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <div class="form-check card p-3" style="border-left: 4px solid var(--color-navy);">
                                        <input class="form-check-input component-checkbox" type="checkbox" id="component_mcq" name="exam_components[]" value="mcq">
                                        <label class="form-check-label fw-medium" for="component_mcq">
                                            <i class="fas fa-list-ul me-2"></i>MCQ Section
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check card p-3" style="border-left: 4px solid var(--color-steel-mid);">
                                        <input class="form-check-input component-checkbox" type="checkbox" id="component_project" name="exam_components[]" value="project">
                                        <label class="form-check-label fw-medium" for="component_project">
                                            <i class="fas fa-project-diagram me-2"></i>Project Work
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check card p-3" style="border-left: 4px solid #C97B50;">
                                        <input class="form-check-input component-checkbox" type="checkbox" id="component_viva" name="exam_components[]" value="viva">
                                        <label class="form-check-label fw-medium" for="component_viva">
                                            <i class="fas fa-microphone me-2"></i>Viva Voce
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div id="mcqFields" class="component-fields mt-3">
                                <div class="card p-3" style="border-left: 4px solid var(--color-navy);">
                                    <label for="mcq_marks" class="form-label fw-medium">MCQ Marks</label>
                                    <input type="number" class="form-control" id="mcq_marks" name="mcq_marks" min="0" value="0" placeholder="Enter MCQ marks">
                                </div>
                            </div>
                            <div id="projectFields" class="component-fields mt-3">
                                <div class="card p-3" style="border-left: 4px solid var(--color-steel-mid);">
                                    <label for="project_marks" class="form-label fw-medium">Project Marks</label>
                                    <input type="number" class="form-control" id="project_marks" name="project_marks" min="0" value="0" placeholder="Enter project marks">
                                </div>
                            </div>
                            <div id="vivaFields" class="component-fields mt-3">
                                <div class="card p-3" style="border-left: 4px solid #C97B50;">
                                    <label for="viva_marks" class="form-label fw-medium">Viva Marks</label>
                                    <input type="number" class="form-control" id="viva_marks" name="viva_marks" min="0" value="0" placeholder="Enter viva marks">
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> Students will be automatically enrolled based on their batch assignments (primary and secondary batches). All active students from the selected batch will be added to exam_results with initial marks 0.
                        </div>
                    </div>
                    <div class="modal-footer border-top-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="create_exam" class="btn btn-primary">
                            <i class="fas fa-check-circle me-2"></i>Create Exam
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Exam Modal -->
    <div class="modal fade" id="deleteExamModal" tabindex="-1" aria-labelledby="deleteExamModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body delete-modal pt-0">
                    <div class="delete-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h4 class="mb-3" style="color: var(--color-text-primary);">Delete Exam?</h4>
                    <p class="text-muted mb-4">
                        Are you sure you want to delete "<span id="deleteExamName" class="fw-bold" style="color: var(--color-text-primary);"></span>"?<br>
                        This action cannot be undone.
                    </p>
                    <div class="alert alert-warning d-flex align-items-center" role="alert">
                        <i class="fas fa-exclamation-circle me-3 fs-5"></i>
                        <div class="text-start small">
                            <strong>Warning:</strong> This exam will be permanently deleted.<br>
                            Make sure no results are associated with this exam.
                        </div>
                    </div>
                    <form method="POST" action="" id="deleteExamForm">
                        <input type="hidden" name="exam_id" id="deleteExamId">
                        <button type="submit" name="delete_exam" class="btn btn-danger px-5 py-2">
                            <i class="fas fa-trash me-2"></i>Yes, Delete Exam
                        </button>
                        <button type="button" class="btn btn-secondary px-5 py-2 ms-2" data-bs-dismiss="modal">
                            Cancel
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button type="button" class="btn btn-primary btn-lg rounded-circle floating-btn" data-bs-toggle="modal" data-bs-target="#createExamModal">
        <i class="fas fa-plus"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Component checkbox with smooth animation
            $('.component-checkbox').change(function() {
                const componentId = $(this).val();
                const componentFields = $('#' + componentId + 'Fields');
                if ($(this).is(':checked')) {
                    componentFields.slideDown(300).addClass('active');
                } else {
                    componentFields.slideUp(300).removeClass('active');
                    $('#' + componentId + '_marks').val('0');
                }
            });

            // View toggle
            $('#gridViewBtn').click(function() {
                if (!$(this).hasClass('active')) {
                    $('.view-toggle-btn').removeClass('active');
                    $(this).addClass('active');
                    $('#listView').fadeOut(200, function() {
                        $('#gridView').fadeIn(300);
                    });
                    $('#emptyState').hide();
                }
            });

            $('#listViewBtn').click(function() {
                if (!$(this).hasClass('active')) {
                    $('.view-toggle-btn').removeClass('active');
                    $(this).addClass('active');
                    $('#gridView').fadeOut(200, function() {
                        $('#listView').fadeIn(300);
                    });
                    $('#emptyState').hide();
                }
            });

            // Search & filter
            function filterExams() {
                const searchTerm = $('#searchInput').val().toLowerCase();
                const batchFilter = $('#batchFilter').val();
                const typeFilter = $('#typeFilter').val();
                const statusFilter = $('#statusFilter').val();
                let visibleCount = 0;

                // Filter grid cards
                $('.grid-card').each(function() {
                    const card = $(this);
                    const examName = card.find('h5').text().toLowerCase();
                    const subject = card.find('.text-muted').first().text().toLowerCase();
                    const batch = card.data('batch');
                    const type = card.data('type');
                    const status = card.data('status');

                    const matchesSearch = !searchTerm || examName.includes(searchTerm) || subject.includes(searchTerm);
                    const matchesBatch = !batchFilter || batch === batchFilter;
                    const matchesType = !typeFilter || type === typeFilter;
                    const matchesStatus = !statusFilter || status === statusFilter.toLowerCase();

                    if (matchesSearch && matchesBatch && matchesType && matchesStatus) {
                        card.show();
                        visibleCount++;
                    } else {
                        card.hide();
                    }
                });

                // Filter list rows
                $('#examsTable tbody tr').each(function() {
                    const row = $(this);
                    const examName = row.find('td:first').text().toLowerCase();
                    const batch = row.find('td:eq(1)').text().toLowerCase();
                    const type = row.find('td:eq(4) .badge').text().toLowerCase().replace(' ', '_');
                    const status = row.find('td:eq(7) .badge').text().toLowerCase();

                    const matchesSearch = !searchTerm || examName.includes(searchTerm);
                    const matchesBatch = !batchFilter || batch.includes($('#batchFilter option:selected').text().toLowerCase());
                    const matchesType = !typeFilter || type === typeFilter;
                    const matchesStatus = !statusFilter || status === statusFilter.toLowerCase();

                    if (matchesSearch && matchesBatch && matchesType && matchesStatus) {
                        row.show();
                    } else {
                        row.hide();
                    }
                });

                // Show/hide empty state
                if (visibleCount === 0 && $('#gridView').is(':visible')) {
                    $('#gridView').hide();
                    $('#listView').hide();
                    $('#emptyState').fadeIn(300);
                } else if (visibleCount > 0 && $('#emptyState').is(':visible')) {
                    $('#emptyState').hide();
                    if ($('#gridViewBtn').hasClass('active')) {
                        $('#gridView').fadeIn(300);
                    } else {
                        $('#listView').fadeIn(300);
                    }
                }
            }

            $('#searchInput').on('input', filterExams);
            $('#clearSearch').click(function() { 
                $('#searchInput').val('').focus(); 
                filterExams(); 
            });
            $('#batchFilter, #typeFilter, #statusFilter').change(filterExams);
            $('#clearAllFilters').click(function() {
                $('#searchInput').val('');
                $('#batchFilter, #typeFilter, #statusFilter').val('');
                filterExams();
                $('html, body').animate({
                    scrollTop: $('.filter-section').offset().top - 100
                }, 500);
            });

            // Set default date to today
            const examDateInput = document.getElementById('exam_date');
            if (examDateInput) {
                const today = new Date().toISOString().split('T')[0];
                examDateInput.value = today;
                examDateInput.min = '2020-01-01';
            }

            // Validate passing marks with visual feedback
            const totalMarksInput = document.getElementById('total_marks');
            const passingMarksInput = document.getElementById('passing_marks');
            if (totalMarksInput && passingMarksInput) {
                totalMarksInput.addEventListener('input', function() {
                    const totalMarks = parseInt(this.value) || 0;
                    passingMarksInput.setAttribute('max', totalMarks);
                    if (parseInt(passingMarksInput.value) > totalMarks) {
                        passingMarksInput.value = totalMarks;
                        passingMarksInput.style.borderColor = '#C0392B';
                        setTimeout(() => { passingMarksInput.style.borderColor = ''; }, 1000);
                    }
                });
                passingMarksInput.addEventListener('input', function() {
                    const totalMarks = parseInt(totalMarksInput.value) || 0;
                    const passingMarks = parseInt(this.value) || 0;
                    if (passingMarks > totalMarks) {
                        this.value = totalMarks;
                        this.style.borderColor = '#C0392B';
                        setTimeout(() => { this.style.borderColor = ''; }, 1000);
                    }
                });
            }

            // Tooltips with smooth animation
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (el) { 
                return new bootstrap.Tooltip(el, {
                    animation: true,
                    delay: { show: 200, hide: 100 }
                }); 
            });

            // Enhanced ripple effect
            $('.btn').on('click', function(e) {
                const $btn = $(this);
                const btnOffset = $btn.offset();
                const x = e.pageX - btnOffset.left;
                const y = e.pageY - btnOffset.top;
                
                const ripple = $('<span class="ripple"></span>');
                ripple.css({
                    top: y + 'px',
                    left: x + 'px'
                });
                
                $btn.append(ripple);
                
                setTimeout(function() { 
                    ripple.remove(); 
                }, 800);
            });
        });

        function showDeleteModal(examId, examName) {
            document.getElementById('deleteExamId').value = examId;
            document.getElementById('deleteExamName').textContent = examName;
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteExamModal'), {
                backdrop: 'static',
                keyboard: false
            });
            deleteModal.show();
        }
    </script>
</body>
</html>
