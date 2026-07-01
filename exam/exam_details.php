<?php
require_once '../db_connection.php';
session_start();
// Check user role
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$exam_id = isset($_GET['id']) ? $_GET['id'] : '';

// Handle export request
if (isset($_GET['export'])) {
    // Get exam details
    $stmt = $db->prepare("SELECT e.*, b.batch_name, u.name as created_by_name 
                         FROM exams e 
                         JOIN batches b ON e.batch_id = b.batch_id 
                         JOIN users u ON e.created_by = u.id 
                         WHERE e.exam_id = ?");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($exam) {
        // Get all students with results - FIX: use batch_name instead of batch_id
        $results = $db->prepare("SELECT 
            s.student_id, 
            s.first_name, 
            s.last_name,
            s.current_status,
            s.batch_name as current_batch,
            er.obtained_marks, 
            er.grade, 
            er.remarks, 
            er.mcq_marks, 
            er.project_marks, 
            er.viva_marks,
            CASE 
                WHEN er.student_id IS NOT NULL THEN 'Taken Exam'
                WHEN s.batch_name = ? THEN 'In Batch (Not Taken)'
                ELSE 'Transferred (Not Taken)'
            END as exam_status
        FROM students s 
        LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
        WHERE (s.batch_name = ? OR EXISTS (
            SELECT 1 FROM exam_results er2 
            WHERE er2.exam_id = ? AND er2.student_id = s.student_id
        ))
        ORDER BY s.first_name, s.last_name");
        $results->execute([$exam['batch_name'], $exam_id, $exam['batch_name'], $exam_id]);
        $results = $results->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="exam_results_' . $exam['exam_id'] . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Student ID', 'Student Name', 'Current Status', 'Current Batch', 'Exam Status', 'MCQ Marks', 'Project Marks', 'Viva Marks', 'Total Marks', 'Percentage', 'Grade', 'Result', 'Remarks']);
        
        foreach ($results as $result) {
            $has_result = !is_null($result['obtained_marks']);
            $percentage = $has_result ? ($result['obtained_marks'] / $exam['total_marks']) * 100 : 0;
            
            fputcsv($output, [
                $result['student_id'],
                $result['first_name'] . ' ' . $result['last_name'],
                $result['current_status'],
                $result['current_batch'],
                $result['exam_status'],
                $has_result ? $result['mcq_marks'] : 'Not Taken',
                $has_result ? $result['project_marks'] : 'Not Taken',
                $has_result ? $result['viva_marks'] : 'Not Taken',
                $has_result ? $result['obtained_marks'] . '/' . $exam['total_marks'] : 'Not Taken',
                $has_result ? number_format($percentage, 2) . '%' : 'Not Taken',
                $has_result ? $result['grade'] : 'Not Taken',
                $has_result ? ($result['obtained_marks'] >= $exam['passing_marks'] ? 'Passed' : 'Failed') : 'Not Taken',
                $has_result ? $result['remarks'] : ''
            ]);
        }
        fclose($output);
        exit();
    }
}

// Get exam details
$exam = null;
if (!empty($exam_id)) {
    $stmt = $db->prepare("SELECT e.*, b.batch_name, u.name as created_by_name 
                         FROM exams e 
                         JOIN batches b ON e.batch_id = b.batch_id 
                         JOIN users u ON e.created_by = u.id 
                         WHERE e.exam_id = ?");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$exam) {
    header("Location: exams.php");
    exit();
}

// Get all exams for the switcher dropdown (all exams, or filter by role as needed)
// For admin, show all; for teacher, show those created by them. Here we show all for simplicity.
$all_exams = [];
$stmt = $db->query("SELECT exam_id, exam_name, batch_name, exam_date 
                    FROM exams e 
                    JOIN batches b ON e.batch_id = b.batch_id 
                    ORDER BY exam_date DESC, exam_name");
$all_exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ALL students who were in the batch at the time of exam OR have results for this exam
// FIX: use batch_name instead of batch_id
$results = $db->prepare("SELECT 
    s.student_id, 
    s.first_name, 
    s.last_name,
    s.current_status,
    s.batch_name as current_batch,
    er.obtained_marks, 
    er.grade, 
    er.remarks, 
    er.mcq_marks, 
    er.project_marks, 
    er.viva_marks,
    CASE 
        WHEN er.student_id IS NOT NULL THEN 'Taken Exam'
        WHEN s.batch_name = ? THEN 'In Batch (Not Taken)'
        ELSE 'Transferred (Not Taken)'
    END as exam_status
FROM students s 
LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
WHERE (s.batch_name = ? OR EXISTS (
    SELECT 1 FROM exam_results er2 
    WHERE er2.exam_id = ? AND er2.student_id = s.student_id
))
ORDER BY s.first_name, s.last_name");
$results->execute([$exam['batch_name'], $exam_id, $exam['batch_name'], $exam_id]);
$results = $results->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics - include ALL students who were in the batch
$total_students = count($results);
$participated = 0;
$passed = 0;
$failed = 0;
$total_marks_obtained = 0;

// Calculate component-wise statistics
$mcq_total = 0;
$project_total = 0;
$viva_total = 0;
$mcq_count = 0;
$project_count = 0;
$viva_count = 0;

// Parse exam components
$exam_components = !empty($exam['exam_components']) ? explode(',', $exam['exam_components']) : [];
$has_mcq = in_array('mcq', $exam_components);
$has_project = in_array('project', $exam_components);
$has_viva = in_array('viva', $exam_components);

foreach ($results as $result) {
    if (!is_null($result['obtained_marks'])) {
        $participated++;
        $total_marks_obtained += $result['obtained_marks'];
        
        // Component-wise calculations
        if ($has_mcq && !is_null($result['mcq_marks'])) {
            $mcq_total += $result['mcq_marks'];
            $mcq_count++;
        }
        if ($has_project && !is_null($result['project_marks'])) {
            $project_total += $result['project_marks'];
            $project_count++;
        }
        if ($has_viva && !is_null($result['viva_marks'])) {
            $viva_total += $result['viva_marks'];
            $viva_count++;
        }
        
        if ($result['obtained_marks'] >= $exam['passing_marks']) {
            $passed++;
        } else {
            $failed++;
        }
    }
}

$average_marks = $participated > 0 ? $total_marks_obtained / $participated : 0;
$participation_rate = $total_students > 0 ? ($participated / $total_students) * 100 : 0;
$pass_percentage = $participated > 0 ? ($passed / $participated) * 100 : 0;

// Calculate component averages
$mcq_avg = $mcq_count > 0 ? $mcq_total / $mcq_count : 0;
$project_avg = $project_count > 0 ? $project_total / $project_count : 0;
$viva_avg = $viva_count > 0 ? $viva_total / $viva_count : 0;

// Handle edit marks request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_marks'])) {
    $student_id = $_POST['student_id'];
    $obtained_marks = $_POST['obtained_marks'];
    $remarks = $_POST['remarks'];
    
    // Get component marks
    $mcq_marks = isset($_POST['mcq_marks']) ? $_POST['mcq_marks'] : null;
    $project_marks = isset($_POST['project_marks']) ? $_POST['project_marks'] : null;
    $viva_marks = isset($_POST['viva_marks']) ? $_POST['viva_marks'] : null;
    
    // Calculate grade
    $grade = calculateGrade($obtained_marks, $exam['total_marks']);
    
    // Check if result already exists
    $check_stmt = $db->prepare("SELECT * FROM exam_results WHERE exam_id = ? AND student_id = ?");
    $check_stmt->execute([$exam_id, $student_id]);
    $existing_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_result) {
        // Update existing result
        $stmt = $db->prepare("UPDATE exam_results SET obtained_marks = ?, grade = ?, remarks = ?, uploaded_by = ?, mcq_marks = ?, project_marks = ?, viva_marks = ?, uploaded_at = NOW() WHERE exam_id = ? AND student_id = ?");
        $success = $stmt->execute([$obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks, $exam_id, $student_id]);
    } else {
        // Insert new result
        $stmt = $db->prepare("INSERT INTO exam_results (exam_id, student_id, obtained_marks, grade, remarks, uploaded_by, mcq_marks, project_marks, viva_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $success = $stmt->execute([$exam_id, $student_id, $obtained_marks, $grade, $remarks, $_SESSION['user_id'], $mcq_marks, $project_marks, $viva_marks]);
    }
    
    if ($success) {
        header("Location: exam_details.php?id=" . $exam_id . "&success=1");
        exit();
    } else {
        header("Location: exam_details.php?id=" . $exam_id . "&error=1");
        exit();
    }
}

function calculateGrade($obtained, $total) {
    if ($total == 0) return 'N/A';
    
    $percentage = ($obtained / $total) * 100;
    
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C';
    if ($percentage >= 40) return 'D';
    return 'F';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Details - ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
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

        /* Grade rows */
        .grade-A { border-left: 4px solid #2d7d46; }
        .grade-B { border-left: 4px solid var(--color-steel-mid); }
        .grade-C { border-left: 4px solid #b8860b; }
        .grade-D { border-left: 4px solid #cc7a2a; }
        .grade-F { border-left: 4px solid #b22234; }
        .not-taken-row { border-left: 4px solid #8a8a8a; opacity: 0.75; }

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
        textarea.form-control {
            height: auto;
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
        .badge.bg-light { background: var(--color-border) !important; color: var(--color-text-primary); }

        .badge-exam { background: linear-gradient(135deg, #1B3C53, #456882); color: #ffffff; }

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
           INFO CARDS
           ================================================================ */
        .info-card {
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 14px 16px;
            transition: var(--transition-smooth);
            box-shadow: var(--shadow-card);
            color: var(--color-text-primary);
            height: 100%;
        }
        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }
        .info-card h6 {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--color-text-muted);
            margin-bottom: 0.3rem;
            letter-spacing: 0.04em;
            font-weight: 700;
        }
        .info-card p {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--color-text-primary);
            margin-bottom: 0;
            word-break: break-word;
        }
        .info-card .text-muted {
            font-weight: 400;
        }

        /* ================================================================
           PERFORMANCE INDICATORS
           ================================================================ */
        .performance-indicator {
            display: flex;
            align-items: center;
            background: #ffffff;
            border: 1px solid var(--color-border);
            border-radius: 12px;
            padding: 14px 18px;
            transition: var(--transition-smooth);
            box-shadow: var(--shadow-card);
            color: var(--color-text-primary);
        }
        .performance-indicator:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
        }
        .performance-indicator .icon {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 14px;
            font-size: 18px;
            flex-shrink: 0;
        }
        .performance-indicator .icon.bg-info { background: var(--color-steel-mid); color: #ffffff; }
        .performance-indicator .icon.bg-success { background: var(--color-steel-dark); color: #ffffff; }
        .performance-indicator .icon.bg-warning { background: #f59e0b; color: #ffffff; }
        .performance-indicator .content {
            flex: 1;
            min-width: 0;
        }
        .performance-indicator .value {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--color-text-primary);
        }
        .performance-indicator .label {
            font-size: 0.75rem;
            color: var(--color-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 600;
        }
        .performance-indicator .text-info { color: var(--color-steel-mid) !important; }
        .performance-indicator .text-success { color: var(--color-steel-dark) !important; }
        .performance-indicator .text-warning { color: #C97B50 !important; }

        /* ================================================================
           EXAM SWITCHER
           ================================================================ */
        .exam-switcher {
            min-width: 160px;
            background: #ffffff;
            border: 2px solid var(--color-border);
            border-radius: 10px;
            color: var(--color-text-primary);
            padding: 8px 14px;
            font-size: 0.85rem;
            transition: var(--transition-smooth);
            cursor: pointer;
            appearance: auto;
            height: 44px;
        }
        .exam-switcher:focus {
            border-color: var(--color-steel-mid);
            box-shadow: 0 0 0 3px rgba(69,104,130,0.15);
            outline: none;
        }
        .exam-switcher option {
            background: #ffffff;
            color: var(--color-text-primary);
        }

        /* ================================================================
           SEARCH BOX
           ================================================================ */
        .search-box {
            background: #ffffff;
            border: 2px solid var(--color-border);
            border-radius: 10px;
            padding: 0 8px;
            transition: var(--transition-smooth);
            height: 44px;
            display: flex;
            align-items: center;
            min-width: 180px;
        }
        .search-box:focus-within {
            border-color: var(--color-steel-mid);
            box-shadow: 0 0 0 4px rgba(69,104,130,0.08);
            transform: translateY(-1px);
        }
        .search-box .input-group-text {
            background: transparent !important;
            border: none !important;
            color: var(--color-text-muted);
            padding: 0 0.5rem 0 0;
        }
        .search-box .form-control {
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            color: var(--color-text-primary);
            height: 38px;
            padding: 0 0.5rem;
        }
        .search-box .btn-outline-secondary {
            border: none;
            color: var(--color-text-muted);
            height: 34px;
            padding: 0 0.5rem;
            background: transparent;
        }
        .search-box .btn-outline-secondary:hover {
            background: var(--color-border);
            color: var(--color-text-primary);
        }

        /* ================================================================
           CHART CONTAINER
           ================================================================ */
        .chart-container {
            position: relative;
            height: 250px;
            margin-top: 8px;
            background: #ffffff;
            border-radius: 12px;
            padding: 12px;
        }

        /* ================================================================
           COMPONENT SECTION
           ================================================================ */
        .component-section {
            background: rgba(27,60,83,0.02);
            border: 2px dashed var(--color-border);
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 16px;
            transition: var(--transition-smooth);
        }
        .component-section:hover {
            border-color: var(--color-steel-mid);
            background: rgba(27,60,83,0.03);
        }

        /* ================================================================
           NO DATA
           ================================================================ */
        .no-data {
            text-align: center;
            padding: 40px 20px;
            background: #ffffff;
            border-radius: 14px;
            border: 2px dashed var(--color-border);
            color: var(--color-text-primary);
        }
        .no-data-icon {
            font-size: 3rem;
            margin-bottom: 12px;
            color: var(--color-text-muted);
            opacity: 0.5;
        }
        .no-data h4 {
            color: var(--color-text-primary);
            font-weight: 700;
        }

        /* ================================================================
           SORTABLE
           ================================================================ */
        .sortable {
            cursor: pointer;
            position: relative;
            padding-right: 20px;
            user-select: none;
        }
        .sortable::after {
            content: '↕';
            position: absolute;
            right: 6px;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.4;
            font-size: 11px;
        }
        .sortable.asc::after { content: '↑'; opacity: 1; }
        .sortable.desc::after { content: '↓'; opacity: 1; }

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
           EDIT BUTTON
           ================================================================ */
        .edit-btn {
            background: rgba(27,60,83,0.06);
            border: 2px solid var(--color-border);
            color: var(--color-navy);
            border-radius: 8px;
            padding: 4px 12px;
            font-size: 0.8rem;
            font-weight: 600;
            transition: var(--transition-smooth);
        }
        .edit-btn:hover {
            background: var(--color-navy);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 4px 14px rgba(27,60,83,0.25);
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
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            color: #ffffff;
            border-bottom: none;
            border-radius: var(--radius-card) var(--radius-card) 0 0;
            padding: 1.25rem 1.5rem;
        }
        .modal-title {
            color: #ffffff;
            font-weight: 600;
        }
        .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }
        .modal-footer {
            border-top: 1px solid var(--color-border);
            padding: 1rem 1.5rem;
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

        /* ================================================================
           UTILITIES
           ================================================================ */
        .gradient-text {
            background: linear-gradient(135deg, #1B3C53, #234C6A, #456882);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 800;
        }
        .text-muted { color: var(--color-text-muted) !important; }
        .fw-semibold { font-weight: 600; }
        .fw-bold { font-weight: 700; }
        .text-xs { font-size: 0.75rem; }

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
            .search-box {
                min-width: 120px;
                width: 100%;
            }
            .exam-switcher {
                min-width: 120px;
                width: 100%;
            }
            .card-header .d-flex {
                flex-direction: column;
                align-items: stretch !important;
                gap: 8px;
            }
            .card-header .d-flex .btn-group {
                flex-wrap: wrap;
            }
            .card-header .d-flex .btn-group .btn {
                flex: 1;
                min-width: 60px;
                font-size: 0.7rem;
                padding: 6px 10px;
            }
            .chart-container {
                height: 180px;
            }
            .performance-indicator {
                padding: 12px 14px;
            }
            .performance-indicator .icon {
                width: 38px;
                height: 38px;
                font-size: 14px;
                margin-right: 10px;
            }
            .performance-indicator .value {
                font-size: 1.2rem;
            }
            .floating-btn {
                width: 48px;
                height: 48px;
                bottom: 16px;
                right: 16px;
                font-size: 0.9rem;
            }
            .modal-dialog {
                margin: 8px;
            }
            .container-fluid {
                padding: 0 8px;
            }
            .page-header .row {
                flex-direction: column;
                align-items: stretch !important;
            }
            .page-header .col-auto {
                width: 100%;
            }
            .page-header .col-auto .d-flex {
                flex-direction: column;
                width: 100%;
            }
            .page-header .col-auto .d-flex .exam-switcher {
                width: 100%;
            }
            .page-header .col-auto .d-flex .btn {
                width: 100%;
            }
        }

        @media (min-width: 768px) and (max-width: 1024px) {
            .main-content {
                padding: 20px;
            }
            .stat-card {
                padding: 1.25rem 1.5rem;
            }
            .stat-card .stat-number {
                font-size: 1.8rem;
            }
        }

        /* ================================================================
           PRINT STYLES
           ================================================================ */
        @media print {
            .floating-btn, .btn-group, .search-box, .alert, .modal, .exam-switcher { display: none !important; }
            body { background: #ffffff; color: #000000; }
            .main-content { margin: 0; padding: 16px; }
            .card { background: #ffffff; box-shadow: none !important; border: 1px solid #ddd; color: #000000; }
            .card::after { display: none; }
            .table tbody tr { background: #ffffff; box-shadow: none !important; border: 1px solid #ddd; color: #000000; }
            .table thead th { background: #f8f9fa; color: #000000; }
            .stat-card { background: #ffffff; border: 1px solid #ddd; box-shadow: none !important; }
            .info-card { background: #ffffff; border: 1px solid #ddd; box-shadow: none !important; }
            .gradient-text { -webkit-text-fill-color: #1B3C53; color: #1B3C53; }
            .stat-number { color: #1B3C53; }
            .hero-banner { background: #1B3C53 !important; color: #ffffff !important; }
            .hero-banner .text-muted { color: rgba(255,255,255,0.8) !important; }
            .performance-indicator { background: #ffffff; border: 1px solid #ddd; box-shadow: none !important; }
            .chart-container { background: #ffffff; border: 1px solid #ddd; }
            .no-data { background: #ffffff; border: 1px solid #ddd; }
        }
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
            <span>Exam Details</span>
        </nav>

        <!-- Hero Banner -->
        <div class="hero-banner">
            <div class="row align-items-center">
                <div class="col">
                    <h1 class="h2 mb-1" style="color: #ffffff; font-weight: 800;">
                        <i class="fas fa-info-circle me-3" style="color: rgba(255,255,255,0.6);"></i>Exam Details
                    </h1>
                    <div class="d-flex flex-wrap align-items-center gap-3" style="color: rgba(255,255,255,0.85);">
                        <span class="fw-semibold" style="font-size: 1.1rem;"><?php echo htmlspecialchars($exam['exam_name']); ?></span>
                        <span style="color: rgba(255,255,255,0.3);">•</span>
                        <span><?php echo htmlspecialchars($exam['batch_name']); ?></span>
                        <span style="color: rgba(255,255,255,0.3);">•</span>
                        <span><?php echo date('d M Y', strtotime($exam['exam_date'])); ?></span>
                    </div>
                </div>
                <div class="col-auto">
                    <div class="d-flex gap-2 flex-wrap">
                        <select class="exam-switcher" id="examSwitcher" onchange="window.location.href='?id='+this.value" style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.2); color: #ffffff;">
                            <option value="" style="color: #1B3C53;">Switch Exam...</option>
                            <?php foreach ($all_exams as $e): ?>
                                <option value="<?php echo $e['exam_id']; ?>" <?php echo ($e['exam_id'] == $exam_id) ? 'selected' : ''; ?> style="color: #1B3C53;">
                                    <?php echo htmlspecialchars($e['exam_name'] . ' (' . $e['batch_name'] . ' - ' . date('d M Y', strtotime($e['exam_date'])) . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <a href="exams.php" class="btn btn-outline-light">
                            <i class="fas fa-arrow-left me-2"></i> Back to Exams
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeInDown" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-check-circle fa-lg me-3"></i>
                    <div class="fw-semibold">Marks updated successfully!</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-circle fa-lg me-3"></i>
                    <div class="fw-semibold">Failed to update marks. Please try again.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Exam Information Card -->
        <div class="card animate__animated animate__fadeInUp">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <h5 class="mb-0 gradient-text">
                    <i class="fas fa-info-circle me-2"></i>Exam Information
                </h5>
                <div class="d-flex gap-2 flex-wrap mt-2 mt-sm-0">
                    <a href="upload_results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-success btn-sm">
                        <i class="fas fa-upload me-1"></i> Upload Results
                    </a>
                    <a href="edit_exam.php?id=<?php echo $exam_id; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit me-1"></i> Edit Exam
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-id-card me-2"></i>Exam ID</h6>
                            <p><?php echo htmlspecialchars($exam['exam_id']); ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-book me-2"></i>Exam Name</h6>
                            <p><?php echo htmlspecialchars($exam['exam_name']); ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-users me-2"></i>Batch</h6>
                            <p><?php echo htmlspecialchars($exam['batch_name']); ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-book-open me-2"></i>Subject</h6>
                            <p><?php echo htmlspecialchars($exam['subject']); ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-calendar me-2"></i>Date</h6>
                            <p><?php echo date('d M Y', strtotime($exam['exam_date'])); ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-chart-bar me-2"></i>Total Marks</h6>
                            <p><?php echo $exam['total_marks']; ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-trophy me-2"></i>Passing Marks</h6>
                            <p><?php echo $exam['passing_marks']; ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-tasks me-2"></i>Type</h6>
                            <p><?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?></p>
                        </div>
                    </div>
                    
                    <?php if (!empty($exam_components)): ?>
                    <div class="col-12 mt-2">
                        <h6 class="component-label gradient-text mb-3">
                            <i class="fas fa-puzzle-piece me-2"></i>Exam Components
                        </h6>
                        <div class="row">
                            <?php if ($has_mcq): ?>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="info-card">
                                    <h6><i class="fas fa-list-ol me-2"></i>MCQ Marks</h6>
                                    <p><?php echo $exam['mcq_marks']; ?> marks</p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($has_project): ?>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="info-card">
                                    <h6><i class="fas fa-project-diagram me-2"></i>Project Marks</h6>
                                    <p><?php echo $exam['project_marks']; ?> marks</p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($has_viva): ?>
                            <div class="col-6 col-md-3 mb-3">
                                <div class="info-card">
                                    <h6><i class="fas fa-microphone me-2"></i>Viva Marks</h6>
                                    <p><?php echo $exam['viva_marks']; ?> marks</p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-12 col-md-6 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-align-left me-2"></i>Description</h6>
                            <p class="mb-0 text-muted" style="font-weight:400;font-size:0.95rem;"><?php echo htmlspecialchars($exam['description']); ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-user me-2"></i>Created By</h6>
                            <p class="mb-0 text-muted" style="font-weight:400;font-size:0.95rem;"><?php echo htmlspecialchars($exam['created_by_name']); ?></p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="info-card">
                            <h6><i class="fas fa-clock me-2"></i>Created At</h6>
                            <p class="mb-0 text-muted" style="font-weight:400;font-size:0.95rem;"><?php echo date('d M Y H:i', strtotime($exam['created_at'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="top-stripe blue"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label mb-1">Total Students</div>
                            <div class="stat-number"><?php echo $total_students; ?></div>
                            <div class="progress"><div class="progress-bar" style="width: <?php echo ($total_students > 0) ? 100 : 0; ?>%"></div></div>
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
                            <div class="stat-label mb-1">Participated</div>
                            <div class="stat-number"><?php echo $participated; ?></div>
                            <div class="progress"><div class="progress-bar" style="width: <?php echo $participation_rate; ?>%"></div></div>
                            <small class="text-muted"><?php echo number_format($participation_rate, 1); ?>%</small>
                        </div>
                        <div class="icon-box green">
                            <i class="fas fa-user-check fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="top-stripe amber"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label mb-1">Passed</div>
                            <div class="stat-number"><?php echo $passed; ?></div>
                            <div class="progress"><div class="progress-bar" style="width: <?php echo $pass_percentage; ?>%"></div></div>
                            <small class="text-muted"><?php echo number_format($pass_percentage, 1); ?>% of participants</small>
                        </div>
                        <div class="icon-box amber">
                            <i class="fas fa-trophy fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="stat-card">
                    <div class="top-stripe red"></div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-label mb-1">Absent</div>
                            <div class="stat-number"><?php echo $total_students - $participated; ?></div>
                            <div class="progress"><div class="progress-bar" style="width: <?php echo ($total_students > 0) ? (($total_students - $participated) / $total_students) * 100 : 0; ?>%"></div></div>
                            <small class="text-muted"><?php echo ($total_students > 0) ? number_format((($total_students - $participated) / $total_students) * 100, 1) : 0; ?>%</small>
                        </div>
                        <div class="icon-box steel">
                            <i class="fas fa-user-slash fa-lg"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Component-wise Average -->
        <?php if (!empty($exam_components) && $participated > 0): ?>
        <div class="card mb-4 animate__animated animate__fadeInUp">
            <div class="card-header">
                <h5 class="mb-0 gradient-text">
                    <i class="fas fa-chart-pie me-2"></i>Component-wise Average Marks
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php if ($has_mcq): ?>
                    <div class="col-12 col-md-4 mb-3">
                        <div class="performance-indicator">
                            <div class="icon bg-info text-white"><i class="fas fa-list-ol"></i></div>
                            <div class="content">
                                <div class="value text-info"><?php echo number_format($mcq_avg, 2); ?></div>
                                <div class="label">MCQ Average</div>
                                <small class="text-muted">Out of <?php echo $exam['mcq_marks']; ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($has_project): ?>
                    <div class="col-12 col-md-4 mb-3">
                        <div class="performance-indicator">
                            <div class="icon bg-success text-white"><i class="fas fa-project-diagram"></i></div>
                            <div class="content">
                                <div class="value text-success"><?php echo number_format($project_avg, 2); ?></div>
                                <div class="label">Project Average</div>
                                <small class="text-muted">Out of <?php echo $exam['project_marks']; ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($has_viva): ?>
                    <div class="col-12 col-md-4 mb-3">
                        <div class="performance-indicator">
                            <div class="icon bg-warning text-white"><i class="fas fa-microphone"></i></div>
                            <div class="content">
                                <div class="value text-warning"><?php echo number_format($viva_avg, 2); ?></div>
                                <div class="label">Viva Average</div>
                                <small class="text-muted">Out of <?php echo $exam['viva_marks']; ?></small>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Charts Section -->
        <?php if ($total_students > 0 && $participated > 0): ?>
        <div class="row mb-4 animate__animated animate__fadeInUp">
            <div class="col-12 col-md-6 mb-3 mb-md-0">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 gradient-text">
                            <i class="fas fa-chart-pie me-2"></i>Performance Overview
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0 gradient-text">
                            <i class="fas fa-chart-bar me-2"></i>Marks Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="marksChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($total_students > 0 && $participated == 0): ?>
        <div class="alert alert-info text-center animate__animated animate__fadeInUp">
            <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
            <h5>No results uploaded yet</h5>
            <p class="mb-0">Students are enrolled in this batch, but no exam results have been uploaded. Once results are added, performance charts will appear here.</p>
        </div>
        <?php endif; ?>

        <!-- Results Table -->
        <div class="card animate__animated animate__fadeInUp" id="resultsCard">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <h5 class="mb-0 gradient-text">
                    <i class="fas fa-list-alt me-2"></i>Exam Results
                    <span class="badge badge-exam ms-2"><?php echo $total_students; ?> Students</span>
                    <span class="badge bg-secondary ms-2"><?php echo $total_students - $participated; ?> Absent</span>
                </h5>
                <div class="d-flex align-items-center gap-3 flex-wrap mt-2 mt-md-0">
                    <div class="search-box">
                        <div class="input-group">
                            <span class="input-group-text border-0 bg-transparent">
                                <i class="fas fa-search text-muted"></i>
                            </span>
                            <input type="text" id="searchInput" class="form-control border-0 bg-transparent shadow-none" 
                                   placeholder="Search students...">
                            <button class="btn btn-outline-secondary border-0" type="button" id="clearSearch">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="btn-group" role="group">
                        <a href="?id=<?php echo $exam_id; ?>&export=1" class="btn btn-outline-primary" id="exportBtn">
                            <i class="fas fa-download me-1"></i> CSV
                        </a>
                        <button type="button" class="btn btn-outline-primary" id="pdfExportBtn">
                            <i class="fas fa-file-pdf me-1"></i> PDF
                        </button>
                        <button type="button" class="btn btn-outline-primary" id="printBtn">
                            <i class="fas fa-print me-1"></i> Print
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (count($results) > 0): ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0" id="resultsTable" style="table-layout: auto; width: 100%; min-width: 600px;">
                            <thead>
                                <tr>
                                    <th class="sortable" data-sort="student_id">Student ID</th>
                                    <th class="sortable" data-sort="student_name">Student Name</th>
                                    <th class="sortable" data-sort="current_status">Status</th>
                                    <th class="sortable" data-sort="current_batch">Current Batch</th>
                                    <?php if ($has_mcq): ?><th class="sortable" data-sort="mcq_marks">MCQ</th><?php endif; ?>
                                    <?php if ($has_project): ?><th class="sortable" data-sort="project_marks">Project</th><?php endif; ?>
                                    <?php if ($has_viva): ?><th class="sortable" data-sort="viva_marks">Viva</th><?php endif; ?>
                                    <th class="sortable" data-sort="obtained_marks">Total</th>
                                    <th class="sortable" data-sort="percentage">%</th>
                                    <th class="sortable" data-sort="grade">Grade</th>
                                    <th class="sortable" data-sort="status">Result</th>
                                    <th>Remarks</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $component_cols = 0;
                                if ($has_mcq) $component_cols++;
                                if ($has_project) $component_cols++;
                                if ($has_viva) $component_cols++;
                                $name_cols = 4;
                                ?>
                                <?php foreach ($results as $result): ?>
                                    <?php
                                    $has_result = !is_null($result['obtained_marks']);
                                    $percentage = $has_result ? ($result['obtained_marks'] / $exam['total_marks']) * 100 : 0;
                                    $is_transferred = $result['current_batch'] != $exam['batch_name'];
                                    $row_class = '';
                                    if ($has_result) {
                                        if ($percentage >= 80) $row_class = 'grade-A';
                                        elseif ($percentage >= 60) $row_class = 'grade-B';
                                        elseif ($percentage >= 40) $row_class = 'grade-C';
                                        elseif ($percentage >= 30) $row_class = 'grade-D';
                                        else $row_class = 'grade-F';
                                    } else {
                                        $row_class = 'not-taken-row';
                                    }
                                    if ($is_transferred && $has_result) {
                                        $row_class .= ' transferred-student';
                                    }
                                    ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($result['student_id']); ?></span></td>
                                        <td class="fw-medium text-wrap" style="min-width: 100px;"><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $result['current_status'] == 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo ucfirst($result['current_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($result['current_batch']); ?>
                                            <?php if ($is_transferred): ?>
                                                <span class="badge bg-warning ms-1" title="Transferred from original batch"><i class="fas fa-exchange-alt"></i></span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($has_mcq): ?>
                                        <td><?php echo $has_result && !is_null($result['mcq_marks']) ? $result['mcq_marks'] : '<span class="text-muted">-</span>'; ?></td>
                                        <?php endif; ?>
                                        <?php if ($has_project): ?>
                                        <td><?php echo $has_result && !is_null($result['project_marks']) ? $result['project_marks'] : '<span class="text-muted">-</span>'; ?></td>
                                        <?php endif; ?>
                                        <?php if ($has_viva): ?>
                                        <td><?php echo $has_result && !is_null($result['viva_marks']) ? $result['viva_marks'] : '<span class="text-muted">-</span>'; ?></td>
                                        <?php endif; ?>
                                        <td class="text-nowrap">
                                            <?php if ($has_result): ?>
                                                <strong class="text-primary"><?php echo $result['obtained_marks']; ?></strong><span class="text-muted small">/<?php echo $exam['total_marks']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small"><i class="fas fa-user-slash me-1"></i> Not Taken</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $has_result ? number_format($percentage, 2) . '%' : '<span class="text-muted">-</span>'; ?></td>
                                        <td>
                                            <?php if ($has_result): ?>
                                                <?php
                                                $grade_class = '';
                                                if (in_array($result['grade'], ['A+', 'A'])) $grade_class = 'bg-success';
                                                elseif (in_array($result['grade'], ['B+', 'B'])) $grade_class = 'bg-info';
                                                elseif (in_array($result['grade'], ['C', 'D'])) $grade_class = 'bg-warning';
                                                else $grade_class = 'bg-danger';
                                                ?>
                                                <span class="badge <?php echo $grade_class; ?>"><?php echo $result['grade']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($has_result): ?>
                                                <?php if ($result['obtained_marks'] >= $exam['passing_marks']): ?>
                                                    <span class="badge bg-success">Passed</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Failed</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Absent</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small text-wrap text-muted" style="max-width: 120px;">
                                            <?php echo $has_result ? htmlspecialchars($result['remarks']) : '-'; ?>
                                        </td>
                                        <td>
                                            <button class="btn edit-btn pulse" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#editMarksModal"
                                                    data-student-id="<?php echo $result['student_id']; ?>"
                                                    data-student-name="<?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?>"
                                                    data-obtained-marks="<?php echo $has_result ? $result['obtained_marks'] : ''; ?>"
                                                    data-mcq-marks="<?php echo $has_result ? $result['mcq_marks'] : ''; ?>"
                                                    data-project-marks="<?php echo $has_result ? $result['project_marks'] : ''; ?>"
                                                    data-viva-marks="<?php echo $has_result ? $result['viva_marks'] : ''; ?>"
                                                    data-remarks="<?php echo $has_result ? htmlspecialchars($result['remarks']) : ''; ?>">
                                                <i class="fas fa-edit me-1"></i> Edit
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <div class="no-data-icon"><i class="fas fa-users"></i></div>
                        <h4 class="mb-3">No Students Found</h4>
                        <p class="mb-4">There are no students enrolled in this batch.</p>
                        <a href="upload_results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-primary">
                            <i class="fas fa-upload me-2"></i> Upload Results
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Edit Marks Modal -->
    <div class="modal fade" id="editMarksModal" tabindex="-1" aria-labelledby="editMarksModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editMarksModalLabel">
                        <i class="fas fa-edit me-2"></i>Edit Exam Marks
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="edit_marks" value="1">
                        <input type="hidden" name="student_id" id="modalStudentId">
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Student</label>
                            <input type="text" class="form-control" id="modalStudentName" readonly>
                        </div>
                        
                        <?php if (!empty($exam_components)): ?>
                        <div class="component-section">
                            <h6 class="mb-3 gradient-text">
                                <i class="fas fa-puzzle-piece me-2"></i>Component Marks
                            </h6>
                            <div class="row g-3">
                                <?php if ($has_mcq): ?>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">MCQ Marks</label>
                                    <input type="number" class="form-control" name="mcq_marks" id="modalMcqMarks" 
                                           min="0" max="<?php echo $exam['mcq_marks']; ?>" step="0.01">
                                    <div class="form-text text-muted">Out of <?php echo $exam['mcq_marks']; ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($has_project): ?>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Project Marks</label>
                                    <input type="number" class="form-control" name="project_marks" id="modalProjectMarks" 
                                           min="0" max="<?php echo $exam['project_marks']; ?>" step="0.01">
                                    <div class="form-text text-muted">Out of <?php echo $exam['project_marks']; ?></div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($has_viva): ?>
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-semibold">Viva Marks</label>
                                    <input type="number" class="form-control" name="viva_marks" id="modalVivaMarks" 
                                           min="0" max="<?php echo $exam['viva_marks']; ?>" step="0.01">
                                    <div class="form-text text-muted">Out of <?php echo $exam['viva_marks']; ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Total Obtained Marks</label>
                            <input type="number" class="form-control" name="obtained_marks" id="modalObtainedMarks" 
                                   required min="0" max="<?php echo $exam['total_marks']; ?>" step="0.01">
                            <div class="form-text text-muted">Out of <?php echo $exam['total_marks']; ?> (Passing: <?php echo $exam['passing_marks']; ?>)</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Remarks</label>
                            <textarea class="form-control" name="remarks" id="modalRemarks" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-0">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-check-circle me-2"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Floating Action Button -->
    <button class="btn btn-primary btn-lg rounded-circle floating-btn" data-bs-toggle="modal" data-bs-target="#editMarksModal">
        <i class="fas fa-plus"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            <?php if ($total_students > 0 && $participated > 0): ?>
            // Performance Chart
            const performanceCtx = document.getElementById('performanceChart').getContext('2d');
            window.performanceChart = new Chart(performanceCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Passed', 'Failed', 'Absent'],
                    datasets: [{
                        data: [<?php echo $passed; ?>, <?php echo $failed; ?>, <?php echo $total_students - $participated; ?>],
                        backgroundColor: ['rgba(45, 125, 70, 0.8)', 'rgba(178, 34, 52, 0.8)', 'rgba(138, 138, 138, 0.7)'],
                        borderColor: ['#2d7d46', '#b22234', '#8a8a8a'],
                        borderWidth: 2,
                        hoverOffset: 12
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { 
                                color: '#1a1a2e', 
                                font: { family: 'Inter', size: 12 },
                                padding: 16,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        }
                    },
                    cutout: '65%'
                }
            });
            
            // Marks Distribution Chart
            const marksCtx = document.getElementById('marksChart').getContext('2d');
            window.marksChart = new Chart(marksCtx, {
                type: 'bar',
                data: {
                    labels: ['0-20%', '21-40%', '41-60%', '61-80%', '81-100%'],
                    datasets: [{
                        label: 'Number of Students',
                        data: [
                            <?php
                            $ranges = [0, 0, 0, 0, 0];
                            foreach ($results as $result) {
                                if (!is_null($result['obtained_marks'])) {
                                    $percentage = ($result['obtained_marks'] / $exam['total_marks']) * 100;
                                    if ($percentage <= 20) $ranges[0]++;
                                    elseif ($percentage <= 40) $ranges[1]++;
                                    elseif ($percentage <= 60) $ranges[2]++;
                                    elseif ($percentage <= 80) $ranges[3]++;
                                    else $ranges[4]++;
                                }
                            }
                            echo implode(', ', $ranges);
                            ?>
                        ],
                        backgroundColor: [
                            'rgba(27, 60, 83, 0.7)',
                            'rgba(35, 76, 106, 0.7)',
                            'rgba(69, 104, 130, 0.7)',
                            'rgba(27, 60, 83, 0.85)',
                            'rgba(45, 125, 70, 0.8)'
                        ],
                        borderColor: ['#1B3C53', '#234C6A', '#456882', '#1B3C53', '#2d7d46'],
                        borderWidth: 2,
                        borderRadius: 6,
                        barPercentage: 0.7
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { 
                                stepSize: 1, 
                                font: { family: 'Inter', size: 11 }, 
                                color: '#1a1a2e' 
                            },
                            grid: { color: 'rgba(0, 0, 0, 0.06)' }
                        },
                        x: {
                            ticks: { 
                                font: { family: 'Inter', size: 10 }, 
                                color: '#1a1a2e' 
                            },
                            grid: { display: false }
                        }
                    },
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(255,255,255,0.95)',
                            titleColor: '#1a1a2e',
                            bodyColor: '#1a1a2e',
                            borderColor: 'rgba(27, 60, 83, 0.2)',
                            borderWidth: 1,
                            cornerRadius: 10,
                            padding: 12
                        }
                    }
                }
            });
            <?php endif; ?>

            // Table Sorting
            let currentSort = { column: null, direction: 'asc' };
            let componentCols = <?php echo $component_cols; ?>;
            let nameCols = <?php echo $name_cols; ?>;
            
            $('.sortable').click(function() {
                const column = $(this).data('sort');
                const tbody = $('#resultsTable tbody');
                const rows = tbody.find('tr').toArray();
                
                $('.sortable').removeClass('asc desc');
                
                if (currentSort.column === column) {
                    currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort.column = column;
                    currentSort.direction = 'asc';
                }
                
                $(this).addClass(currentSort.direction);
                
                rows.sort((a, b) => {
                    let aValue, bValue;
                    switch(column) {
                        case 'student_id':
                            aValue = $(a).find('td').eq(0).text(); bValue = $(b).find('td').eq(0).text(); break;
                        case 'student_name':
                            aValue = $(a).find('td').eq(1).text().toLowerCase(); bValue = $(b).find('td').eq(1).text().toLowerCase(); break;
                        case 'current_status':
                            aValue = $(a).find('td').eq(2).text().toLowerCase(); bValue = $(b).find('td').eq(2).text().toLowerCase(); break;
                        case 'current_batch':
                            aValue = $(a).find('td').eq(3).text().toLowerCase(); bValue = $(b).find('td').eq(3).text().toLowerCase(); break;
                        case 'mcq_marks':
                            aValue = parseFloat($(a).find('td').eq(4)?.text()) || 0; bValue = parseFloat($(b).find('td').eq(4)?.text()) || 0; break;
                        case 'project_marks':
                            let pCol = <?php echo $has_mcq ? 5 : 4; ?>;
                            aValue = parseFloat($(a).find('td').eq(pCol)?.text()) || 0; bValue = parseFloat($(b).find('td').eq(pCol)?.text()) || 0; break;
                        case 'viva_marks':
                            let vCol = <?php echo ($has_mcq ? 1 : 0) + ($has_project ? 1 : 0) + 4; ?>;
                            aValue = parseFloat($(a).find('td').eq(vCol)?.text()) || 0; bValue = parseFloat($(b).find('td').eq(vCol)?.text()) || 0; break;
                        case 'obtained_marks':
                            aValue = parseFloat($(a).find('td').eq(nameCols + componentCols).text().split('/')[0]) || 0;
                            bValue = parseFloat($(b).find('td').eq(nameCols + componentCols).text().split('/')[0]) || 0; break;
                        case 'percentage':
                            aValue = parseFloat($(a).find('td').eq(nameCols + componentCols + 1).text()) || 0;
                            bValue = parseFloat($(b).find('td').eq(nameCols + componentCols + 1).text()) || 0; break;
                        case 'grade':
                            aValue = $(a).find('td').eq(nameCols + componentCols + 2).text() || '';
                            bValue = $(b).find('td').eq(nameCols + componentCols + 2).text() || ''; break;
                        case 'status':
                            aValue = $(a).find('td').eq(nameCols + componentCols + 3).text() || '';
                            bValue = $(b).find('td').eq(nameCols + componentCols + 3).text() || ''; break;
                        default:
                            aValue = $(a).find('td').eq(1)?.text() || ''; bValue = $(b).find('td').eq(1)?.text() || '';
                    }
                    if (currentSort.direction === 'asc') return aValue > bValue ? 1 : -1;
                    else return aValue < bValue ? 1 : -1;
                });
                
                rows.forEach((row, index) => {
                    $(row).css('animation-delay', (index * 0.02) + 's').addClass('animate__animated animate__fadeIn');
                    tbody.append(row);
                });
            });
            
            // Search Functionality
            $('#searchInput').on('input', function() {
                const searchTerm = this.value.toLowerCase();
                $('#resultsTable tbody tr').each(function() {
                    $(this).toggle($(this).text().toLowerCase().includes(searchTerm));
                });
            });
            
            $('#clearSearch').click(function() {
                $('#searchInput').val('');
                $('#resultsTable tbody tr').show();
            });
            
            // Modal population
            $('#editMarksModal').on('show.bs.modal', function(event) {
                const button = $(event.relatedTarget);
                $('#modalStudentId').val(button.data('student-id'));
                $('#modalStudentName').val(button.data('student-name'));
                $('#modalObtainedMarks').val(button.data('obtained-marks'));
                $('#modalMcqMarks').val(button.data('mcq-marks'));
                $('#modalProjectMarks').val(button.data('project-marks'));
                $('#modalVivaMarks').val(button.data('viva-marks'));
                $('#modalRemarks').val(button.data('remarks'));
            });
            
            // PDF Export - Fixed layout with proper spacing
            $('#pdfExportBtn').click(function() {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF('landscape', 'mm', 'a4');
                
                // Try to load logo
                const logoPath = '../assets/images/logo.png';
                const img = new Image();
                img.crossOrigin = "Anonymous";
                img.src = logoPath;
                img.onload = function() { generatePDFWithLogo(img); };
                img.onerror = function() { generatePDFWithLogo(null); };
                
                function generatePDFWithLogo(logoImg) {
                    let yPos = 25;
                    let leftMargin = 14;
                    
                    // Logo and header
                    if (logoImg) {
                        try {
                            doc.addImage(logoImg, 'PNG', leftMargin, 10, 22, 22);
                        } catch(e) {
                            // If logo fails, just skip
                        }
                        doc.setFontSize(18); 
                        doc.setFont('helvetica', 'bold'); 
                        doc.setTextColor(27, 60, 83);
                        doc.text('ASD Academy', leftMargin + 28, 20);
                        doc.setTextColor(0, 0, 0);
                        yPos = 35;
                    } else {
                        doc.setFontSize(18); 
                        doc.setFont('helvetica', 'bold');
                        doc.text('ASD Academy', leftMargin, 20);
                        yPos = 30;
                    }
                    
                    // Exam title
                    doc.setFontSize(14); 
                    doc.setFont('helvetica', 'bold');
                    doc.text('Exam Results Report', leftMargin, yPos);
                    yPos += 8;
                    
                    // Exam details
                    doc.setFontSize(10); 
                    doc.setFont('helvetica', 'normal');
                    doc.text(`Exam: <?php echo addslashes($exam['exam_name']); ?>`, leftMargin, yPos);
                    yPos += 6;
                    doc.text(`Batch: <?php echo addslashes($exam['batch_name']); ?>`, leftMargin, yPos);
                    yPos += 6;
                    doc.text(`Date: <?php echo date('d M Y', strtotime($exam['exam_date'])); ?>`, leftMargin, yPos);
                    yPos += 6;
                    doc.text(`Total/Passing Marks: <?php echo $exam['total_marks']; ?> / <?php echo $exam['passing_marks']; ?>`, leftMargin, yPos);
                    yPos += 6;
                    doc.text(`Total Students: <?php echo $total_students; ?> | Participated: <?php echo $participated; ?> | Passed: <?php echo $passed; ?> | Failed: <?php echo $failed; ?>`, leftMargin, yPos);
                    yPos += 10;
                    
                    // Table headers
                    const tableHeaders = ['ID', 'Name', 'Status', 'Batch'];
                    <?php if ($has_mcq): ?> tableHeaders.push('MCQ'); <?php endif; ?>
                    <?php if ($has_project): ?> tableHeaders.push('Proj'); <?php endif; ?>
                    <?php if ($has_viva): ?> tableHeaders.push('Viva'); <?php endif; ?>
                    tableHeaders.push('Total', '%', 'Grade', 'Result');
                    
                    const tableData = [];
                    <?php foreach ($results as $result): ?>
                        <?php
                        $has_result = !is_null($result['obtained_marks']);
                        $percentage = $has_result ? ($result['obtained_marks'] / $exam['total_marks']) * 100 : 0;
                        $status = $has_result ? ($result['obtained_marks'] >= $exam['passing_marks'] ? 'Passed' : 'Failed') : 'Absent';
                        ?>
                        tableData.push([
                            '<?php echo addslashes($result['student_id']); ?>',
                            '<?php echo addslashes($result['first_name'] . ' ' . $result['last_name']); ?>',
                            '<?php echo ucfirst($result['current_status']); ?>',
                            '<?php echo addslashes($result['current_batch']); ?>',
                            <?php if ($has_mcq): ?>'<?php echo $has_result && !is_null($result['mcq_marks']) ? $result['mcq_marks'] : '-'; ?>',<?php endif; ?>
                            <?php if ($has_project): ?>'<?php echo $has_result && !is_null($result['project_marks']) ? $result['project_marks'] : '-'; ?>',<?php endif; ?>
                            <?php if ($has_viva): ?>'<?php echo $has_result && !is_null($result['viva_marks']) ? $result['viva_marks'] : '-'; ?>',<?php endif; ?>
                            '<?php echo $has_result ? $result['obtained_marks'] : '-'; ?>',
                            '<?php echo $has_result ? number_format($percentage, 1) . '%' : '-'; ?>',
                            '<?php echo $has_result ? $result['grade'] : '-'; ?>',
                            '<?php echo $status; ?>'
                        ]);
                    <?php endforeach; ?>
                    
                    // Generate table with proper spacing
                    doc.autoTable({
                        head: [tableHeaders],
                        body: tableData,
                        startY: yPos,
                        theme: 'striped',
                        styles: { 
                            fontSize: 7,
                            cellPadding: 2,
                            lineColor: [200, 200, 200],
                            lineWidth: 0.1
                        },
                        headStyles: { 
                            fillColor: [27, 60, 83],
                            textColor: [255, 255, 255],
                            fontSize: 7,
                            fontStyle: 'bold',
                            halign: 'center'
                        },
                        columnStyles: {
                            0: { cellWidth: 20 },
                            1: { cellWidth: 30 },
                            2: { cellWidth: 18 },
                            3: { cellWidth: 25 }
                        },
                        margin: { left: leftMargin, right: 14 },
                        tableWidth: 'auto'
                    });
                    
                    doc.save('exam_results_<?php echo $exam['exam_id']; ?>.pdf');
                }
            });
            
            // Print
            $('#printBtn').click(function() { window.print(); });
            
            // Keyboard shortcuts
            $(document).keydown(function(e) {
                if (e.ctrlKey && e.key === 'f') { e.preventDefault(); $('#searchInput').focus(); }
                if (e.ctrlKey && e.key === 'p') { e.preventDefault(); $('#printBtn').click(); }
            });
        });
    </script>
</body>
</html>