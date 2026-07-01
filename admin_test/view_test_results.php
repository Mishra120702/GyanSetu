<?php
// view_results.php
session_start();
require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$testId = $_GET['test_id'] ?? 0;
$error = '';
$testData = null;
$attempts = [];
$questionStats = [];
$overallStats = [];
$studentFilter = $_GET['student'] ?? '';
$statusFilter = $_GET['status'] ?? '';

// Fetch test data with batch name
try {
    $stmt = $db->prepare("
        SELECT 
            t.*,
            b.batch_name,
            COUNT(DISTINCT ta.id) as total_attempts,
            COUNT(DISTINCT CASE WHEN ta.status = 'submitted' THEN ta.id END) as completed_attempts,
            COUNT(DISTINCT ta.student_id) as unique_students,
            u.name as created_by_name
        FROM tests t
        LEFT JOIN batches b ON t.batch_id = b.batch_id
        LEFT JOIN test_attempts ta ON t.id = ta.test_id
        LEFT JOIN users u ON t.created_by = u.id
        WHERE t.id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$testId]);
    $testData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$testData) {
        $error = "Test not found!";
    }
} catch (Exception $e) {
    $error = "Error loading test: " . $e->getMessage();
}

// Fetch test attempts with filtering and batch name
if ($testData) {
    try {
        $whereConditions = ["ta.test_id = ?"];
        $params = [$testId];
        
        if ($studentFilter) {
            $whereConditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_id LIKE ?)";
            $searchTerm = "%$studentFilter%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        if ($statusFilter && in_array($statusFilter, ['submitted', 'in_progress', 'timeout'])) {
            $whereConditions[] = "ta.status = ?";
            $params[] = $statusFilter;
        }
        
        $whereClause = implode(" AND ", $whereConditions);
        
        $attemptsStmt = $db->prepare("
            SELECT 
                ta.*,
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.batch_name_2,
                b.batch_name,
                ROW_NUMBER() OVER (PARTITION BY ta.student_id ORDER BY ta.percentage DESC) as rank_by_student,
                CASE 
                    WHEN ta.percentage >= ? THEN 'Distinction'
                    WHEN ta.percentage >= ? THEN 'First Class'
                    WHEN ta.percentage >= ? THEN 'Second Class'
                    WHEN ta.percentage >= ? THEN 'Pass'
                    ELSE 'Fail'
                END as grade_category,
                CASE 
                    WHEN ta.percentage >= ? THEN 'bg-gradient-to-r from-green-500 to-emerald-500'
                    WHEN ta.percentage >= ? THEN 'bg-gradient-to-r from-blue-500 to-cyan-500'
                    WHEN ta.percentage >= ? THEN 'bg-gradient-to-r from-yellow-500 to-amber-500'
                    WHEN ta.percentage >= ? THEN 'bg-gradient-to-r from-purple-500 to-pink-500'
                    ELSE 'bg-gradient-to-r from-red-500 to-pink-500'
                END as grade_color
            FROM test_attempts ta
            JOIN students s ON ta.student_id = s.student_id
            LEFT JOIN batches b ON s.batch_name_2 = b.batch_id
            WHERE $whereClause
            ORDER BY ta.percentage DESC, ta.submitted_at DESC
        ");
        
        // Add passing marks thresholds for parameters
        $passingPercentage = $testData['passing_marks'] > 0 ? 
            ($testData['passing_marks'] / $testData['total_marks']) * 100 : 0;
        $distinctionThreshold = $passingPercentage + 20;
        $firstClassThreshold = $passingPercentage + 10;
        $secondClassThreshold = $passingPercentage + 5;
        
        // Add grade thresholds to parameters
        $gradeParams = [$distinctionThreshold, $firstClassThreshold, $secondClassThreshold, $passingPercentage];
        $params = array_merge($gradeParams, $gradeParams, $params);
        
        $attemptsStmt->execute($params);
        $attempts = $attemptsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch question statistics
        $questionStatsStmt = $db->prepare("
            SELECT 
                tq.id,
                tq.question_text,
                tq.marks,
                tq.correct_answer,
                COUNT(DISTINCT ta.id) as total_attempts,
                COUNT(DISTINCT CASE WHEN tqa.selected_answer = tq.correct_answer THEN tqa.id END) as correct_attempts,
                COUNT(DISTINCT CASE WHEN tqa.selected_answer != tq.correct_answer AND tqa.selected_answer != '' THEN tqa.id END) as wrong_attempts,
                COUNT(DISTINCT CASE WHEN tqa.selected_answer = '' THEN tqa.id END) as unanswered,
                ROUND(
                    (COUNT(DISTINCT CASE WHEN tqa.selected_answer = tq.correct_answer THEN tqa.id END) * 100.0) / 
                    NULLIF(COUNT(DISTINCT CASE WHEN tqa.selected_answer != '' THEN tqa.id END), 0), 
                    2
                ) as accuracy_rate,
                GROUP_CONCAT(DISTINCT 
                    CASE 
                        WHEN tqa.selected_answer != tq.correct_answer AND tqa.selected_answer != '' 
                        THEN tqa.selected_answer 
                    END 
                    SEPARATOR ','
                ) as common_wrong_answers
            FROM test_questions tq
            LEFT JOIN test_answers tqa ON tq.id = tqa.question_id
            LEFT JOIN test_attempts ta ON tqa.attempt_id = ta.id AND ta.test_id = ?
            WHERE tq.test_id = ?
            GROUP BY tq.id
            ORDER BY tq.question_order ASC
        ");
        $questionStatsStmt->execute([$testId, $testId]);
        $questionStats = $questionStatsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate overall statistics
        if (!empty($attempts)) {
            $completedAttempts = array_filter($attempts, fn($a) => $a['status'] === 'submitted');
            
            if (!empty($completedAttempts)) {
                $overallStats = [
                    'total_attempts' => count($attempts),
                    'completed_attempts' => count($completedAttempts),
                    'avg_percentage' => round(array_sum(array_column($completedAttempts, 'percentage')) / count($completedAttempts), 2),
                    'avg_marks' => round(array_sum(array_column($completedAttempts, 'obtained_marks')) / count($completedAttempts), 2),
                    'max_percentage' => max(array_column($completedAttempts, 'percentage')),
                    'min_percentage' => min(array_column($completedAttempts, 'percentage')),
                    'pass_count' => count(array_filter($completedAttempts, fn($a) => $a['percentage'] >= $passingPercentage)),
                    'fail_count' => count(array_filter($completedAttempts, fn($a) => $a['percentage'] < $passingPercentage)),
                    'avg_time_taken' => round(array_sum(array_column($completedAttempts, 'time_taken_seconds')) / count($completedAttempts) / 60, 2)
                ];
            }
        }
        
    } catch (Exception $e) {
        $error = "Error loading results: " . $e->getMessage();
    }
}

// Handle result download
if (isset($_GET['download']) && $_GET['download'] === 'csv' && $testData) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="test_' . $testId . '_results_' . date('Y-m-d_H-i') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        'Rank', 'Student ID', 'Student Name', 'Batch ID', 'Batch Name', 'Email', 
        'Attempt #', 'Status', 'Start Time', 'Submission Time', 'Time Taken (mins)',
        'Total Questions', 'Attempted', 'Correct', 'Wrong', 'Unanswered',
        'Total Marks', 'Obtained Marks', 'Percentage', 'Grade', 'Pass/Fail'
    ]);
    
    foreach ($attempts as $index => $attempt) {
        $isPass = $attempt['percentage'] >= $passingPercentage ? 'Pass' : 'Fail';
        $timeTakenMins = round($attempt['time_taken_seconds'] / 60, 2);
        
        fputcsv($output, [
            $index + 1,
            $attempt['student_id'],
            $attempt['first_name'] . ' ' . $attempt['last_name'],
            $attempt['batch_name_2'] ?? 'N/A',
            $attempt['batch_name'] ?? 'N/A',
            $attempt['email'],
            $attempt['attempt_number'],
            $attempt['status'],
            $attempt['started_at'] ? date('Y-m-d H:i:s', strtotime($attempt['started_at'])) : 'N/A',
            $attempt['submitted_at'] ? date('Y-m-d H:i:s', strtotime($attempt['submitted_at'])) : 'N/A',
            $timeTakenMins,
            $attempt['total_questions'],
            $attempt['questions_attempted'],
            $attempt['correct_answers'],
            $attempt['wrong_answers'],
            $attempt['total_questions'] - $attempt['questions_attempted'],
            $attempt['total_marks'],
            $attempt['obtained_marks'],
            $attempt['percentage'],
            $attempt['grade_category'],
            $isPass
        ]);
    }
    
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Results - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy-deep:   #1B3C53;
            --navy-mid:    #234C6A;
            --navy-light:  #456882;
            --sand:        #D2C1B6;
            --sand-light:  #e8ddd8;
            --sand-faint:  #f5f0ee;
            --white:       #ffffff;
            --text-primary: #1B3C53;
            --text-secondary: #456882;
            --text-muted:  #7a9ab0;
        }

        * { font-family: 'Inter', 'DM Sans', sans-serif; }

        body {
            background: linear-gradient(145deg, #1B3C53 0%, #234C6A 50%, #1a3347 100%);
            min-height: 100vh;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 50% at 20% 0%, rgba(69,104,130,0.25) 0%, transparent 60%),
                radial-gradient(ellipse 60% 40% at 80% 100%, rgba(210,193,182,0.08) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* ── Cards ── */
        .glass-effect {
            background: rgba(255,255,255,0.97);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(210,193,182,0.25);
            box-shadow: 0 4px 24px rgba(27,60,83,0.10);
        }

        .glass-card {
            background: #ffffff;
            border: 1px solid rgba(210,193,182,0.30);
            box-shadow: 0 2px 16px rgba(27,60,83,0.08);
        }

        /* ── Gradient text ── */
        .gradient-text {
            background: linear-gradient(120deg, #1B3C53 0%, #456882 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* ── Stat cards ── */
        .stats-card {
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            border-left: 4px solid transparent;
        }
        .stats-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 30px rgba(27,60,83,0.14);
        }

        /* ── Table rows ── */
        .result-row { transition: background 0.18s ease, transform 0.18s ease; }
        .result-row:hover {
            background: rgba(27,60,83,0.04);
            transform: translateX(4px);
        }

        /* ── Badges ── */
        .badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .status-submitted  { background: linear-gradient(135deg,#10b981,#34d399); color:#fff; }
        .status-in_progress{ background: linear-gradient(135deg,#f59e0b,#fbbf24); color:#fff; }
        .status-timeout    { background: linear-gradient(135deg,#ef4444,#f87171); color:#fff; }

        /* ── Charts ── */
        .chart-container { position:relative; height:300px; width:100%; }

        /* ── Dropdown ── */
        .dropdown-menu {
            animation: dropdownFade 0.18s ease-out;
            box-shadow: 0 10px 30px rgba(27,60,83,0.15);
        }
        @keyframes dropdownFade {
            from { opacity:0; transform:translateY(-8px); }
            to   { opacity:1; transform:translateY(0);    }
        }

        /* ── Pulse ── */
        .pulse { animation: pulse 2s infinite; }
        @keyframes pulse {
            0%,100% { opacity:1; }
            50%      { opacity:0.65; }
        }

        /* ── Rank medals ── */
        .rank-1 { background: linear-gradient(135deg,#f5c518,#e6a817); }
        .rank-2 { background: linear-gradient(135deg,#b0b8c1,#8d9ba7); }
        .rank-3 { background: linear-gradient(135deg,#cd7f32,#a86523); }

        /* ── Progress bars ── */
        .accuracy-bar  { height:6px; border-radius:3px; background:var(--sand-light); overflow:hidden; }
        .accuracy-fill { height:100%; border-radius:3px; transition:width 1s ease; }

        /* ── Batch badge ── */
        .batch-badge {
            max-width:150px; white-space:nowrap; overflow:hidden;
            text-overflow:ellipsis; display:inline-block; vertical-align:middle;
        }

        /* ── Header banner ── */
        .page-header-banner {
            background: linear-gradient(130deg, var(--navy-deep) 0%, var(--navy-mid) 60%, var(--navy-light) 100%);
            position: relative;
            overflow: hidden;
        }
        .page-header-banner::after {
            content:'';
            position:absolute;
            inset:0;
            background: url("data:image/svg+xml,%3Csvg width='120' height='120' viewBox='0 0 120 120' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='60' cy='60' r='55' stroke='white' stroke-width='0.4' fill='none' opacity='0.06'/%3E%3Ccircle cx='60' cy='60' r='35' stroke='white' stroke-width='0.4' fill='none' opacity='0.05'/%3E%3C/svg%3E") repeat;
            background-size: 120px 120px;
            pointer-events:none;
        }

        /* ── Info pills on header ── */
        .info-pill {
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(210,193,182,0.22);
            backdrop-filter: blur(8px);
            border-radius: 14px;
            padding: 14px 18px;
            transition: background 0.2s;
        }
        .info-pill:hover { background: rgba(255,255,255,0.16); }
        .info-pill .pill-label { color: var(--sand); font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.07em; }
        .info-pill .pill-value { color: #fff; font-size:15px; font-weight:700; margin-top:2px; }
        .info-pill .pill-sub   { color: rgba(210,193,182,0.70); font-size:11px; margin-top:1px; }

        /* ── Metric cards ── */
        .metric-card {
            background:#fff;
            border:1px solid rgba(210,193,182,0.35);
            border-radius:16px;
            padding:22px 20px;
            border-left-width:4px;
            transition: transform 0.22s, box-shadow 0.22s;
        }
        .metric-card:hover { transform:translateY(-3px); box-shadow:0 10px 28px rgba(27,60,83,0.11); }
        .metric-num   { font-size:28px; font-weight:800; color:var(--navy-deep); line-height:1; }
        .metric-label { font-size:12px; font-weight:600; color:var(--text-secondary); margin-top:4px; text-transform:uppercase; letter-spacing:0.05em; }
        .metric-sub   { font-size:11px; color:var(--text-muted); margin-top:6px; }
        .metric-icon  { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; }

        /* ── Section heading ── */
        .section-heading { font-size:16px; font-weight:700; color:var(--navy-deep); }
        .section-sub     { font-size:12px; color:var(--text-muted); margin-top:2px; }

        /* ── Filter card ── */
        .filter-input {
            width:100%; padding:10px 14px;
            background:#fafafa;
            border:1px solid rgba(210,193,182,0.50);
            border-radius:10px;
            font-size:13px;
            color:var(--navy-deep);
            transition: border-color 0.18s, box-shadow 0.18s;
            outline:none;
        }
        .filter-input:focus {
            border-color: var(--navy-mid);
            box-shadow: 0 0 0 3px rgba(35,76,106,0.10);
        }

        /* ── Buttons ── */
        .btn-primary {
            background: linear-gradient(130deg, var(--navy-deep) 0%, var(--navy-mid) 100%);
            color:#fff; border:none; border-radius:10px; padding:10px 20px;
            font-size:13px; font-weight:600; cursor:pointer;
            transition: opacity 0.18s, transform 0.15s, box-shadow 0.18s;
            box-shadow: 0 3px 10px rgba(27,60,83,0.25);
        }
        .btn-primary:hover { opacity:0.90; transform:translateY(-1px); box-shadow:0 6px 18px rgba(27,60,83,0.30); }

        .btn-secondary {
            background: var(--sand-faint); color:var(--navy-deep);
            border:1px solid rgba(210,193,182,0.50);
            border-radius:10px; padding:10px 20px;
            font-size:13px; font-weight:600; cursor:pointer;
            transition: background 0.18s, transform 0.15s;
        }
        .btn-secondary:hover { background:var(--sand-light); transform:translateY(-1px); }

        .btn-success {
            background: linear-gradient(130deg,#10b981,#059669);
            color:#fff; border:none; border-radius:10px; padding:10px 20px;
            font-size:13px; font-weight:600; cursor:pointer;
            box-shadow:0 3px 10px rgba(16,185,129,0.25);
            transition: opacity 0.18s, transform 0.15s;
        }
        .btn-success:hover { opacity:0.90; transform:translateY(-1px); }

        /* ── Table header ── */
        .tbl-head { background: linear-gradient(130deg, var(--navy-deep) 0%, var(--navy-mid) 100%); }
        .tbl-head th { color:rgba(255,255,255,0.85); font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.07em; padding:14px 16px; }
        .tbl-body td { padding:14px 16px; border-bottom:1px solid rgba(210,193,182,0.20); font-size:13px; vertical-align:middle; }
        .tbl-body tr:last-child td { border-bottom:none; }

        /* ── Question block ── */
        .q-block {
            background: var(--sand-faint);
            border:1px solid rgba(210,193,182,0.40);
            border-radius:14px;
            padding:18px;
            transition: background 0.18s;
        }
        .q-block:hover { background:#fff; }
        .q-mini-card { background:#fff; border:1px solid rgba(210,193,182,0.30); border-radius:10px; padding:12px; text-align:center; }

        /* ── Modal ── */
        #attemptModal .modal-inner {
            background:#fff;
            border-radius:20px;
            border:1px solid rgba(210,193,182,0.30);
            box-shadow:0 24px 60px rgba(27,60,83,0.22);
        }
        .modal-header {
            background: linear-gradient(130deg, var(--navy-deep) 0%, var(--navy-mid) 100%);
            border-radius:20px 20px 0 0;
            padding:20px 24px;
        }

        /* ── Action icon buttons ── */
        .icon-btn {
            width:32px; height:32px; border-radius:8px;
            display:inline-flex; align-items:center; justify-content:center;
            transition: background 0.15s, transform 0.15s;
            cursor:pointer; border:none; background:transparent;
        }
        .icon-btn-view  { color:var(--navy-mid); }
        .icon-btn-view:hover  { background:rgba(35,76,106,0.10); transform:scale(1.1); }
        .icon-btn-dl    { color:#10b981; }
        .icon-btn-dl:hover    { background:rgba(16,185,129,0.10); transform:scale(1.1); }

        /* ── Scrollbar ── */
        ::-webkit-scrollbar { width:6px; height:6px; }
        ::-webkit-scrollbar-track { background:var(--sand-faint); }
        ::-webkit-scrollbar-thumb { background:var(--navy-light); border-radius:3px; }
    </style>
</head>
<body class="min-h-screen">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen p-4 md:p-8" style="position:relative;z-index:1;">
        <div class="max-w-7xl mx-auto">
            <!-- Header Banner -->
            <div class="page-header-banner rounded-2xl p-6 md:p-8 mb-7 relative z-10">
                <div class="flex flex-col md:flex-row md:items-center justify-between relative z-10">
                    <div>
                        <div class="flex items-center mb-2">
                            <div style="width:36px;height:36px;background:rgba(210,193,182,0.18);border-radius:10px;display:flex;align-items:center;justify-content:center;margin-right:12px;">
                                <i class="fas fa-chart-bar" style="color:var(--sand);font-size:16px;"></i>
                            </div>
                            <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.10em;color:var(--sand);opacity:0.80;">Admin · Analytics</span>
                        </div>
                        <h1 style="font-size:clamp(22px,3.5vw,32px);font-weight:800;color:#fff;letter-spacing:-0.02em;line-height:1.15;margin-bottom:4px;">
                            Test Results Analytics
                        </h1>
                        <p style="color:rgba(210,193,182,0.75);font-size:13px;">Detailed performance analysis and statistics</p>
                    </div>
                    <div class="mt-5 md:mt-0 flex space-x-3">
                        <a href="admin_dashboard.php"
                           style="display:inline-flex;align-items:center;background:rgba(255,255,255,0.10);border:1px solid rgba(210,193,182,0.25);color:rgba(255,255,255,0.88);padding:10px 18px;border-radius:11px;font-size:13px;font-weight:600;text-decoration:none;transition:background 0.2s;"
                           onmouseover="this.style.background='rgba(255,255,255,0.18)'" onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                            <i class="fas fa-arrow-left mr-2" style="font-size:12px;"></i> Dashboard
                        </a>
                        <a href="edit_test.php?test_id=<?= $testId ?>"
                           style="display:inline-flex;align-items:center;background:var(--sand);color:var(--navy-deep);padding:10px 18px;border-radius:11px;font-size:13px;font-weight:700;text-decoration:none;transition:opacity 0.2s;box-shadow:0 3px 12px rgba(210,193,182,0.30);"
                           onmouseover="this.style.opacity='0.88'" onmouseout="this.style.opacity='1'">
                            <i class="fas fa-edit mr-2" style="font-size:12px;"></i> Edit Test
                        </a>
                    </div>
                </div>

                <?php if ($testData): ?>
                <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-3 relative z-10">
                    <!-- Test Title pill -->
                    <div class="info-pill">
                        <div class="pill-label"><i class="fas fa-file-alt mr-1"></i> Test Title</div>
                        <div class="pill-value" style="font-size:clamp(12px,1.4vw,15px);" title="<?= htmlspecialchars($testData['title']) ?>">
                            <?= htmlspecialchars(mb_strimwidth($testData['title'], 0, 40, '…')) ?>
                        </div>
                        <div class="pill-sub"><?= htmlspecialchars($testData['subject']) ?></div>
                    </div>
                    <!-- Batch pill -->
                    <div class="info-pill">
                        <div class="pill-label"><i class="fas fa-users mr-1"></i> Batch</div>
                        <?php if ($testData['batch_name']): ?>
                            <div class="pill-value" style="font-size:clamp(12px,1.4vw,15px);" title="<?= htmlspecialchars($testData['batch_name']) ?>">
                                <?= htmlspecialchars(mb_strimwidth($testData['batch_name'], 0, 30, '…')) ?>
                            </div>
                            <?php if ($testData['batch_id']): ?>
                                <div class="pill-sub">ID: <?= htmlspecialchars($testData['batch_id']) ?></div>
                            <?php endif; ?>
                        <?php elseif ($testData['batch_id']): ?>
                            <div class="pill-value"><?= htmlspecialchars($testData['batch_id']) ?></div>
                        <?php else: ?>
                            <div class="pill-value" style="opacity:0.55;">No Batch</div>
                        <?php endif; ?>
                    </div>
                    <!-- Period pill -->
                    <div class="info-pill">
                        <div class="pill-label"><i class="fas fa-calendar-alt mr-1"></i> Test Period</div>
                        <div class="pill-value" style="font-size:13px;">
                            <?= $testData['start_date'] ? date('M d, Y H:i', strtotime($testData['start_date'])) : 'No start date' ?>
                        </div>
                        <?php if ($testData['end_date']): ?>
                            <div class="pill-sub">→ <?= date('M d, Y H:i', strtotime($testData['end_date'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <!-- Performance pill -->
                    <div class="info-pill">
                        <div class="pill-label"><i class="fas fa-chart-line mr-1"></i> Avg Performance</div>
                        <div class="pill-value"><?= $overallStats['avg_percentage'] ?? '0' ?>%</div>
                        <div class="pill-sub"><?= $overallStats['pass_count'] ?? '0' ?> students passed</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($error): ?>
                <div style="background:linear-gradient(130deg,#ef4444,#dc2626);color:#fff;padding:16px 22px;border-radius:14px;margin-bottom:24px;display:flex;align-items:center;box-shadow:0 4px 14px rgba(239,68,68,0.25);">
                    <i class="fas fa-exclamation-triangle" style="font-size:18px;margin-right:12px;opacity:0.9;"></i>
                    <span style="font-size:13px;font-weight:600;"><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($testData): ?>
            
            <!-- Overall Statistics -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-5 mb-7">
                <div class="metric-card" style="border-left-color:#234C6A;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <div class="metric-num"><?= $overallStats['total_attempts'] ?? 0 ?></div>
                            <div class="metric-label">Total Attempts</div>
                        </div>
                        <div class="metric-icon" style="background:rgba(35,76,106,0.10);">
                            <i class="fas fa-users" style="color:#234C6A;"></i>
                        </div>
                    </div>
                    <div class="metric-sub"><i class="fas fa-check-circle" style="color:#10b981;margin-right:4px;"></i><?= $overallStats['completed_attempts'] ?? 0 ?> completed</div>
                </div>

                <div class="metric-card" style="border-left-color:#10b981;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <div class="metric-num"><?= $overallStats['avg_percentage'] ?? '0' ?>%</div>
                            <div class="metric-label">Average Score</div>
                        </div>
                        <div class="metric-icon" style="background:rgba(16,185,129,0.10);">
                            <i class="fas fa-chart-line" style="color:#10b981;"></i>
                        </div>
                    </div>
                    <div class="metric-sub"><i class="fas fa-trophy" style="color:#f59e0b;margin-right:4px;"></i>High: <?= $overallStats['max_percentage'] ?? '0' ?>%</div>
                </div>

                <div class="metric-card" style="border-left-color:#f59e0b;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <div class="metric-num"><?= $overallStats['pass_count'] ?? 0 ?></div>
                            <div class="metric-label">Students Passed</div>
                        </div>
                        <div class="metric-icon" style="background:rgba(245,158,11,0.10);">
                            <i class="fas fa-graduation-cap" style="color:#f59e0b;"></i>
                        </div>
                    </div>
                    <?php if (($overallStats['total_attempts'] ?? 0) > 0): ?>
                    <div style="margin-top:10px;">
                        <div class="accuracy-bar">
                            <div class="accuracy-fill" style="background:linear-gradient(90deg,#10b981,#34d399);width:<?= round(($overallStats['pass_count'] / $overallStats['total_attempts']) * 100, 1) ?>%;"></div>
                        </div>
                        <div class="metric-sub" style="margin-top:4px;"><?= round(($overallStats['pass_count'] / $overallStats['total_attempts']) * 100, 1) ?>% pass rate</div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="metric-card" style="border-left-color:#ef4444;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div>
                            <div class="metric-num"><?= $overallStats['fail_count'] ?? 0 ?></div>
                            <div class="metric-label">Students Failed</div>
                        </div>
                        <div class="metric-icon" style="background:rgba(239,68,68,0.10);">
                            <i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i>
                        </div>
                    </div>
                    <div class="metric-sub"><i class="fas fa-clock" style="color:#456882;margin-right:4px;"></i>Avg time: <?= $overallStats['avg_time_taken'] ?? '0' ?> mins</div>
                </div>
            </div>
            
            <!-- Filters and Actions -->
            <div class="glass-card rounded-2xl p-6 mb-7" style="border-radius:16px;">
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:20px;gap:12px;">
                    <div>
                        <div class="section-heading"><i class="fas fa-filter" style="color:#456882;margin-right:8px;"></i>Filter Results</div>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="?test_id=<?= $testId ?>&download=csv" class="btn-success" style="display:inline-flex;align-items:center;text-decoration:none;padding:9px 16px;">
                            <i class="fas fa-download" style="margin-right:7px;font-size:12px;"></i> Export CSV
                        </a>
                        <button onclick="printResults()" class="btn-primary" style="display:inline-flex;align-items:center;">
                            <i class="fas fa-print" style="margin-right:7px;font-size:12px;"></i> Print Report
                        </button>
                    </div>
                </div>

                <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;">
                    <input type="hidden" name="test_id" value="<?= $testId ?>">

                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#456882;margin-bottom:7px;">
                            <i class="fas fa-user-graduate" style="margin-right:5px;"></i> Search Student
                        </label>
                        <input type="text" name="student" value="<?= htmlspecialchars($studentFilter) ?>"
                               placeholder="Name, ID, or Email" class="filter-input">
                    </div>

                    <div>
                        <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#456882;margin-bottom:7px;">
                            <i class="fas fa-check-circle" style="margin-right:5px;"></i> Filter by Status
                        </label>
                        <select name="status" class="filter-input">
                            <option value="">All Statuses</option>
                            <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="timeout" <?= $statusFilter === 'timeout' ? 'selected' : '' ?>>Timeout</option>
                        </select>
                    </div>

                    <div style="display:flex;align-items:flex-end;gap:10px;">
                        <button type="submit" class="btn-primary" style="flex:1;display:flex;align-items:center;justify-content:center;">
                            <i class="fas fa-search" style="margin-right:7px;font-size:12px;"></i> Apply
                        </button>
                        <a href="?test_id=<?= $testId ?>" class="btn-secondary" style="flex:1;display:flex;align-items:center;justify-content:center;text-decoration:none;">
                            <i class="fas fa-redo" style="margin-right:7px;font-size:12px;"></i> Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Charts Section -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:28px;">
                <div class="glass-card" style="border-radius:16px;padding:24px;">
                    <div style="display:flex;align-items:center;margin-bottom:20px;">
                        <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(130deg,#1B3C53,#456882);display:flex;align-items:center;justify-content:center;margin-right:12px;">
                            <i class="fas fa-chart-pie" style="color:#fff;font-size:15px;"></i>
                        </div>
                        <div>
                            <div class="section-heading">Score Distribution</div>
                            <div class="section-sub">Performance across grade categories</div>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="scoreDistributionChart"></canvas></div>
                </div>

                <div class="glass-card" style="border-radius:16px;padding:24px;">
                    <div style="display:flex;align-items:center;margin-bottom:20px;">
                        <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(130deg,#234C6A,#456882);display:flex;align-items:center;justify-content:center;margin-right:12px;">
                            <i class="fas fa-chart-line" style="color:#fff;font-size:15px;"></i>
                        </div>
                        <div>
                            <div class="section-heading">Time vs Score Analysis</div>
                            <div class="section-sub">Correlation between time taken and marks</div>
                        </div>
                    </div>
                    <div class="chart-container"><canvas id="timeScoreChart"></canvas></div>
                </div>
            </div>
            
            <!-- Results Table -->
            <div class="glass-card mb-7" style="border-radius:16px;padding:24px;">
                <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:20px;gap:10px;">
                    <div>
                        <div class="section-heading">Detailed Results</div>
                        <div class="section-sub"><?= count($attempts) ?> attempt(s) found</div>
                    </div>
                    <div style="font-size:12px;color:#7a9ab0;display:flex;align-items:center;">
                        <i class="fas fa-info-circle" style="margin-right:6px;"></i>
                        Click any row to view attempt details
                    </div>
                </div>

                <div style="overflow-x:auto;border-radius:12px;border:1px solid rgba(210,193,182,0.25);">
                    <table style="width:100%;border-collapse:collapse;">
                        <thead>
                            <tr class="tbl-head">
                                <th style="text-align:left;">Rank</th>
                                <th style="text-align:left;">Student</th>
                                <th style="text-align:left;">Batch</th>
                                <th style="text-align:left;">Attempt</th>
                                <th style="text-align:left;">Score</th>
                                <th style="text-align:left;">Time Taken</th>
                                <th style="text-align:left;">Status</th>
                                <th style="text-align:left;">Grade</th>
                                <th style="text-align:left;">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="tbl-body">
                            <?php if (empty($attempts)): ?>
                                <tr>
                                    <td colspan="9" style="padding:48px;text-align:center;color:#7a9ab0;">
                                        <i class="fas fa-inbox" style="font-size:36px;margin-bottom:12px;display:block;opacity:0.35;"></i>
                                        <span style="font-size:14px;font-weight:500;">No attempts found for this test</span>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attempts as $index => $attempt): ?>
                                <tr class="result-row" style="cursor:pointer;"
                                    onclick="viewAttemptDetails(<?= $attempt['id'] ?>)">
                                    <td>
                                        <?php if ($index < 3): ?>
                                            <div class="<?= "rank-" . ($index + 1) ?>" style="width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:12px;">
                                                <?= $index + 1 ?>
                                            </div>
                                        <?php else: ?>
                                            <div style="width:30px;height:30px;border-radius:50%;background:var(--sand-light);display:flex;align-items:center;justify-content:center;color:var(--navy-light);font-weight:700;font-size:12px;">
                                                <?= $index + 1 ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;color:var(--navy-deep);font-size:13px;">
                                            <?= htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']) ?>
                                        </div>
                                        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= $attempt['student_id'] ?></div>
                                    </td>
                                    <td>
                                        <?php if ($attempt['batch_name']): ?>
                                            <div class="batch-badge" style="padding:3px 10px;background:rgba(35,76,106,0.08);color:#234C6A;border-radius:20px;font-size:11px;font-weight:600;border:1px solid rgba(35,76,106,0.14);"
                                                 title="<?= htmlspecialchars($attempt['batch_name']) ?> (<?= htmlspecialchars($attempt['batch_name_2'] ?? 'N/A') ?>)">
                                                <i class="fas fa-users" style="margin-right:4px;color:#456882;"></i>
                                                <span style="max-width:100px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:inline-block;vertical-align:middle;">
                                                    <?= htmlspecialchars(strlen($attempt['batch_name']) > 15 ? substr($attempt['batch_name'], 0, 15) . '…' : $attempt['batch_name']) ?>
                                                </span>
                                                <?php if ($attempt['batch_name_2']): ?>
                                                    <span style="font-size:10px;color:#456882;margin-left:3px;">(<?= htmlspecialchars($attempt['batch_name_2']) ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($attempt['batch_name_2']): ?>
                                            <span style="padding:3px 10px;background:var(--sand-faint);color:var(--navy-light);border-radius:20px;font-size:11px;font-weight:600;border:1px solid rgba(210,193,182,0.35);">
                                                <i class="fas fa-users" style="margin-right:4px;opacity:0.6;"></i>
                                                <?= htmlspecialchars($attempt['batch_name_2']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span style="padding:3px 10px;background:var(--sand-faint);color:var(--text-muted);border-radius:20px;font-size:11px;font-weight:600;">
                                                <i class="fas fa-times" style="margin-right:4px;"></i>No Batch
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;color:var(--navy-deep);font-size:13px;">Attempt #<?= $attempt['attempt_number'] ?></div>
                                        <?php if ($attempt['submitted_at']): ?>
                                            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;"><?= date('M d, H:i', strtotime($attempt['submitted_at'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="font-weight:800;font-size:16px;color:<?= $attempt['percentage'] >= $passingPercentage ? '#10b981' : '#ef4444' ?>;">
                                            <?= $attempt['percentage'] ?>%
                                        </div>
                                        <div style="font-size:11px;color:var(--text-muted);margin-top:1px;"><?= $attempt['obtained_marks'] ?>/<?= $attempt['total_marks'] ?> marks</div>
                                    </td>
                                    <td>
                                        <div style="font-weight:600;color:var(--navy-deep);font-size:13px;"><?= round($attempt['time_taken_seconds'] / 60, 1) ?> mins</div>
                                        <div style="font-size:11px;color:var(--text-muted);margin-top:1px;"><?= $attempt['questions_attempted'] ?>/<?= $attempt['total_questions'] ?> questions</div>
                                    </td>
                                    <td>
                                        <span class="badge <?= 'status-' . $attempt['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $attempt['status'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="padding:4px 11px;border-radius:20px;font-size:11px;font-weight:700;color:#fff;" class="<?= $attempt['grade_color'] ?>">
                                            <?= $attempt['grade_category'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:4px;">
                                            <button onclick="event.stopPropagation(); viewAttemptDetails(<?= $attempt['id'] ?>)"
                                                    class="icon-btn icon-btn-view" title="View Details">
                                                <i class="fas fa-eye" style="font-size:13px;"></i>
                                            </button>
                                            <button onclick="event.stopPropagation(); downloadAttempt(<?= $attempt['id'] ?>)"
                                                    class="icon-btn icon-btn-dl" title="Download Report">
                                                <i class="fas fa-download" style="font-size:13px;"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($attempts)): ?>
                <div style="margin-top:16px;display:flex;align-items:center;justify-content:space-between;font-size:12px;color:var(--text-muted);padding-top:12px;border-top:1px solid rgba(210,193,182,0.25);">
                    <span>Showing <?= count($attempts) ?> result(s)</span>
                    <button onclick="scrollToTop()" style="color:var(--navy-mid);font-weight:600;background:none;border:none;cursor:pointer;font-size:12px;">
                        <i class="fas fa-arrow-up" style="margin-right:4px;"></i> Back to top
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Question Analytics -->
            <?php if (!empty($questionStats)): ?>
            <div class="glass-card" style="border-radius:16px;padding:24px;">
                <div style="display:flex;align-items:center;margin-bottom:20px;">
                    <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(130deg,#1B3C53,#234C6A);display:flex;align-items:center;justify-content:center;margin-right:12px;">
                        <i class="fas fa-question-circle" style="color:#fff;font-size:15px;"></i>
                    </div>
                    <div>
                        <div class="section-heading">Question-wise Analytics</div>
                        <div class="section-sub">Performance breakdown for each question</div>
                    </div>
                </div>

                <div style="display:flex;flex-direction:column;gap:14px;">
                    <?php foreach ($questionStats as $qIndex => $question): ?>
                    <div class="q-block">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;gap:12px;">
                            <div style="flex:1;">
                                <div style="display:flex;align-items:center;margin-bottom:8px;">
                                    <div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(130deg,#1B3C53,#456882);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;margin-right:10px;flex-shrink:0;">
                                        <?= $qIndex + 1 ?>
                                    </div>
                                    <span style="font-weight:700;color:var(--navy-deep);font-size:13px;">
                                        Question #<?= $qIndex + 1 ?>
                                        <span style="font-weight:400;color:var(--text-muted);margin-left:8px;">(<?= $question['marks'] ?> marks)</span>
                                    </span>
                                </div>
                                <p style="color:#456882;font-size:13px;line-height:1.5;margin-left:38px;"><?= htmlspecialchars(substr($question['question_text'], 0, 200)) ?><?= strlen($question['question_text']) > 200 ? '…' : '' ?></p>
                            </div>
                            <div style="text-align:right;flex-shrink:0;">
                                <div style="font-size:22px;font-weight:800;color:<?= $question['accuracy_rate'] > 70 ? '#10b981' : ($question['accuracy_rate'] > 40 ? '#f59e0b' : '#ef4444') ?>;">
                                    <?= $question['accuracy_rate'] ?>%
                                </div>
                                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);">Accuracy</div>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
                            <div class="q-mini-card">
                                <div style="font-size:17px;font-weight:800;color:#10b981;"><?= $question['correct_attempts'] ?></div>
                                <div style="font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-top:2px;">Correct</div>
                            </div>
                            <div class="q-mini-card">
                                <div style="font-size:17px;font-weight:800;color:#ef4444;"><?= $question['wrong_attempts'] ?></div>
                                <div style="font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-top:2px;">Wrong</div>
                            </div>
                            <div class="q-mini-card">
                                <div style="font-size:17px;font-weight:800;color:var(--navy-light);"><?= $question['unanswered'] ?></div>
                                <div style="font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-top:2px;">Unanswered</div>
                            </div>
                            <div class="q-mini-card">
                                <div style="font-size:17px;font-weight:800;color:var(--navy-deep);"><?= $question['total_attempts'] ?></div>
                                <div style="font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;margin-top:2px;">Total</div>
                            </div>
                        </div>

                        <?php if ($question['common_wrong_answers']): ?>
                        <div style="margin-top:12px;padding:8px 12px;background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.20);border-radius:8px;font-size:12px;color:#92400e;">
                            <i class="fas fa-exclamation-triangle" style="color:#f59e0b;margin-right:6px;"></i>
                            Common wrong answers: <strong><?= strtoupper(str_replace(',', ', ', $question['common_wrong_answers'])) ?></strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Attempt Details Modal -->
    <div id="attemptModal" style="position:fixed;inset:0;background:rgba(27,60,83,0.55);backdrop-filter:blur(4px);display:none;z-index:50;overflow-y:auto;">
        <div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;">
            <div class="modal-inner" style="width:100%;max-width:860px;max-height:90vh;overflow:hidden;border-radius:20px;border:1px solid rgba(210,193,182,0.25);box-shadow:0 24px 60px rgba(27,60,83,0.25);">
                <div class="modal-header">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;">
                            <div style="width:32px;height:32px;background:rgba(210,193,182,0.18);border-radius:8px;display:flex;align-items:center;justify-content:center;margin-right:10px;">
                                <i class="fas fa-file-alt" style="color:var(--sand);font-size:13px;"></i>
                            </div>
                            <h3 style="font-size:16px;font-weight:700;color:#fff;">Attempt Details</h3>
                        </div>
                        <button onclick="closeModal()" style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.10);border:none;color:rgba(255,255,255,0.70);cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:background 0.15s;"
                                onmouseover="this.style.background='rgba(255,255,255,0.20)'" onmouseout="this.style.background='rgba(255,255,255,0.10)'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <div style="padding:24px;overflow-y:auto;max-height:calc(90vh - 80px);" id="attemptDetailsContent">
                    <!-- Dynamic content will be loaded here -->
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            initializeCharts();
            initializeAccuracyBars();
            initializeBatchTooltips();
        });
        
        function initializeCharts() {
            const completedAttempts = <?= json_encode(array_filter($attempts, fn($a) => $a['status'] === 'submitted')) ?>;
            
            // Score Distribution Chart
            const scoreCtx = document.getElementById('scoreDistributionChart')?.getContext('2d');
            if (scoreCtx && completedAttempts.length > 0) {
                const scores = completedAttempts.map(a => a.percentage);
                const bins = [0, 25, 50, 75, 100];
                const data = new Array(bins.length - 1).fill(0);
                
                scores.forEach(score => {
                    for (let i = 0; i < bins.length - 1; i++) {
                        if (score >= bins[i] && score < bins[i + 1]) {
                            data[i]++;
                            break;
                        }
                    }
                });
                
                new Chart(scoreCtx, {
                    type: 'bar',
                    data: {
                        labels: ['0-25%', '25-50%', '50-75%', '75-100%'],
                        datasets: [{
                            label: 'Number of Students',
                            data: data,
                            backgroundColor: [
                                'rgba(239, 68, 68, 0.75)',
                                'rgba(210, 193, 182, 0.80)',
                                'rgba(69, 104, 130, 0.75)',
                                'rgba(16, 185, 129, 0.75)'
                            ],
                            borderColor: [
                                'rgb(239, 68, 68)',
                                'rgb(180, 160, 148)',
                                'rgb(69, 104, 130)',
                                'rgb(16, 185, 129)'
                            ],
                            borderWidth: 1,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `${context.dataset.label}: ${context.raw}`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Number of Students'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Score Range'
                                }
                            }
                        }
                    }
                });
            }
            
            // Time vs Score Chart
            const timeCtx = document.getElementById('timeScoreChart')?.getContext('2d');
            if (timeCtx && completedAttempts.length > 0) {
                const timeData = completedAttempts.map(a => ({
                    x: a.time_taken_seconds / 60, // Convert to minutes
                    y: a.percentage,
                    student: a.first_name + ' ' + a.last_name,
                    score: a.obtained_marks + '/' + a.total_marks,
                    batch: a.batch_name || a.batch_name_2 || 'No Batch'
                }));
                
                new Chart(timeCtx, {
                    type: 'scatter',
                    data: {
                        datasets: [{
                            label: 'Students',
                            data: timeData,
                            backgroundColor: 'rgba(35, 76, 106, 0.70)',
                            borderColor: 'rgb(27, 60, 83)',
                            borderWidth: 1,
                            pointRadius: 6,
                            pointHoverRadius: 8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const data = context.raw;
                                        return [
                                            `Student: ${data.student}`,
                                            `Batch: ${data.batch}`,
                                            `Score: ${data.y}%`,
                                            `Time: ${data.x.toFixed(1)} mins`,
                                            `Marks: ${data.score}`
                                        ];
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'Time Taken (minutes)'
                                }
                            },
                            y: {
                                title: {
                                    display: true,
                                    text: 'Score (%)'
                                },
                                beginAtZero: true,
                                max: 100
                            }
                        }
                    }
                });
            }
        }
        
        function initializeAccuracyBars() {
            // Animate accuracy bars
            document.querySelectorAll('.accuracy-fill').forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        }
        
        function initializeBatchTooltips() {
            // Add tooltips for truncated batch names
            document.querySelectorAll('.batch-badge').forEach(badge => {
                badge.addEventListener('mouseenter', function(e) {
                    if (this.scrollWidth > this.clientWidth) {
                        const tooltip = document.createElement('div');
                        tooltip.style.cssText = 'position:fixed;background:#1B3C53;color:#fff;padding:6px 12px;border-radius:8px;font-size:12px;z-index:9999;box-shadow:0 4px 14px rgba(27,60,83,0.25);';
                        tooltip.textContent = this.getAttribute('title');
                        document.body.appendChild(tooltip);
                        
                        const rect = this.getBoundingClientRect();
                        tooltip.style.top = (rect.top - 40) + 'px';
                        tooltip.style.left = Math.min(
                            rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2),
                            window.innerWidth - tooltip.offsetWidth - 10
                        ) + 'px';
                        
                        this._tooltip = tooltip;
                    }
                });
                
                badge.addEventListener('mouseleave', function() {
                    if (this._tooltip) {
                        this._tooltip.remove();
                        this._tooltip = null;
                    }
                });
            });
        }
        
        function viewAttemptDetails(attemptId) {
            fetch(`get_attempt_details.php?attempt_id=${attemptId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('attemptDetailsContent').innerHTML = data.html;
                        document.getElementById('attemptModal').style.display = 'block';
                        document.body.style.overflow = 'hidden';
                    } else {
                        alert('Error loading attempt details: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading attempt details');
                });
        }
        
        function closeModal() {
            document.getElementById('attemptModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }
        
        function downloadAttempt(attemptId) {
            window.open(`download_attempt_report.php?attempt_id=${attemptId}`, '_blank');
        }
        
        function printResults() {
            window.print();
        }
        
        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        // Close modal on ESC key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal();
            }
        });
        
        // Close modal on outside click
        document.getElementById('attemptModal').addEventListener('click', function(event) {
            if (event.target === this) {
                closeModal();
            }
        });
        
        // Table row click handling
        document.querySelectorAll('.result-row').forEach(row => {
            row.addEventListener('click', function(event) {
                if (!event.target.closest('button')) {
                    const attemptId = this.getAttribute('onclick')?.match(/\d+/)?.[0];
                    if (attemptId) {
                        viewAttemptDetails(attemptId);
                    }
                }
            });
        });
    </script>
</body>
</html>