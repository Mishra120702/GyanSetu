<?php
// test_result.php - Modern result display with efficient layout
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$testId = $_GET['test_id'] ?? 0;
$attemptId = $_GET['attempt_id'] ?? null;
$studentId = $_SESSION['user_id'];

// Get student details
$studentStmt = $db->prepare("
    SELECT student_id, first_name, last_name, batch_name, batch_name_2, batch_name_3, batch_name_4 
    FROM students WHERE user_id = ?
");
$studentStmt->execute([$studentId]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);

// Get test details and specific attempt
if ($attemptId) {
    $testStmt = $db->prepare("
        SELECT t.*, ta.*
        FROM tests t
        JOIN test_attempts ta ON t.id = ta.test_id
        WHERE t.id = ? AND ta.id = ? AND ta.student_id = ?
    ");
    $testStmt->execute([$testId, $attemptId, $student['student_id']]);
} else {
    $testStmt = $db->prepare("
        SELECT t.*, ta.*
        FROM tests t
        JOIN test_attempts ta ON t.id = ta.test_id
        WHERE t.id = ? AND ta.student_id = ?
        ORDER BY ta.submitted_at DESC
        LIMIT 1
    ");
    $testStmt->execute([$testId, $student['student_id']]);
}
$testResult = $testStmt->fetch(PDO::FETCH_ASSOC);

if (!$testResult) {
    header("Location: student_dashboard.php");
    exit;
}

// Get detailed answers
$answersStmt = $db->prepare("
    SELECT tq.*, ta.selected_answer, ta.is_correct, ta.marks_obtained
    FROM test_questions tq
    LEFT JOIN test_answers ta ON tq.id = ta.question_id AND ta.attempt_id = ?
    WHERE tq.test_id = ?
    ORDER BY tq.question_order
");
$answersStmt->execute([$testResult['id'], $testId]);
$answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get batch rankings
$rankingsStmt = $db->prepare("
    SELECT 
        ta.student_id,
        s.first_name,
        s.last_name,
        ta.obtained_marks,
        ta.percentage,
        ta.submitted_at,
        ta.attempt_number,
        RANK() OVER (ORDER BY ta.percentage DESC, ta.submitted_at ASC) as rank_position
    FROM test_attempts ta
    JOIN students s ON ta.student_id = s.student_id
    WHERE ta.test_id = ? 
    AND ta.status = 'submitted'
    ORDER BY ta.percentage DESC, ta.submitted_at ASC
");
$rankingsStmt->execute([$testId]);
$rankings = $rankingsStmt->fetchAll(PDO::FETCH_ASSOC);

// Find current student's rank
$currentStudentRank = null;
$studentRankPosition = null;
foreach ($rankings as $index => $rank) {
    if ($rank['student_id'] == $student['student_id']) {
        $currentStudentRank = $rank;
        $studentRankPosition = $index + 1;
        break;
    }
}

// Calculate statistics
$totalQuestions = count($answers);
$answeredQuestions = count(array_filter($answers, fn($a) => !empty($a['selected_answer'])));
$correctQuestions = count(array_filter($answers, fn($a) => $a['is_correct'] == 1));
$accuracy = $answeredQuestions > 0 ? round(($correctQuestions / $answeredQuestions) * 100, 1) : 0;

// Get student's all attempts
$allAttemptsStmt = $db->prepare("
    SELECT * FROM test_attempts 
    WHERE test_id = ? AND student_id = ? AND status = 'submitted'
    ORDER BY attempt_number DESC
");
$allAttemptsStmt->execute([$testId, $student['student_id']]);
$allAttempts = $allAttemptsStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if can retake
$canRetake = $testResult['attempt_number'] < $testResult['max_attempts'];

// Performance indicators
$isPassed = $testResult['percentage'] >= $testResult['passing_marks'];
$performanceLevel = $testResult['percentage'] >= 80 ? 'Excellent' : ($testResult['percentage'] >= 60 ? 'Good' : ($testResult['percentage'] >= 40 ? 'Average' : 'Needs Improvement'));
$performanceColor = $testResult['percentage'] >= 80 ? 'green' : ($testResult['percentage'] >= 60 ? 'blue' : ($testResult['percentage'] >= 40 ? 'yellow' : 'red'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Result - <?= htmlspecialchars($testResult['title']) ?></title>
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
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        
        .result-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            position: relative;
            overflow: hidden;
        }
        
        .result-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,50 Q25,40 50,50 T100,50" stroke="white" stroke-width="0.5" fill="none" opacity="0.1"/></svg>');
            pointer-events: none;
        }
        
        .score-circle {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 36px;
            position: relative;
            background: white;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
        }
        
        .score-circle::before {
            content: '';
            position: absolute;
            top: -5px;
            left: -5px;
            right: -5px;
            bottom: -5px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            z-index: -1;
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }
        
        .tab-button {
            padding: 12px 24px;
            font-weight: 600;
            color: #6B7280;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .tab-button.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .answer-correct {
            border-left: 4px solid #10B981;
            background: linear-gradient(to right, #F0FDF4, white);
        }
        
        .answer-wrong {
            border-left: 4px solid #EF4444;
            background: linear-gradient(to right, #FEF2F2, white);
        }
        
        .answer-unanswered {
            border-left: 4px solid #6B7280;
            background: linear-gradient(to right, #F9FAFB, white);
        }
        
        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        
        .rank-1 { background: #FFD700; color: #000; }
        .rank-2 { background: #C0C0C0; color: #000; }
        .rank-3 { background: #CD7F32; color: #000; }
        
        .current-student {
            background: #EFF6FF;
            border: 2px solid #3B82F6;
        }
        
        .progress-bar {
            height: 10px;
            border-radius: 5px;
            background: #E5E7EB;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 1s ease-in-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate-slide-in {
            animation: slideIn 0.5s ease-out forwards;
        }
    </style>
</head>
<body class="min-h-screen">
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
                        <h1 class="text-xl md:text-2xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                            <i class="fas fa-chart-line mr-2"></i>
                            Test Result
                        </h1>
                        <p class="text-sm text-gray-600"><?= htmlspecialchars($testResult['title']) ?></p>
                    </div>
                </div>
                
                <div class="flex items-center space-x-4">
                    <a href="student_dashboard.php" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="p-4 md:p-8">
            <div class="max-w-7xl mx-auto space-y-6">
                <!-- Result Header -->
                <div class="result-header rounded-2xl p-8 text-white relative overflow-hidden">
                    <div class="relative z-10">
                        <div class="flex flex-col lg:flex-row items-center justify-between">
                            <div class="text-center lg:text-left mb-6 lg:mb-0">
                                <h2 class="text-3xl font-bold mb-2">Test Completed! 🎉</h2>
                                <p class="text-xl opacity-90 mb-4"><?= htmlspecialchars($testResult['title']) ?></p>
                                
                                <div class="space-y-2">
                                    <p><i class="fas fa-user mr-2 opacity-75"></i> <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></p>
                                    <p><i class="fas fa-calendar mr-2 opacity-75"></i> <?= date('F j, Y g:i A', strtotime($testResult['submitted_at'])) ?></p>
                                    <p><i class="fas fa-clock mr-2 opacity-75"></i> Time taken: <?= floor($testResult['time_taken_seconds'] / 60) ?>m <?= $testResult['time_taken_seconds'] % 60 ?>s</p>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <div class="score-circle mb-4">
                                    <?= round($testResult['percentage']) ?>%
                                </div>
                                <p class="text-xl font-semibold mb-1">
                                    <?= $testResult['obtained_marks'] ?>/<?= $testResult['total_marks'] ?> Marks
                                </p>
                                <span class="px-4 py-2 bg-white bg-opacity-20 rounded-full text-sm font-medium">
                                    <?= $isPassed ? '✅ Passed' : '📝 Needs Improvement' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Performance Overview -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="glass-card p-6 rounded-xl stat-card">
                        <div class="flex items-center">
                            <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center mr-4">
                                <i class="fas fa-question-circle text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Total Questions</p>
                                <p class="text-2xl font-bold text-gray-800"><?= $totalQuestions ?></p>
                                <p class="text-xs text-gray-500"><?= $answeredQuestions ?> attempted</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-6 rounded-xl stat-card">
                        <div class="flex items-center">
                            <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center mr-4">
                                <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Correct</p>
                                <p class="text-2xl font-bold text-green-600"><?= $correctQuestions ?></p>
                                <p class="text-xs text-gray-500"><?= round(($correctQuestions/$totalQuestions)*100) ?>% accuracy</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-6 rounded-xl stat-card">
                        <div class="flex items-center">
                            <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mr-4">
                                <i class="fas fa-times-circle text-red-600 text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Wrong</p>
                                <p class="text-2xl font-bold text-red-600"><?= $testResult['wrong_answers'] ?></p>
                                <p class="text-xs text-gray-500"><?= round(($testResult['wrong_answers']/$totalQuestions)*100) ?>% incorrect</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="glass-card p-6 rounded-xl stat-card">
                        <div class="flex items-center">
                            <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center mr-4">
                                <i class="fas fa-trophy text-purple-600 text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Rank</p>
                                <p class="text-2xl font-bold text-purple-600">#<?= $studentRankPosition ?? '-' ?></p>
                                <p class="text-xs text-gray-500">out of <?= count($rankings) ?> students</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="glass-card rounded-xl overflow-hidden">
                    <div class="border-b border-gray-200">
                        <div class="flex overflow-x-auto">
                            <button onclick="showTab('summary')" class="tab-button active" id="summaryTab">
                                <i class="fas fa-chart-pie mr-2"></i> Summary
                            </button>
                            <button onclick="showTab('answers')" class="tab-button" id="answersTab">
                                <i class="fas fa-file-alt mr-2"></i> Answer Review
                            </button>
                            <button onclick="showTab('rankings')" class="tab-button" id="rankingsTab">
                                <i class="fas fa-trophy mr-2"></i> Rankings
                            </button>
                            <button onclick="showTab('attempts')" class="tab-button" id="attemptsTab">
                                <i class="fas fa-history mr-2"></i> Attempt History
                            </button>
                        </div>
                    </div>
                    
                    <!-- Summary Tab -->
                    <div id="summaryContent" class="p-6">
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Score Breakdown -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Score Breakdown</h3>
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span>Your Score</span>
                                            <span class="font-semibold"><?= round($testResult['percentage'], 1) ?>%</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill bg-<?= $performanceColor ?>-500" style="width: <?= $testResult['percentage'] ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <div class="flex justify-between text-sm mb-1">
                                            <span>Passing Marks</span>
                                            <span class="font-semibold"><?= $testResult['passing_marks'] ?>%</span>
                                        </div>
                                        <div class="progress-bar">
                                            <div class="progress-fill bg-gray-500" style="width: <?= $testResult['passing_marks'] ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-2 gap-4 mt-4">
                                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                                            <p class="text-2xl font-bold text-gray-800"><?= $testResult['obtained_marks'] ?></p>
                                            <p class="text-sm text-gray-600">Marks Obtained</p>
                                        </div>
                                        <div class="text-center p-4 bg-gray-50 rounded-lg">
                                            <p class="text-2xl font-bold text-gray-800"><?= $testResult['total_marks'] ?></p>
                                            <p class="text-sm text-gray-600">Total Marks</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Performance Analysis -->
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Performance Analysis</h3>
                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium">Performance Level</p>
                                            <p class="text-sm text-gray-600">Based on your score</p>
                                        </div>
                                        <span class="px-3 py-1 bg-<?= $performanceColor ?>-100 text-<?= $performanceColor ?>-700 rounded-full text-sm font-medium">
                                            <?= $performanceLevel ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium">Accuracy</p>
                                            <p class="text-sm text-gray-600">Correct vs Total</p>
                                        </div>
                                        <span class="text-2xl font-bold text-gray-800"><?= $accuracy ?>%</span>
                                    </div>
                                    
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <p class="font-medium">Attempt</p>
                                            <p class="text-sm text-gray-600">Current attempt number</p>
                                        </div>
                                        <span class="text-2xl font-bold text-gray-800">#<?= $testResult['attempt_number'] ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Answers Review Tab -->
                    <div id="answersContent" class="p-6 hidden">
                        <div class="flex justify-between items-center mb-6">
                            <h3 class="text-lg font-semibold text-gray-800">Answer Review</h3>
                            <div class="flex space-x-2">
                                <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm">
                                    <i class="fas fa-check mr-1"></i> Correct: <?= $correctQuestions ?>
                                </span>
                                <span class="px-3 py-1 bg-red-100 text-red-700 rounded-full text-sm">
                                    <i class="fas fa-times mr-1"></i> Wrong: <?= $testResult['wrong_answers'] ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <?php foreach ($answers as $index => $answer): ?>
                            <div class="<?= $answer['is_correct'] ? 'answer-correct' : ($answer['selected_answer'] ? 'answer-wrong' : 'answer-unanswered') ?> p-5 rounded-lg">
                                <div class="flex flex-wrap items-center gap-3 mb-3">
                                    <span class="bg-gray-800 text-white px-3 py-1 rounded-full text-sm font-medium">
                                        Q<?= $index + 1 ?>
                                    </span>
                                    <span class="text-sm font-medium">Marks: <?= $answer['marks_obtained'] ?>/<?= $answer['marks'] ?></span>
                                    <?php if ($answer['is_correct']): ?>
                                        <span class="bg-green-100 text-green-700 px-2 py-1 rounded text-xs">Correct</span>
                                    <?php elseif ($answer['selected_answer']): ?>
                                        <span class="bg-red-100 text-red-700 px-2 py-1 rounded text-xs">Incorrect</span>
                                    <?php else: ?>
                                        <span class="bg-gray-100 text-gray-700 px-2 py-1 rounded text-xs">Unanswered</span>
                                    <?php endif; ?>
                                </div>
                                
                                <p class="text-gray-800 mb-4 font-medium"><?= htmlspecialchars($answer['question_text']) ?></p>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <?php 
                                    $options = ['a' => $answer['option_a'], 'b' => $answer['option_b'], 'c' => $answer['option_c'], 'd' => $answer['option_d']];
                                    foreach ($options as $key => $option): 
                                        $isCorrect = $key === $answer['correct_answer'];
                                        $isSelected = $key === $answer['selected_answer'];
                                    ?>
                                    <div class="p-3 rounded border <?= $isCorrect ? 'border-green-500 bg-green-50' : ($isSelected ? 'border-red-500 bg-red-50' : 'border-gray-200') ?>">
                                        <div class="flex items-center">
                                            <span class="w-6 h-6 rounded-full border flex items-center justify-center mr-3 text-sm font-medium
                                                       <?= $isCorrect ? 'border-green-500 bg-green-500 text-white' : ($isSelected ? 'border-red-500 bg-red-500 text-white' : 'border-gray-400') ?>">
                                                <?= strtoupper($key) ?>
                                            </span>
                                            <span class="flex-1 <?= $isCorrect ? 'text-green-700' : ($isSelected ? 'text-red-700' : 'text-gray-700') ?>">
                                                <?= htmlspecialchars($option) ?>
                                            </span>
                                            <?php if ($isCorrect): ?>
                                                <i class="fas fa-check text-green-600 ml-2"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <?php if ($answer['explanation']): ?>
                                <div class="mt-4 p-4 bg-blue-50 rounded-lg">
                                    <p class="text-sm text-blue-800">
                                        <i class="fas fa-lightbulb mr-2"></i>
                                        <?= htmlspecialchars($answer['explanation']) ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Rankings Tab -->
                    <div id="rankingsContent" class="p-6 hidden">
                        <h3 class="text-lg font-semibold text-gray-800 mb-6">Leaderboard</h3>
                        
                        <?php if (empty($rankings)): ?>
                        <div class="text-center py-12">
                            <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-users text-3xl text-gray-400"></i>
                            </div>
                            <p class="text-gray-600">No rankings available yet</p>
                        </div>
                        <?php else: ?>
                        <!-- Top 3 -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                            <?php for ($i = 0; $i < min(3, count($rankings)); $i++): ?>
                            <div class="text-center p-6 <?= $i == 0 ? 'bg-yellow-50' : ($i == 1 ? 'bg-gray-50' : 'bg-orange-50') ?> rounded-lg">
                                <div class="rank-badge rank-<?= $i + 1 ?> mx-auto mb-3">
                                    <?= $i + 1 ?>
                                </div>
                                <p class="font-bold text-gray-800"><?= htmlspecialchars($rankings[$i]['first_name'] . ' ' . $rankings[$i]['last_name']) ?></p>
                                <p class="text-2xl font-bold mt-2"><?= round($rankings[$i]['percentage']) ?>%</p>
                                <p class="text-sm text-gray-600"><?= $rankings[$i]['obtained_marks'] ?> marks</p>
                            </div>
                            <?php endfor; ?>
                        </div>
                        
                        <!-- Full List -->
                        <div class="space-y-2">
                            <?php foreach ($rankings as $index => $rank): ?>
                            <div class="flex items-center justify-between p-4 rounded-lg border <?= $rank['student_id'] == $student['student_id'] ? 'current-student' : 'border-gray-200' ?>">
                                <div class="flex items-center space-x-4">
                                    <span class="rank-badge <?= ($index + 1) <= 3 ? 'rank-' . ($index + 1) : 'bg-gray-200 text-gray-700' ?>">
                                        <?= $index + 1 ?>
                                    </span>
                                    <div>
                                        <p class="font-medium">
                                            <?= htmlspecialchars($rank['first_name'] . ' ' . $rank['last_name']) ?>
                                            <?php if ($rank['student_id'] == $student['student_id']): ?>
                                                <span class="ml-2 text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded">You</span>
                                            <?php endif; ?>
                                        </p>
                                        <p class="text-sm text-gray-600">Attempt #<?= $rank['attempt_number'] ?></p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-xl font-bold"><?= round($rank['percentage']) ?>%</p>
                                    <p class="text-sm text-gray-600"><?= $rank['obtained_marks'] ?> marks</p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Attempt History Tab -->
                    <div id="attemptsContent" class="p-6 hidden">
                        <h3 class="text-lg font-semibold text-gray-800 mb-6">Attempt History</h3>
                        
                        <?php if (empty($allAttempts)): ?>
                        <div class="text-center py-12">
                            <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-history text-3xl text-gray-400"></i>
                            </div>
                            <p class="text-gray-600">No attempt history available</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($allAttempts as $attempt): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors cursor-pointer" onclick="window.location.href='?test_id=<?= $testId ?>&attempt_id=<?= $attempt['id'] ?>'">
                                <div class="flex items-center space-x-4">
                                    <div class="text-center">
                                        <p class="text-2xl font-bold <?= $attempt['percentage'] >= $testResult['passing_marks'] ? 'text-green-600' : 'text-red-600' ?>">
                                            <?= round($attempt['percentage']) ?>%
                                        </p>
                                    </div>
                                    <div>
                                        <p class="font-medium">Attempt #<?= $attempt['attempt_number'] ?></p>
                                        <p class="text-sm text-gray-600">
                                            <?= $attempt['obtained_marks'] ?>/<?= $attempt['total_marks'] ?> marks • 
                                            <?= date('M j, g:i A', strtotime($attempt['submitted_at'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <?php if ($attempt['id'] == $testResult['id']): ?>
                                <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full text-sm">Current</span>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="flex flex-wrap justify-between items-center gap-4">
                    <div class="flex space-x-3">
                        <a href="student_dashboard.php" class="px-6 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                            <i class="fas fa-home mr-2"></i> Dashboard
                        </a>
                        <?php if ($canRetake): ?>
                        <a href="take_test.php?test_id=<?= $testId ?>" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-redo mr-2"></i> Retake Test
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <div class="flex space-x-3">
                        <button onclick="window.print()" class="px-4 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                            <i class="fas fa-print mr-2"></i> Print
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        if (sidebar && overlay) {
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }
    }

    function showTab(tabName) {
        // Hide all tab contents
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.add('hidden');
        });
        
        // Remove active class from all tabs
        document.querySelectorAll('.tab-button').forEach(button => {
            button.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabName + 'Content').classList.remove('hidden');
        document.getElementById(tabName + 'Tab').classList.add('active');
    }

    // Animate progress bars on load
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(() => {
            document.querySelectorAll('.progress-fill').forEach(bar => {
                bar.style.width = bar.style.width;
            });
        }, 100);
    });
    </script>
</body>
</html>