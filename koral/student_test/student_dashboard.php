<?php
// student_dashboard.php - Fixed timezone handling with modern design
session_start();
require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$studentId = $_SESSION['user_id'];

// Get student details with all batch assignments
$studentStmt = $db->prepare("
    SELECT student_id, first_name, last_name, batch_name, batch_name_2, batch_name_3, batch_name_4 
    FROM students WHERE user_id = ?
");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

// Create array of all assigned batches
$assignedBatches = array_filter([
    $student['batch_name'],
    $student['batch_name_2'],
    $student['batch_name_3'],
    $student['batch_name_4']
]);

// Get current date and time in IST
$currentDateTime = date('Y-m-d H:i:s');

// Get available tests for assigned batches with proper time constraints
if (!empty($assignedBatches)) {
    $placeholders = implode(',', array_fill(0, count($assignedBatches), '?'));
    
    $testQuery = "
        SELECT 
            t.*,
            COUNT(DISTINCT tq.id) as question_count,
            COALESCE(ta.attempts, 0) as attempts_made,
            ta.status as last_status,
            ta.percentage as last_score,
            ta.attempt_number as last_attempt,
            ta.id as last_attempt_id,
            -- Store original UTC times for calculations
            t.start_date as utc_start_date,
            t.end_date as utc_end_date,
            -- Calculate availability status based on UTC times
            CASE 
                WHEN t.start_date IS NOT NULL AND t.start_date > UTC_TIMESTAMP() THEN 'upcoming'
                WHEN t.end_date IS NOT NULL AND t.end_date < UTC_TIMESTAMP() THEN 'expired'
                WHEN (t.start_date IS NULL OR t.start_date <= UTC_TIMESTAMP()) 
                     AND (t.end_date IS NULL OR t.end_date >= UTC_TIMESTAMP()) THEN 'available'
                ELSE 'unavailable'
            END as availability_status,
            -- Calculate seconds until start (for countdown)
            CASE 
                WHEN t.start_date IS NOT NULL AND t.start_date > UTC_TIMESTAMP() 
                THEN TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), t.start_date)
                ELSE NULL
            END as seconds_until_start,
            -- Calculate seconds remaining (for countdown)
            CASE 
                WHEN t.end_date IS NOT NULL AND t.end_date > UTC_TIMESTAMP() 
                THEN TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), t.end_date)
                ELSE NULL
            END as seconds_remaining
        FROM tests t
        LEFT JOIN test_questions tq ON t.id = tq.test_id
        LEFT JOIN (
            SELECT 
                test_id, 
                COUNT(*) as attempts, 
                MAX(status) as status, 
                MAX(percentage) as percentage,
                MAX(attempt_number) as attempt_number,
                MAX(id) as id
            FROM test_attempts 
            WHERE student_id = ?
            GROUP BY test_id
        ) ta ON t.id = ta.test_id
        WHERE t.is_active = 1
        AND (t.batch_id IN ($placeholders) OR t.batch_id IS NULL OR t.batch_id = '')
        GROUP BY t.id
        ORDER BY 
            CASE 
                WHEN t.start_date IS NOT NULL AND t.start_date > UTC_TIMESTAMP() THEN 0
                WHEN t.start_date IS NULL OR t.start_date <= UTC_TIMESTAMP() THEN 1
                ELSE 2
            END,
            t.start_date ASC,
            t.created_at DESC
    ";
    
    $params = array_merge([$student['student_id']], array_values($assignedBatches));
    $testStmt = $db->prepare($testQuery);
    $testStmt->execute($params);
    $tests = $testStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $testQuery = "
        SELECT 
            t.*,
            COUNT(DISTINCT tq.id) as question_count,
            COALESCE(ta.attempts, 0) as attempts_made,
            ta.status as last_status,
            ta.percentage as last_score,
            ta.attempt_number as last_attempt,
            ta.id as last_attempt_id,
            t.start_date as utc_start_date,
            t.end_date as utc_end_date,
            CASE 
                WHEN t.start_date IS NOT NULL AND t.start_date > UTC_TIMESTAMP() THEN 'upcoming'
                WHEN t.end_date IS NOT NULL AND t.end_date < UTC_TIMESTAMP() THEN 'expired'
                WHEN (t.start_date IS NULL OR t.start_date <= UTC_TIMESTAMP()) 
                     AND (t.end_date IS NULL OR t.end_date >= UTC_TIMESTAMP()) THEN 'available'
                ELSE 'unavailable'
            END as availability_status,
            CASE 
                WHEN t.start_date IS NOT NULL AND t.start_date > UTC_TIMESTAMP() 
                THEN TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), t.start_date)
                ELSE NULL
            END as seconds_until_start,
            CASE 
                WHEN t.end_date IS NOT NULL AND t.end_date > UTC_TIMESTAMP() 
                THEN TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), t.end_date)
                ELSE NULL
            END as seconds_remaining
        FROM tests t
        LEFT JOIN test_questions tq ON t.id = tq.test_id
        LEFT JOIN (
            SELECT 
                test_id, 
                COUNT(*) as attempts, 
                MAX(status) as status, 
                MAX(percentage) as percentage,
                MAX(attempt_number) as attempt_number,
                MAX(id) as id
            FROM test_attempts 
            WHERE student_id = ?
            GROUP BY test_id
        ) ta ON t.id = ta.test_id
        WHERE t.is_active = 1
        AND (t.batch_id IS NULL OR t.batch_id = '')
        GROUP BY t.id
        ORDER BY 
            CASE 
                WHEN t.start_date IS NOT NULL AND t.start_date > UTC_TIMESTAMP() THEN 0
                WHEN t.start_date IS NULL OR t.start_date <= UTC_TIMESTAMP() THEN 1
                ELSE 2
            END,
            t.start_date ASC,
            t.created_at DESC
    ";
    
    $testStmt = $db->prepare($testQuery);
    $testStmt->execute([$student['student_id']]);
    $tests = $testStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent test attempts
