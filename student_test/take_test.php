<?php
// take_test.php - Enhanced version with modern design
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

$testId = $_GET['test_id'] ?? 0;

// Get student details
$studentStmt = $db->prepare("SELECT student_id FROM students WHERE user_id = ?");
$studentStmt->execute([$_SESSION['user_id']]);
$student = $studentStmt->fetch(PDO::FETCH_ASSOC);
$studentId = $student['student_id'];

// Get test details
$testStmt = $db->prepare("
    SELECT t.*, COUNT(tq.id) as question_count
    FROM tests t
    LEFT JOIN test_questions tq ON t.id = tq.test_id
    WHERE t.id = ? AND t.is_active = 1
    GROUP BY t.id
");
$testStmt->execute([$testId]);
$test = $testStmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    header("Location: student_dashboard.php");
    exit;
}

// Check if student can take test
$attemptStmt = $db->prepare("
    SELECT COUNT(*) as attempts, MAX(attempt_number) as last_attempt
    FROM test_attempts 
    WHERE test_id = ? AND student_id = ? AND status = 'submitted'
");
$attemptStmt->execute([$testId, $studentId]);
$attemptData = $attemptStmt->fetch(PDO::FETCH_ASSOC);

if ($attemptData['attempts'] >= $test['max_attempts']) {
    header("Location: test_result.php?test_id=" . $testId);
    exit;
}

$attemptNumber = ($attemptData['last_attempt'] ?? 0) + 1;

// Check if there's an existing in-progress attempt
$existingAttemptStmt = $db->prepare("
    SELECT id FROM test_attempts 
    WHERE test_id = ? AND student_id = ? AND status = 'in_progress'
    ORDER BY started_at DESC LIMIT 1
");
$existingAttemptStmt->execute([$testId, $studentId]);
$existingAttempt = $existingAttemptStmt->fetch(PDO::FETCH_ASSOC);

if ($existingAttempt) {
    $attemptId = $existingAttempt['id'];
    // Update start time
    $updateStmt = $db->prepare("UPDATE test_attempts SET started_at = NOW() WHERE id = ?");
    $updateStmt->execute([$attemptId]);
} else {
    // Create new attempt
    $createStmt = $db->prepare("
        INSERT INTO test_attempts (test_id, student_id, attempt_number, started_at, 
                                 total_questions, status)
        VALUES (?, ?, ?, NOW(), ?, 'in_progress')
    ");
    $createStmt->execute([$testId, $studentId, $attemptNumber, $test['question_count']]);
    $attemptId = $db->lastInsertId();
}

// Get questions for this test
$questionsStmt = $db->prepare("
    SELECT tq.*
    FROM test_questions tq
    WHERE tq.test_id = ?
    ORDER BY tq.question_order
");
$questionsStmt->execute([$testId]);
$questions = $questionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get saved answers
$savedAnswersStmt = $db->prepare("
    SELECT question_id, selected_answer 
    FROM test_answers 
    WHERE attempt_id = ?
");
$savedAnswersStmt->execute([$attemptId]);
$savedAnswers = $savedAnswersStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$currentTime = time();
$endTime = $currentTime + ($test['duration_minutes'] * 60);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Take Test: <?= htmlspecialchars($test['title']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
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
        
        .question-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .question-nav-btn {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            transition: all 0.3s ease;
            cursor: pointer;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .question-nav-btn::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transform: rotate(45deg);
            transition: transform 0.6s ease;
        }
        
        .question-nav-btn:hover::before {
            transform: rotate(45deg) translate(50%, 50%);
        }
        
        .question-nav-btn.answered {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(16, 185, 129, 0.3);
        }
        
        .question-nav-btn.current {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            color: white;
            transform: scale(1.15) translateY(-3px);
            box-shadow: 0 8px 16px rgba(59, 130, 246, 0.4);
            z-index: 10;
            border: 3px solid white;
        }
        
        .question-nav-btn.unanswered {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            color: #6b7280;
        }
        
        .question-nav-btn.marked {
            background: linear-gradient(135deg, #f59e0b, #fbbf24);
            color: white;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .timer-danger {
            animation: pulseDanger 1s infinite;
        }
        
        @keyframes pulseDanger {
            0%, 100% { color: #ef4444; text-shadow: 0 0 10px rgba(239, 68, 68, 0.5); }
            50% { color: #fca5a5; text-shadow: 0 0 20px rgba(239, 68, 68, 0.8); }
        }
        
        .option-selected {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            border: 2px solid #3b82f6;
            transform: translateX(10px);
            box-shadow: 0 4px 15px rgba(59, 130, 246, 0.2);
        }
        
        .option-item {
            transition: all 0.3s ease;
        }
        
        .option-item:hover {
            transform: translateX(5px);
        }
        
        .check-circle {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid #d1d5db;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-right: 16px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .option-item input:checked + label .check-circle {
            background: linear-gradient(135deg, #3b82f6, #60a5fa);
            border-color: #3b82f6;
            color: white;
            transform: rotate(360deg);
        }
        
        .option-item input:checked + label .check-circle::before {
            content: '✓';
            font-weight: bold;
        }
        
        .test-sidebar {
            height: calc(100vh - 80px);
            position: sticky;
            top: 80px;
            overflow-y: auto;
        }
        
        .floating-btn {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
            100% { transform: translateY(0px); }
        }
        
        .highlight {
            position: relative;
        }
        
        .highlight::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #667eea, #764ba2);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }
        
        .highlight:hover::after {
            transform: scaleX(1);
        }
        
        .progress-ring {
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .shimmer {
            position: relative;
            overflow: hidden;
        }
        
        .shimmer::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transform: rotate(45deg);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: rotate(45deg) translateX(-50%) translateY(-50%); }
            100% { transform: rotate(45deg) translateX(50%) translateY(50%); }
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            animation: modalBgFade 0.3s ease;
        }
        
        @keyframes modalBgFade {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            transform: translateY(-20px);
            animation: modalSlide 0.4s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        @keyframes modalSlide {
            to { transform: translateY(0); }
        }
        
        .test-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .stats-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
        }
        
        .timer-circle {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 4px solid;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.1);
        }
        
        .timer-circle::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transform: rotate(45deg);
            animation: shimmer 4s infinite;
        }
        
        .confetti {
            position: fixed;
            width: 10px;
            height: 10px;
            background: linear-gradient(135deg, #f59e0b, #ef4444, #10b981, #3b82f6);
            opacity: 0;
            z-index: 1000;
            pointer-events: none;
        }
        
        .success-check {
            animation: successCheck 0.5s ease-in-out forwards;
        }
        
        @keyframes successCheck {
            0% { transform: scale(0); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        .test-complete-modal {
            animation: scaleIn 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes scaleIn {
            from { transform: scale(0.5); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Animated Background Elements -->
    <div class="fixed top-0 right-0 w-96 h-96 bg-purple-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 animate-pulse"></div>
    <div class="fixed bottom-0 left-0 w-96 h-96 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-10 float"></div>
    
    <?php include '../header.php'; ?>
    
    <!-- Enhanced Test Header -->
    <div class="test-header text-white">
        <div class="container mx-auto px-4 py-4">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
                <!-- Test Info -->
                <div class="mb-4 lg:mb-0">
                    <div class="flex items-center space-x-3 mb-2">
                        <div class="bg-white/20 p-2 rounded-lg">
                            <i class="fas fa-vial text-xl"></i>
                        </div>
                        <h1 class="text-xl lg:text-2xl font-bold"><?= htmlspecialchars($test['title']) ?></h1>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <div class="stats-card">
                            <i class="fas fa-hashtag mr-2"></i>
                            Attempt #<?= $attemptNumber ?> of <?= $test['max_attempts'] ?>
                        </div>
                        <div class="stats-card">
                            <i class="fas fa-clock mr-2"></i>
                            <?= $test['duration_minutes'] ?> minutes
                        </div>
                        <div class="stats-card">
                            <i class="fas fa-question-circle mr-2"></i>
                            <?= count($questions) ?> questions
                        </div>
                        <div class="stats-card">
                            <i class="fas fa-star mr-2"></i>
                            <?= $test['total_marks'] ?> marks
                        </div>
                    </div>
                </div>
                
                <!-- Timer and Stats -->
                <div class="flex items-center space-x-6">
                    <!-- Timer -->
                    <div class="text-center">
                        <div class="timer-circle border-green-500 text-white mb-2" id="timer">
                            <?= str_pad($test['duration_minutes'], 2, '0', STR_PAD_LEFT) ?>:00
                        </div>
                        <div class="text-xs text-white/80">Time Remaining</div>
                    </div>
                    
                    <!-- Answered Counter -->
                    <div class="text-center">
                        <div id="answeredCount" class="text-2xl lg:text-3xl font-bold text-white">0/<?= count($questions) ?></div>
                        <div class="text-xs text-white/80">Answered</div>
                    </div>
                    
                    <!-- Submit Button -->
                    <button id="submitTestBtn" 
                            class="bg-gradient-to-r from-red-600 to-pink-600 text-white px-6 py-3 rounded-xl hover:from-red-700 hover:to-pink-700 transition-all duration-300 font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-1 group">
                        <i class="fas fa-paper-plane mr-2 group-hover:rotate-12 transition-transform duration-300"></i>
                        Submit Test
                        <i class="fas fa-arrow-right ml-2 transform group-hover:translate-x-2 transition-transform duration-300"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Area -->
    <div class="container mx-auto px-4 py-6">
        <div class="flex flex-col lg:flex-row gap-6">
            <!-- Questions Section (Left 70%) -->
            <div class="lg:w-3/4">
                <form id="testForm">
                    <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
                    <input type="hidden" name="test_id" value="<?= $testId ?>">
                    
                    <div class="space-y-6">
                        <?php foreach ($questions as $index => $question): ?>
                            <div id="question-<?= $question['id'] ?>" 
                                 class="question-card glass-card rounded-2xl p-6 md:p-8 <?= $index === 0 ? '' : 'hidden' ?>">
                                <!-- Question Header -->
                                <div class="flex flex-col md:flex-row md:items-center justify-between mb-8">
                                    <div class="mb-4 md:mb-0">
                                        <div class="flex items-center space-x-3 mb-3">
                                            <div class="bg-gradient-to-r from-blue-500 to-purple-500 text-white px-4 py-2 rounded-xl font-bold text-lg shadow-md">
                                                Q<?= $index + 1 ?>
                                            </div>
                                            <div class="bg-gradient-to-r from-yellow-100 to-yellow-200 text-yellow-800 px-3 py-1 rounded-lg font-semibold">
                                                <?= $question['marks'] ?> mark(s)
                                            </div>
                                            <?php if (isset($savedAnswers[$question['id']])): ?>
                                                <div class="bg-gradient-to-r from-green-100 to-green-200 text-green-800 px-3 py-1 rounded-lg font-semibold animate-pulse">
                                                    <i class="fas fa-check-circle mr-1"></i> Saved
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <h2 class="text-xl font-bold text-gray-800 leading-relaxed">
                                            <?= htmlspecialchars($question['question_text']) ?>
                                        </h2>
                                    </div>
                                    
                                    <div class="text-right">
                                        <div class="text-sm text-gray-600">Difficulty</div>
                                        <div class="flex items-center">
                                            <?php for($i = 1; $i <= 3; $i++): ?>
                                                <div class="w-3 h-3 rounded-full <?= $i <= ($question['marks'] ?? 1) ? 'bg-gradient-to-r from-red-500 to-red-600' : 'bg-gray-300' ?> mx-1"></div>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Options -->
                                <div class="space-y-4">
                                    <?php 
                                    $options = [
                                        'a' => $question['option_a'],
                                        'b' => $question['option_b'],
                                        'c' => $question['option_c'],
                                        'd' => $question['option_d']
                                    ];
                                    ?>
                                    
                                    <?php foreach ($options as $key => $option): ?>
                                        <div class="option-item">
                                            <input type="radio" 
                                                   name="answer[<?= $question['id'] ?>]" 
                                                   value="<?= $key ?>" 
                                                   id="q<?= $question['id'] ?>_<?= $key ?>"
                                                   class="hidden"
                                                   <?= ($savedAnswers[$question['id']] ?? '') === $key ? 'checked' : '' ?>>
                                            <label for="q<?= $question['id'] ?>_<?= $key ?>" 
                                                   class="flex items-center p-4 md:p-6 border-2 border-gray-200 rounded-xl cursor-pointer hover:bg-gradient-to-r hover:from-blue-50 hover:to-blue-100 transition-all duration-300 <?= ($savedAnswers[$question['id']] ?? '') === $key ? 'option-selected' : '' ?>">
                                                <div class="check-circle">
                                                    <?= strtoupper($key) ?>
                                                </div>
                                                <div class="flex-1">
                                                    <span class="text-gray-800 text-lg leading-relaxed"><?= htmlspecialchars($option) ?></span>
                                                </div>
                                                <?php if (($savedAnswers[$question['id']] ?? '') === $key): ?>
                                                    <div class="ml-4">
                                                        <div class="w-6 h-6 rounded-full bg-gradient-to-r from-green-500 to-green-600 flex items-center justify-center text-white">
                                                            <i class="fas fa-check text-xs"></i>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Navigation Buttons -->
                                <div class="flex flex-col md:flex-row justify-between items-center mt-10 pt-8 border-t border-gray-200">
                                    <button type="button" 
                                            onclick="previousQuestion()"
                                            class="mb-4 md:mb-0 px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 hover:border-gray-400 transition-all duration-300 font-semibold transform hover:-translate-y-1 <?= $index === 0 ? 'invisible' : '' ?> group">
                                        <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform duration-300"></i>
                                        Previous Question
                                    </button>
                                    
                                    <div class="flex flex-col md:flex-row gap-4">
                                        <button type="button" 
                                                onclick="markForReview(<?= $question['id'] ?>)"
                                                class="px-6 py-3 border-2 border-yellow-400 text-yellow-700 rounded-xl hover:bg-gradient-to-r hover:from-yellow-50 hover:to-yellow-100 transition-all duration-300 font-semibold transform hover:-translate-y-1 group">
                                            <i class="fas fa-flag mr-2 group-hover:rotate-12 transition-transform duration-300"></i>
                                            Mark for Review
                                        </button>
                                        
                                        <?php if ($index < count($questions) - 1): ?>
                                            <button type="button" 
                                                    onclick="nextQuestion()"
                                                    class="px-6 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-xl hover:from-blue-700 hover:to-purple-700 transition-all duration-300 font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-1 group">
                                                Next Question
                                                <i class="fas fa-arrow-right ml-2 group-hover:translate-x-2 transition-transform duration-300"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" 
                                                    onclick="submitTest()"
                                                    class="px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl hover:from-green-700 hover:to-emerald-700 transition-all duration-300 font-semibold shadow-lg hover:shadow-xl transform hover:-translate-y-1 group">
                                                Complete Test
                                                <i class="fas fa-check-circle ml-2 group-hover:scale-125 transition-transform duration-300"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
            
            <!-- Enhanced Navigation Sidebar -->
            <div class="lg:w-1/4">
                <div class="test-sidebar glass-card rounded-2xl p-5 md:p-6">
                    <div class="mb-8">
                        <h3 class="text-xl font-bold text-gray-800 mb-3 flex items-center highlight">
                            <i class="fas fa-map-signs mr-3 text-purple-500"></i>
                            Test Navigation
                        </h3>
                        <p class="text-sm text-gray-600 mb-4">Click on question numbers to jump between questions</p>
                        
                        <!-- Enhanced Question Grid -->
                        <div class="grid grid-cols-4 md:grid-cols-5 gap-3 mb-8">
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="text-center">
                                    <div id="nav-<?= $question['id'] ?>" 
                                         class="question-nav-btn <?= ($savedAnswers[$question['id']] ?? '') ? 'answered' : 'unanswered' ?> <?= $index === 0 ? 'current' : '' ?>"
                                         onclick="goToQuestion(<?= $index ?>)"
                                         title="Question <?= $index + 1 ?>">
                                        <?= $index + 1 ?>
                                    </div>
                                    <div class="text-xs mt-2 text-gray-600 font-medium">Q<?= $index + 1 ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Legend -->
                        <div class="space-y-3 mb-8">
                            <h4 class="font-semibold text-gray-800 mb-2">Legend</h4>
                            <div class="flex items-center space-x-3 mb-2">
                                <div class="question-nav-btn answered"></div>
                                <span class="text-sm text-gray-700">Answered</span>
                            </div>
                            <div class="flex items-center space-x-3 mb-2">
                                <div class="question-nav-btn unanswered"></div>
                                <span class="text-sm text-gray-700">Not Answered</span>
                            </div>
                            <div class="flex items-center space-x-3 mb-2">
                                <div class="question-nav-btn current"></div>
                                <span class="text-sm text-gray-700">Current</span>
                            </div>
                            <div class="flex items-center space-x-3">
                                <div class="question-nav-btn marked"></div>
                                <span class="text-sm text-gray-700">Marked for Review</span>
                            </div>
                        </div>
                        
                        <!-- Progress Stats -->
                        <div class="bg-gradient-to-r from-blue-50 to-purple-50 p-4 rounded-xl mb-6">
                            <h4 class="font-semibold text-gray-800 mb-3">Progress Summary</h4>
                            <div class="space-y-2">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600">Total Questions:</span>
                                    <span class="font-bold text-gray-800"><?= count($questions) ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600">Answered:</span>
                                    <span id="answeredStat" class="font-bold text-green-600">0</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600">Remaining:</span>
                                    <span id="remainingStat" class="font-bold text-blue-600"><?= count($questions) ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600">Marked:</span>
                                    <span id="markedStat" class="font-bold text-yellow-600">0</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="space-y-3">
                            <button onclick="saveAnswers()" 
                                    class="w-full bg-gradient-to-r from-blue-500 to-blue-600 text-white py-2 rounded-lg hover:from-blue-600 hover:to-blue-700 transition-all duration-300 font-medium">
                                <i class="fas fa-save mr-2"></i> Save Progress
                            </button>
                            <button onclick="clearCurrentAnswer()" 
                                    class="w-full border-2 border-red-300 text-red-600 py-2 rounded-lg hover:bg-red-50 transition-all duration-300 font-medium">
                                <i class="fas fa-times mr-2"></i> Clear Current Answer
                            </button>
                            <button onclick="showSubmitConfirmation()" 
                                    class="w-full bg-gradient-to-r from-red-500 to-pink-500 text-white py-2 rounded-lg hover:from-red-600 hover:to-pink-600 transition-all duration-300 font-medium">
                                <i class="fas fa-paper-plane mr-2"></i> Submit Now
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Enhanced Auto-save Indicator -->
    <div id="autoSaveIndicator" class="fixed bottom-6 right-6 bg-gradient-to-r from-green-500 to-emerald-500 text-white px-6 py-3 rounded-xl shadow-2xl hidden transform transition-all duration-300 z-50">
        <div class="flex items-center">
            <div class="mr-3">
                <i class="fas fa-check-circle text-xl"></i>
            </div>
            <div>
                <div class="font-semibold">Progress Saved!</div>
                <div class="text-xs text-green-100">Your answers have been auto-saved</div>
            </div>
        </div>
    </div>
    
    <!-- Time Warning Modal -->
    <div id="timeWarningModal" class="modal hidden">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-yellow-50 to-orange-50 p-8 rounded-t-2xl">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-16 h-16 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-white text-2xl"></i>
                        </div>
                    </div>
                    <div class="ml-6">
                        <h3 class="text-2xl font-bold text-yellow-800">Time Running Out!</h3>
                        <p class="text-yellow-600 mt-2 font-medium">Only 5 minutes remaining</p>
                    </div>
                </div>
            </div>
            
            <div class="p-8">
                <p class="text-gray-700 mb-6">You have less than 5 minutes remaining. Consider reviewing marked questions and submitting your test.</p>
                
                <div class="flex justify-end space-x-4">
                    <button onclick="closeModal('timeWarningModal')" 
                            class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50">
                        Continue Test
                    </button>
                    <button onclick="submitTest()" 
                            class="px-6 py-3 bg-gradient-to-r from-red-600 to-pink-600 text-white rounded-xl hover:from-red-700 hover:to-pink-700">
                        Submit Now
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Confirmation Modal -->
    <div id="submitConfirmationModal" class="modal hidden">
        <div class="modal-content test-complete-modal">
            <div class="bg-gradient-to-r from-blue-50 to-purple-50 p-8 rounded-t-2xl">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-paper-plane text-white text-2xl"></i>
                        </div>
                    </div>
                    <div class="ml-6">
                        <h3 class="text-2xl font-bold text-gray-800">Submit Test?</h3>
                        <p class="text-gray-600 mt-2">Are you sure you want to submit your test?</p>
                    </div>
                </div>
            </div>
            
            <div class="p-8">
                <div class="bg-gradient-to-r from-blue-100 to-purple-100 border-l-4 border-blue-500 p-4 rounded-r-lg mb-6">
                    <div class="flex">
                        <i class="fas fa-info-circle text-blue-500 mr-3 mt-1"></i>
                        <div>
                            <h4 class="font-bold text-blue-700 mb-1">Important</h4>
                            <p class="text-blue-600 text-sm">
                                Once submitted, you cannot change your answers. Review all questions before submitting.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-xl text-center">
                        <div class="text-2xl font-bold text-green-600" id="modalAnswered">0</div>
                        <div class="text-sm text-green-700 font-medium">Answered</div>
                    </div>
                    <div class="bg-gradient-to-br from-yellow-50 to-yellow-100 p-4 rounded-xl text-center">
                        <div class="text-2xl font-bold text-yellow-600" id="modalMarked">0</div>
                        <div class="text-sm text-yellow-700 font-medium">Marked</div>
                    </div>
                    <div class="bg-gradient-to-br from-red-50 to-red-100 p-4 rounded-xl text-center">
                        <div class="text-2xl font-bold text-red-600" id="modalUnanswered"><?= count($questions) ?></div>
                        <div class="text-sm text-red-700 font-medium">Unanswered</div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-4">
                    <button onclick="closeModal('submitConfirmationModal')" 
                            class="px-6 py-3 border-2 border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50">
                        Cancel
                    </button>
                    <button onclick="confirmSubmit()" 
                            class="px-6 py-3 bg-gradient-to-r from-green-600 to-emerald-600 text-white rounded-xl hover:from-green-700 hover:to-emerald-700">
                        Yes, Submit Test
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let questions = <?= json_encode(array_column($questions, 'id')) ?>;
        let currentQuestionIndex = 0;
        let endTime = <?= $endTime ?> * 1000; // Convert to milliseconds
        let autoSaveInterval;
        let markedQuestions = new Set();
        let answeredQuestions = new Set(<?= json_encode(array_keys($savedAnswers)) ?>);
        let timeWarningShown = false;
        
        // Enhanced Timer Function
        function updateTimer() {
            const now = Date.now();
            const remainingTime = Math.max(0, endTime - now);
            
            if (remainingTime <= 0) {
                clearInterval(timerInterval);
                submitTest();
                return;
            }
            
            const minutes = Math.floor(remainingTime / 60000);
            const seconds = Math.floor((remainingTime % 60000) / 1000);
            
            const timerElement = document.getElementById('timer');
            const timerCircle = timerElement.closest('.timer-circle');
            timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            // Visual effects based on remaining time
            if (minutes < 5 && !timeWarningShown) {
                // Show warning modal
                timeWarningShown = true;
                showModal('timeWarningModal');
                
                // Change timer color and add pulse
                timerCircle.style.borderColor = '#ef4444';
                timerElement.classList.add('timer-danger');
                timerCircle.classList.add('animate-pulse');
            }
            
            if (minutes < 2) {
                // More urgent warning
                timerCircle.style.background = 'rgba(239, 68, 68, 0.2)';
            }
            
            // Update progress bar animation
            const totalTime = <?= $test['duration_minutes'] * 60 * 1000 ?>;
            const progress = ((totalTime - remainingTime) / totalTime) * 100;
            timerCircle.style.background = `conic-gradient(#10b981 ${progress}%, rgba(255, 255, 255, 0.1) 0)`;
            
            // Auto-save every 30 seconds
            if (seconds % 30 === 0) {
                saveAnswers();
            }
        }
        
        // Enhanced Navigation Functions
        function goToQuestion(index) {
            // Animate out current question
            const currentQuestion = document.getElementById(`question-${questions[currentQuestionIndex]}`);
            currentQuestion.style.opacity = '0';
            currentQuestion.style.transform = 'translateX(-20px)';
            currentQuestion.style.transition = 'all 0.3s ease';
            
            document.getElementById(`nav-${questions[currentQuestionIndex]}`).classList.remove('current');
            
            setTimeout(() => {
                currentQuestion.classList.add('hidden');
                currentQuestion.style.opacity = '';
                currentQuestion.style.transform = '';
                
                currentQuestionIndex = index;
                
                // Animate in new question
                const newQuestion = document.getElementById(`question-${questions[currentQuestionIndex]}`);
                newQuestion.classList.remove('hidden');
                newQuestion.style.opacity = '0';
                newQuestion.style.transform = 'translateX(20px)';
                
                setTimeout(() => {
                    newQuestion.style.transition = 'all 0.3s ease';
                    newQuestion.style.opacity = '1';
                    newQuestion.style.transform = 'translateX(0)';
                }, 50);
                
                document.getElementById(`nav-${questions[currentQuestionIndex]}`).classList.add('current');
                
                updateProgressStats();
                updateAnsweredCount();
            }, 300);
        }
        
        function nextQuestion() {
            if (currentQuestionIndex < questions.length - 1) {
                goToQuestion(currentQuestionIndex + 1);
            }
        }
        
        function previousQuestion() {
            if (currentQuestionIndex > 0) {
                goToQuestion(currentQuestionIndex - 1);
            }
        }
        
        function markForReview(questionId) {
            const navBtn = document.getElementById(`nav-${questionId}`);
            if (markedQuestions.has(questionId)) {
                markedQuestions.delete(questionId);
                navBtn.classList.remove('marked');
                navBtn.classList.add(navBtn.classList.contains('answered') ? 'answered' : 'unanswered');
            } else {
                markedQuestions.add(questionId);
                navBtn.classList.remove('answered', 'unanswered');
                navBtn.classList.add('marked');
                // Add animation effect
                navBtn.style.animation = 'none';
                setTimeout(() => {
                    navBtn.style.animation = 'pulse 2s infinite';
                }, 10);
            }
            updateProgressStats();
        }
        
        // Enhanced Save Answers Function
        function saveAnswers() {
            const formData = new FormData(document.getElementById('testForm'));
            const answers = {};
            
            document.querySelectorAll('input[type="radio"]:checked').forEach(input => {
                const match = input.name.match(/answer\[(\d+)\]/);
                if (match) {
                    answers[match[1]] = input.value;
                }
            });
            
            fetch('save_test_answer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    attempt_id: <?= $attemptId ?>,
                    answers: answers,
                    time_spent: <?= $test['duration_minutes'] * 60 ?> - Math.floor((endTime - Date.now()) / 1000)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAutoSaveIndicator();
                    updateAnsweredCount();
                    updateNavigationButtons();
                    updateProgressStats();
                }
            })
            .catch(error => console.error('Error:', error));
        }
        
        function updateAnsweredCount() {
            const answered = document.querySelectorAll('input[type="radio"]:checked').length;
            document.getElementById('answeredCount').textContent = `${answered}/${questions.length}`;
        }
        
        function updateNavigationButtons() {
            questions.forEach((questionId, index) => {
                const navBtn = document.getElementById(`nav-${questionId}`);
                const isAnswered = document.querySelector(`input[name="answer[${questionId}]"]:checked`);
                
                if (!markedQuestions.has(questionId)) {
                    if (isAnswered) {
                        if (!navBtn.classList.contains('answered')) {
                            navBtn.classList.remove('unanswered');
                            navBtn.classList.add('answered');
                            // Add animation for newly answered
                            navBtn.style.transform = 'scale(1.2)';
                            setTimeout(() => {
                                navBtn.style.transform = '';
                            }, 300);
                        }
                        answeredQuestions.add(questionId);
                    } else {
                        if (!navBtn.classList.contains('unanswered')) {
                            navBtn.classList.remove('answered');
                            navBtn.classList.add('unanswered');
                        }
                        answeredQuestions.delete(questionId);
                    }
                }
            });
        }
        
        function updateProgressStats() {
            const answered = document.querySelectorAll('input[type="radio"]:checked').length;
            const marked = markedQuestions.size;
            const unanswered = questions.length - answered;
            
            document.getElementById('answeredStat').textContent = answered;
            document.getElementById('markedStat').textContent = marked;
            document.getElementById('remainingStat').textContent = unanswered;
            
            // Update modal stats
            document.getElementById('modalAnswered').textContent = answered;
            document.getElementById('modalMarked').textContent = marked;
            document.getElementById('modalUnanswered').textContent = unanswered;
        }
        
        function showAutoSaveIndicator() {
            const indicator = document.getElementById('autoSaveIndicator');
            indicator.classList.remove('hidden');
            indicator.style.transform = 'translateY(20px)';
            indicator.style.opacity = '0';
            
            setTimeout(() => {
                indicator.style.transition = 'all 0.3s ease';
                indicator.style.transform = 'translateY(0)';
                indicator.style.opacity = '1';
            }, 10);
            
            setTimeout(() => {
                indicator.style.opacity = '0';
                indicator.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    indicator.classList.add('hidden');
                }, 300);
            }, 2000);
        }
        
        function clearCurrentAnswer() {
            const questionId = questions[currentQuestionIndex];
            document.querySelectorAll(`input[name="answer[${questionId}]"]`).forEach(radio => {
                radio.checked = false;
                radio.closest('label').classList.remove('option-selected');
            });
            saveAnswer(questionId, null);
            updateAnsweredCount();
            updateNavigationButtons();
            updateProgressStats();
        }
        
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
        function showSubmitConfirmation() {
            updateProgressStats();
            showModal('submitConfirmationModal');
        }
        
        function confirmSubmit() {
            submitTest();
        }
        
        function createConfetti() {
            const colors = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6'];
            for (let i = 0; i < 100; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.top = '-10px';
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.transform = `rotate(${Math.random() * 360}deg)`;
                document.body.appendChild(confetti);
                
                // Animation
                const animation = confetti.animate([
                    { transform: 'translateY(0) rotate(0deg)', opacity: 1 },
                    { transform: `translateY(${window.innerHeight}px) rotate(${Math.random() * 360}deg)`, opacity: 0 }
                ], {
                    duration: 1000 + Math.random() * 1000,
                    easing: 'cubic-bezier(0.215, 0.61, 0.355, 1)'
                });
                
                animation.onfinish = () => confetti.remove();
            }
        }
        
        function submitTest() {
            // Create confetti effect
            createConfetti();
            
            // Save final answers
            saveAnswers();
            
            // Show loading state
            const submitBtn = document.getElementById('submitTestBtn');
            const originalHTML = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Submitting...';
            submitBtn.disabled = true;
            
            fetch('submit_test.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    attempt_id: <?= $attemptId ?>,
                    test_id: <?= $testId ?>,
                    student_id: '<?= $studentId ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Success animation before redirect
                    submitBtn.innerHTML = '<i class="fas fa-check mr-2"></i> Submitted Successfully!';
                    submitBtn.classList.remove('bg-gradient-to-r', 'from-red-600', 'to-pink-600');
                    submitBtn.classList.add('bg-gradient-to-r', 'from-green-600', 'to-emerald-600');
                    
                    setTimeout(() => {
                        window.location.href = 'test_result.php?test_id=<?= $testId ?>';
                    }, 1000);
                } else {
                    alert('Error submitting test: ' + data.message);
                    submitBtn.innerHTML = originalHTML;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error submitting test. Please try again.');
                submitBtn.innerHTML = originalHTML;
                submitBtn.disabled = false;
            });
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Start timer
            const timerInterval = setInterval(updateTimer, 1000);
            updateTimer();
            
            // Initialize stats
            updateAnsweredCount();
            updateNavigationButtons();
            updateProgressStats();
            
            // Auto-save every 30 seconds
            autoSaveInterval = setInterval(saveAnswers, 30000);
            
            // Set up radio button events
            document.querySelectorAll('input[type="radio"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    const questionId = this.name.match(/answer\[(\d+)\]/)[1];
                    saveAnswer(questionId, this.value);
                    updateAnsweredCount();
                    updateNavigationButtons();
                    updateProgressStats();
                    
                    // Enhanced selected option styling
                    const parentLabel = this.closest('label');
                    document.querySelectorAll(`input[name="answer[${questionId}]"]`).forEach(r => {
                        r.closest('label').classList.remove('option-selected');
                        const checkCircle = r.closest('label').querySelector('.check-circle');
                        checkCircle.style.transform = 'rotate(0deg)';
                    });
                    parentLabel.classList.add('option-selected');
                    parentLabel.querySelector('.check-circle').style.transform = 'rotate(360deg)';
                });
            });
            
            // Highlight already selected answers on load
            document.querySelectorAll('input[type="radio"]:checked').forEach(radio => {
                radio.closest('label').classList.add('option-selected');
            });
            
            // Submit button event
            document.getElementById('submitTestBtn').addEventListener('click', showSubmitConfirmation);
            
            // Enhanced keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Navigation with arrow keys
                if (e.key === 'ArrowRight' || e.key === ' ') {
                    e.preventDefault();
                    nextQuestion();
                } else if (e.key === 'ArrowLeft') {
                    e.preventDefault();
                    previousQuestion();
                }
                
                // Number keys for direct question navigation
                if (e.key >= '1' && e.key <= '9') {
                    const num = parseInt(e.key);
                    if (num <= questions.length) {
                        e.preventDefault();
                        goToQuestion(num - 1);
                    }
                }
                
                // M to mark for review
                if (e.key === 'm' || e.key === 'M') {
                    e.preventDefault();
                    markForReview(questions[currentQuestionIndex]);
                }
                
                // S to save
                if (e.key === 's' || e.key === 'S') {
                    e.preventDefault();
                    saveAnswers();
                }
                
                // C to clear current answer
                if (e.key === 'c' || e.key === 'C') {
                    e.preventDefault();
                    clearCurrentAnswer();
                }
            });
        });
        
        // Prevent accidental page leave with enhanced warning
        window.addEventListener('beforeunload', function(e) {
            e.preventDefault();
            e.returnValue = 'You have unsaved test progress. Are you sure you want to leave?';
        });
        
        // Save single answer
        function saveAnswer(questionId, answer) {
            fetch('save_single_answer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    attempt_id: <?= $attemptId ?>,
                    question_id: questionId,
                    answer: answer
                })
            });
        }
    </script>
</body>
</html>