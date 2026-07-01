<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in and is a trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit();
}

$trainer_id = $_SESSION['user_id'];
$exam_id = isset($_GET['exam_id']) ? $_GET['exam_id'] : '';
$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';

// Get trainer details
$stmt = $db->prepare("SELECT t.* FROM trainers t WHERE t.user_id = ?");
$stmt->execute([$trainer_id]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

// Robust trainer matching:
$trainer_match_ids = array_values(array_unique(array_filter([
    (int)$trainer['id'],
    (int)$trainer_id
])));
$trainer_placeholders = implode(',', array_fill(0, count($trainer_match_ids), '?'));

// Get assigned batches for filter
$batches_stmt = $db->prepare("
    SELECT DISTINCT b.batch_id, b.batch_name 
    FROM batches b 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
    WHERE b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders)
    ORDER BY b.batch_name
");
$batches_stmt->execute(array_merge($trainer_match_ids, $trainer_match_ids));
$assigned_batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get exams for selected batch
$exams = [];
if (!empty($batch_id)) {
    $exams_stmt = $db->prepare("
        SELECT exam_id, exam_name, exam_date 
        FROM exams 
        WHERE batch_id = ? 
        ORDER BY exam_date DESC
    ");
    $exams_stmt->execute([$batch_id]);
    $exams = $exams_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get results
$results = [];
if (!empty($exam_id)) {
    $results_stmt = $db->prepare("
        SELECT DISTINCT er.*, s.first_name, s.last_name, s.student_id,
               e.exam_name, e.total_marks, e.passing_marks
        FROM exam_results er
        JOIN students s ON er.student_id = s.student_id
        JOIN exams e ON er.exam_id = e.exam_id
        JOIN batches b ON e.batch_id = b.batch_id
        LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
        WHERE er.exam_id = ? AND (b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders))
        ORDER BY s.first_name, s.last_name
    ");
    $params = array_merge([$exam_id], $trainer_match_ids, $trainer_match_ids);
    $results_stmt->execute($params);
    $results = $results_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate statistics
$total_students = count($results);
$passed = 0;
$failed = 0;
$average_marks = 0;

foreach ($results as $result) {
    if ($result['obtained_marks'] >= $result['passing_marks']) {
        $passed++;
    } else {
        $failed++;
    }
    $average_marks += $result['obtained_marks'];
}

$average_marks = $total_students > 0 ? $average_marks / $total_students : 0;
$pass_percentage = $total_students > 0 ? ($passed / $total_students) * 100 : 0;

// Chart-ready data built from $results already fetched above - no extra queries
$grade_buckets = ['A+' => 0, 'A' => 0, 'B+' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
$score_buckets = ['0-20%' => 0, '21-40%' => 0, '41-60%' => 0, '61-80%' => 0, '81-100%' => 0];
foreach ($results as $r) {
    if (isset($grade_buckets[$r['grade']])) {
        $grade_buckets[$r['grade']]++;
    }
    $pct = $r['total_marks'] > 0 ? ($r['obtained_marks'] / $r['total_marks']) * 100 : 0;
    if ($pct <= 20) $score_buckets['0-20%']++;
    elseif ($pct <= 40) $score_buckets['21-40%']++;
    elseif ($pct <= 60) $score_buckets['41-60%']++;
    elseif ($pct <= 80) $score_buckets['61-80%']++;
    else $score_buckets['81-100%']++;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Analytics Matrix - Trainer Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --trainer-primary: #1B3C53;
            --trainer-violet: #234C6A;
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

        .card-header {
            background: linear-gradient(90deg, rgba(255,255,255,.96), rgba(248,250,255,.94)) !important;
            border-bottom: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 20px 20px 0 0 !important;
            font-weight: 800;
        }

        .text-primary { color: var(--trainer-violet) !important; }
        .btn-primary {
            background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
            border: none !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
            font-weight: 700;
        }
        
        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 5px 20px rgba(15,23,42,.08);
            text-align: center;
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
        
        .grade-A { background-color: rgba(16, 185, 129, 0.10); }
        .grade-B { background-color: rgba(245, 158, 11, 0.10); }
        .grade-C { background-color: rgba(245, 158, 11, 0.18); }
        .grade-D { background-color: rgba(239, 68, 68, 0.10); }
        .grade-F { background-color: rgba(239, 68, 68, 0.18); }

        .chart-card {
            background: rgba(255,255,255,.94);
            border: 1px solid rgba(226,232,240,.82);
            border-radius: 20px;
            box-shadow: 0 14px 32px rgba(15,23,42,.07);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .chart-card h6 {
            font-weight: 800;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-card h6 i { color: #234C6A; }

        .chart-canvas-wrap {
            position: relative;
            height: 260px;
        }
        
        /* Mobile menu toggle */
        .mobile-menu-toggle {
            position: fixed;
            top: 15px;
            left: 15px;
            z-index: 1040;
            background: #6610f2;
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
            
            .card-header {
                padding: 0.75rem 1rem;
            }
            
            .card-body {
                padding: 1rem;
            }
        }
        
        /* Compact table for mobile */
        @media (max-width: 767.98px) {
            .compact-table th:nth-child(4),
            .compact-table td:nth-child(4),
            .compact-table th:nth-child(7),
            .compact-table td:nth-child(7),
            .compact-table th:nth-child(8),
            .compact-table td:nth-child(8) {
                display: none;
            }
        }
        
        @media (max-width: 575.98px) {
            .compact-table th:nth-child(3),
            .compact-table td:nth-child(3),
            .compact-table th:nth-child(6),
            .compact-table td:nth-child(6) {
                display: none;
            }
        }
        
        /* Mobile optimizations */
        @media (max-width: 767.98px) {
            h1, .h1 { font-size: 1.5rem !important; }
            h2, .h2 { font-size: 1.25rem !important; }
            h3, .h3 { font-size: 1.125rem !important; }
            
            .btn {
                padding: 0.375rem 0.75rem;
                font-size: 0.875rem;
            }
            
            .form-select {
                padding: 0.375rem 0.75rem;
                font-size: 0.875rem;
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
        
        /* Filter form on mobile */
        @media (max-width: 767.98px) {
            .filter-form .col-md-2 {
                margin-top: 0.5rem;
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

        .analytics-hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.2rem, 2.5vw, 1.9rem);
            margin-bottom: 1.5rem;
            color: white;
            background: var(--dash-main);
            box-shadow: 0 24px 58px rgba(27,60,83,.25);
            border: 1px solid rgba(255,255,255,.22);
        }

        .analytics-hero::before {
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

        .analytics-hero::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .analytics-hero > * {
            position: relative;
            z-index: 1;
        }

        .analytics-hero h1 {
            color: white !important;
            font-weight: 900;
            letter-spacing: -.03em;
        }

        .analytics-hero p {
            color: rgba(255,255,255,.84) !important;
            font-weight: 600;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .48rem .76rem;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.25);
            color: white;
            font-size: .75rem;
            font-weight: 900;
            backdrop-filter: blur(12px);
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

        .card:hover, .stats-card:hover, .chart-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 22px 48px rgba(15,23,42,.11) !important;
        }

        .card {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .chart-card:nth-child(1) {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .chart-card:nth-child(2) {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(1) .stats-card {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(2) .stats-card {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(3) .stats-card {
            --feature-accent: linear-gradient(90deg, #ef4444, #f43f5e, #456882);
            --feature-glow: radial-gradient(circle, rgba(239,68,68,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .row.mb-4.g-2 > div:nth-child(4) .stats-card {
            --feature-accent: linear-gradient(90deg, #456882, #234C6A, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(69,104,130,.13), rgba(35,76,106,.05) 60%, transparent 72%);
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

        .card-header h6 {
            font-weight: 900 !important;
        }

        .card-header i, .chart-card h6 i {
            color: #234C6A;
        }

        .chart-card h6 {
            font-weight: 900;
        }

        .form-select {
            border-radius: 14px !important;
            border-color: rgba(148,163,184,.40) !important;
        }

        .form-select:focus {
            border-color: #234C6A !important;
            box-shadow: 0 0 0 4px rgba(139,92,246,.12) !important;
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

        .btn-outline-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(15,23,42,.12);
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

        .table-hover tbody tr:hover {
            background: linear-gradient(90deg, rgba(245,243,255,.90), rgba(255,241,248,.80)) !important;
        }

        .grade-A { background-color: rgba(16,185,129,.08) !important; }
        .grade-B { background-color: rgba(35,76,106,.07) !important; }
        .grade-C { background-color: rgba(245,158,11,.10) !important; }
        .grade-D, .grade-F { background-color: rgba(239,68,68,.08) !important; }

        .badge {
            border-radius: 999px !important;
            font-weight: 900 !important;
            letter-spacing: .02em;
        }

        .bg-primary { background: var(--dash-main) !important; }
        .bg-success { background: var(--dash-green) !important; }
        .bg-info { background: var(--dash-blue) !important; }
        .bg-warning { background: var(--dash-orange) !important; }
        .bg-danger { background: var(--dash-red) !important; }

        .text-primary, .text-info {
            color: #234C6A !important;
        }

        .text-success {
            color: #059669 !important;
        }

        .text-danger {
            color: #e11d48 !important;
        }

        .mobile-menu-toggle {
            background: var(--dash-main) !important;
            border-radius: 14px !important;
            box-shadow: 0 12px 28px rgba(35,76,106,.25) !important;
        }

        .empty-state-card {
            position: relative;
            overflow: hidden;
            background: rgba(255,255,255,.90);
            border: 1px dashed rgba(148,163,184,.45);
            border-radius: 24px;
            box-shadow: 0 18px 42px rgba(15,23,42,.06);
        }

        @media (max-width: 767.98px) {
            .analytics-hero,
            .card,
            .stats-card,
            .chart-card {
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
        <div class="container-fluid px-3 px-md-4">
            <!-- Page Header -->
            <div class="analytics-hero d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between">
                <div>
                    <h1 class="h3 mb-2"><i class="fas fa-chart-bar me-2"></i>Analytics Matrix</h1>
                    <p class="mb-3">Review exam performance, grade distribution, score ranges, and student results.</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="hero-chip"><i class="fas fa-users"></i> <?php echo $total_students; ?> students</span>
                        <span class="hero-chip"><i class="fas fa-check-circle"></i> <?php echo $passed; ?> passed</span>
                        <span class="hero-chip"><i class="fas fa-chart-line"></i> <?php echo number_format($average_marks, 2); ?> avg marks</span>
                    </div>
                </div>
                <div class="mt-3 mt-md-0">
                    <a href="trainer_exams.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-1"></i> Back to Exams
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Filter Results</h6>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-2 g-md-3 filter-form">
                        <div class="col-12 col-md-5">
                            <label class="form-label small">Select Batch</label>
                            <select class="form-select form-select-sm" name="batch_id" id="batchSelect" required>
                                <option value="">Choose Batch...</option>
                                <?php foreach ($assigned_batches as $batch): ?>
                                    <option value="<?php echo $batch['batch_id']; ?>" 
                                        <?php echo $batch_id == $batch['batch_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($batch['batch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label small">Select Exam</label>
                            <select class="form-select form-select-sm" name="exam_id" id="examSelect" <?php echo empty($batch_id) ? 'disabled' : ''; ?>>
                                <option value="">Choose Exam...</option>
                                <?php foreach ($exams as $exam): ?>
                                    <option value="<?php echo $exam['exam_id']; ?>" 
                                        <?php echo $exam_id == $exam['exam_id'] ? 'selected' : ''; ?>>
                                        <span class="text-truncate d-inline-block" style="max-width: 150px;">
                                            <?php echo htmlspecialchars($exam['exam_name']); ?>
                                        </span>
                                        <span class="text-muted">
                                            (<?php echo date('M j, Y', strtotime($exam['exam_date'])); ?>)
                                        </span>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary btn-sm w-100" <?php echo empty($batch_id) ? 'disabled' : ''; ?>>
                                <i class="fas fa-filter me-1"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (!empty($exam_id) && !empty($results)): ?>
                <!-- Statistics -->
                <div class="row mb-4 g-2">
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-number text-primary"><?php echo $total_students; ?></div>
                            <div class="stats-label">Total Students</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-number text-success"><?php echo $passed; ?></div>
                            <div class="stats-label">Passed Students</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-number text-danger"><?php echo $failed; ?></div>
                            <div class="stats-label">Failed Students</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stats-card">
                            <div class="stats-number text-info"><?php echo number_format($average_marks, 2); ?></div>
                            <div class="stats-label">Average Marks</div>
                        </div>
                    </div>
                </div>

                <!-- Analytics Charts -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h6><i class="fas fa-chart-pie"></i> Grade Distribution</h6>
                            <div class="chart-canvas-wrap">
                                <canvas id="gradeDistChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-card">
                            <h6><i class="fas fa-chart-column"></i> Score Range Distribution</h6>
                            <div class="chart-canvas-wrap">
                                <canvas id="scoreDistChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Results Table -->
                <div class="card shadow">
                    <div class="card-header py-3 d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                        <h6 class="m-0 font-weight-bold text-primary mb-2 mb-md-0">
                            Results for: <span class="text-truncate d-inline-block" style="max-width: 200px;"><?php echo htmlspecialchars($results[0]['exam_name']); ?></span>
                        </h6>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary" onclick="window.print()">
                                <i class="fas fa-print me-1"></i> <span class="d-none d-sm-inline">Print</span>
                            </button>
                            <button class="btn btn-sm btn-outline-success" id="exportBtn">
                                <i class="fas fa-download me-1"></i> <span class="d-none d-sm-inline">Export</span>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover compact-table" id="resultsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Student Name</th>
                                        <th>Marks</th>
                                        <th class="d-none d-md-table-cell">Total</th>
                                        <th class="d-none d-lg-table-cell">%</th>
                                        <th>Grade</th>
                                        <th>Status</th>
                                        <th class="d-none d-xl-table-cell">Remarks</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $result): 
                                        $percentage = ($result['obtained_marks'] / $result['total_marks']) * 100;
                                        $status = $result['obtained_marks'] >= $result['passing_marks'] ? 'Passed' : 'Failed';
                                        $grade_class = 'grade-' . substr($result['grade'], 0, 1);
                                    ?>
                                        <tr class="<?php echo $grade_class; ?>">
                                            <td><?php echo htmlspecialchars($result['student_id']); ?></td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <span class="text-truncate" style="max-width: 120px;"><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></span>
                                                </div>
                                            </td>
                                            <td><strong><?php echo $result['obtained_marks']; ?></strong></td>
                                            <td class="d-none d-md-table-cell"><?php echo $result['total_marks']; ?></td>
                                            <td class="d-none d-lg-table-cell"><?php echo number_format($percentage, 2); ?>%</td>
                                            <td>
                                                <span class="badge 
                                                    <?php 
                                                    if ($result['grade'] == 'A+' || $result['grade'] == 'A') echo 'bg-success';
                                                    elseif ($result['grade'] == 'B+' || $result['grade'] == 'B') echo 'bg-primary';
                                                    elseif ($result['grade'] == 'C') echo 'bg-warning';
                                                    else echo 'bg-danger';
                                                    ?>">
                                                    <?php echo $result['grade']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $status == 'Passed' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo $status; ?>
                                                </span>
                                                <div class="d-lg-none mt-1">
                                                    <small><?php echo number_format($percentage, 2); ?>%</small>
                                                </div>
                                            </td>
                                            <td class="d-none d-xl-table-cell"><?php echo htmlspecialchars($result['remarks']); ?></td>
                                            <td>
                                                <a href="upload_results.php?exam_id=<?php echo $exam_id; ?>&student_id=<?php echo $result['student_id']; ?>" 
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
                    </div>
                </div>
            <?php elseif (!empty($exam_id) && empty($results)): ?>
                <div class="text-center py-5 empty-state-card">
                    <i class="fas fa-chart-bar fa-4x text-muted mb-4"></i>
                    <h3 class="text-muted">No Results Found</h3>
                    <p class="text-muted">No results have been uploaded for this exam yet.</p>
                    <a href="upload_results.php?exam_id=<?php echo $exam_id; ?>" class="btn btn-primary">
                        <i class="fas fa-upload me-2"></i> Upload Results
                    </a>
                </div>
            <?php elseif (empty($exam_id) && !empty($batch_id)): ?>
                <div class="text-center py-5 empty-state-card">
                    <i class="fas fa-exclamation-circle fa-4x text-muted mb-4"></i>
                    <h3 class="text-muted">Select an Exam</h3>
                    <p class="text-muted">Please select an exam to view results.</p>
                </div>
            <?php else: ?>
                <div class="text-center py-5 empty-state-card">
                    <i class="fas fa-filter fa-4x text-muted mb-4"></i>
                    <h3 class="text-muted">Select Filters</h3>
                    <p class="text-muted">Please select a batch and exam to view results.</p>
                </div>
            <?php endif; ?>
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
            
            const batchSelect = document.getElementById('batchSelect');
            const examSelect = document.getElementById('examSelect');
            
            // Load exams when batch is selected
            batchSelect.addEventListener('change', function() {
                const batchId = this.value;
                
                if (batchId) {
                    // In a real application, you would fetch exams via AJAX
                    // For now, we'll just enable the exam select
                    examSelect.disabled = false;
                    
                    // Submit form to reload with selected batch
                    this.form.submit();
                } else {
                    examSelect.disabled = true;
                    examSelect.innerHTML = '<option value="">Choose Exam...</option>';
                }
            });
            
            // Export functionality
            document.getElementById('exportBtn').addEventListener('click', function() {
                alert('Export functionality would be implemented here. This would generate a CSV or PDF report.');
            });

            // ===== Analytics charts =====
            const gradeLabels = <?php echo json_encode(array_keys($grade_buckets ?? [])); ?>;
            const gradeData = <?php echo json_encode(array_values($grade_buckets ?? [])); ?>;
            const scoreLabels = <?php echo json_encode(array_keys($score_buckets ?? [])); ?>;
            const scoreData = <?php echo json_encode(array_values($score_buckets ?? [])); ?>;

            const gradeChartEl = document.getElementById('gradeDistChart');
            if (gradeChartEl) {
                const gradeTotal = gradeData.reduce((a, b) => a + b, 0);
                if (gradeTotal > 0) {
                    new Chart(gradeChartEl, {
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
                    gradeChartEl.parentElement.innerHTML = '<p class="text-muted text-center small mt-5">No data to display</p>';
                }
            }

            const scoreChartEl = document.getElementById('scoreDistChart');
            if (scoreChartEl) {
                const scoreTotal = scoreData.reduce((a, b) => a + b, 0);
                if (scoreTotal > 0) {
                    new Chart(scoreChartEl, {
                        type: 'bar',
                        data: {
                            labels: scoreLabels,
                            datasets: [{
                                label: 'Students',
                                data: scoreData,
                                backgroundColor: 'rgba(35,76,106, 0.75)',
                                borderRadius: 8,
                                maxBarThickness: 50
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(226,232,240,0.6)' } },
                                x: { grid: { display: false } }
                            }
                        }
                    });
                } else {
                    scoreChartEl.parentElement.innerHTML = '<p class="text-muted text-center small mt-5">No data to display</p>';
                }
            }
        });
    </script>
</body>
</html