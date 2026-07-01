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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,50 Q25,40 50,50 T100,50" stroke="white" stroke-width="0.5" fill="none" opacity="0.1"/><path d="M0,30 Q25,20 50,30 T100,30" stroke="white" stroke-width="0.5" fill="none" opacity="0.1"/></svg>');
            pointer-events: none;
            z-index: -1;
        }
        
        .glass-effect {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stats-card {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .result-row {
            transition: all 0.2s ease;
        }
        
        .result-row:hover {
            background: rgba(102, 126, 234, 0.05);
            transform: translateX(5px);
        }
        
        .badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .status-submitted {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
        }
        
        .status-in_progress {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
        }
        
        .status-timeout {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
        }
        
        .progress-ring {
            transition: stroke-dashoffset 1s ease;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .dropdown-menu {
            animation: dropdownFade 0.2s ease-out;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        @keyframes dropdownFade {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .rank-1 { background: linear-gradient(135deg, #fbbf24, #f59e0b); }
        .rank-2 { background: linear-gradient(135deg, #9ca3af, #6b7280); }
        .rank-3 { background: linear-gradient(135deg, #92400e, #b45309); }
        
        .accuracy-bar {
            height: 6px;
            border-radius: 3px;
            background: #e5e7eb;
            overflow: hidden;
        }
        
        .accuracy-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 1s ease;
        }
        
        .batch-badge {
            max-width: 150px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: inline-block;
            vertical-align: middle;
        }
    </style>
</head>
<body class="min-h-screen">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen p-4 md:p-8">
        <div class="max-w-7xl mx-auto relative z-10">
            <!-- Header -->
            <div class="glass-effect rounded-2xl p-6 md:p-8 mb-8 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-blue-400 to-purple-400 opacity-10 rounded-full -mt-16 -mr-16"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 bg-gradient-to-tr from-blue-400 to-purple-400 opacity-10 rounded-full -mb-24 -ml-24"></div>
                
                <div class="flex flex-col md:flex-row md:items-center justify-between relative z-10">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-2 gradient-text">
                            Test Results Analytics
                        </h1>
                        <p class="text-gray-600 text-lg flex items-center">
                            <i class="fas fa-chart-bar text-purple-500 mr-2"></i>
                            Detailed performance analysis and statistics
                        </p>
                    </div>
                    <div class="mt-6 md:mt-0 flex space-x-3">
                        <a href="admin_dashboard.php" 
                           class="inline-flex items-center bg-gradient-to-r from-gray-200 to-gray-300 text-gray-700 px-6 py-3 rounded-xl hover:from-gray-300 hover:to-gray-400 transition-all duration-300 font-semibold shadow-md hover:shadow-lg">
                            <i class="fas fa-arrow-left mr-3"></i>
                            <span>Back to Dashboard</span>
                        </a>
                        <a href="edit_test.php?test_id=<?= $testId ?>" 
                           class="inline-flex items-center bg-gradient-to-r from-blue-500 to-purple-600 text-white px-6 py-3 rounded-xl hover:from-blue-600 hover:to-purple-700 transition-all duration-300 font-semibold shadow-md hover:shadow-lg">
                            <i class="fas fa-edit mr-3"></i>
                            <span>Edit Test</span>
                        </a>
                    </div>
                </div>
                
                <?php if ($testData): ?>
                <div class="mt-6 grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-gradient-to-r from-blue-50 to-blue-100 p-4 rounded-xl border border-blue-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-500 rounded-lg mr-4 text-white">
                                <i class="fas fa-file-alt text-xl"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-blue-700">Test Title</div>
                                <div class="text-lg font-bold text-gray-800 truncate"><?= htmlspecialchars($testData['title']) ?></div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <?= htmlspecialchars($testData['subject']) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-green-50 to-green-100 p-4 rounded-xl border border-green-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-500 rounded-lg mr-4 text-white">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-green-700">Batch</div>
                                <?php if ($testData['batch_name']): ?>
                                    <div class="text-lg font-bold text-gray-800 truncate" title="<?= htmlspecialchars($testData['batch_name']) ?>">
                                        <?= htmlspecialchars(strlen($testData['batch_name']) > 20 ? substr($testData['batch_name'], 0, 20) . '...' : $testData['batch_name']) ?>
                                    </div>
                                    <?php if ($testData['batch_id']): ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            ID: <?= htmlspecialchars($testData['batch_id']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($testData['batch_id']): ?>
                                    <div class="text-lg font-bold text-gray-800">
                                        <?= htmlspecialchars($testData['batch_id']) ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-lg font-bold text-gray-600">No Batch</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-purple-50 to-purple-100 p-4 rounded-xl border border-purple-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-500 rounded-lg mr-4 text-white">
                                <i class="fas fa-calendar-alt text-xl"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-purple-700">Test Period</div>
                                <div class="text-lg font-bold text-gray-800">
                                    <?= $testData['start_date'] ? date('M d, Y H:i', strtotime($testData['start_date'])) : 'No start date' ?>
                                    <?= $testData['end_date'] ? ' - ' . date('M d, Y H:i', strtotime($testData['end_date'])) : '' ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-r from-yellow-50 to-yellow-100 p-4 rounded-xl border border-yellow-200">
                        <div class="flex items-center">
                            <div class="p-3 bg-yellow-500 rounded-lg mr-4 text-white">
                                <i class="fas fa-chart-line text-xl"></i>
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-yellow-700">Overall Performance</div>
                                <div class="text-lg font-bold text-gray-800">
                                    <?= $overallStats['avg_percentage'] ?? '0' ?>% Average
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <?= $overallStats['pass_count'] ?? '0' ?> passed
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-gradient-to-r from-red-400 to-pink-500 text-white px-6 py-4 rounded-xl mb-6 shadow-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-xl mr-3"></i>
                        <span class="font-medium"><?= htmlspecialchars($error) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($testData): ?>
            
            <!-- Overall Statistics -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mb-8">
                <div class="glass-card rounded-2xl p-6 stats-card" style="border-left-color: #667eea;">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl font-bold text-gray-800"><?= $overallStats['total_attempts'] ?? 0 ?></div>
                            <div class="text-sm text-gray-600">Total Attempts</div>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-gray-500">
                        <i class="fas fa-check-circle text-green-500 mr-1"></i>
                        <?= $overallStats['completed_attempts'] ?? 0 ?> completed
                    </div>
                </div>
                
                <div class="glass-card rounded-2xl p-6 stats-card" style="border-left-color: #10b981;">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl font-bold text-gray-800"><?= $overallStats['avg_percentage'] ?? '0' ?>%</div>
                            <div class="text-sm text-gray-600">Average Score</div>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center">
                            <i class="fas fa-chart-line text-green-600 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-gray-500">
                        <i class="fas fa-trophy text-yellow-500 mr-1"></i>
                        High: <?= $overallStats['max_percentage'] ?? '0' ?>%
                    </div>
                </div>
                
                <div class="glass-card rounded-2xl p-6 stats-card" style="border-left-color: #f59e0b;">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl font-bold text-gray-800"><?= $overallStats['pass_count'] ?? 0 ?></div>
                            <div class="text-sm text-gray-600">Students Passed</div>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-yellow-100 flex items-center justify-center">
                            <i class="fas fa-graduation-cap text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                    <?php if ($overallStats['total_attempts'] > 0): ?>
                    <div class="mt-4">
                        <div class="accuracy-bar">
                            <div class="accuracy-fill bg-gradient-to-r from-green-500 to-emerald-500" 
                                 style="width: <?= ($overallStats['pass_count'] / $overallStats['total_attempts']) * 100 ?>%"></div>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            <?= round(($overallStats['pass_count'] / $overallStats['total_attempts']) * 100, 1) ?>% pass rate
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="glass-card rounded-2xl p-6 stats-card" style="border-left-color: #ef4444;">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-2xl font-bold text-gray-800"><?= $overallStats['fail_count'] ?? 0 ?></div>
                            <div class="text-sm text-gray-600">Students Failed</div>
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-red-100 flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                    </div>
                    <div class="mt-4 text-xs text-gray-500">
                        <i class="fas fa-clock text-blue-500 mr-1"></i>
                        Avg time: <?= $overallStats['avg_time_taken'] ?? '0' ?> mins
                    </div>
                </div>
            </div>
            
            <!-- Filters and Actions -->
            <div class="glass-card rounded-2xl p-6 mb-8">
                <div class="flex flex-col md:flex-row md:items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 md:mb-0">
                        <i class="fas fa-filter text-blue-500 mr-2"></i>
                        Filter Results
                    </h2>
                    
                    <div class="flex space-x-3">
                        <a href="?test_id=<?= $testId ?>&download=csv" 
                           class="inline-flex items-center bg-gradient-to-r from-green-500 to-emerald-600 text-white px-4 py-2 rounded-lg hover:from-green-600 hover:to-emerald-700 transition-all duration-300 font-semibold">
                            <i class="fas fa-download mr-2"></i>
                            Export CSV
                        </a>
                        <button onclick="printResults()" 
                                class="inline-flex items-center bg-gradient-to-r from-blue-500 to-purple-600 text-white px-4 py-2 rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all duration-300 font-semibold">
                            <i class="fas fa-print mr-2"></i>
                            Print Report
                        </button>
                    </div>
                </div>
                
                <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <input type="hidden" name="test_id" value="<?= $testId ?>">
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-user-graduate mr-2 text-blue-500"></i>
                            Search Student
                        </label>
                        <input type="text" name="student" value="<?= htmlspecialchars($studentFilter) ?>" 
                               placeholder="Name, ID, or Email"
                               class="w-full px-4 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-check-circle mr-2 text-green-500"></i>
                            Filter by Status
                        </label>
                        <select name="status" class="w-full px-4 py-2 bg-white border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="">All Statuses</option>
                            <option value="submitted" <?= $statusFilter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                            <option value="in_progress" <?= $statusFilter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                            <option value="timeout" <?= $statusFilter === 'timeout' ? 'selected' : '' ?>>Timeout</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end space-x-3">
                        <button type="submit" 
                                class="flex-1 bg-gradient-to-r from-blue-500 to-purple-600 text-white px-4 py-2 rounded-lg hover:from-blue-600 hover:to-purple-700 transition-all duration-300 font-semibold">
                            <i class="fas fa-search mr-2"></i>
                            Apply Filters
                        </button>
                        <a href="?test_id=<?= $testId ?>" 
                           class="flex-1 bg-gradient-to-r from-gray-200 to-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:from-gray-300 hover:to-gray-400 transition-all duration-300 font-semibold text-center">
                            <i class="fas fa-redo mr-2"></i>
                            Clear
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Charts Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- Score Distribution Chart -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center mb-6">
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-r from-blue-500 to-purple-500 text-white flex items-center justify-center mr-3">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">Score Distribution</h3>
                            <p class="text-sm text-gray-600">Performance across grade categories</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="scoreDistributionChart"></canvas>
                    </div>
                </div>
                
                <!-- Time vs Score Chart -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex items-center mb-6">
                        <div class="w-10 h-10 rounded-lg bg-gradient-to-r from-green-500 to-teal-500 text-white flex items-center justify-center mr-3">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">Time vs Score Analysis</h3>
                            <p class="text-sm text-gray-600">Correlation between time taken and marks</p>
                        </div>
                    </div>
                    <div class="chart-container">
                        <canvas id="timeScoreChart"></canvas>
                    </div>
                </div>
            </div>
            
            <!-- Results Table -->
            <div class="glass-card rounded-2xl p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Detailed Results</h3>
                        <p class="text-sm text-gray-600"><?= count($attempts) ?> attempt(s) found</p>
                    </div>
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-2"></i>
                        Click on any row to view detailed attempt
                    </div>
                </div>
                
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="bg-gray-50 text-gray-700 text-sm">
                                <th class="p-4 text-left">Rank</th>
                                <th class="p-4 text-left">Student</th>
                                <th class="p-4 text-left">Batch</th>
                                <th class="p-4 text-left">Attempt</th>
                                <th class="p-4 text-left">Score</th>
                                <th class="p-4 text-left">Time Taken</th>
                                <th class="p-4 text-left">Status</th>
                                <th class="p-4 text-left">Grade</th>
                                <th class="p-4 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($attempts)): ?>
                                <tr>
                                    <td colspan="9" class="p-8 text-center text-gray-500">
                                        <i class="fas fa-inbox text-4xl mb-4 text-gray-300"></i>
                                        <p class="text-lg">No attempts found for this test</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($attempts as $index => $attempt): ?>
                                <tr class="result-row border-t border-gray-100 hover:bg-gray-50 cursor-pointer" 
                                    onclick="viewAttemptDetails(<?= $attempt['id'] ?>)">
                                    <td class="p-4">
                                        <?php if ($index < 3): ?>
                                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold <?= "rank-" . ($index + 1) ?>">
                                                <?= $index + 1 ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-700 font-bold">
                                                <?= $index + 1 ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-800">
                                            <?= htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']) ?>
                                        </div>
                                        <div class="text-sm text-gray-500"><?= $attempt['student_id'] ?></div>
                                    </td>
                                    <td class="p-4">
                                        <?php if ($attempt['batch_name']): ?>
                                            <div class="batch-badge px-3 py-1 bg-gradient-to-r from-blue-100 to-blue-50 text-blue-700 rounded-full text-xs font-semibold border border-blue-200"
                                                 title="<?= htmlspecialchars($attempt['batch_name']) ?> (<?= htmlspecialchars($attempt['batch_name_2'] ?? 'N/A') ?>)">
                                                <i class="fas fa-users mr-1 text-blue-500"></i>
                                                <span class="truncate inline-block max-w-[100px]">
                                                    <?= htmlspecialchars(strlen($attempt['batch_name']) > 15 ? substr($attempt['batch_name'], 0, 15) . '...' : $attempt['batch_name']) ?>
                                                </span>
                                                <?php if ($attempt['batch_name_2']): ?>
                                                    <span class="text-blue-500 text-xs ml-1">(<?= htmlspecialchars($attempt['batch_name_2']) ?>)</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php elseif ($attempt['batch_name_2']): ?>
                                            <span class="px-3 py-1 bg-gradient-to-r from-gray-100 to-gray-50 text-gray-700 rounded-full text-xs font-semibold">
                                                <i class="fas fa-users mr-1 text-gray-500"></i>
                                                <?= htmlspecialchars($attempt['batch_name_2']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="px-3 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-semibold">
                                                <i class="fas fa-times mr-1"></i>
                                                No Batch
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4">
                                        <div class="text-sm">
                                            <div class="font-semibold">Attempt #<?= $attempt['attempt_number'] ?></div>
                                            <?php if ($attempt['submitted_at']): ?>
                                                <div class="text-gray-500"><?= date('M d, H:i', strtotime($attempt['submitted_at'])) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <div class="font-bold text-lg <?= $attempt['percentage'] >= $passingPercentage ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= $attempt['percentage'] ?>%
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?= $attempt['obtained_marks'] ?>/<?= $attempt['total_marks'] ?> marks
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <div class="text-sm">
                                            <div class="font-semibold"><?= round($attempt['time_taken_seconds'] / 60, 1) ?> mins</div>
                                            <div class="text-gray-500">
                                                <?= $attempt['questions_attempted'] ?>/<?= $attempt['total_questions'] ?> questions
                                            </div>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <span class="badge <?= 'status-' . $attempt['status'] ?>">
                                            <?= ucfirst(str_replace('_', ' ', $attempt['status'])) ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold text-white <?= $attempt['grade_color'] ?>">
                                            <?= $attempt['grade_category'] ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <div class="flex space-x-2">
                                            <button onclick="event.stopPropagation(); viewAttemptDetails(<?= $attempt['id'] ?>)"
                                                    class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg"
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button onclick="event.stopPropagation(); downloadAttempt(<?= $attempt['id'] ?>)"
                                                    class="p-2 text-green-600 hover:bg-green-50 rounded-lg"
                                                    title="Download Report">
                                                <i class="fas fa-download"></i>
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
                <div class="mt-6 flex items-center justify-between text-sm text-gray-500">
                    <div>
                        Showing <?= count($attempts) ?> of <?= count($attempts) ?> results
                    </div>
                    <div class="flex items-center space-x-4">
                        <button onclick="scrollToTop()" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-up mr-1"></i> Back to top
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Question Analytics -->
            <?php if (!empty($questionStats)): ?>
            <div class="glass-card rounded-2xl p-6">
                <div class="flex items-center mb-6">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-r from-purple-500 to-pink-500 text-white flex items-center justify-center mr-3">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">Question-wise Analytics</h3>
                        <p class="text-sm text-gray-600">Performance breakdown for each question</p>
                    </div>
                </div>
                
                <div class="space-y-4">
                    <?php foreach ($questionStats as $qIndex => $question): ?>
                    <div class="bg-gray-50 rounded-xl p-4 hover:bg-white transition-colors">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex-1">
                                <div class="flex items-center mb-2">
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-r from-blue-500 to-purple-500 text-white flex items-center justify-center font-bold mr-3">
                                        <?= $qIndex + 1 ?>
                                    </div>
                                    <div class="font-semibold text-gray-800">
                                        Question #<?= $qIndex + 1 ?> 
                                        <span class="ml-3 text-sm font-normal text-gray-500">
                                            (<?= $question['marks'] ?> marks)
                                        </span>
                                    </div>
                                </div>
                                <p class="text-gray-700 ml-11"><?= htmlspecialchars(substr($question['question_text'], 0, 200)) ?><?= strlen($question['question_text']) > 200 ? '...' : '' ?></p>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold <?= $question['accuracy_rate'] > 70 ? 'text-green-600' : ($question['accuracy_rate'] > 40 ? 'text-yellow-600' : 'text-red-600') ?>">
                                    <?= $question['accuracy_rate'] ?>%
                                </div>
                                <div class="text-sm text-gray-500">Accuracy</div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-center">
                            <div class="bg-white p-3 rounded-lg">
                                <div class="text-lg font-bold text-green-600"><?= $question['correct_attempts'] ?></div>
                                <div class="text-sm text-gray-600">Correct</div>
                            </div>
                            <div class="bg-white p-3 rounded-lg">
                                <div class="text-lg font-bold text-red-600"><?= $question['wrong_attempts'] ?></div>
                                <div class="text-sm text-gray-600">Wrong</div>
                            </div>
                            <div class="bg-white p-3 rounded-lg">
                                <div class="text-lg font-bold text-gray-600"><?= $question['unanswered'] ?></div>
                                <div class="text-sm text-gray-600">Unanswered</div>
                            </div>
                            <div class="bg-white p-3 rounded-lg">
                                <div class="text-lg font-bold text-blue-600"><?= $question['total_attempts'] ?></div>
                                <div class="text-sm text-gray-600">Total Attempts</div>
                            </div>
                        </div>
                        
                        <?php if ($question['common_wrong_answers']): ?>
                        <div class="mt-3 text-sm text-gray-600">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i>
                            Common wrong answers: <?= strtoupper(str_replace(',', ', ', $question['common_wrong_answers'])) ?>
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
    <div id="attemptModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 overflow-y-auto">
        <div class="min-h-screen flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
                <div class="p-6 border-b">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xl font-bold text-gray-800">Attempt Details</h3>
                        <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>
                </div>
                <div class="p-6 overflow-y-auto max-h-[70vh]" id="attemptDetailsContent">
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
                                'rgba(239, 68, 68, 0.7)',
                                'rgba(245, 158, 11, 0.7)',
                                'rgba(59, 130, 246, 0.7)',
                                'rgba(34, 197, 94, 0.7)'
                            ],
                            borderColor: [
                                'rgb(239, 68, 68)',
                                'rgb(245, 158, 11)',
                                'rgb(59, 130, 246)',
                                'rgb(34, 197, 94)'
                            ],
                            borderWidth: 1
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
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderColor: 'rgb(59, 130, 246)',
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
                        tooltip.className = 'fixed bg-gray-900 text-white px-3 py-2 rounded-lg text-sm z-50 shadow-lg';
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
                        document.getElementById('attemptModal').classList.remove('hidden');
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
            document.getElementById('attemptModal').classList.add('hidden');
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