<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in and is a trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../log2.php");
    exit();
}

// Get trainer details
$trainer_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
$stmt->execute([$trainer_id]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header("Location: ../../logout_t.php");
    exit();
}

// Robust trainer matching:
$trainer_match_ids = array_values(array_unique(array_filter([
    (int)$trainer['id'],
    (int)$trainer_id
])));
$trainer_placeholders = implode(',', array_fill(0, count($trainer_match_ids), '?'));

// Get assigned batches
$batches_stmt = $db->prepare("
    SELECT DISTINCT b.batch_id, b.batch_name, b.start_date, b.end_date, b.status, 
           COUNT(DISTINCT s.student_id) as student_count
    FROM batches b 
    LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
    LEFT JOIN students s ON b.batch_id = s.batch_name 
    WHERE b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders)
    GROUP BY b.batch_id
");
$batches_stmt->execute(array_merge($trainer_match_ids, $trainer_match_ids));
$assigned_batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming exams for assigned batches
$batch_ids = array_column($assigned_batches, 'batch_id');
$upcoming_exams = [];
if (!empty($batch_ids)) {
    $placeholders = str_repeat('?,', count($batch_ids) - 1) . '?';
    $exams_stmt = $db->prepare("
        SELECT e.*, b.batch_name, 
               (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.exam_id) as results_uploaded,
               (SELECT COUNT(*) FROM students s WHERE s.batch_name = e.batch_id AND s.current_status = 'active') as total_students
        FROM exams e 
        JOIN batches b ON e.batch_id = b.batch_id 
        WHERE e.batch_id IN ($placeholders) 
        AND e.exam_date >= CURDATE()
        ORDER BY e.exam_date ASC
        LIMIT 5
    ");
    $exams_stmt->execute($batch_ids);
    $upcoming_exams = $exams_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent results uploaded
$recent_results = [];
if ($trainer) {
    $recent_results_stmt = $db->prepare("
        SELECT DISTINCT er.*, e.exam_name, e.batch_id, b.batch_name, s.first_name, s.last_name
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        JOIN batches b ON e.batch_id = b.batch_id
        LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
        JOIN students s ON er.student_id = s.student_id
        WHERE b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders)
        ORDER BY er.uploaded_at DESC
        LIMIT 5
    ");
    $recent_results_stmt->execute(array_merge($trainer_match_ids, $trainer_match_ids));
    $recent_results = $recent_results_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Statistics
$total_students = 0;
$total_exams = 0;
$results_pending = 0;

foreach ($assigned_batches as $batch) {
    $total_students += $batch['student_count'];
    
    // Count exams for this batch
    $exam_count_stmt = $db->prepare("SELECT COUNT(*) FROM exams WHERE batch_id = ?");
    $exam_count_stmt->execute([$batch['batch_id']]);
    $total_exams += $exam_count_stmt->fetchColumn();
    
    // Count pending results for this batch
    $pending_stmt = $db->prepare("
        SELECT COUNT(DISTINCT e.exam_id) 
        FROM exams e 
        WHERE e.batch_id = ? 
        AND e.exam_date <= CURDATE()
        AND NOT EXISTS (
            SELECT 1 FROM exam_results er 
            WHERE er.exam_id = e.exam_id 
            LIMIT 1
        )
    ");
    $pending_stmt->execute([$batch['batch_id']]);
    $results_pending += $pending_stmt->fetchColumn();
}

// Build chart-ready data from existing query results (no new DB queries)
$batch_labels = [];
$batch_student_counts = [];
foreach ($assigned_batches as $batch) {
    $batch_labels[] = $batch['batch_name'];
    $batch_student_counts[] = (int)$batch['student_count'];
}

$completion_done = max(0, $total_exams - $results_pending);
$completion_rate_pct = $total_exams > 0 ? round(($completion_done / $total_exams) * 100) : 0;

// Grade distribution from recent results (lightweight, uses data already fetched)
$grade_counts = ['A+' => 0, 'A' => 0, 'B+' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
foreach ($recent_results as $r) {
    if (isset($grade_counts[$r['grade']])) {
        $grade_counts[$r['grade']]++;
    }
}

// Smart insights generated purely from existing stats - no schema/query changes
$insights = [];
if ($results_pending > 0) {
    $insights[] = [
        'icon' => 'fa-triangle-exclamation',
        'tone' => 'amber',
        'text' => "You have <strong>$results_pending exam" . ($results_pending > 1 ? 's' : '') . "</strong> with results still pending upload."
    ];
}
if ($completion_rate_pct >= 80) {
    $insights[] = [
        'icon' => 'fa-circle-check',
        'tone' => 'emerald',
        'text' => "Great pace — you've processed <strong>{$completion_rate_pct}%</strong> of all exam results."
    ];
} elseif ($total_exams > 0) {
    $insights[] = [
        'icon' => 'fa-clock',
        'tone' => 'blue',
        'text' => "Results completion is at <strong>{$completion_rate_pct}%</strong>. Catching up could improve student feedback turnaround."
    ];
}
if (count($assigned_batches) > 0 && $total_students > 0) {
    $avg_batch_size = round($total_students / count($assigned_batches));
    $insights[] = [
        'icon' => 'fa-chalkboard-user',
        'tone' => 'violet',
        'text' => "Average batch size is <strong>{$avg_batch_size} students</strong> across your <strong>" . count($assigned_batches) . "</strong> assigned batches."
    ];
}
if (!empty($upcoming_exams)) {
    $next_exam = $upcoming_exams[0];
    $days_until = max(0, ceil((strtotime($next_exam['exam_date']) - time()) / 86400));
    $insights[] = [
        'icon' => 'fa-calendar-day',
        'tone' => 'pink',
        'text' => "Next up: <strong>" . htmlspecialchars($next_exam['exam_name']) . "</strong> in <strong>{$days_until} day" . ($days_until != 1 ? 's' : '') . "</strong>."
    ];
}
if (empty($insights)) {
    $insights[] = [
        'icon' => 'fa-leaf',
        'tone' => 'emerald',
        'text' => "All caught up! No pending actions right now."
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Exam Analytics Dashboard - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #6f42c1;
            --success: #1cc88a;
            --info: #36b9cc;
            --warning: #f6c23e;
            --danger: #e74a3b;
            --light: #f8f9fc;
            --dark: #5a5c69;
            --trainer-primary: #6610f2;
            --trainer-secondary: #6f42c1;
        }
        
        body {
            background-color: #f8f9fc;
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
                padding: 25px;
            }
        }
        
        .trainer-header {
            background: linear-gradient(135deg, var(--trainer-primary), var(--trainer-secondary));
            color: white;
            padding: 1.5rem 0;
            margin: -15px -15px 1.5rem -15px;
            border-radius: 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        
        @media (min-width: 768px) {
            .trainer-header {
                border-radius: 0 0 20px 20px;
                margin: -20px -20px 2rem -20px;
                padding: 2rem 0;
            }
        }
        
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.08);
            border-left: 5px solid var(--trainer-primary);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            height: 100%;
        }
        
        @media (min-width: 768px) {
            .stats-card {
                padding: 25px;
                border-radius: 15px;
                margin-bottom: 25px;
            }
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--trainer-primary), var(--trainer-secondary));
        }
        
        @media (min-width: 768px) {
            .stats-card:hover {
                transform: translateY(-8px);
                box-shadow: 0 15px 35px rgba(0,0,0,0.15);
            }
        }
        
        .stats-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 15px;
        }
        
        @media (min-width: 768px) {
            .stats-icon {
                width: 60px;
                height: 60px;
                border-radius: 15px;
                font-size: 24px;
            }
        }
        
        .stats-number {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
            line-height: 1;
        }
        
        @media (min-width: 768px) {
            .stats-number {
                font-size: 32px;
            }
        }
        
        .stats-label {
            font-size: 13px;
            color: #6c757d;
            font-weight: 600;
        }
        
        @media (min-width: 768px) {
            .stats-label {
                font-size: 14px;
            }
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        @media (min-width: 768px) {
            .card {
                border-radius: 15px;
                margin-bottom: 25px;
            }
            
            .card:hover {
                transform: translateY(-5px);
                box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            }
        }
        
        .card-header {
            background: linear-gradient(135deg, #fff, #f8f9fc);
            border-bottom: 1px solid rgba(0,0,0,0.05);
            padding: 15px 20px;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 700;
            color: var(--dark);
        }
        
        @media (min-width: 768px) {
            .card-header {
                padding: 20px 25px;
                border-radius: 15px 15px 0 0 !important;
            }
        }
        
        .exam-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        @media (min-width: 768px) {
            .exam-badge {
                padding: 8px 15px;
                font-size: 0.8rem;
            }
        }
        
        .progress {
            height: 6px;
            border-radius: 8px;
            margin-top: 8px;
        }
        
        @media (min-width: 768px) {
            .progress {
                height: 8px;
                border-radius: 10px;
                margin-top: 10px;
            }
        }
        
        .batch-card {
            background: linear-gradient(135deg, #fff, #f8f9fc);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--trainer-primary);
            transition: all 0.3s ease;
        }
        
        @media (min-width: 768px) {
            .batch-card {
                padding: 20px;
                margin-bottom: 20px;
                border-radius: 15px;
            }
            
            .batch-card:hover {
                transform: translateX(5px);
                box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            }
        }
        
        .quick-action-btn {
            background: linear-gradient(135deg, var(--trainer-primary), var(--trainer-secondary));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 12px 15px;
            margin-bottom: 12px;
            transition: all 0.3s ease;
            text-align: left;
            width: 100%;
            font-size: 0.9rem;
        }
        
        @media (min-width: 768px) {
            .quick-action-btn {
                padding: 15px 20px;
                margin-bottom: 15px;
                border-radius: 12px;
                font-size: 1rem;
            }
            
            .quick-action-btn:hover {
                transform: translateY(-3px);
                box-shadow: 0 8px 20px rgba(102, 16, 242, 0.3);
                color: white;
            }
        }
        
        .notification-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .fade-in {
            animation: fadeIn 0.6s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        @media (max-width: 767.98px) {
            .table {
                font-size: 0.875rem;
            }
            
            .table th,
            .table td {
                padding: 0.5rem;
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
            
            .btn-sm {
                padding: 0.25rem 0.5rem;
                font-size: 0.75rem;
            }
            
            .btn-lg {
                padding: 0.5rem 1rem;
                font-size: 1rem;
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
        
        /* Touch-friendly improvements */
        @media (max-width: 1024px) {
            button, 
            .btn,
            .quick-action-btn,
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
        
        /* Mobile batch cards */
        @media (max-width: 767.98px) {
            .batch-card {
                padding: 12px;
                margin-bottom: 12px;
            }
            
            .batch-card h6 {
                font-size: 0.95rem;
                margin-bottom: 0.25rem;
            }
            
            .batch-card .btn-sm {
                padding: 0.2rem 0.4rem;
                font-size: 0.7rem;
            }
        }
    
        /* ===== Same trainer purple/pink dashboard theme: visual-only enhancement ===== */
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

        .trainer-header {
            position: relative;
            overflow: hidden;
            border-radius: 0 0 28px 28px !important;
            margin-bottom: 2rem !important;
            background: var(--dash-main) !important;
            box-shadow: 0 24px 58px rgba(27,60,83,.25) !important;
            border: 1px solid rgba(255,255,255,.18);
        }

        .trainer-header::before {
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

        .trainer-header::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .trainer-header .container-fluid {
            position: relative;
            z-index: 1;
        }

        .trainer-header h1 {
            font-weight: 900 !important;
            letter-spacing: -.03em;
        }

        .trainer-header p {
            color: rgba(255,255,255,.84) !important;
            font-weight: 600;
        }

        .trainer-header .bg-white.bg-opacity-20 {
            background: rgba(255,255,255,.16) !important;
            border: 1px solid rgba(255,255,255,.24);
            border-radius: 999px !important;
            backdrop-filter: blur(14px);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.16);
        }

        .trainer-header .text-black {
            color: #fff !important;
        }

        .stats-card, .card, .batch-card {
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .stats-card {
            border-left: 0 !important;
            overflow: hidden;
        }

        .stats-card::before {
            height: 4px !important;
            background: var(--dash-main) !important;
        }

        .stats-card::after {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            right: -44px;
            top: -48px;
            border-radius: 999px;
            background: rgba(139,92,246,.10);
            pointer-events: none;
        }

        .stats-card > * {
            position: relative;
            z-index: 1;
        }

        .stats-icon {
            border-radius: 18px !important;
            color: #fff !important;
            background: var(--dash-main) !important;
            box-shadow: 0 14px 28px rgba(35,76,106,.20);
        }

        .stats-card:nth-child(1) .stats-icon,
        .row.mb-4 > div:nth-child(1) .stats-icon {
            background: var(--dash-blue) !important;
        }

        .row.mb-4 > div:nth-child(2) .stats-icon {
            background: var(--dash-green) !important;
        }

        .row.mb-4 > div:nth-child(3) .stats-icon {
            background: var(--dash-orange) !important;
        }

        .row.mb-4 > div:nth-child(4) .stats-icon {
            background: var(--dash-main) !important;
        }

        .stats-number {
            font-weight: 900 !important;
            letter-spacing: -.02em;
        }

        .stats-label {
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 900 !important;
            color: #64748b !important;
            font-size: .74rem !important;
        }

        .card {
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
            z-index: 2;
        }

        .card::after {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            right: -70px;
            top: -70px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .75;
            pointer-events: none;
        }

        .card > * {
            position: relative;
            z-index: 3;
        }

        .col-lg-8 .card:nth-child(1) {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-8 .card:nth-child(2) {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-4 .card:nth-child(1) {
            --feature-accent: linear-gradient(90deg, #f59e0b, #f97316, #456882);
            --feature-glow: radial-gradient(circle, rgba(249,115,22,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-4 .card:nth-child(2) {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .col-lg-4 .card:nth-child(3) {
            --feature-accent: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(79,70,229,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .card-header {
            background: linear-gradient(90deg, rgba(255,255,255,.96), rgba(248,250,255,.94)) !important;
            border-bottom: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px 24px 0 0 !important;
            color: #1f2937 !important;
        }

        .card-header h5 {
            font-weight: 900;
            letter-spacing: -.01em;
        }

        .card-header i {
            color: #234C6A;
        }

        .batch-card {
            position: relative;
            overflow: hidden;
            border-left: 0 !important;
            background: linear-gradient(135deg, rgba(255,255,255,.94), rgba(248,250,255,.92)) !important;
        }

        .batch-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: var(--dash-main);
        }

        .batch-card::after {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            right: -50px;
            bottom: -60px;
            border-radius: 999px;
            background: rgba(139,92,246,.08);
        }

        .batch-card > * {
            position: relative;
            z-index: 1;
        }

        .quick-action-btn {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(27,60,83,.96), rgba(35,76,106,.94), rgba(69,104,130,.88)) !important;
            border: 1px solid rgba(255,255,255,.16) !important;
            border-radius: 18px !important;
            box-shadow: 0 14px 28px rgba(35,76,106,.18);
            font-weight: 900;
        }

        .quick-action-btn::after {
            content: "";
            position: absolute;
            inset: auto -40px -55px auto;
            width: 110px;
            height: 110px;
            border-radius: 999px;
            background: rgba(255,255,255,.14);
        }

        .quick-action-btn > * {
            position: relative;
            z-index: 1;
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

        .list-group-item {
            border-color: rgba(226,232,240,.75) !important;
            background: transparent !important;
            transition: all .22s ease;
        }

        .list-group-item:hover {
            background: linear-gradient(90deg, rgba(245,243,255,.86), rgba(255,241,248,.72)) !important;
            border-radius: 16px;
            transform: translateY(-2px);
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
            font-weight: 800 !important;
            letter-spacing: .02em;
        }

        .btn-primary {
            background: var(--dash-main) !important;
            border: none !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
            font-weight: 800;
        }

        .btn-outline-primary {
            color: #234C6A !important;
            border-color: rgba(35,76,106,.36) !important;
            font-weight: 800;
            border-radius: 14px !important;
        }

        .btn-outline-primary:hover {
            color: white !important;
            background: var(--dash-main) !important;
            border-color: transparent !important;
            box-shadow: 0 10px 22px rgba(35,76,106,.18);
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

        .mobile-menu-toggle {
            background: var(--dash-main) !important;
            border-radius: 14px !important;
            box-shadow: 0 12px 28px rgba(35,76,106,.25) !important;
        }

        @media (max-width: 767.98px) {
            .trainer-header {
                border-radius: 0 0 22px 22px !important;
                margin-bottom: 1.4rem !important;
            }

            .stats-card, .card, .batch-card {
                border-radius: 20px !important;
            }

            .card-header {
                border-radius: 20px 20px 0 0 !important;
            }
        }

    
        /* Sidebar consistency fix for trainer pages */
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

        /* ===== Analytics dashboard additions ===== */
        .chart-card {
            background: rgba(255,255,255,.92) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
            padding: 1.25rem;
            position: relative;
            overflow: hidden;
        }

        .chart-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .chart-card-header h5 {
            font-weight: 900;
            font-size: 1rem;
            color: #1f2937;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-card-header h5 i {
            color: #234C6A;
        }

        .chart-canvas-wrap {
            position: relative;
            height: 230px;
        }

        .insight-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 10px;
            background: rgba(255,255,255,.7);
            border: 1px solid rgba(226,232,240,.7);
            transition: all .2s ease;
        }

        .insight-card:hover {
            transform: translateX(4px);
            box-shadow: 0 8px 18px rgba(15,23,42,.08);
        }

        .insight-card:last-child {
            margin-bottom: 0;
        }

        .insight-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: .85rem;
            color: white;
        }

        .insight-icon.amber { background: linear-gradient(135deg, #f59e0b, #f97316); }
        .insight-icon.emerald { background: linear-gradient(135deg, #10b981, #22c55e); }
        .insight-icon.blue { background: linear-gradient(135deg, #234C6A, #456882); }
        .insight-icon.violet { background: linear-gradient(135deg, #234C6A, #234C6A); }
        .insight-icon.pink { background: linear-gradient(135deg, #db2777, #456882); }

        .insight-card p {
            margin: 0;
            font-size: .85rem;
            color: #374151;
            line-height: 1.5;
        }

        .completion-ring-wrap {
            position: relative;
            width: 140px;
            height: 140px;
            margin: 0 auto;
        }

        .completion-ring-number {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .completion-ring-number .num {
            font-size: 1.8rem;
            font-weight: 900;
            color: #1f2937;
        }

        .completion-ring-number .label {
            font-size: .65rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #94a3b8;
            font-weight: 700;
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
<style>

/* ===== Company Source Safe UI Patch: Exam Analytics Dashboard approved theme ===== */
/* CSS-only patch. PHP queries, chart data, links, JS, session, DB logic and IDs untouched. */

:root {
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
    --theme-navy: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%);
    --theme-blue: linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%);
    --theme-purple: linear-gradient(135deg, #6d28d9 0%, #7c3aed 54%, #a78bfa 100%);
    --theme-orange: linear-gradient(135deg, #b45309 0%, #d97706 54%, #f59e0b 100%);
    --theme-green: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%);
}

body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.10), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
    color: #1B3C53 !important;
}

.main-content {
    background: transparent !important;
}

/* Sidebar alignment remains safe */
@media (min-width: 1024px) {
    .main-content {
        margin-left: 16rem !important;
    }
}

aside {
    z-index: 1041 !important;
}

/* Hero banner, same approved company look */
.trainer-header {
    background:
        radial-gradient(circle at 92% 20%, rgba(255,255,255,.18), transparent 26%),
        radial-gradient(circle at 70% 110%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    color: #ffffff !important;
    border-radius: 0 0 28px 28px !important;
    border: 1.5px solid rgba(255,255,255,.20) !important;
    box-shadow:
        0 24px 64px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
}

.trainer-header h1,
.trainer-header p,
.trainer-header small,
.trainer-header .fw-bold,
.trainer-header .text-black,
.trainer-header i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 7px rgba(0,0,0,.16) !important;
}

.trainer-header .bg-white.bg-opacity-20 {
    background: rgba(255,255,255,.16) !important;
    border: 1.4px solid rgba(255,255,255,.32) !important;
    box-shadow:
        0 10px 22px rgba(15,23,42,.12),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    backdrop-filter: blur(12px) !important;
}

.trainer-avatar {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.45px solid rgba(255,255,255,.46) !important;
    box-shadow: 0 12px 26px rgba(0,0,0,.20) !important;
}

/* Top stat cards: colorful approved dashboard style */
.row.mb-4:first-of-type > [class*="col-"] > .stats-card {
    position: relative !important;
    overflow: hidden !important;
    min-height: 142px !important;
    border-radius: 24px !important;
    border: 1.6px solid rgba(255,255,255,.38) !important;
    border-left: 0 !important;
    color: #ffffff !important;
    box-shadow:
        0 20px 42px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.row.mb-4:first-of-type > [class*="col-"] > .stats-card::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    height: auto !important;
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.20), transparent 34%),
        radial-gradient(circle at 4% 100%, rgba(255,255,255,.10), transparent 32%) !important;
    pointer-events: none !important;
}

.row.mb-4:first-of-type > [class*="col-"] > .stats-card::after {
    content: "" !important;
    position: absolute !important;
    right: -38px !important;
    top: -42px !important;
    width: 124px !important;
    height: 124px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.14) !important;
    pointer-events: none !important;
}

.row.mb-4:first-of-type > [class*="col-"] > .stats-card > * {
    position: relative !important;
    z-index: 2 !important;
}

.row.mb-4:first-of-type > [class*="col-"]:nth-child(1) > .stats-card {
    background: var(--theme-blue) !important;
}

.row.mb-4:first-of-type > [class*="col-"]:nth-child(2) > .stats-card {
    background: var(--theme-purple) !important;
}

.row.mb-4:first-of-type > [class*="col-"]:nth-child(3) > .stats-card {
    background: var(--theme-orange) !important;
}

.row.mb-4:first-of-type > [class*="col-"]:nth-child(4) > .stats-card {
    background: var(--theme-navy) !important;
}

.row.mb-4:first-of-type > [class*="col-"] > .stats-card:hover {
    transform: translateY(-5px) !important;
    filter: brightness(1.06) !important;
    box-shadow:
        0 28px 62px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

.row.mb-4:first-of-type > [class*="col-"] > .stats-card .stats-number,
.row.mb-4:first-of-type > [class*="col-"] > .stats-card .stats-label,
.row.mb-4:first-of-type > [class*="col-"] > .stats-card i,
.row.mb-4:first-of-type > [class*="col-"] > .stats-card div {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.16) !important;
}

.row.mb-4:first-of-type > [class*="col-"] > .stats-card .stats-icon {
    width: 54px !important;
    height: 54px !important;
    min-width: 54px !important;
    min-height: 54px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.45px solid rgba(255,255,255,.46) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow:
        0 12px 26px rgba(0,0,0,.20),
        inset 0 1px 0 rgba(255,255,255,.26) !important;
    transition: transform .22s ease, box-shadow .22s ease !important;
}

.row.mb-4:first-of-type > [class*="col-"] > .stats-card:hover .stats-icon {
    transform: translateY(-2px) scale(1.10) rotate(3deg) !important;
    box-shadow:
        0 17px 36px rgba(0,0,0,.25),
        0 0 0 8px rgba(255,255,255,.14),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}

/* Chart cards: remove old harsh inner border, add theme shades */
.chart-card,
.card,
.batch-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.09), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.55px solid rgba(210,193,182,.66) !important;
    border-radius: 24px !important;
    box-shadow:
        0 18px 44px rgba(27,60,83,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    backdrop-filter: blur(16px) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.chart-card:hover,
.card:hover,
.batch-card:hover {
    transform: translateY(-3px) !important;
    border-color: rgba(35,76,106,.42) !important;
    box-shadow:
        0 26px 58px rgba(27,60,83,.16),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
}

/* Clean top accent line for panels */
.chart-card::before,
.card::before {
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882) !important;
    height: 5px !important;
}

.chart-card::after,
.card::after {
    background: radial-gradient(circle, rgba(69,104,130,.13), rgba(210,193,182,.08) 58%, transparent 72%) !important;
}

/* Chart icon badges white/navy theme */
.chart-card-header h5 {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

.chart-card-header h5 i,
.card-header h5 i {
    width: 36px !important;
    height: 36px !important;
    border-radius: 999px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.40), transparent 34%),
        linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow:
        0 12px 26px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.22) !important;
}

/* No inner border around chart canvas. Let the charts breathe, apparently they require oxygen. */
.chart-canvas-wrap {
    background:
        linear-gradient(135deg, rgba(255,255,255,.58), rgba(238,243,246,.46)) !important;
    border: 0 !important;
    border-radius: 18px !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.76) !important;
    padding: .5rem !important;
}

.chart-canvas-wrap canvas,
.completion-ring-wrap canvas {
    border: 0 !important;
    outline: 0 !important;
    box-shadow: none !important;
}

/* Completion ring number readable */
.completion-ring-number .num {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

.completion-ring-number .label {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    font-weight: 900 !important;
}

/* Smart insights */
.insight-card {
    background:
        linear-gradient(135deg, rgba(255,255,255,.78), rgba(238,243,246,.70)) !important;
    border: 1.25px solid rgba(210,193,182,.58) !important;
    border-radius: 18px !important;
    box-shadow: 0 10px 22px rgba(27,60,83,.06) !important;
}

.insight-card:hover {
    transform: translateY(-2px) !important;
    box-shadow: 0 16px 32px rgba(27,60,83,.12) !important;
}

.insight-icon {
    border-radius: 999px !important;
    border: 1.3px solid rgba(255,255,255,.44) !important;
    box-shadow:
        0 10px 22px rgba(27,60,83,.14),
        inset 0 1px 0 rgba(255,255,255,.24) !important;
}

.insight-icon.blue,
.insight-icon.violet,
.insight-icon.pink {
    background: var(--theme-navy) !important;
}

.insight-icon.emerald {
    background: var(--theme-green) !important;
}

.insight-icon.amber {
    background: var(--theme-orange) !important;
}

.insight-card p {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

.badge.bg-primary {
    background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
    color: #ffffff !important;
    border-radius: 999px !important;
}

/* Cards, headers, batch list */
.card-header {
    background:
        linear-gradient(135deg, rgba(238,243,246,.95), rgba(246,241,237,.88)) !important;
    border-bottom: 1px solid rgba(210,193,182,.60) !important;
    color: #1B3C53 !important;
}

.card-header h5 {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

.batch-card {
    border-left: 0 !important;
}

.batch-card::before {
    background: linear-gradient(180deg, #1B3C53, #234C6A, #456882) !important;
    width: 5px !important;
}

.batch-card h6,
.batch-card p,
.batch-card small,
.batch-card span {
    color: #1B3C53 !important;
}

.batch-card .badge.bg-success {
    background: linear-gradient(135deg, #047857, #10b981) !important;
    color: #ffffff !important;
}

.btn-outline-primary {
    color: #1B3C53 !important;
    border-color: rgba(27,60,83,.48) !important;
    background: rgba(255,255,255,.72) !important;
    border-radius: 14px !important;
    font-weight: 900 !important;
}

.btn-outline-primary:hover {
    background: var(--theme-navy) !important;
    color: #ffffff !important;
    border-color: transparent !important;
}

/* Quick actions */
.quick-action-btn {
    background:
        radial-gradient(circle at 94% 12%, rgba(255,255,255,.16), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.4px solid rgba(255,255,255,.22) !important;
    border-radius: 18px !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.18) !important;
    font-weight: 1000 !important;
}

.quick-action-btn:hover {
    transform: translateY(-3px) !important;
    filter: brightness(1.06) !important;
    box-shadow: 0 20px 38px rgba(27,60,83,.24) !important;
}

/* Recent Results: clearer gaps */
.list-group-flush {
    display: flex !important;
    flex-direction: column !important;
    gap: 12px !important;
}

.list-group-item {
    background:
        linear-gradient(135deg, rgba(255,255,255,.78), rgba(238,243,246,.62)) !important;
    border: 1.2px solid rgba(210,193,182,.56) !important;
    border-radius: 16px !important;
    padding: 12px 14px !important;
    box-shadow: 0 8px 18px rgba(27,60,83,.045) !important;
}

.list-group-item:hover {
    background:
        linear-gradient(135deg, rgba(238,243,246,.96), rgba(246,241,237,.82)) !important;
    transform: translateY(-2px) !important;
}

.list-group-item h6,
.list-group-item p,
.list-group-item small {
    color: #1B3C53 !important;
}

/* Tables and progress */
.table-responsive {
    border-radius: 18px !important;
    border: 1px solid rgba(210,193,182,.62) !important;
    background: rgba(255,255,255,.72) !important;
}

.table thead th {
    background:
        linear-gradient(135deg, rgba(238,243,246,.94), rgba(210,193,182,.30)) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

.table-hover tbody tr:hover {
    background:
        linear-gradient(90deg, rgba(238,243,246,.92), rgba(246,241,237,.82)) !important;
}

.progress {
    background: rgba(27,60,83,.11) !important;
}

.progress-bar,
.progress-bar.bg-success {
    background: linear-gradient(90deg, #1B3C53, #456882) !important;
}

/* Empty states */
.text-center.py-4,
.text-center.py-3 {
    background:
        radial-gradient(circle at 50% 0%, rgba(69,104,130,.08), transparent 40%),
        linear-gradient(135deg, rgba(255,253,250,.70), rgba(238,243,246,.55)) !important;
    border-radius: 18px !important;
    border: 1.2px dashed rgba(69,104,130,.26) !important;
}

.text-muted {
    color: #456882 !important;
}

/* Bootstrap buttons */
.btn-primary {
    background: var(--theme-navy) !important;
    border: none !important;
    color: #ffffff !important;
    border-radius: 14px !important;
    font-weight: 900 !important;
    box-shadow: 0 10px 22px rgba(27,60,83,.16) !important;
}

/* Mobile button */
.mobile-menu-toggle {
    background: var(--theme-navy) !important;
    border-radius: 14px !important;
    box-shadow: 0 12px 28px rgba(27,60,83,.25) !important;
}

@media (max-width: 767.98px) {
    .trainer-header {
        border-radius: 0 0 22px 22px !important;
    }

    .stats-card,
    .chart-card,
    .card,
    .batch-card {
        border-radius: 20px !important;
    }

    .row.mb-4:first-of-type > [class*="col-"] > .stats-card {
        min-height: 126px !important;
    }
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
    
    <!-- Include Header and Sidebar -->
    <?php include '../t_sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Trainer Header -->
        <div class="trainer-header">
            <div class="container-fluid px-3 px-md-4">
                <div class="row align-items-center">
                    <div class="col-12 col-md-8">
                        <h1 class="h2 mb-2">Exam Analytics Dashboard</h1>
                        <p class="mb-0 opacity-75">Monitor performance, track trends, and manage your assigned batches and exams</p>
                    </div>
                    <div class="col-12 col-md-4 mt-2 mt-md-0">
                        <div class="d-flex align-items-center justify-content-md-end">
                            <div class="d-flex align-items-center bg-white bg-opacity-20 p-2 rounded-pill">
                                <div class="trainer-avatar me-2 d-none d-md-flex">
                                    <i class="fas fa-user text-black fs-4"></i>
                                </div>
                                <div>
                                    <div class="text-black fw-bold"><?php echo htmlspecialchars($trainer['name']); ?></div>
                                    <small class="text-black opacity-75">Trainer</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid px-3 px-md-4">
            <!-- Quick Stats -->
            <div class="row mb-4">
                <div class="col-6 col-xl-3 col-md-6 mb-3">
                    <div class="stats-card fade-in">
                        <div class="stats-icon bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stats-number text-primary"><?php echo $total_students; ?></div>
                        <div class="stats-label">Total Students</div>
                    </div>
                </div>
                <div class="col-6 col-xl-3 col-md-6 mb-3">
                    <div class="stats-card fade-in" style="animation-delay: 0.1s;">
                        <div class="stats-icon bg-success bg-opacity-10 text-success">
                            <i class="fas fa-file-alt"></i>
                        </div>
                        <div class="stats-number text-success"><?php echo $total_exams; ?></div>
                        <div class="stats-label">Total Exams</div>
                    </div>
                </div>
                <div class="col-6 col-xl-3 col-md-6 mb-3">
                    <div class="stats-card fade-in" style="animation-delay: 0.2s;">
                        <div class="stats-icon bg-warning bg-opacity-10 text-warning">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stats-number text-warning"><?php echo $results_pending; ?></div>
                        <div class="stats-label">Pending Results</div>
                    </div>
                </div>
                <div class="col-6 col-xl-3 col-md-6 mb-3">
                    <div class="stats-card fade-in" style="animation-delay: 0.3s;">
                        <div class="stats-icon bg-info bg-opacity-10 text-info">
                            <i class="fas fa-chalkboard-teacher"></i>
                        </div>
                        <div class="stats-number text-info"><?php echo count($assigned_batches); ?></div>
                        <div class="stats-label">Assigned Batches</div>
                    </div>
                </div>
            </div>

            <!-- Analytics Section: Charts + Smart Insights -->
            <div class="row mb-4">
                <div class="col-lg-4 mb-3 mb-lg-0">
                    <div class="chart-card fade-in h-100" style="animation-delay: 0.1s;">
                        <div class="chart-card-header">
                            <h5><i class="fas fa-circle-notch"></i> Completion Rate</h5>
                        </div>
                        <div class="completion-ring-wrap">
                            <canvas id="completionRingChart"></canvas>
                            <div class="completion-ring-number">
                                <span class="num"><?php echo $completion_rate_pct; ?>%</span>
                                <span class="label">Done</span>
                            </div>
                        </div>
                        <p class="text-center text-muted small mt-3 mb-0">
                            <?php echo $completion_done; ?> of <?php echo $total_exams; ?> exams processed
                        </p>
                    </div>
                </div>
                <div class="col-lg-4 mb-3 mb-lg-0">
                    <div class="chart-card fade-in h-100" style="animation-delay: 0.2s;">
                        <div class="chart-card-header">
                            <h5><i class="fas fa-chart-column"></i> Students per Batch</h5>
                        </div>
                        <div class="chart-canvas-wrap">
                            <canvas id="batchStudentsChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="chart-card fade-in h-100" style="animation-delay: 0.3s;">
                        <div class="chart-card-header">
                            <h5><i class="fas fa-award"></i> Recent Grade Mix</h5>
                        </div>
                        <div class="chart-canvas-wrap">
                            <canvas id="gradeMixChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-12">
                    <div class="chart-card fade-in" style="animation-delay: 0.35s;">
                        <div class="chart-card-header">
                            <h5><i class="fas fa-wand-magic-sparkles"></i> Smart Insights</h5>
                            <span class="badge bg-primary">Auto-generated</span>
                        </div>
                        <div class="row">
                            <?php foreach ($insights as $idx => $insight): ?>
                                <div class="col-md-6">
                                    <div class="insight-card">
                                        <div class="insight-icon <?php echo $insight['tone']; ?>">
                                            <i class="fas <?php echo $insight['icon']; ?>"></i>
                                        </div>
                                        <p><?php echo $insight['text']; ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Left Column -->
                <div class="col-lg-8 mb-4 mb-lg-0">
                    <!-- Assigned Batches -->
                    <div class="card fade-in">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                            <h5 class="mb-0"><i class="fas fa-users-class me-2"></i>My Assigned Batches</h5>
                            <span class="badge bg-primary mt-1 mt-sm-0"><?php echo count($assigned_batches); ?> Batches</span>
                        </div>
                        <div class="card-body">
                            <?php if (count($assigned_batches) > 0): ?>
                                <div class="row">
                                    <?php foreach ($assigned_batches as $batch): ?>
                                        <div class="col-12 col-md-6 mb-3">
                                            <div class="batch-card">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h6 class="mb-0 text-truncate"><?php echo htmlspecialchars($batch['batch_name']); ?></h6>
                                                    <span class="badge <?php echo $batch['status'] == 'ongoing' ? 'bg-success' : 'bg-secondary'; ?> ms-2 flex-shrink-0">
                                                        <?php echo ucfirst($batch['status']); ?>
                                                    </span>
                                                </div>
                                                <p class="text-muted small mb-2">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    <?php echo date('M Y', strtotime($batch['start_date'])); ?> - 
                                                    <?php echo date('M Y', strtotime($batch['end_date'])); ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <span class="text-muted small">
                                                        <i class="fas fa-user-graduate me-1"></i>
                                                        <?php echo $batch['student_count']; ?> students
                                                    </span>
                                                    <a href="trainer_exams.php?batch_id=<?php echo $batch['batch_id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        View Exams
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Batches Assigned</h5>
                                    <p class="text-muted">You haven't been assigned to any batches yet.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Upcoming Exams -->
                    <div class="card fade-in" style="animation-delay: 0.2s;">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Upcoming Exams</h5>
                            <a href="trainer_exams.php" class="btn btn-sm btn-outline-primary mt-1 mt-sm-0">View All</a>
                        </div>
                        <div class="card-body">
                            <?php if (count($upcoming_exams) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Exam Name</th>
                                                <th>Batch</th>
                                                <th>Date</th>
                                                <th>Progress</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($upcoming_exams as $exam): 
                                                $progress = $exam['total_students'] > 0 ? ($exam['results_uploaded'] / $exam['total_students']) * 100 : 0;
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div class="d-flex flex-column">
                                                            <strong class="text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($exam['exam_name']); ?></strong>
                                                            <small class="text-muted text-truncate" style="max-width: 150px;"><?php echo htmlspecialchars($exam['subject']); ?></small>
                                                            <small class="d-block d-md-none">
                                                                <span class="badge exam-badge bg-info mt-1">
                                                                    <?php echo ucfirst(str_replace('_', ' ', $exam['exam_type'])); ?>
                                                                </span>
                                                            </small>
                                                        </div>
                                                    </td>
                                                    <td class="align-middle"><?php echo htmlspecialchars($exam['batch_name']); ?></td>
                                                    <td class="align-middle"><?php echo date('M j, Y', strtotime($exam['exam_date'])); ?></td>
                                                    <td class="align-middle">
                                                        <div class="d-flex align-items-center">
                                                            <div class="progress flex-grow-1 me-2" style="min-width: 60px;">
                                                                <div class="progress-bar bg-success" role="progressbar" 
                                                                     style="width: <?php echo $progress; ?>%"
                                                                     aria-valuenow="<?php echo $progress; ?>" 
                                                                     aria-valuemin="0" 
                                                                     aria-valuemax="100">
                                                                </div>
                                                            </div>
                                                            <small class="d-none d-md-block"><?php echo round($progress); ?>%</small>
                                                        </div>
                                                        <small class="d-md-none text-center mt-1"><?php echo round($progress); ?>%</small>
                                                    </td>
                                                    <td class="align-middle">
                                                        <a href="upload_results.php?exam_id=<?php echo $exam['exam_id']; ?>" 
                                                           class="btn btn-sm btn-primary">
                                                            <i class="fas fa-upload me-1"></i>
                                                            <span class="d-none d-md-inline">Upload</span>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                    <h5 class="text-muted">No Upcoming Exams</h5>
                                    <p class="text-muted">There are no upcoming exams for your batches.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- Quick Actions -->
                    <div class="card fade-in" style="animation-delay: 0.3s;">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body p-2 p-md-3">
                            <div class="d-grid gap-2">
                                <a href="trainer_exams.php" class="quick-action-btn position-relative d-flex align-items-center">
                                    <i class="fas fa-file-alt me-2 fs-5"></i>
                                    <span class="flex-grow-1">Manage Exams</span>
                                    <?php if ($results_pending > 0): ?>
                                        <span class="notification-badge"><?php echo $results_pending; ?></span>
                                    <?php endif; ?>
                                </a>
                                <a href="upload_results.php" class="quick-action-btn d-flex align-items-center">
                                    <i class="fas fa-upload me-2 fs-5"></i>
                                    <span class="flex-grow-1">Upload Results</span>
                                </a>
                                <a href="trainer_results.php" class="quick-action-btn d-flex align-items-center">
                                    <i class="fas fa-chart-bar me-2 fs-5"></i>
                                    <span class="flex-grow-1">View Results</span>
                                </a>
                                <a href="../students/students.php" class="quick-action-btn d-flex align-items-center">
                                    <i class="fas fa-user-graduate me-2 fs-5"></i>
                                    <span class="flex-grow-1">My Students</span>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="card fade-in" style="animation-delay: 0.4s;">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Results</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($recent_results) > 0): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recent_results as $result): ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1 me-2">
                                                    <h6 class="mb-1 text-truncate"><?php echo htmlspecialchars($result['first_name'] . ' ' . $result['last_name']); ?></h6>
                                                    <p class="mb-1 small text-muted text-truncate">
                                                        <?php echo htmlspecialchars($result['exam_name']); ?>
                                                    </p>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, g:i A', strtotime($result['uploaded_at'])); ?>
                                                    </small>
                                                </div>
                                                <div class="text-end flex-shrink-0">
                                                    <span class="badge bg-success d-block mb-1"><?php echo $result['obtained_marks']; ?> marks</span>
                                                    <small class="text-muted">Grade: <?php echo $result['grade']; ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-inbox fa-2x text-muted mb-2"></i>
                                    <p class="text-muted mb-0">No recent activity</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Performance Summary -->
                    <div class="card fade-in" style="animation-delay: 0.5s;">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Performance Overview</h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center">
                                <div class="mb-3">
                                    <div class="display-4 text-primary fw-bold"><?php echo $total_exams > 0 ? round(($total_exams - $results_pending) / $total_exams * 100) : 0; ?>%</div>
                                    <p class="text-muted mb-0">Results Completion Rate</p>
                                </div>
                                <div class="progress mb-3" style="height: 10px;">
                                    <div class="progress-bar bg-success" role="progressbar" 
                                         style="width: <?php echo $total_exams > 0 ? ($total_exams - $results_pending) / $total_exams * 100 : 0; ?>%">
                                    </div>
                                </div>
                                <small class="text-muted">
                                    <?php echo $total_exams - $results_pending; ?> of <?php echo $total_exams; ?> exams processed
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar toggle for mobile
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Add hover effects to cards (desktop only)
            if (window.innerWidth >= 768) {
                const cards = document.querySelectorAll('.card, .stats-card');
                cards.forEach(card => {
                    card.addEventListener('mouseenter', function() {
                        this.style.transform = 'translateY(-5px)';
                    });
                    card.addEventListener('mouseleave', function() {
                        this.style.transform = 'translateY(0)';
                    });
                });
            }
            
            // Auto-refresh notifications
            setInterval(function() {
                console.log('Checking for updates...');
            }, 30000);

            // ===== Analytics charts =====
            const completionPct = <?php echo (int)$completion_rate_pct; ?>;
            const batchLabels = <?php echo json_encode($batch_labels); ?>;
            const batchCounts = <?php echo json_encode($batch_student_counts); ?>;
            const gradeLabels = <?php echo json_encode(array_keys($grade_counts)); ?>;
            const gradeData = <?php echo json_encode(array_values($grade_counts)); ?>;

            const completionRingEl = document.getElementById('completionRingChart');
            if (completionRingEl) {
                new Chart(completionRingEl, {
                    type: 'doughnut',
                    data: {
                        labels: ['Completed', 'Remaining'],
                        datasets: [{
                            data: [completionPct, Math.max(0, 100 - completionPct)],
                            backgroundColor: ['#234C6A', '#e2e8f0'],
                            borderWidth: 0,
                            cutout: '78%'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: { legend: { display: false }, tooltip: { enabled: false } }
                    }
                });
            }

            const batchChartEl = document.getElementById('batchStudentsChart');
            if (batchChartEl && batchLabels.length > 0) {
                new Chart(batchChartEl, {
                    type: 'bar',
                    data: {
                        labels: batchLabels,
                        datasets: [{
                            label: 'Students',
                            data: batchCounts,
                            backgroundColor: 'rgba(35,76,106, 0.75)',
                            borderRadius: 8,
                            maxBarThickness: 36
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
            } else if (batchChartEl) {
                batchChartEl.parentElement.innerHTML = '<p class="text-muted text-center small mt-5">No batch data available</p>';
            }

            const gradeChartEl = document.getElementById('gradeMixChart');
            const gradeTotal = gradeData.reduce((a, b) => a + b, 0);
            if (gradeChartEl && gradeTotal > 0) {
                new Chart(gradeChartEl, {
                    type: 'pie',
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
            } else if (gradeChartEl) {
                gradeChartEl.parentElement.innerHTML = '<p class="text-muted text-center small mt-5">No recent grade data yet</p>';
            }
        });
    </script>
</body>
</html>