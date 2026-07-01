<?php
// weekly_report_card.php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Check if user is admin or student
$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
$isStudent = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'student';
$isMentor = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'mentor';

if (!$isAdmin && !$isStudent && !$isMentor) {
    header("Location: ../login.php");
    exit;
}

include '../header.php';
include '../sidebar.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get student ID - for admin viewing specific student, or student viewing own report
    $student_id = '';
    $student_name = '';
    
    if ($isAdmin || $isMentor) {
        // Admin can view any student's report
        if (isset($_GET['student_id']) && !empty($_GET['student_id'])) {
            $student_id = $_GET['student_id'];
        } else {
            // Default to first active student or show selection
            $stmt = $db->query("SELECT student_id FROM students WHERE current_status = 'active' LIMIT 1");
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $student_id = $student ? $student['student_id'] : '';
        }
    } else {
        // Student can only view their own report
        $student_id = $_SESSION['username'] ?? ''; // Assuming username is student_id
    }
    
    // Get week filter
    $currentWeek = date('Y-m-d', strtotime('monday this week'));
    $selectedWeek = isset($_GET['week']) ? $_GET['week'] : $currentWeek;
    
    // Calculate week range
    $weekStart = date('Y-m-d', strtotime('monday this week', strtotime($selectedWeek)));
    $weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($selectedWeek)));
    
    // Get student info
    $studentInfo = [];
    if ($student_id) {
        $stmt = $db->prepare("
            SELECT s.student_id, s.first_name, s.last_name, s.email, 
                   s.batch_name, s.batch_name_2, s.batch_name_3,
                   b.batch_name as primary_batch_name,
                   b2.batch_name as secondary_batch_name,
                   b3.batch_name as tertiary_batch_name
            FROM students s
            LEFT JOIN batches b ON s.batch_name = b.batch_id
            LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
            LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
            WHERE s.student_id = ?
        ");
        $stmt->execute([$student_id]);
        $studentInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($studentInfo) {
            $student_name = $studentInfo['first_name'] . ' ' . $studentInfo['last_name'];
        }
    }
    
    // Get weekly feedback
    $weeklyFeedback = [];
    if ($student_id) {
        $stmt = $db->prepare("
            SELECT wf.*, t.name as trainer_name, 
                   DATE_FORMAT(wf.week_start_date, '%d %b %Y') as week_start,
                   DATE_FORMAT(wf.week_end_date, '%d %b %Y') as week_end
            FROM weekly_feedback wf
            LEFT JOIN trainers t ON wf.trainer_id = t.id
            WHERE wf.student_id = ? 
            AND wf.week_start_date <= ? 
            AND wf.week_end_date >= ?
            ORDER BY wf.week_start_date DESC
            LIMIT 1
        ");
        $stmt->execute([$student_id, $weekEnd, $weekStart]);
        $weeklyFeedback = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get weekly test results
    $weeklyTests = [];
    if ($student_id) {
        $stmt = $db->prepare("
            SELECT t.test_name, t.subject, t.total_marks, t.passing_marks,
                   ta.obtained_marks, ta.percentage, ta.result, ta.status,
                   ta.submitted_at, 
                   DATE_FORMAT(ta.submitted_at, '%d %b %Y %H:%i') as submitted_date,
                   ta.test_id,
                   (SELECT COUNT(DISTINCT student_id) FROM test_attempts WHERE test_id = ta.test_id) as total_test_takers,
                   (SELECT COUNT(DISTINCT student_id) FROM test_attempts WHERE test_id = ta.test_id AND result = 'pass') as passed_count
            FROM test_attempts ta
            JOIN tests t ON ta.test_id = t.id
            WHERE ta.student_id = ?
            AND DATE(ta.submitted_at) BETWEEN ? AND ?
            AND ta.status = 'submitted'
            AND t.test_type = 'weekly'
            ORDER BY ta.submitted_at DESC
        ");
        $stmt->execute([$student_id, $weekStart, $weekEnd]);
        $weeklyTests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Calculate average test score
    $averageTestScore = 0;
    $totalTests = count($weeklyTests);
    if ($totalTests > 0) {
        $totalPercentage = 0;
        foreach ($weeklyTests as $test) {
            $totalPercentage += $test['percentage'];
        }
        $averageTestScore = round($totalPercentage / $totalTests, 2);
    }
    
    // Get weekly attendance
    $weeklyAttendance = [];
    $attendanceStats = [
        'present' => 0,
        'absent' => 0,
        'total' => 0
    ];
    
    if ($student_id && !empty($studentInfo['batch_name'])) {
        $batchIds = [
            $studentInfo['batch_name'],
            $studentInfo['batch_name_2'],
            $studentInfo['batch_name_3']
        ];
        $batchIds = array_filter($batchIds); // Remove null/empty values
        
        $placeholders = str_repeat('?,', count($batchIds) - 1) . '?';
        
        $stmt = $db->prepare("
            SELECT date, status, remarks,
                   DAYNAME(date) as day_name,
                   DATE_FORMAT(date, '%d %b %Y') as formatted_date
            FROM attendance
            WHERE student_id = ?
            AND date BETWEEN ? AND ?
            AND batch_id IN ($placeholders)
            ORDER BY date
        ");
        
        $params = array_merge([$student_id, $weekStart, $weekEnd], $batchIds);
        $stmt->execute($params);
        $weeklyAttendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate attendance stats
        foreach ($weeklyAttendance as $attendance) {
            $attendanceStats['total']++;
            if (strtolower($attendance['status']) === 'present') {
                $attendanceStats['present']++;
            } else {
                $attendanceStats['absent']++;
            }
        }
        
        // Calculate attendance percentage
        $attendanceStats['percentage'] = $attendanceStats['total'] > 0 
            ? round(($attendanceStats['present'] / $attendanceStats['total']) * 100, 2) 
            : 0;
    }
    
    // Get overall performance metrics
    $overallPerformance = [
        'feedback_rating' => $weeklyFeedback ? $weeklyFeedback['rating'] : 0,
        'test_score' => $averageTestScore,
        'attendance' => $attendanceStats['percentage']
    ];
    
    // Calculate overall grade
    $overallScore = 0;
    $weights = ['feedback_rating' => 0.3, 'test_score' => 0.4, 'attendance' => 0.3];
    
    foreach ($overallPerformance as $key => $value) {
        // Normalize feedback rating (1-5 to 0-100)
        if ($key === 'feedback_rating') {
            $value = ($value / 5) * 100;
        }
        $overallScore += $value * $weights[$key];
    }
    
    $overallGrade = '';
    if ($overallScore >= 90) {
        $overallGrade = 'A+ (Excellent)';
    } elseif ($overallScore >= 80) {
        $overallGrade = 'A (Very Good)';
    } elseif ($overallScore >= 70) {
        $overallGrade = 'B (Good)';
    } elseif ($overallScore >= 60) {
        $overallGrade = 'C (Satisfactory)';
    } elseif ($overallScore >= 50) {
        $overallGrade = 'D (Needs Improvement)';
    } else {
        $overallGrade = 'F (Poor)';
    }
    
    // Get list of available weeks for dropdown
    $availableWeeks = [];
    if ($student_id) {
        // Get weeks with feedback
        $stmt = $db->prepare("
            SELECT DISTINCT week_start_date 
            FROM weekly_feedback 
            WHERE student_id = ? 
            ORDER BY week_start_date DESC
        ");
        $stmt->execute([$student_id]);
        $feedbackWeeks = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get weeks with tests
        $stmt = $db->prepare("
            SELECT DISTINCT DATE(submitted_at) as test_date
            FROM test_attempts 
            WHERE student_id = ? 
            AND status = 'submitted'
            ORDER BY test_date DESC
        ");
        $stmt->execute([$student_id]);
        $testWeeks = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Combine and get unique weeks
        $allDates = array_merge($feedbackWeeks, $testWeeks);
        $allDates = array_unique($allDates);
        
        // Convert to week start dates
        foreach ($allDates as $date) {
            $weekStartDate = date('Y-m-d', strtotime('monday this week', strtotime($date)));
            if (!in_array($weekStartDate, $availableWeeks)) {
                $availableWeeks[] = $weekStartDate;
            }
        }
        
        // Sort weeks descending
        rsort($availableWeeks);
        
        // Add current week if not already present
        if (!in_array($currentWeek, $availableWeeks)) {
            array_unshift($availableWeeks, $currentWeek);
        }
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Report Card</title>
    
    <!-- Tailwind CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        .sidebar-link:hover {
            background-color: #f0f7ff;
        }
        .sidebar-link.active {
            background-color: #e1f0ff;
            border-left: 4px solid #3b82f6;
        }
        .metric-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .metric-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .progress-ring {
            transform: rotate(-90deg);
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        .grade-a { background-color: #dcfce7; color: #166534; }
        .grade-b { background-color: #f0f9ff; color: #1e40af; }
        .grade-c { background-color: #fef3c7; color: #92400e; }
        .grade-d { background-color: #fee2e2; color: #991b1b; }
        .grade-f { background-color: #f3f4f6; color: #374151; }
        .star-rating i {
            color: #fbbf24;
        }
        .star-rating i.empty {
            color: #d1d5db;
        }
        .attendance-present { background-color: #10b981; color: white; }
        .attendance-absent { background-color: #ef4444; color: white; }
        .attendance-late { background-color: #f59e0b; color: white; }
        .test-passed { background-color: #10b981; color: white; }
        .test-failed { background-color: #ef4444; color: white; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <!-- Sidebar -->
    <?php include '../sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="md:ml-64">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-chart-line text-blue-500"></i>
                <span>Weekly Report Card</span>
            </h1>
            <div class="flex items-center space-x-4">
                <?php if ($isAdmin || $isMentor): ?>
                    <a href="students_list.php" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Students
                    </a>
                <?php endif; ?>
                <button onclick="printReport()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-print mr-2"></i> Print Report
                </button>
            </div>
        </header>

        <div class="container mx-auto px-4 py-8">
            <!-- Student Selection (for Admin/Mentor) -->
            <?php if ($isAdmin || $isMentor): ?>
                <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800">Select Student</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <input type="hidden" name="week" value="<?= htmlspecialchars($selectedWeek) ?>">
                        
                        <div class="col-span-2">
                            <label for="student_id" class="block text-sm font-medium text-gray-700 mb-1">Student</label>
                            <select id="student_id" name="student_id" 
                                    class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onchange="this.form.submit()">
                                <option value="">Select a student...</option>
                                <?php
                                $stmt = $db->query("
                                    SELECT student_id, first_name, last_name, batch_name 
                                    FROM students 
                                    WHERE current_status = 'active' 
                                    ORDER BY first_name, last_name
                                ");
                                $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($allStudents as $student) {
                                    $selected = ($student_id == $student['student_id']) ? 'selected' : '';
                                    $name = htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' (' . $student['student_id'] . ')');
                                    echo "<option value=\"{$student['student_id']}\" $selected>$name</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="week" class="block text-sm font-medium text-gray-700 mb-1">Week</label>
                            <select id="week" name="week" 
                                    class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onchange="this.form.submit()">
                                <?php foreach ($availableWeeks as $week): ?>
                                    <?php
                                    $weekDisplay = date('d M Y', strtotime($week)) . ' - ' . date('d M Y', strtotime('sunday this week', strtotime($week)));
                                    $weekSelected = ($selectedWeek == $week) ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($week) ?>" <?= $weekSelected ?>>
                                        <?= htmlspecialchars($weekDisplay) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="flex items-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg w-full">
                                <i class="fas fa-filter mr-2"></i> Load Report
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Week Selection for Students -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800">Select Week</h2>
                    <form method="GET" class="flex items-end space-x-4">
                        <div class="flex-grow">
                            <label for="week" class="block text-sm font-medium text-gray-700 mb-1">Week</label>
                            <select id="week" name="week" 
                                    class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    onchange="this.form.submit()">
                                <?php foreach ($availableWeeks as $week): ?>
                                    <?php
                                    $weekDisplay = date('d M Y', strtotime($week)) . ' - ' . date('d M Y', strtotime('sunday this week', strtotime($week)));
                                    $weekSelected = ($selectedWeek == $week) ? 'selected' : '';
                                    ?>
                                    <option value="<?= htmlspecialchars($week) ?>" <?= $weekSelected ?>>
                                        <?= htmlspecialchars($weekDisplay) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-sync-alt mr-2"></i> Refresh
                        </button>
                    </form>
                </div>
            <?php endif; ?>
            
            <?php if (!$student_id || !$studentInfo): ?>
                <div class="bg-white rounded-lg shadow-md p-8 text-center">
                    <i class="fas fa-user-graduate text-6xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Student Selected</h3>
                    <p class="text-gray-500">Please select a student to view their weekly report card.</p>
                </div>
            <?php else: ?>
                <!-- Report Header -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-2">Weekly Report Card</h2>
                            <p class="text-gray-600">
                                Week of <?= date('d M Y', strtotime($weekStart)) ?> - <?= date('d M Y', strtotime($weekEnd)) ?>
                            </p>
                        </div>
                        <div class="mt-4 md:mt-0 text-right">
                            <div class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($student_name) ?></div>
                            <div class="text-gray-600">ID: <?= htmlspecialchars($student_id) ?></div>
                            <div class="text-gray-500 text-sm">
                                <?= htmlspecialchars($studentInfo['primary_batch_name'] ?? 'No batch assigned') ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Overall Performance Card -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-100">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-800">Overall Performance</h3>
                                <span class="text-3xl font-bold <?php 
                                    echo strpos($overallGrade, 'A') !== false ? 'text-green-600' : 
                                         (strpos($overallGrade, 'B') !== false ? 'text-blue-600' : 
                                         (strpos($overallGrade, 'C') !== false ? 'text-yellow-600' : 
                                         (strpos($overallGrade, 'D') !== false ? 'text-orange-600' : 'text-red-600'))); 
                                ?>">
                                    <?= round($overallScore, 1) ?>%
                                </span>
                            </div>
                            <div class="mb-4">
                                <span class="status-badge <?php 
                                    echo strpos($overallGrade, 'A') !== false ? 'grade-a' : 
                                         (strpos($overallGrade, 'B') !== false ? 'grade-b' : 
                                         (strpos($overallGrade, 'C') !== false ? 'grade-c' : 
                                         (strpos($overallGrade, 'D') !== false ? 'grade-d' : 'grade-f'))); 
                                ?>">
                                    <?= htmlspecialchars($overallGrade) ?>
                                </span>
                            </div>
                            <div class="space-y-3">
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-gray-600">Feedback Rating</span>
                                        <span class="font-medium"><?= $overallPerformance['feedback_rating'] ?>/5</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-blue-600 h-2 rounded-full" 
                                             style="width: <?= ($overallPerformance['feedback_rating'] / 5) * 100 ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-gray-600">Test Scores</span>
                                        <span class="font-medium"><?= $overallPerformance['test_score'] ?>%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-green-600 h-2 rounded-full" 
                                             style="width: <?= $overallPerformance['test_score'] ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-gray-600">Attendance</span>
                                        <span class="font-medium"><?= $overallPerformance['attendance'] ?>%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-purple-600 h-2 rounded-full" 
                                             style="width: <?= $overallPerformance['attendance'] ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Performance Chart -->
                        <div class="lg:col-span-2">
                            <div class="bg-white border border-gray-200 rounded-xl p-6 h-full">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Performance Overview</h3>
                                <div class="h-64">
                                    <canvas id="performanceChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Three Main Sections -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- 1. Weekly Feedback -->
                        <div class="bg-white border border-gray-200 rounded-xl p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                    <i class="fas fa-comments text-blue-500 mr-2"></i>
                                    Weekly Feedback
                                </h3>
                                <?php if ($weeklyFeedback): ?>
                                    <span class="text-sm text-gray-500">
                                        <?= htmlspecialchars($weeklyFeedback['week_start']) ?> - <?= htmlspecialchars($weeklyFeedback['week_end']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($weeklyFeedback): ?>
                                <div class="mb-4">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm text-gray-600">Trainer: <?= htmlspecialchars($weeklyFeedback['trainer_name'] ?? 'N/A') ?></span>
                                        <div class="star-rating">
                                            <?php
                                            $rating = $weeklyFeedback['rating'];
                                            for ($i = 1; $i <= 5; $i++) {
                                                if ($i <= $rating) {
                                                    echo '<i class="fas fa-star"></i>';
                                                } else {
                                                    echo '<i class="far fa-star empty"></i>';
                                                }
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-gray-50 rounded-lg p-4">
                                        <p class="text-gray-700"><?= nl2br(htmlspecialchars($weeklyFeedback['remarks'] ?? 'No remarks provided.')) ?></p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-comment-slash text-4xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-500">No feedback available for this week.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- 2. Weekly Test Results -->
                        <div class="bg-white border border-gray-200 rounded-xl p-6">
                            <h3 class="text-lg font-semibold text-gray-800 flex items-center mb-4">
                                <i class="fas fa-file-alt text-green-500 mr-2"></i>
                                Weekly Tests
                            </h3>
                            
                            <?php if ($weeklyTests): ?>
                                <div class="space-y-4">
                                    <?php foreach ($weeklyTests as $test): ?>
                                        <div class="border border-gray-100 rounded-lg p-4 hover:bg-gray-50">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <h4 class="font-medium text-gray-800"><?= htmlspecialchars($test['test_name']) ?></h4>
                                                    <p class="text-sm text-gray-600"><?= htmlspecialchars($test['subject']) ?></p>
                                                </div>
                                                <span class="status-badge <?= $test['result'] == 'pass' ? 'test-passed' : 'test-failed' ?>">
                                                    <?= ucfirst($test['result']) ?>
                                                </span>
                                            </div>
                                            
                                            <div class="grid grid-cols-2 gap-4 text-sm mb-3">
                                                <div>
                                                    <span class="text-gray-600">Score:</span>
                                                    <span class="font-medium ml-2"><?= $test['obtained_marks'] ?>/<?= $test['total_marks'] ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-gray-600">Percentage:</span>
                                                    <span class="font-medium ml-2"><?= $test['percentage'] ?>%</span>
                                                </div>
                                            </div>
                                            
                                            <div class="flex justify-between text-xs text-gray-500">
                                                <span><?= htmlspecialchars($test['submitted_date']) ?></span>
                                                <span>Passing: <?= $test['passing_marks'] ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="bg-gray-50 rounded-lg p-4 mt-4">
                                        <div class="flex justify-between items-center">
                                            <span class="text-gray-700">Average Test Score:</span>
                                            <span class="text-xl font-bold <?= $averageTestScore >= 60 ? 'text-green-600' : 'text-red-600' ?>">
                                                <?= $averageTestScore ?>%
                                            </span>
                                        </div>
                                        <div class="text-sm text-gray-500 mt-1">
                                            Based on <?= $totalTests ?> test<?= $totalTests != 1 ? 's' : '' ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-file-excel text-4xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-500">No tests taken this week.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- 3. Weekly Attendance -->
                        <div class="bg-white border border-gray-200 rounded-xl p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                    <i class="fas fa-calendar-check text-purple-500 mr-2"></i>
                                    Weekly Attendance
                                </h3>
                                <span class="text-2xl font-bold <?= $attendanceStats['percentage'] >= 75 ? 'text-green-600' : 'text-red-600' ?>">
                                    <?= $attendanceStats['percentage'] ?>%
                                </span>
                            </div>
                            
                            <?php if ($weeklyAttendance): ?>
                                <div class="mb-4">
                                    <div class="grid grid-cols-2 gap-4 mb-6">
                                        <div class="bg-green-50 rounded-lg p-4 text-center">
                                            <div class="text-2xl font-bold text-green-700"><?= $attendanceStats['present'] ?></div>
                                            <div class="text-sm text-green-600">Present</div>
                                        </div>
                                        <div class="bg-red-50 rounded-lg p-4 text-center">
                                            <div class="text-2xl font-bold text-red-700"><?= $attendanceStats['absent'] ?></div>
                                            <div class="text-sm text-red-600">Absent</div>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-2">
                                        <?php foreach ($weeklyAttendance as $attendance): ?>
                                            <div class="flex items-center justify-between py-2 border-b border-gray-100">
                                                <div class="flex items-center">
                                                    <span class="w-20 text-gray-600"><?= $attendance['day_name'] ?></span>
                                                    <span class="text-gray-800"><?= $attendance['formatted_date'] ?></span>
                                                </div>
                                                <span class="status-badge <?= strtolower($attendance['status']) == 'present' ? 'attendance-present' : 'attendance-absent' ?>">
                                                    <?= htmlspecialchars($attendance['status']) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-calendar-times text-4xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-500">No attendance records for this week.</p>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($studentInfo['batch_name_2']) || !empty($studentInfo['batch_name_3'])): ?>
                                <div class="text-xs text-gray-500 mt-4">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Attendance includes all assigned batches:
                                    <?php
                                    $batches = [];
                                    if (!empty($studentInfo['primary_batch_name'])) $batches[] = $studentInfo['primary_batch_name'];
                                    if (!empty($studentInfo['secondary_batch_name'])) $batches[] = $studentInfo['secondary_batch_name'];
                                    if (!empty($studentInfo['tertiary_batch_name'])) $batches[] = $studentInfo['tertiary_batch_name'];
                                    echo htmlspecialchars(implode(', ', $batches));
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recommendations Section -->
                    <div class="mt-8 bg-gradient-to-r from-yellow-50 to-orange-50 border border-yellow-100 rounded-xl p-6">
                        <h3 class="text-lg font-semibold text-gray-800 flex items-center mb-4">
                            <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
                            Recommendations & Suggestions
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php
                            $recommendations = [];
                            
                            // Based on feedback rating
                            if ($overallPerformance['feedback_rating'] < 3) {
                                $recommendations[] = "Seek clarification from trainer on areas needing improvement.";
                            }
                            
                            // Based on test scores
                            if ($overallPerformance['test_score'] < 60) {
                                $recommendations[] = "Review test materials and focus on weak subject areas.";
                            } elseif ($overallPerformance['test_score'] >= 85) {
                                $recommendations[] = "Excellent test performance! Consider helping peers.";
                            }
                            
                            // Based on attendance
                            if ($overallPerformance['attendance'] < 75) {
                                $recommendations[] = "Improve attendance to avoid missing important sessions.";
                            }
                            
                            // Based on overall grade
                            if (strpos($overallGrade, 'D') !== false || strpos($overallGrade, 'F') !== false) {
                                $recommendations[] = "Consider scheduling extra help sessions with trainer.";
                            }
                            
                            // Default recommendations if none generated
                            if (empty($recommendations)) {
                                $recommendations[] = "Maintain current performance level and continue regular practice.";
                                $recommendations[] = "Participate actively in class discussions and group activities.";
                            }
                            
                            foreach ($recommendations as $rec): ?>
                                <div class="flex items-start">
                                    <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                                    <span class="text-gray-700"><?= htmlspecialchars($rec) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Report Footer -->
                    <div class="mt-8 pt-6 border-t border-gray-200 text-center text-sm text-gray-500">
                        <p>Report generated on <?= date('d M Y, h:i A') ?> • 
                           <?= $isAdmin || $isMentor ? 'Generated by Admin' : 'Generated for Student' ?></p>
                        <p class="mt-1">This report is based on data from week starting <?= date('d M Y', strtotime($weekStart)) ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        
        // Print report
        function printReport() {
            window.print();
        }
        
        // Initialize performance chart
        <?php if ($student_id && $studentInfo): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('performanceChart').getContext('2d');
            
            // Calculate scores for chart
            const feedbackScore = <?= $overallPerformance['feedback_rating'] ?> * 20; // Convert 1-5 to 0-100
            const testScore = <?= $overallPerformance['test_score'] ?>;
            const attendanceScore = <?= $overallPerformance['attendance'] ?>;
            
            const performanceChart = new Chart(ctx, {
                type: 'radar',
                data: {
                    labels: ['Feedback', 'Test Scores', 'Attendance', 'Participation', 'Assignments'],
                    datasets: [{
                        label: 'Performance Score',
                        data: [
                            feedbackScore,
                            testScore,
                            attendanceScore,
                            Math.min(100, feedbackScore * 0.8 + testScore * 0.2), // Simulated participation
                            Math.min(100, testScore * 0.9) // Simulated assignments
                        ],
                        backgroundColor: 'rgba(59, 130, 246, 0.2)',
                        borderColor: 'rgb(59, 130, 246)',
                        pointBackgroundColor: 'rgb(59, 130, 246)',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: 'rgb(59, 130, 246)'
                    }]
                },
                options: {
                    scales: {
                        r: {
                            angleLines: {
                                display: true
                            },
                            suggestedMin: 0,
                            suggestedMax: 100,
                            ticks: {
                                stepSize: 20,
                                callback: function(value) {
                                    return value + '%';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.raw + '%';
                                }
                            }
                        }
                    }
                }
            });
        });
        <?php endif; ?>
        
        // Auto-refresh page when week changes (for student view)
        <?php if (!$isAdmin && !$isMentor): ?>
        document.getElementById('week').addEventListener('change', function() {
            this.form.submit();
        });
        <?php endif; ?>
    </script>
</body>
</html>