$recentAttemptsStmt = $db->prepare("
    SELECT ta.*, t.title, t.subject
    FROM test_attempts ta
    JOIN tests t ON ta.test_id = t.id
    WHERE ta.student_id = ? AND ta.status = 'submitted'
    ORDER BY ta.submitted_at DESC
    LIMIT 5
");
$recentAttemptsStmt->execute([$student['student_id']]);
$recentAttempts = $recentAttemptsStmt->fetchAll(PDO::FETCH_ASSOC);

// Show assigned batches info
$batchInfo = '';
if (!empty($assignedBatches)) {
    $batchInfo = "Assigned to: " . implode(", ", $assignedBatches);
} else {
    $batchInfo = "Not assigned to any batch";
}

// Function to format UTC time to IST for display
function formatToIST($utcDateTime) {
    if (!$utcDateTime) return 'No time limit';
    $date = new DateTime($utcDateTime, new DateTimeZone('UTC'));
    $date->setTimezone(new DateTimeZone('Asia/Kolkata'));
    return $date->format('M j, Y g:i A');
}

// Calculate statistics
$totalTests = count($tests);
$availableTests = count(array_filter($tests, fn($t) => $t['availability_status'] === 'available'));
$attemptedTests = count(array_filter($tests, fn($t) => $t['attempts_made'] > 0));
$scores = array_filter(array_column($recentAttempts, 'percentage'));
$avgScore = count($scores) > 0 ? array_sum($scores) / count($scores) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - MCQ Tests</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            position: relative;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .test-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .test-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }
        
        .countdown-timer {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            padding: 8px 16px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }
        
        .countdown-upcoming {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-available {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
        }
        
        .status-upcoming {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
        }
        
        .status-expired {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
        }
        
        .batch-badge {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .score-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 16px;
            border: 3px solid;
            transition: all 0.3s ease;
        }
        
        .animate-pulse-slow {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .floating {
            animation: floating 3s ease-in-out infinite;
        }
        
        @keyframes floating {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Animated Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-0 left-0 w-96 h-96 bg-purple-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-pulse"></div>
        <div class="absolute bottom-0 right-0 w-96 h-96 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-pulse"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-pink-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 floating"></div>
    </div>
    
    <?php include '../header.php'; ?>
    <?php include '../s_sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen transition-all duration-300">
        <!-- Header -->
        <div class="glass-card sticky top-0 z-50">
            <div class="flex items-center justify-between p-4 md:p-6">
                <div class="flex items-center space-x-4">
                    <button onclick="toggleSidebar()" class="md:hidden p-2 rounded-lg bg-gradient-to-r from-blue-500 to-purple-500 text-white">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h1 class="text-xl md:text-2xl font-bold gradient-text">
                            <i class="fas fa-tachometer-alt mr-2"></i>
                            Student Dashboard
                        </h1>
                        <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($batchInfo) ?></p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <div class="hidden md:flex items-center space-x-2 bg-gray-100 px-4 py-2 rounded-lg">
                        <i class="fas fa-clock text-yellow-500"></i>
                        <span class="text-sm font-medium" id="current-ist"></span>
                    </div>
                    
                    <div class="relative group">
                        <div class="w-10 h-10 rounded-full bg-gradient-to-r from-blue-500 to-purple-500 flex items-center justify-center text-white shadow-lg cursor-pointer">
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="absolute right-0 mt-2 w-48 bg-white rounded-xl shadow-2xl py-2 hidden group-hover:block z-50">
                            <div class="px-4 py-3 border-b">
                                <p class="text-sm font-medium"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                                <p class="text-xs text-gray-500">Student</p>
                            </div>
                            <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="p-4 md:p-8">
            <div class="max-w-7xl mx-auto space-y-6">
                <!-- Welcome Section -->
                <div class="glass-card rounded-2xl p-6 md:p-8">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                        <div>
                            <h2 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">
                                Welcome back, <?= htmlspecialchars($student['first_name']) ?>! 👋
                            </h2>
                            <p class="text-gray-600">Track your progress and ace your tests</p>
                        </div>
                        <div class="mt-4 md:mt-0 bg-gradient-to-r from-blue-500 to-purple-500 text-white px-6 py-3 rounded-xl">
                            <div class="text-sm opacity-90">Overall Average</div>
                            <div class="text-2xl font-bold"><?= round($avgScore, 1) ?>%</div>
                        </div>
                    </div>
                </div>
                
                <!-- Stats Grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="glass-card p-6 rounded-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Total Tests</p>
                                <p class="text-2xl font-bold text-gray-800"><?= $totalTests ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-6 rounded-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Available Now</p>
                                <p class="text-2xl font-bold text-green-600"><?= $availableTests ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
                                <i class="fas fa-play-circle text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-6 rounded-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Attempted</p>
                                <p class="text-2xl font-bold text-purple-600"><?= $attemptedTests ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
                                <i class="fas fa-check-circle text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-6 rounded-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600">Avg Score</p>
                                <p class="text-2xl font-bold text-yellow-600"><?= round($avgScore, 1) ?>%</p>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center">
                                <i class="fas fa-chart-line text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Performance -->
                <?php if (!empty($recentAttempts)): ?>
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-history text-purple-500 mr-2"></i>
                            Recent Performance
                        </h3>
                        <a href="test_history.php" class="text-sm text-blue-600 hover:text-blue-800">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <div class="space-y-4">
                        <?php foreach ($recentAttempts as $attempt): ?>
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex items-center space-x-4">
                                <div class="score-circle <?= $attempt['percentage'] >= 60 ? 'border-green-500 text-green-700' : 'border-red-500 text-red-700' ?>">
                                    <?= round($attempt['percentage']) ?>%
                                </div>
                                <div>
                                    <h4 class="font-semibold text-gray-800"><?= htmlspecialchars($attempt['title']) ?></h4>
                                    <p class="text-sm text-gray-600">
                                        <?= htmlspecialchars($attempt['subject']) ?> • 
                                        Attempt #<?= $attempt['attempt_number'] ?> • 
                                        <?= date('M j, g:i A', strtotime($attempt['submitted_at'])) ?>
                                    </p>
                                </div>
                            </div>
                            <a href="test_result.php?test_id=<?= $attempt['test_id'] ?>" 
                               class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                View Result
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Available Tests -->
                <div class="glass-card rounded-2xl p-6">
                    <div class="flex flex-wrap justify-between items-center mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-rocket text-green-500 mr-2"></i>
                            Available Tests
                            <span class="ml-2 text-sm font-normal text-gray-500">(<?= $totalTests ?> tests)</span>
                        </h3>
                        
                        <?php if (!empty($assignedBatches)): ?>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($assignedBatches as $batch): ?>
                            <span class="batch-badge"><?= htmlspecialchars($batch) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($tests)): ?>
                    <div class="text-center py-12">
                        <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-file-alt text-3xl text-gray-400"></i>
                        </div>
                        <h4 class="text-lg font-semibold text-gray-700 mb-2">No Tests Available</h4>
                        <p class="text-gray-500">Check back later for new tests</p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($tests as $test): ?>
                        <div class="test-card bg-white rounded-xl p-6 border border-gray-100">
                            <div class="flex flex-col lg:flex-row lg:items-start justify-between">
                                <!-- Test Info -->
                                <div class="flex-1">
                                    <div class="flex items-start space-x-3 mb-4">
                                        <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center text-white font-bold">
                                            <?= strtoupper(substr($test['title'], 0, 1)) ?>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                                <h4 class="text-lg font-bold text-gray-800"><?= htmlspecialchars($test['title']) ?></h4>
                                                <span class="status-badge status-<?= $test['availability_status'] ?>">
                                                    <?= ucfirst($test['availability_status']) ?>
                                                </span>
                                                <?php if ($test['attempts_made'] > 0): ?>
                                                <span class="px-2 py-1 bg-purple-100 text-purple-700 rounded-lg text-xs font-medium">
                                                    <?= $test['attempts_made'] ?>/<?= $test['max_attempts'] ?> Attempts
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm text-gray-600"><?= htmlspecialchars($test['subject']) ?></p>
                                        </div>
                                    </div>
                                    
                                    <!-- Time Display -->
                                    <?php if ($test['availability_status'] === 'upcoming' && $test['seconds_until_start']): ?>
                                    <div class="mb-4">
                                        <div class="countdown-timer countdown-upcoming" id="countdown-<?= $test['id'] ?>">
                                            <i class="fas fa-clock mr-2"></i>
                                            <span id="countdown-text-<?= $test['id'] ?>">
                                                Starts in: <?= gmdate('H:i:s', $test['seconds_until_start']) ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-2">
                                            <i class="far fa-calendar-alt mr-1"></i>
                                            Starts: <?= formatToIST($test['utc_start_date']) ?>
                                        </p>
                                    </div>
                                    <?php elseif ($test['availability_status'] === 'available' && $test['seconds_remaining']): ?>
                                    <div class="mb-4">
                                        <div class="countdown-timer" id="countdown-<?= $test['id'] ?>">
                                            <i class="fas fa-hourglass-end mr-2"></i>
                                            <span id="countdown-text-<?= $test['id'] ?>">
                                                Time left: <?= gmdate('H:i:s', $test['seconds_remaining']) ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-2">
                                            <i class="far fa-calendar-alt mr-1"></i>
                                            Ends: <?= formatToIST($test['utc_end_date']) ?>
                                        </p>
                                    </div>
                                    <?php elseif ($test['utc_start_date'] && $test['utc_end_date']): ?>
                                    <p class="text-sm text-gray-600 mb-4">
                                        <i class="far fa-calendar-alt mr-1"></i>
                                        <?= formatToIST($test['utc_start_date']) ?> - <?= formatToIST($test['utc_end_date']) ?>
                                    </p>
                                    <?php endif; ?>
                                    
                                    <!-- Test Stats -->
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                                        <div class="bg-gray-50 p-2 rounded-lg text-center">
                                            <p class="text-xs text-gray-500">Questions</p>
                                            <p class="font-semibold"><?= $test['question_count'] ?></p>
                                        </div>
                                        <div class="bg-gray-50 p-2 rounded-lg text-center">
                                            <p class="text-xs text-gray-500">Duration</p>
                                            <p class="font-semibold"><?= $test['duration_minutes'] ?> min</p>
                                        </div>
                                        <div class="bg-gray-50 p-2 rounded-lg text-center">
                                            <p class="text-xs text-gray-500">Total Marks</p>
                                            <p class="font-semibold"><?= $test['total_marks'] ?></p>
                                        </div>
                                        <div class="bg-gray-50 p-2 rounded-lg text-center">
                                            <p class="text-xs text-gray-500">Passing</p>
                                            <p class="font-semibold"><?= $test['passing_marks'] ?>%</p>
                                        </div>
                                    </div>
                                    
                                    <?php if ($test['description']): ?>
                                    <p class="text-sm text-gray-600 bg-gray-50 p-3 rounded-lg">
                                        <?= htmlspecialchars(substr($test['description'], 0, 150)) ?>...
                                    </p>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Actions -->
                                <div class="mt-6 lg:mt-0 lg:ml-6 flex flex-col items-center lg:items-end space-y-3">
                                    <?php if ($test['attempts_made'] > 0 && $test['last_status'] === 'submitted'): ?>
                                    <div class="text-center mb-2">
                                        <div class="text-2xl font-bold <?= $test['last_score'] >= $test['passing_marks'] ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= round($test['last_score']) ?>%
                                        </div>
                                        <p class="text-xs text-gray-500">Last Score</p>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex flex-col space-y-2">
                                        <?php if ($test['availability_status'] === 'available' && $test['attempts_made'] < $test['max_attempts']): ?>
                                        <a href="take_test.php?test_id=<?= $test['id'] ?>" 
                                           class="px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:from-blue-700 hover:to-purple-700 transition-all text-center font-medium">
                                            <i class="fas fa-play mr-2"></i>
                                            <?= $test['attempts_made'] > 0 ? 'Retake Test' : 'Start Test' ?>
                                        </a>
                                        <?php elseif ($test['availability_status'] === 'upcoming'): ?>
                                        <button disabled 
                                                class="px-6 py-3 bg-gray-300 text-gray-500 rounded-xl cursor-not-allowed">
                                            <i class="fas fa-clock mr-2"></i>
                                            Starts Soon
                                        </button>
                                        <?php elseif ($test['availability_status'] === 'expired'): ?>
                                        <button disabled 
                                                class="px-6 py-3 bg-gray-300 text-gray-500 rounded-xl cursor-not-allowed">
                                            <i class="fas fa-calendar-times mr-2"></i>
                                            Expired
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($test['attempts_made'] > 0 && $test['last_status'] === 'submitted'): ?>
                                        <a href="test_result.php?test_id=<?= $test['id'] ?>&attempt_id=<?= $test['last_attempt_id'] ?>" 
                                           class="px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all text-center font-medium">
                                            <i class="fas fa-chart-bar mr-2"></i>
                                            View Result
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Store time data for countdown -->
                            <?php if (($test['availability_status'] === 'upcoming' && $test['seconds_until_start']) || 
                                      ($test['availability_status'] === 'available' && $test['seconds_remaining'])): ?>
                            <script>
                                window.testTimers = window.testTimers || {};
                                window.testTimers[<?= $test['id'] ?>] = {
                                    type: '<?= $test['availability_status'] ?>',
                                    seconds: <?= $test['availability_status'] === 'upcoming' ? $test['seconds_until_start'] : $test['seconds_remaining'] ?>,
                                    testId: <?= $test['id'] ?>
                                };
                            </script>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Toggle sidebar
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
    }

    // Update IST clock
    function updateISTClock() {
        const now = new Date();
        const options = { 
            timeZone: 'Asia/Kolkata',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        document.getElementById('current-ist').textContent = new Intl.DateTimeFormat('en-US', options).format(now) + ' IST';
    }

    // Countdown timer function
    function updateCountdowns() {
        if (!window.testTimers) return;
        
        Object.keys(window.testTimers).forEach(testId => {
            const timer = window.testTimers[testId];
            if (timer.seconds > 0) {
                timer.seconds--;
                
                const hours = Math.floor(timer.seconds / 3600);
                const minutes = Math.floor((timer.seconds % 3600) / 60);
                const seconds = timer.seconds % 60;
                
                let timeString = '';
                if (timer.type === 'upcoming') {
                    timeString = `Starts in: ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                } else {
                    timeString = `Time left: ${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                }
                
                const element = document.getElementById(`countdown-text-${testId}`);
                if (element) element.textContent = timeString;
                
                window.testTimers[testId] = timer;
            } else {
                // Time's up - reload page
                setTimeout(() => location.reload(), 2000);
            }
        });
    }

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        updateISTClock();
        setInterval(updateISTClock, 1000);
        
        if (window.testTimers && Object.keys(window.testTimers).length > 0) {
            setInterval(updateCountdowns, 1000);
        }
    });
    </script>
</body>
</html>