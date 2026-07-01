<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in and is a trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit();
}

$exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : '';

if (empty($exam_id)) {
    header("Location: trainer_exams.php");
    exit();
}

// Get trainer details
$trainer_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
$stmt->execute([$trainer_id]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

// Get exam details
$stmt = $db->prepare("
    SELECT e.*, b.batch_name, b.batch_mentor_id,
           (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.exam_id) as results_uploaded,
           (SELECT COUNT(*) FROM students s WHERE s.batch_name = e.batch_id AND s.current_status = 'active') as total_students,
           (SELECT COUNT(*) FROM batch_courses bc WHERE bc.batch_id = b.batch_id AND bc.trainer_id IN (?, ?)) as is_course_trainer
    FROM exams e 
    JOIN batches b ON e.batch_id = b.batch_id 
    WHERE e.exam_id = ?
");
$stmt->execute([$trainer['id'], $trainer_id, $exam_id]);
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

// Check if trainer is assigned to this batch
if (!$exam || ($exam['batch_mentor_id'] != $trainer['id'] && $exam['batch_mentor_id'] != $trainer_id && $exam['is_course_trainer'] == 0)) {
    header("Location: trainer_exams.php");
    exit();
}

// Get students with their results
$students_stmt = $db->prepare("
    SELECT s.student_id, s.first_name, s.last_name, 
           er.obtained_marks, er.grade, er.remarks, er.uploaded_at,
           er.mcq_marks, er.project_marks, er.viva_marks
    FROM students s
    LEFT JOIN exam_results er ON s.student_id = er.student_id AND er.exam_id = ?
    WHERE s.batch_name = ? AND s.current_status = 'active'
    ORDER BY s.first_name, s.last_name
");
$students_stmt->execute([$exam_id, $exam['batch_id']]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_students = count($students);
$results_uploaded = 0;
$passed = 0;
$failed = 0;
$total_marks = 0;
$highest_marks = 0;
$lowest_marks = $exam['total_marks'];

foreach ($students as $student) {
    if (!is_null($student['obtained_marks'])) {
        $results_uploaded++;
        $total_marks += $student['obtained_marks'];
        
        if ($student['obtained_marks'] > $highest_marks) {
            $highest_marks = $student['obtained_marks'];
        }
        if ($student['obtained_marks'] < $lowest_marks) {
            $lowest_marks = $student['obtained_marks'];
        }
        
        if ($student['obtained_marks'] >= $exam['passing_marks']) {
            $passed++;
        } else {
            $failed++;
        }
    }
}

$average_marks = $results_uploaded > 0 ? $total_marks / $results_uploaded : 0;
$completion_rate = $total_students > 0 ? ($results_uploaded / $total_students) * 100 : 0;
$pass_percentage = $results_uploaded > 0 ? ($passed / $results_uploaded) * 100 : 0;

// Get exam components
$exam_components = !empty($exam['exam_components']) ? explode(',', $exam['exam_components']) : [];

// Chart-ready grade distribution built from $students already fetched above
$grade_buckets = ['A+' => 0, 'A' => 0, 'B+' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
foreach ($students as $s) {
    if (!is_null($s['grade']) && isset($grade_buckets[$s['grade']])) {
        $grade_buckets[$s['grade']]++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Exam Details - Trainer Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --trainer-primary: #1B3C53;
            --trainer-secondary: #234C6A;
        }
        
        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.10), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(35,76,106,.10), transparent 30%),
                linear-gradient(135deg, #f8fafc 0%, #F6F1ED 48%, #f8fbff 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        .main-content {
            margin-left: 0;
            padding: 15px;
            transition: all 0.3s;
            min-height: 100vh;
        }
        
        @media (min-width: 768px) {
            .main-content {
                margin-left: 250px;
                padding: 20px;
            }
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: 16rem !important;
            }
        }

        .card {
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 20px !important;
            box-shadow: 0 14px 32px rgba(15,23,42,.07) !important;
            background: rgba(255,255,255,.94) !important;
        }
        
        .exam-header {
            background: linear-gradient(120deg, #1B3C53 0%, #234C6A 50%, #c026d3 100%);
            background-size: 200% 200%;
            animation: examGradientShift 12s ease infinite;
            color: white;
            padding: 1.5rem 0;
            margin: -15px -15px 1.5rem -15px;
            border-radius: 0;
            box-shadow: 0 18px 40px rgba(35,76,106,.22);
            position: relative;
            overflow: hidden;
        }

        @keyframes examGradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        @media (min-width: 768px) {
            .exam-header {
                border-radius: 22px;
                margin: 0 0 2rem 0;
                padding: 2rem 0;
            }
        }
        
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 5px 20px rgba(15,23,42,.08);
            text-align: center;
            border-left: 0;
            transition: all 0.3s ease;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; width: 100%; height: 4px;
            background: linear-gradient(90deg, #1B3C53, #234C6A);
        }
        
        @media (min-width: 768px) {
            .stats-card {
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 18px;
            }
            
            .stats-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 30px rgba(15,23,42,.12);
            }
        }
        
        .stats-number {
            font-size: 1.75rem;
            font-weight: 800;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        @media (min-width: 768px) {
            .stats-number {
                font-size: 2rem;
            }
        }
        
        .progress-sm {
            height: 6px;
            border-radius: 8px;
        }
        
        @media (min-width: 768px) {
            .progress-sm {
                height: 8px;
                border-radius: 10px;
            }
        }
        
        .student-row {
            transition: all 0.3s ease;
        }
        
        @media (min-width: 768px) {
            .student-row:hover {
                background: linear-gradient(90deg, rgba(245,243,255,.9), rgba(255,241,248,.7));
                transform: translateX(5px);
            }
        }
        
        .grade-A { background-color: rgba(16, 185, 129, 0.10); }
        .grade-B { background-color: rgba(245, 158, 11, 0.10); }
        .grade-C { background-color: rgba(245, 158, 11, 0.18); }
        .grade-D { background-color: rgba(239, 68, 68, 0.10); }
        .grade-F { background-color: rgba(239, 68, 68, 0.18); }

        .text-primary { color: var(--trainer-secondary) !important; }
        .btn-primary, .bg-primary {
            background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
            border-color: transparent !important;
        }
        .btn-primary { box-shadow: 0 10px 22px rgba(35,76,106,.18); font-weight: 700; }
        .bg-success { background: linear-gradient(135deg, #10b981, #22c55e) !important; }

        .chart-card {
            background: rgba(255,255,255,.94);
            border: 1px solid rgba(226,232,240,.82);
            border-radius: 20px;
            box-shadow: 0 14px 32px rgba(15,23,42,.07);
            padding: 1.25rem;
        }

        .chart-card h5 {
            font-weight: 800;
            color: #1f2937;
        }

        .chart-card h5 i { color: #234C6A; }

        .chart-canvas-wrap {
            position: relative;
            height: 220px;
        }
        
        .component-badge {
            font-size: 0.7rem;
            padding: 3px 8px;
            margin-right: 3px;
            margin-bottom: 3px;
        }
        
        @media (min-width: 768px) {
            .component-badge {
                font-size: 0.75rem;
                padding: 4px 10px;
                margin-right: 5px;
                margin-bottom: 5px;
            }
        }
        
        /* Mobile menu toggle */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1040;
            background: var(--trainer-primary);
            color: white;
            border: none;
            border-radius: 8px;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        @media (min-width: 768px) {
            .mobile-menu-toggle {
                display: none;
            }
        }
        
        /* Mobile sidebar overlay */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1039;
            display: none;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        @media (max-width: 767.98px) {
            .table {
                font-size: 0.8rem;
            }
            
            .table th,
            .table td {
                padding: 0.4rem;
                white-space: nowrap;
            }
        }
        
        /* Mobile optimizations */
        @media (max-width: 767.98px) {
            h1, .h1 { font-size: 1.5rem !important; }
            h2, .h2 { font-size: 1.25rem !important; }
            h3, .h3 { font-size: 1.125rem !important; }
            
            .display-4 {
                font-size: 2.5rem !important;
            }
            
            .btn {
                padding: 0.375rem 0.75rem;
                font-size: 0.875rem;
            }
            
            .card {
                border-radius: 12px;
            }
            
            .card-header {
                padding: 0.75rem 1rem;
            }
            
            .card-body {
                padding: 1rem;
            }
        }
        
        /* Compact student table for mobile */
        @media (max-width: 767.98px) {
            .compact-table th:nth-child(5),
            .compact-table td:nth-child(5),
            .compact-table th:nth-child(6),
            .compact-table td:nth-child(6),
            .compact-table th:nth-child(9),
            .compact-table td:nth-child(9) {
                display: none;
            }
        }
        
        @media (max-width: 575.98px) {
            .compact-table th:nth-child(3),
            .compact-table td:nth-child(3),
            .compact-table th:nth-child(4),
            .compact-table td:nth-child(4) {
                display: none;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 1024px) {
            button, 
            .btn,
            a[href] {
                min-height: 44px;
                min-width: 44px;
            }
            
            .table-hover tbody tr {
                min-height: 44px;
            }
        }
        
        /* Prevent text overflow */
        .text-truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Action buttons on mobile */
        @media (max-width: 767.98px) {
            .action-buttons {
                position: static;
                margin-top: 1rem;
            }
            
            .action-buttons .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
        }
    
        /* ===== Same trainer purple/pink dashboard theme + sidebar consistency ===== */
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --dash-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --dash-green: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --dash-orange: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            --dash-red: linear-gradient(135deg, #ef4444 0%, #f43f5e 100%);
            --dash-ink: #101827;
        }

        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.15), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130,.15), transparent 30%),
                linear-gradient(135deg, #eef4ff 0%, #f7f3ff 48%, #f8fbff 100%) !important;
            color: var(--dash-ink);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83,.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(69,104,130,.045) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.82), transparent 84%);
            z-index: -2;
        }

        .main-content {
            background: transparent !important;
        }

        @media (min-width: 1024px) {
            .main-content {
                margin-left: 16rem !important;
            }
        }

        @media (min-width: 768px) and (max-width: 1023.98px) {
            .main-content {
                margin-left: 0 !important;
            }
        }

        aside {
            z-index: 1041;
        }

        .exam-header {
            position: relative;
            overflow: hidden;
            border-radius: 28px !important;
            margin: 0 0 2rem 0 !important;
            padding: clamp(1.25rem, 2.5vw, 2rem) 0 !important;
            background: var(--dash-main) !important;
            box-shadow: 0 24px 58px rgba(27,60,83,.25) !important;
            border: 1px solid rgba(255,255,255,.22);
            animation: none !important;
        }

        .exam-header::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            right: -135px;
            top: -145px;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            filter: blur(2px);
        }

        .exam-header::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .exam-header .container-fluid {
            position: relative;
            z-index: 1;
        }

        .exam-header h1 {
            color: white !important;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .exam-header p {
            color: rgba(255,255,255,.84) !important;
            font-weight: 600;
        }

        .exam-header .btn-light,
        .exam-header .btn-outline-light {
            border-radius: 14px !important;
            font-weight: 900 !important;
            border: 1px solid rgba(255,255,255,.28) !important;
            backdrop-filter: blur(12px);
        }

        .exam-header .btn-light {
            color: #234C6A !important;
            background: rgba(255,255,255,.92) !important;
        }

        .exam-header .btn-outline-light {
            color: white !important;
            background: rgba(255,255,255,.12) !important;
        }

        .card, .stats-card, .chart-card {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .card::before, .stats-card::before, .chart-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
            z-index: 2;
        }

        .card::after, .stats-card::after, .chart-card::after {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            right: -65px;
            top: -65px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .74;
            pointer-events: none;
        }

        .card > *, .stats-card > *, .chart-card > * {
            position: relative;
            z-index: 3;
        }

        .stats-card {
            transition: all .22s ease;
        }

        .stats-card:hover, .card:hover, .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 22px 48px rgba(15,23,42,.11) !important;
        }

        .stats-card:nth-child(1), .row.mb-4.g-2 > div:nth-child(1) .stats-card {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(2) .stats-card,
        .row.mb-4.g-2 > div:nth-child(5) .stats-card {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(3) .stats-card {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(4) .stats-card {
            --feature-accent: linear-gradient(90deg, #f59e0b, #f97316, #456882);
            --feature-glow: radial-gradient(circle, rgba(249,115,22,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(6) .stats-card {
            --feature-accent: linear-gradient(90deg, #ef4444, #f43f5e, #456882);
            --feature-glow: radial-gradient(circle, rgba(239,68,68,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-4 .card:nth-child(1) {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-4 .card:nth-child(2) {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .chart-card {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-8 .card {
            --feature-accent: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(79,70,229,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .stats-number {
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .stats-label {
            color: #64748b;
            font-size: .74rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .card-header {
            background: linear-gradient(90deg, rgba(255,255,255,.96), rgba(248,250,255,.94)) !important;
            border-bottom: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px 24px 0 0 !important;
            color: #1f2937 !important;
        }

        .card-header.bg-primary,
        .card-header.bg-success {
            color: #1f2937 !important;
        }

        .card-header h5 {
            font-weight: 900;
        }

        .card-header i {
            color: #234C6A;
        }

        .progress {
            background: #e2e8f0 !important;
            overflow: hidden;
        }

        .progress-bar {
            background: var(--dash-main) !important;
        }

        .badge {
            border-radius: 999px !important;
            font-weight: 900 !important;
            letter-spacing: .02em;
        }

        .component-badge {
            box-shadow: 0 8px 18px rgba(15,23,42,.08);
        }

        .btn {
            border-radius: 14px !important;
            font-weight: 800 !important;
        }

        .btn-primary {
            background: var(--dash-main) !important;
            border: none !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
        }

        .btn-outline-primary {
            color: #234C6A !important;
            border-color: rgba(35,76,106,.36) !important;
        }

        .btn-outline-primary:hover {
            background: var(--dash-main) !important;
            border-color: transparent !important;
            color: white !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
        }

        .table-responsive {
            border-radius: 18px;
            border: 1px solid rgba(226,232,240,.78);
            background: rgba(255,255,255,.70);
        }

        .table thead th {
            background: linear-gradient(90deg, #EEF3F6, #F6F1ED) !important;
            color: #64748b !important;
            font-size: .72rem;
            font-weight: 900 !important;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid rgba(226,232,240,.9) !important;
        }

        .table tbody td {
            border-color: rgba(226,232,240,.72) !important;
            vertical-align: middle;
        }

        .student-row {
            border-left: 4px solid transparent;
        }

        .student-row:hover {
            background: linear-gradient(90deg, rgba(245,243,255,.90), rgba(255,241,248,.80)) !important;
            transform: translateX(4px);
        }

        .grade-A { background-color: rgba(16,185,129,.08) !important; border-left-color: #10b981; }
        .grade-B { background-color: rgba(35,76,106,.07) !important; border-left-color: #234C6A; }
        .grade-C { background-color: rgba(245,158,11,.10) !important; border-left-color: #f59e0b; }
        .grade-D, .grade-F { background-color: rgba(239,68,68,.08) !important; border-left-color: #ef4444; }

        .chart-card h5 {
            font-weight: 900;
        }

        .chart-card h5 i {
            color: #234C6A;
        }

        .mobile-menu-toggle {
            background: var(--dash-main) !important;
            border-radius: 14px !important;
            box-shadow: 0 12px 28px rgba(35,76,106,.25) !important;
        }

        .text-primary, .text-info {
            color: #234C6A !important;
        }

        .text-success {
            color: #059669 !important;
        }

        .text-warning {
            color: #d97706 !important;
        }

        .bg-primary, .bg-success, .bg-info, .bg-warning, .bg-danger {
            border: none !important;
        }

        .bg-primary { background: var(--dash-main) !important; }
        .bg-success { background: var(--dash-green) !important; }
        .bg-info { background: var(--dash-blue) !important; }
        .bg-warning { background: var(--dash-orange) !important; }
        .bg-danger { background: var(--dash-red) !important; }

        @media (max-width: 767.98px) {
            .exam-header {
                border-radius: 0 0 22px 22px !important;
                margin: -15px -15px 1.5rem -15px !important;
            }

            .card, .stats-card, .chart-card {
                border-radius: 20px !important;
            }

            .card-header {
                border-radius: 20px 20px 0 0 !important;
            }
        }

    
/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
}
body {
    background:
        radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
        radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
        linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-purple-500,.text-purple-600,.text-indigo-500,.text-indigo-600,.text-blue-500,.text-blue-600,.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-purple-200,.border-indigo-200,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    </style>
</head>
<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle d-lg-none" id="mobileMenuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <?php include '../t_sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Exam Header -->
        <div class="exam-header animate__animated animate__fadeIn">
            <div class="container-fluid px-3 px-md-4">
                <div class="row align-items-center">
                    <div class="col-12 col-md-8 mb-3 mb-md-0">
                        <h1 class="h2 mb-2 text-truncate"><?php echo htmlspecialchars($exam['exam_name']); ?></h1>
                        <p class="mb-0 opacity-75 text-truncate-2">
                            <i class="fas fa-users me-1"></i> <?php echo htmlspecialchars($exam['batch_name']); ?> • 
                            <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($exam['subject']); ?> • 
                            <i class="fas fa-calendar me-1"></i> <?php echo date('M j, Y', strtotime($exam['exam_date'])); ?>
                        </p>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="action-buttons">
                            <div class="d-flex flex-column flex-sm-row gap-2">
                                <a href="upload_results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-light">
                                    <i class="fas fa-upload me-1"></i> Upload Results
                                </a>
                                <a href="trainer_exams.php" class="btn btn-outline-light">
                                    <i class="fas fa-arrow-left me-1"></i> Back to Exams
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid px-3 px-md-4">
            <!-- Quick Stats -->
            <div class="row mb-4 g-2">
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stats-card">
                        <div class="stats-number text-primary"><?php echo $total_students; ?></div>
                        <div class="stats-label">Total Students</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stats-card">
                        <div class="stats-number text-success"><?php echo $results_uploaded; ?></div>
                        <div class="stats-label">Results Uploaded</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stats-card">
                        <div class="stats-number text-info"><?php echo number_format($completion_rate, 1); ?>%</div>
                        <div class="stats-label">Completion Rate</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stats-card">
                        <div class="stats-number text-warning"><?php echo number_format($average_marks, 2); ?></div>
                        <div class="stats-label">Average Marks</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stats-card">
                        <div class="stats-number text-success"><?php echo $passed; ?></div>
                        <div class="stats-label">Passed</div>
                    </div>
                </div>
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="stats-card">
                        <div class="stats-number text-danger"><?php echo $failed; ?></div>
                        <div class="stats-label">Failed</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Left Column - Exam Information -->
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <!-- Exam Details Card -->
                    <div class="card shadow mb-4">
                        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Exam Information</h5>
                            <button class="btn btn-sm btn-light d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#examInfoCollapse">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="collapse d-lg-block" id="examInfoCollapse">
                            <div class="card-body">
                                <div class="mb-3">
                                    <strong>Batch:</strong>
                                    <p class="mb-2"><?php echo htmlspecialchars($exam['batch_name']); ?></p>
                                    
                                    <strong>Subject:</strong>
                                    <p class="mb-2"><?php echo htmlspecialchars($exam['subject']); ?></p>
                                    
                                    <strong>Exam Date:</strong>
                                    <p class="mb-2"><?php echo date('F j, Y', strtotime($exam['exam_date'])); ?></p>
                                    
                                    <strong>Exam Type:</strong>
                                    <p class="mb-2">
                                        <span class="badge bg-info">
                                            <?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?>
                                        </span>
                                    </p>
                                    
                                    <strong>Total Marks:</strong>
                                    <p class="mb-2"><?php echo $exam['total_marks']; ?></p>
                                    
                                    <strong>Passing Marks:</strong>
                                    <p class="mb-2"><?php echo $exam['passing_marks']; ?></p>
                                </div>

                                <!-- Exam Components -->
                                <?php if (!empty($exam_components)): ?>
                                    <div class="mb-3">
                                        <strong>Exam Components:</strong>
                                        <div class="mt-2">
                                            <?php foreach ($exam_components as $component): 
                                                $badge_class = '';
                                                $component_name = '';
                                                $max_marks = 0;
                                                
                                                switch($component) {
                                                    case 'mcq':
                                                        $badge_class = 'bg-primary';
                                                        $component_name = 'MCQ';
                                                        $max_marks = $exam['mcq_marks'] ?? 0;
                                                        break;
                                                    case 'project':
                                                        $badge_class = 'bg-success';
                                                        $component_name = 'Project';
                                                        $max_marks = $exam['project_marks'] ?? 0;
                                                        break;
                                                    case 'viva':
                                                        $badge_class = 'bg-warning';
                                                        $component_name = 'Viva';
                                                        $max_marks = $exam['viva_marks'] ?? 0;
                                                        break;
                                                }
                                            ?>
                                                <span class="badge component-badge <?php echo $badge_class; ?> d-inline-block mb-1">
                                                    <?php echo $component_name; ?> (Max: <?php echo $max_marks; ?>)
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($exam['description'])): ?>
                                    <div class="mb-3">
                                        <strong>Description:</strong>
                                        <p class="mb-0 text-muted text-truncate-2"><?php echo htmlspecialchars($exam['description']); ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Performance Summary -->
                    <div class="card shadow">
                        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Performance Summary</h5>
                            <button class="btn btn-sm btn-light d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#performanceCollapse">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div class="collapse d-lg-block" id="performanceCollapse">
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <div class="display-4 text-primary fw-bold"><?php echo number_format($pass_percentage, 1); ?>%</div>
                                    <p class="text-muted mb-0">Overall Pass Rate</p>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Highest Marks</small>
                                        <small><?php echo number_format($highest_marks, 2); ?></small>
                                    </div>
                                    <div class="progress progress-sm mb-3">
                                        <div class="progress-bar bg-success" style="width: <?php echo ($highest_marks / $exam['total_marks']) * 100; ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Average Marks</small>
                                        <small><?php echo number_format($average_marks, 2); ?></small>
                                    </div>
                                    <div class="progress progress-sm mb-3">
                                        <div class="progress-bar bg-info" style="width: <?php echo ($average_marks / $exam['total_marks']) * 100; ?>%"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between mb-1">
                                        <small>Lowest Marks</small>
                                        <small><?php echo number_format($lowest_marks, 2); ?></small>
                                    </div>
                                    <div class="progress progress-sm">
                                        <div class="progress-bar bg-warning" style="width: <?php echo ($lowest_marks / $exam['total_marks']) * 100; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grade Distribution Chart -->
                    <div class="chart-card mt-4">
                        <h5 class="mb-3"><i class="fas fa-chart-pie me-2"></i>Grade Distribution</h5>
                        <div class="chart-canvas-wrap">
                            <canvas id="examGradeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Student Results -->
                <div class="col-lg-8">
                    <div class="card shadow">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
                            <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Student Results</h5>
                            <div>
                                <span class="badge bg-primary"><?php echo $results_uploaded; ?>/<?php echo $total_students; ?> Uploaded</span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($total_students > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover compact-table">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Student</th>
                                                <th class="d-none d-sm-table-cell">Student ID</th>
                                                <th>Marks</th>
                                                <th class="d-none d-md-table-cell">%</th>
                                                <th class="d-none d-lg-table-cell">Grade</th>
                                                <th>Status</th>
                                                <?php if (!empty($exam_components)): ?>
                                                    <?php foreach ($exam_components as $component): ?>
                                                        <th class="d-none d-lg-table-cell"><?php echo ucfirst($component); ?></th>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <th class="d-none d-xl-table-cell">Remarks</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($students as $student): 
                                                $percentage = !is_null($student['obtained_marks']) ? ($student['obtained_marks'] / $exam['total_marks']) * 100 : 0;
                                                $status = !is_null($student['obtained_marks']) ? 
                                                    ($student['obtained_marks'] >= $exam['passing_marks'] ? 'Passed' : 'Failed') : 
                                                    'Pending';
                                                $grade_class = !is_null($student['grade']) ? 'grade-' . substr($student['grade'], 0, 1) : '';
                                            ?>
                                                <tr class="student-row <?php echo $grade_class; ?>">
                                                    <td>
                                                        <div class="d-flex flex-column">
                                                            <strong class="text-truncate" style="max-width: 120px;"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></strong>
                                                            <small class="text-muted d-sm-none"><?php echo htmlspecialchars($student['student_id']); ?></small>
                                                        </div>
                                                    </td>
                                                    <td class="d-none d-sm-table-cell align-middle"><?php echo htmlspecialchars($student['student_id']); ?></td>
                                                    <td class="align-middle">
                                                        <?php if (!is_null($student['obtained_marks'])): ?>
                                                            <div class="d-flex flex-column">
                                                                <strong><?php echo $student['obtained_marks']; ?></strong>
                                                                <small class="text-muted">/<?php echo $exam['total_marks']; ?></small>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="d-none d-md-table-cell align-middle">
                                                        <?php if (!is_null($student['obtained_marks'])): ?>
                                                            <?php echo number_format($percentage, 2); ?>%
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="d-none d-lg-table-cell align-middle">
                                                        <?php if (!is_null($student['grade'])): ?>
                                                            <span class="badge 
                                                                <?php 
                                                                if ($student['grade'] == 'A+' || $student['grade'] == 'A') echo 'bg-success';
                                                                elseif ($student['grade'] == 'B+' || $student['grade'] == 'B') echo 'bg-primary';
                                                                elseif ($student['grade'] == 'C') echo 'bg-warning';
                                                                else echo 'bg-danger';
                                                                ?>">
                                                                <?php echo $student['grade']; ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="align-middle">
                                                        <span class="badge 
                                                            <?php 
                                                            if ($status == 'Passed') echo 'bg-success';
                                                            elseif ($status == 'Failed') echo 'bg-danger';
                                                            else echo 'bg-secondary';
                                                            ?>">
                                                            <?php echo $status; ?>
                                                        </span>
                                                        <?php if (!is_null($student['grade'])): ?>
                                                            <div class="d-lg-none mt-1">
                                                                <span class="badge 
                                                                    <?php 
                                                                    if ($student['grade'] == 'A+' || $student['grade'] == 'A') echo 'bg-success';
                                                                    elseif ($student['grade'] == 'B+' || $student['grade'] == 'B') echo 'bg-primary';
                                                                    elseif ($student['grade'] == 'C') echo 'bg-warning';
                                                                    else echo 'bg-danger';
                                                                    ?>">
                                                                    <?php echo $student['grade']; ?>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <!-- Component Marks -->
                                                    <?php if (!empty($exam_components)): ?>
                                                        <?php foreach ($exam_components as $component): 
                                                            $field_name = $component . '_marks';
                                                            $marks = $student[$field_name];
                                                        ?>
                                                            <td class="d-none d-lg-table-cell align-middle">
                                                                <?php if (!is_null($marks)): ?>
                                                                    <?php echo $marks; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">-</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                    
                                                    <td class="d-none d-xl-table-cell align-middle">
                                                        <?php if (!empty($student['remarks'])): ?>
                                                            <span class="text-muted" title="<?php echo htmlspecialchars($student['remarks']); ?>">
                                                                <i class="fas fa-comment"></i>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="align-middle">
                                                        <a href="upload_results.php?exam_id=<?php echo $exam_id; ?>&student_id=<?php echo $student['student_id']; ?>" 
                                                           class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-edit"></i>
                                                            <span class="d-none d-sm-inline">Edit</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-user-graduate fa-4x text-muted mb-4"></i>
                                    <h5 class="text-muted">No Students Found</h5>
                                    <p class="text-muted">There are no active students in this batch.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar toggle for mobile
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            const sidebar = document.querySelector('aside');
            
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    sidebarOverlay.classList.toggle('active');
                    document.body.classList.toggle('sidebar-open');
                });
            }
            
            if (sidebarOverlay) {
                sidebarOverlay.addEventListener('click', function() {
                    sidebar.classList.add('-translate-x-full');
                    this.classList.remove('active');
                    document.body.classList.remove('sidebar-open');
                });
            }
            
            // Close sidebar when clicking on a link (mobile)
            document.querySelectorAll('nav a').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        sidebar.classList.add('-translate-x-full');
                        if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                        document.body.classList.remove('sidebar-open');
                    }
                });
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 768) {
                    sidebar.classList.remove('-translate-x-full');
                    if (sidebarOverlay) sidebarOverlay.classList.remove('active');
                    document.body.classList.remove('sidebar-open');
                }
            });
            
            // Add hover effects to stats cards (desktop only)
            if (window.innerWidth >= 768) {
                const statsCards = document.querySelectorAll('.stats-card');
                statsCards.forEach(card => {
                    card.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateY(-5px)';
                    });
                    card.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateY(0)';
                    });
                });
            }
            
            // Initialize collapse behavior for mobile
            const collapseElements = document.querySelectorAll('.collapse');
            collapseElements.forEach(collapse => {
                if (window.innerWidth >= 992) {
                    collapse.classList.add('show');
                }
            });

            // ===== Grade distribution chart =====
            const gradeLabels = <?php echo json_encode(array_keys($grade_buckets)); ?>;
            const gradeData = <?php echo json_encode(array_values($grade_buckets)); ?>;
            const examGradeChartEl = document.getElementById('examGradeChart');
            if (examGradeChartEl) {
                const gradeTotal = gradeData.reduce((a, b) => a + b, 0);
                if (gradeTotal > 0) {
                    new Chart(examGradeChartEl, {
                        type: 'doughnut',
                        data: {
                            labels: gradeLabels,
                            datasets: [{
                                data: gradeData,
                                backgroundColor: ['#10b981', '#22c55e', '#234C6A', '#456882', '#f59e0b', '#f97316', '#ef4444'],
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
                        }
                    });
                } else {
                    examGradeChartEl.parentElement.innerHTML = '<p class="text-muted text-center small mt-5">No grades uploaded yet</p>';
                }
            }
        });
    </script>
</body>
</html>