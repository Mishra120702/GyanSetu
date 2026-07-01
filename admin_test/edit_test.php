<?php
// edit_test.php
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
$success = '';
$testData = null;
$questions = [];
$hasSubmissions = false;

// Fetch test data
try {
    $stmt = $db->prepare("
        SELECT t.*, 
               COUNT(DISTINCT ta.id) as submission_count
        FROM tests t
        LEFT JOIN test_attempts ta ON t.id = ta.test_id
        WHERE t.id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$testId]);
    $testData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$testData) {
        $error = "Test not found!";
    } else {
        $hasSubmissions = $testData['submission_count'] > 0;
        
        // Fetch target students
        $targetStudents = [];
        if ($testData['assigned_to'] === 'specific') {
            $studentTargetStmt = $db->prepare("SELECT student_id FROM test_students WHERE test_id = ?");
            $studentTargetStmt->execute([$testId]);
            $targetStudents = $studentTargetStmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Fetch questions
        $questionStmt = $db->prepare("
            SELECT * FROM test_questions 
            WHERE test_id = ? 
            ORDER BY question_order ASC
        ");
        $questionStmt->execute([$testId]);
        $questions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = "Error loading test: " . $e->getMessage();
}

// DB stores IST directly — no conversion needed
function convertToIST($datetime) {
    if (empty($datetime)) return '';
    // Just format for datetime-local input (Y-m-d\TH:i)
    return date('Y-m-d\TH:i', strtotime($datetime));
}

function convertToUTC($datetime) {
    if (empty($datetime)) return null;
    // Store as-is in IST (no conversion)
    return date('Y-m-d H:i:s', strtotime($datetime));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        if ($hasSubmissions) {
            $batch_id = !empty($_POST['batch_id']) ? $_POST['batch_id'] : null;
            $course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
            $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] === 'specific' ? 'specific' : 'all';
            $selected_student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? $_POST['student_ids'] : [];

            // Only update non-question fields when submissions exist
            $updateStmt = $db->prepare("
                UPDATE tests 
                SET title = ?, description = ?, subject = ?, 
                    passing_marks = ?, duration_minutes = ?, 
                    max_attempts = ?, start_date = ?, end_date = ?,
                    batch_id = ?, course_id = ?, assigned_to = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            // Convert IST input to UTC for database
            $startDate = !empty($_POST['start_date']) ? convertToUTC($_POST['start_date']) : null;
            $endDate = !empty($_POST['end_date']) ? convertToUTC($_POST['end_date']) : null;
            
            $updateStmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['subject'],
                $_POST['passing_marks'],
                $_POST['duration_minutes'],
                $_POST['max_attempts'],
                $startDate,
                $endDate,
                $batch_id,
                $course_id,
                $assigned_to,
                $testId
            ]);
            
            // Sync targeting
            $deleteTargetStmt = $db->prepare("DELETE FROM test_students WHERE test_id = ?");
            $deleteTargetStmt->execute([$testId]);

            if ($assigned_to === 'specific' && !empty($selected_student_ids)) {
                $studentStmt = $db->prepare("INSERT INTO test_students (test_id, student_id) VALUES (?, ?)");
                foreach ($selected_student_ids as $sid) {
                    $studentStmt->execute([$testId, $sid]);
                }
            }
            
            $success = "Test settings updated successfully!";
        } else {
            $batch_id = !empty($_POST['batch_id']) ? $_POST['batch_id'] : null;
            $course_id = !empty($_POST['course_id']) ? intval($_POST['course_id']) : null;
            $assigned_to = isset($_POST['assigned_to']) && $_POST['assigned_to'] === 'specific' ? 'specific' : 'all';
            $selected_student_ids = isset($_POST['student_ids']) && is_array($_POST['student_ids']) ? $_POST['student_ids'] : [];

            // Update test details
            $updateStmt = $db->prepare("
                UPDATE tests 
                SET title = ?, description = ?, subject = ?, 
                    passing_marks = ?, duration_minutes = ?, 
                    max_attempts = ?, start_date = ?, end_date = ?,
                    batch_id = ?, course_id = ?, assigned_to = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            // Convert IST input to UTC for database
            $startDate = !empty($_POST['start_date']) ? convertToUTC($_POST['start_date']) : null;
            $endDate = !empty($_POST['end_date']) ? convertToUTC($_POST['end_date']) : null;
            
            $updateStmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['subject'],
                $_POST['passing_marks'],
                $_POST['duration_minutes'],
                $_POST['max_attempts'],
                $startDate,
                $endDate,
                $batch_id,
                $course_id,
                $assigned_to,
                $testId
            ]);

            // Sync targeting
            $deleteTargetStmt = $db->prepare("DELETE FROM test_students WHERE test_id = ?");
            $deleteTargetStmt->execute([$testId]);

            if ($assigned_to === 'specific' && !empty($selected_student_ids)) {
                $studentStmt = $db->prepare("INSERT INTO test_students (test_id, student_id) VALUES (?, ?)");
                foreach ($selected_student_ids as $sid) {
                    $studentStmt->execute([$testId, $sid]);
                }
            }
            
            // Update existing questions
            $questionIds = $_POST['question_ids'] ?? [];
            $totalMarks = 0;
            
            foreach ($questionIds as $index => $questionId) {
                if ($questionId && !str_starts_with($questionId, 'delete_')) {
                    $updateQuestionStmt = $db->prepare("
                        UPDATE test_questions 
                        SET question_text = ?, option_a = ?, option_b = ?, 
                            option_c = ?, option_d = ?, correct_answer = ?, 
                            marks = ?, explanation = ?, question_order = ?
                        WHERE id = ? AND test_id = ?
                    ");
                    
                    $updateQuestionStmt->execute([
                        $_POST['questions'][$index],
                        $_POST['options_a'][$index],
                        $_POST['options_b'][$index],
                        $_POST['options_c'][$index],
                        $_POST['options_d'][$index],
                        $_POST['correct_answers'][$index],
                        $_POST['marks'][$index],
                        $_POST['explanations'][$index] ?? '',
                        $index + 1,
                        $questionId,
                        $testId
                    ]);
                    
                    $totalMarks += $_POST['marks'][$index];
                } elseif (str_starts_with($questionId, 'delete_')) {
                    // Mark for deletion (we'll handle deletion separately)
                    $actualQuestionId = str_replace('delete_', '', $questionId);
                    
                    // Check if this question has any answers
                    $checkAnswersStmt = $db->prepare("
                        SELECT COUNT(*) as answer_count 
                        FROM test_answers 
                        WHERE question_id = ?
                    ");
                    $checkAnswersStmt->execute([$actualQuestionId]);
                    $hasAnswers = $checkAnswersStmt->fetch(PDO::FETCH_ASSOC)['answer_count'] > 0;
                    
                    if (!$hasAnswers) {
                        // Safe to delete if no answers exist
                        $deleteQuestionStmt = $db->prepare("
                            DELETE FROM test_questions 
                            WHERE id = ? AND test_id = ?
                        ");
                        $deleteQuestionStmt->execute([$actualQuestionId, $testId]);
                    }
                }
            }
            
            // Add new questions
            $newQuestionIndices = $_POST['new_question_indices'] ?? [];
            foreach ($newQuestionIndices as $index) {
                if (!empty($_POST['new_questions'][$index])) {
                    $insertQuestionStmt = $db->prepare("
                        INSERT INTO test_questions 
                        (test_id, question_text, option_a, option_b, option_c, option_d, 
                         correct_answer, marks, explanation, question_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $newQuestionOrder = count($questionIds) - count(array_filter($questionIds, fn($id) => str_starts_with($id, 'delete_'))) + $index + 1;
                    
                    $insertQuestionStmt->execute([
                        $testId,
                        $_POST['new_questions'][$index],
                        $_POST['new_options_a'][$index],
                        $_POST['new_options_b'][$index],
                        $_POST['new_options_c'][$index],
                        $_POST['new_options_d'][$index],
                        $_POST['new_correct_answers'][$index],
                        $_POST['new_marks'][$index],
                        $_POST['new_explanations'][$index] ?? '',
                        $newQuestionOrder
                    ]);
                    
                    $totalMarks += $_POST['new_marks'][$index];
                }
            }
            
            // Update total marks
            $updateTotalStmt = $db->prepare("UPDATE tests SET total_marks = ? WHERE id = ?");
            $updateTotalStmt->execute([$totalMarks, $testId]);
            
            $success = "Test updated successfully!";
        }
        
        $db->commit();
        
        // Recalculate scores if submissions exist and questions were modified
        if ($hasSubmissions && !isset($_POST['new_question_indices'])) {
            recalculateScores($testId);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error updating test: " . $e->getMessage();
    }
    
    // Refresh data
    $stmt->execute([$testId]);
    $testData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $questionStmt->execute([$testId]);
    $questions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to recalculate scores
function recalculateScores($testId) {
    global $db;
    
    try {
        // Get all attempts
        $attemptStmt = $db->prepare("
            SELECT id, student_id FROM test_attempts 
            WHERE test_id = ? AND status = 'submitted'
        ");
        $attemptStmt->execute([$testId]);
        $attempts = $attemptStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($attempts as $attempt) {
            // Calculate new score
            $scoreStmt = $db->prepare("
                SELECT 
                    COUNT(*) as total_questions,
                    SUM(CASE WHEN ta.selected_answer = tq.correct_answer THEN tq.marks ELSE 0 END) as obtained_marks,
                    SUM(tq.marks) as total_marks
                FROM test_answers ta
                JOIN test_questions tq ON ta.question_id = tq.id
                WHERE ta.attempt_id = ?
                AND tq.test_id = ?
            ");
            $scoreStmt->execute([$attempt['id'], $testId]);
            $scoreData = $scoreStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($scoreData) {
                $percentage = $scoreData['total_marks'] > 0 
                    ? ($scoreData['obtained_marks'] / $scoreData['total_marks']) * 100 
                    : 0;
                
                // Update attempt
                $updateStmt = $db->prepare("
                    UPDATE test_attempts 
                    SET total_questions = ?, 
                        obtained_marks = ?, 
                        total_marks = ?,
                        percentage = ?,
                        correct_answers = (
                            SELECT COUNT(*) 
                            FROM test_answers ta2
                            JOIN test_questions tq2 ON ta2.question_id = tq2.id
                            WHERE ta2.attempt_id = ? 
                            AND ta2.selected_answer = tq2.correct_answer
                        ),
                        wrong_answers = (
                            SELECT COUNT(*) 
                            FROM test_answers ta2
                            JOIN test_questions tq2 ON ta2.question_id = tq2.id
                            WHERE ta2.attempt_id = ? 
                            AND ta2.selected_answer != tq2.correct_answer
                            AND ta2.selected_answer != ''
                        ),
                        questions_attempted = (
                            SELECT COUNT(*) 
                            FROM test_answers ta2
                            WHERE ta2.attempt_id = ? 
                            AND ta2.selected_answer != ''
                        )
                    WHERE id = ?
                ");
                
                $updateStmt->execute([
                    $scoreData['total_questions'],
                    $scoreData['obtained_marks'],
                    $scoreData['total_marks'],
                    $percentage,
                    $attempt['id'],
                    $attempt['id'],
                    $attempt['id'],
                    $attempt['id']
                ]);
            }
        }
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Get batches for dropdown
$batchStmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name");
$batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

// Get current IST time for min attribute in datetime-local inputs
$currentIST = date('Y-m-d\TH:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit MCQ Test - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Theme: #1B3C53 | #234C6A | #456882 | #D2C1B6 */
        :root {
            --color-darkest:  #1B3C53;
            --color-dark:     #234C6A;
            --color-mid:      #456882;
            --color-sand:     #D2C1B6;
            --color-sand-lt:  #e8ddd8;
        }

        * { font-family: 'Inter', sans-serif; }

        body {
            background: linear-gradient(135deg, var(--color-darkest) 0%, var(--color-dark) 60%, var(--color-mid) 100%);
            min-height: 100vh;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,50 Q25,40 50,50 T100,50" stroke="white" stroke-width="0.5" fill="none" opacity="0.08"/><path d="M0,30 Q25,20 50,30 T100,30" stroke="white" stroke-width="0.5" fill="none" opacity="0.08"/></svg>');
            pointer-events: none;
            z-index: -1;
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.97);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(210, 193, 182, 0.35);
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(210, 193, 182, 0.4);
            box-shadow: 0 8px 32px rgba(27, 60, 83, 0.12);
        }

        .question-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .question-card.locked {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .question-card.locked::after {
            content: 'Locked - Submissions Exist';
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(27, 60, 83, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-dark);
            font-weight: bold;
            backdrop-filter: blur(2px);
        }

        .warning-banner {
            background: linear-gradient(135deg, #c0874a, #d4963e);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%   { opacity: 0.9; }
            50%  { opacity: 1;   }
            100% { opacity: 0.9; }
        }

        .input-focus:focus {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(27, 60, 83, 0.12);
        }

        /* inputs — themed border & ring */
        input[type="text"],
        input[type="number"],
        input[type="datetime-local"],
        textarea,
        select {
            border-color: #c8bdb8 !important;
        }
        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="datetime-local"]:focus,
        textarea:focus,
        select:focus {
            border-color: var(--color-mid) !important;
            box-shadow: 0 0 0 3px rgba(69, 104, 130, 0.18) !important;
            outline: none;
        }

        .option-correct {
            background: linear-gradient(135deg, #d6e9f0, #b8d8e8);
            border: 2px solid var(--color-mid) !important;
        }

        .gradient-text {
            background: linear-gradient(135deg, var(--color-dark) 0%, var(--color-mid) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .shake {
            animation: shake 0.5s ease-in-out;
        }

        @keyframes shake {
            0%, 100%               { transform: translateX(0);  }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80%     { transform: translateX(5px);  }
        }

        .timezone-badge {
            background: linear-gradient(135deg, var(--color-mid), var(--color-dark));
            color: var(--color-sand);
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }

        .date-input-wrapper {
            position: relative;
        }

        .date-input-wrapper::after {
            content: 'IST';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: var(--color-mid);
            color: white;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 600;
            pointer-events: none;
        }

        .info-tooltip {
            position: relative;
            cursor: help;
        }

        .info-tooltip:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: var(--color-darkest);
            color: var(--color-sand);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            margin-bottom: 5px;
        }

        .info-tooltip:hover::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 5px solid transparent;
            border-top-color: var(--color-darkest);
            margin-bottom: -5px;
            z-index: 1000;
        }

        /* Section icon badges */
        .icon-badge-primary {
            background: linear-gradient(135deg, var(--color-dark), var(--color-mid));
        }
        .icon-badge-secondary {
            background: linear-gradient(135deg, var(--color-mid), #5e849e);
        }

        /* Decorative blobs in header */
        .blob-tl { background: var(--color-mid); }
        .blob-br { background: var(--color-dark); }

        /* Select-all / Deselect-all buttons */
        .btn-select-all {
            background: rgba(69, 104, 130, 0.1);
            color: var(--color-dark);
        }
        .btn-select-all:hover { background: rgba(69, 104, 130, 0.2); }
        .btn-deselect-all {
            background: rgba(210, 193, 182, 0.3);
            color: #555;
        }
        .btn-deselect-all:hover { background: rgba(210, 193, 182, 0.5); }

        /* Student targeting grid */
        #student_list_grid {
            background: rgba(210, 193, 182, 0.15);
            border-color: rgba(210, 193, 182, 0.5) !important;
        }

        /* Current time block */
        .time-block {
            background: linear-gradient(135deg, rgba(27,60,83,0.05), rgba(69,104,130,0.08));
            border-color: rgba(69, 104, 130, 0.2) !important;
        }

        /* Timezone info banner */
        .tz-info-banner {
            background: rgba(69, 104, 130, 0.08);
            border-color: rgba(69, 104, 130, 0.3) !important;
            color: var(--color-dark);
        }

        /* Question number circle */
        .q-num-circle {
            background: linear-gradient(135deg, var(--color-dark), var(--color-mid));
        }
        .q-num-circle-new {
            background: linear-gradient(135deg, var(--color-mid), #5e849e);
        }

        /* Pulse dot */
        .pulse-dot { background: var(--color-mid); }

        /* Scrollbar theming */
        #student_list_grid::-webkit-scrollbar { width: 6px; }
        #student_list_grid::-webkit-scrollbar-track { background: rgba(210,193,182,0.2); border-radius: 3px; }
        #student_list_grid::-webkit-scrollbar-thumb { background: var(--color-mid); border-radius: 3px; }
    </style>
</head>
<body class="min-h-screen">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-0 md:ml-64 min-h-screen p-4 md:p-8">
        <div class="max-w-7xl mx-auto relative z-10">
            <!-- Header -->
            <div class="glass-effect rounded-2xl p-6 md:p-8 mb-8 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 blob-tl opacity-10 rounded-full -mt-16 -mr-16"></div>
                <div class="absolute bottom-0 left-0 w-48 h-48 blob-br opacity-10 rounded-full -mb-24 -ml-24"></div>
                
                <div class="flex flex-col md:flex-row md:items-center justify-between relative z-10">
                    <div>
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-2 gradient-text">
                            Edit MCQ Test
                        </h1>
                        <p class="text-gray-600 text-lg flex items-center">
                            <i class="fas fa-edit mr-2" style="color:#456882"></i>
                            Modify test settings and questions
                            <span class="timezone-badge ml-3">
                                <i class="fas fa-clock mr-1"></i>IST Timezone
                            </span>
                        </p>
                    </div>
                    <div class="mt-6 md:mt-0">
                        <a href="admin_dashboard.php" 
                           class="inline-flex items-center text-white px-6 py-3 rounded-xl transition-all duration-300 font-semibold shadow-md hover:shadow-lg transform hover:-translate-y-0.5" style="background: linear-gradient(135deg, #456882, #234C6A)">
                            <i class="fas fa-arrow-left mr-3"></i>
                            <span>Back to Dashboard</span>
                        </a>
                    </div>
                </div>
                
                <?php if ($hasSubmissions): ?>
                <div class="mt-6 warning-banner text-white p-4 rounded-xl">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-xl mr-3"></i>
                        <div>
                            <strong>Warning:</strong> This test has <?= $testData['submission_count'] ?> submissions. 
                            Question editing is disabled to preserve existing results. You can only modify test settings.
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Timezone Info -->
                <div class="mt-4 tz-info-banner border px-4 py-3 rounded-xl flex items-start">
                    <i class="fas fa-info-circle mt-1 mr-3" style="color:#456882"></i>
                    <div>
                        <strong>Timezone Information:</strong> All times are displayed in Indian Standard Time (IST - UTC+5:30).
                        The system will automatically convert to your local timezone when saving.
                    </div>
                </div>
            </div>
            
            <!-- Alerts -->
            <?php if ($error): ?>
                <div class="bg-gradient-to-r from-red-400 to-pink-500 text-white px-6 py-4 rounded-xl mb-6 shadow-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-xl mr-3"></i>
                        <span class="font-medium"><?= htmlspecialchars($error) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-gradient-to-r from-green-400 to-emerald-500 text-white px-6 py-4 rounded-xl mb-6 shadow-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-xl mr-3"></i>
                        <span class="font-medium"><?= htmlspecialchars($success) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($testData): ?>
            <!-- Edit Test Form -->
            <form method="POST" id="editTestForm" class="space-y-8">
                <!-- Test Details Card -->
                <div class="glass-card rounded-2xl p-6 md:p-8">
                    <div class="flex items-center mb-6">
                        <div class="w-12 h-12 rounded-xl icon-badge-primary flex items-center justify-center text-white text-xl mr-4 shadow-md">
                            <i class="fas fa-cog"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-1">Test Settings</h2>
                            <p class="text-gray-600">Modify test configuration and parameters</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-heading mr-2" style="color:#456882"></i>
                                Test Title *
                            </label>
                            <input type="text" name="title" required 
                                   value="<?= htmlspecialchars($testData['title']) ?>"
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-book mr-2" style="color:#456882"></i>
                                Subject
                            </label>
                            <input type="text" name="subject" 
                                   value="<?= htmlspecialchars($testData['subject']) ?>"
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-clock mr-2" style="color:#456882"></i>
                                Duration (Minutes)
                            </label>
                            <input type="number" name="duration_minutes" value="<?= $testData['duration_minutes'] ?>" min="1" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-redo mr-2" style="color:#456882"></i>
                                Maximum Attempts
                            </label>
                            <input type="number" name="max_attempts" value="<?= $testData['max_attempts'] ?>" min="1" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-trophy mr-2" style="color:#456882"></i>
                                Passing Marks
                            </label>
                            <input type="number" name="passing_marks" value="<?= $testData['passing_marks'] ?>" min="0" 
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                        </div>
                        
                        <div class="date-input-wrapper">
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-calendar-plus mr-2" style="color:#456882"></i>
                                Start Date (Optional)
                                <span class="info-tooltip ml-2" style="color:#456882" data-tooltip="Indian Standard Time (IST)">
                                    <i class="fas fa-question-circle text-sm"></i>
                                </span>
                            </label>
                            <input type="datetime-local" name="start_date" 
                                   value="<?= !empty($testData['start_date']) ? convertToIST($testData['start_date']) : '' ?>"
                                   min="<?= $currentIST ?>"
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus pr-16">
                        </div>
                        
                        <div class="date-input-wrapper">
                            <label class="block text-sm font-semibold text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-calendar-minus mr-2" style="color:#c0504a"></i>
                                End Date (Optional)
                                <span class="info-tooltip ml-2" style="color:#456882" data-tooltip="Indian Standard Time (IST)">
                                    <i class="fas fa-question-circle text-sm"></i>
                                </span>
                            </label>
                            <input type="datetime-local" name="end_date" 
                                   value="<?= !empty($testData['end_date']) ? convertToIST($testData['end_date']) : '' ?>"
                                   min="<?= $currentIST ?>"
                                   class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus pr-16">
                        </div>
                    </div>
                    
                    <!-- Dynamic Targeting Section -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6 pt-6 border-t border-gray-100">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-users mr-2" style="color:#456882"></i>
                                Select Batch *
                            </label>
                            <select name="batch_id" id="batch_id" required onchange="fetchBatchDetails(this.value)"
                                    class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                                <option value="">-- Select Batch --</option>
                                <?php foreach ($batches as $b): ?>
                                    <option value="<?= htmlspecialchars($b['batch_id']) ?>" <?= (isset($testData['batch_id']) && $testData['batch_id'] === $b['batch_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($b['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-graduation-cap mr-2" style="color:#456882"></i>
                                Link to Course (Optional)
                            </label>
                            <select name="course_id" id="course_id"
                                    class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                                <option value="">-- No specific course link --</option>
                                <!-- Will be populated via JS -->
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fas fa-bullseye mr-2" style="color:#456882"></i>
                                Assign To
                            </label>
                            <select name="assigned_to" id="assigned_to" onchange="toggleTargeting(this.value)"
                                    class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus">
                                <option value="all" <?= ($testData['assigned_to'] ?? 'all') === 'all' ? 'selected' : '' ?>>All Students in Batch</option>
                                <option value="specific" <?= ($testData['assigned_to'] ?? 'all') === 'specific' ? 'selected' : '' ?>>Specific Students</option>
                            </select>
                        </div>
                    </div>

                    <!-- Specific Students Targeting Container -->
                    <div id="student_targeting_container" class="<?= ($testData['assigned_to'] ?? 'all') === 'specific' ? '' : 'hidden' ?> mt-6 pt-6 border-t border-gray-100">
                        <div class="flex items-center justify-between mb-4">
                            <label class="block text-sm font-semibold text-gray-700">
                                <i class="fas fa-user-check mr-2" style="color:#456882"></i>
                                Select Target Students
                            </label>
                            <div class="flex gap-2">
                                <button type="button" onclick="selectAllTargetStudents(true)" class="text-xs btn-select-all px-3 py-1.5 rounded-lg font-medium transition-all duration-200">Select All</button>
                                <button type="button" onclick="selectAllTargetStudents(false)" class="text-xs btn-deselect-all px-3 py-1.5 rounded-lg font-medium transition-all duration-200">Deselect All</button>
                            </div>
                        </div>
                        <div id="student_list_grid" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 max-h-60 overflow-y-auto p-1 bg-gray-50 rounded-xl border border-gray-100">
                            <!-- Will be populated dynamically via JS -->
                        </div>
                    </div>
                    
                    <!-- Current Time Display -->
                    <div class="mt-6 time-block p-4 rounded-xl border">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-clock mr-3 text-lg" style="color:#456882"></i>
                                <div>
                                    <div class="text-sm font-semibold text-gray-600">Current Server Time (IST)</div>
                                    <div class="text-xl font-bold text-gray-800" id="currentTimeDisplay">
                                        <?= date('l, F j, Y H:i:s') ?>
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-600">Test Time Status</div>
                                <?php
                                $now = time();
                                $startTime = !empty($testData['start_date']) ? strtotime($testData['start_date']) : null;
                                $endTime = !empty($testData['end_date']) ? strtotime($testData['end_date']) : null;
                                
                                $status = 'Always Active';
                                $statusClass = 'bg-green-100 text-green-800';
                                
                                if ($startTime && $endTime) {
                                    if ($now < $startTime) {
                                        $status = 'Scheduled';
                                        $statusClass = 'bg-blue-100 text-blue-800';
                                    } elseif ($now >= $startTime && $now <= $endTime) {
                                        $status = 'Active Now';
                                        $statusClass = 'bg-green-100 text-green-800';
                                    } else {
                                        $status = 'Expired';
                                        $statusClass = 'bg-red-100 text-red-800';
                                    }
                                } elseif ($startTime && !$endTime) {
                                    if ($now < $startTime) {
                                        $status = 'Starts Later';
                                        $statusClass = 'bg-blue-100 text-blue-800';
                                    } else {
                                        $status = 'Active (No End)';
                                        $statusClass = 'bg-green-100 text-green-800';
                                    }
                                } elseif (!$startTime && $endTime) {
                                    if ($now > $endTime) {
                                        $status = 'Ended';
                                        $statusClass = 'bg-red-100 text-red-800';
                                    } else {
                                        $status = 'Active Until End';
                                        $statusClass = 'bg-green-100 text-green-800';
                                    }
                                }
                                ?>
                                <span class="px-4 py-2 rounded-full text-sm font-semibold <?= $statusClass ?>">
                                    <?= $status ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            <i class="fas fa-align-left mr-2" style="color:#456882"></i>
                            Description
                        </label>
                        <textarea name="description" rows="3" 
                                  class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-300 input-focus"
                                  placeholder="Provide a brief description of the test..."><?= htmlspecialchars($testData['description']) ?></textarea>
                    </div>
                </div>
                
                <!-- Questions Section -->
                <?php if (!$hasSubmissions): ?>
                <div class="glass-card rounded-2xl p-6 md:p-8">
                    <div class="flex items-center mb-6">
                        <div class="w-12 h-12 rounded-xl icon-badge-secondary flex items-center justify-center text-white text-xl mr-4 shadow-md">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-1">Questions & Answers</h2>
                            <p class="text-gray-600">Edit existing questions or add new ones</p>
                        </div>
                    </div>
                    
                    <div id="questionsContainer">
                        <?php foreach ($questions as $index => $question): ?>
                        <div class="question-card glass-effect rounded-2xl p-6 mb-6" data-question-id="<?= $question['id'] ?>">
                            <input type="hidden" name="question_ids[]" value="<?= $question['id'] ?>">
                            
                            <div class="flex items-center justify-between mb-6">
                                <div class="flex items-center space-x-4">
                                    <div class="w-10 h-10 rounded-full q-num-circle text-white flex items-center justify-center font-bold">
                                        <?= $index + 1 ?>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-gray-800">Question #<?= $index + 1 ?></h3>
                                        <p class="text-sm text-gray-500">Edit question details</p>
                                    </div>
                                </div>
                                <button type="button" onclick="removeQuestion(this)" 
                                        class="w-10 h-10 rounded-xl text-white transition-all duration-300 shadow-md" style="background: linear-gradient(135deg, #b94040, #9e3535); hover:opacity-90">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                            
                            <!-- Question Text -->
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 mb-2">
                                    Question Text *
                                </label>
                                <textarea name="questions[]" rows="3" required 
                                          class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                          placeholder="Enter your question here..."><?= htmlspecialchars($question['question_text']) ?></textarea>
                            </div>
                            
                            <!-- Options -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Option A *
                                    </label>
                                    <input type="text" name="options_a[]" required 
                                           value="<?= htmlspecialchars($question['option_a']) ?>"
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                </div>
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Option B *
                                    </label>
                                    <input type="text" name="options_b[]" required 
                                           value="<?= htmlspecialchars($question['option_b']) ?>"
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                </div>
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Option C *
                                    </label>
                                    <input type="text" name="options_c[]" required 
                                           value="<?= htmlspecialchars($question['option_c']) ?>"
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                                </div>
                                <div class="option-input">
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Option D *
                                    </label>
                                    <input type="text" name="options_d[]" required 
                                           value="<?= htmlspecialchars($question['option_d']) ?>"
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                                </div>
                            </div>
                            
                            <!-- Additional Settings -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Correct Answer *
                                    </label>
                                    <select name="correct_answers[]" required 
                                            onchange="highlightCorrectOption(this)"
                                            class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent">
                                        <option value="">Select Answer</option>
                                        <option value="a" <?= $question['correct_answer'] == 'a' ? 'selected' : '' ?>>Option A</option>
                                        <option value="b" <?= $question['correct_answer'] == 'b' ? 'selected' : '' ?>>Option B</option>
                                        <option value="c" <?= $question['correct_answer'] == 'c' ? 'selected' : '' ?>>Option C</option>
                                        <option value="d" <?= $question['correct_answer'] == 'd' ? 'selected' : '' ?>>Option D</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Marks
                                    </label>
                                    <input type="number" name="marks[]" value="<?= $question['marks'] ?>" min="1" 
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                                        Explanation (Optional)
                                    </label>
                                    <input type="text" name="explanations[]" 
                                           value="<?= htmlspecialchars($question['explanation']) ?>"
                                           class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                           placeholder="Brief explanation">
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- New Questions Container -->
                    <div id="newQuestionsContainer" class="hidden">
                        <!-- New questions will be added here -->
                    </div>
                    
                    <!-- Add Question Button -->
                    <div class="mt-8 text-center">
                        <button type="button" onclick="addNewQuestion()" 
                                class="text-white px-8 py-4 rounded-xl transition-all duration-300 font-semibold shadow-lg hover:opacity-90" style="background: linear-gradient(135deg, #234C6A, #456882)">
                            <i class="fas fa-plus-circle text-xl mr-3"></i>
                            Add New Question
                        </button>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Form Actions -->
                <div class="glass-card rounded-2xl p-6 md:p-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between">
                        <div class="mb-4 md:mb-0">
                            <div class="text-sm text-gray-600 mb-2">Ready to update your test?</div>
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 rounded-full pulse-dot animate-pulse"></div>
                                <div class="font-medium text-gray-800">All changes will be saved</div>
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                <i class="fas fa-info-circle mr-1"></i>
                                Times are saved in IST and converted to UTC for storage
                            </div>
                        </div>
                        
                        <div class="flex space-x-4">
                            <a href="admin_dashboard.php" 
                               class="px-8 py-3 border-2 text-gray-700 rounded-xl hover:bg-gray-50 transition-all duration-300 font-semibold" style="border-color:#D2C1B6">
                                <i class="fas fa-times mr-2"></i>
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="px-8 py-3 text-white rounded-xl transition-all duration-300 font-semibold shadow-lg hover:opacity-90" style="background: linear-gradient(135deg, #1B3C53, #234C6A)">
                                <i class="fas fa-save mr-2"></i>
                                Update Test
                            </button>
                        </div>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        let newQuestionCount = 0;
        
        // Update current time display
        function updateCurrentTime() {
            const now = new Date();
            const options = { 
                weekday: 'long', 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                timeZone: 'Asia/Kolkata'
            };
            
            const formatter = new Intl.DateTimeFormat('en-IN', options);
            document.getElementById('currentTimeDisplay').textContent = formatter.format(now);
        }
        
        // Update time every second
        setInterval(updateCurrentTime, 1000);
        updateCurrentTime(); // Initial call
        
        function highlightCorrectOption(select) {
            const questionCard = select.closest('.question-card');
            const optionIndex = select.value;
            
            // Remove previous highlight
            const optionInputs = questionCard.querySelectorAll('.option-input');
            optionInputs.forEach(input => {
                input.classList.remove('option-correct');
            });
            
            // Add highlight to correct option
            if (optionIndex) {
                const optionIndexMap = { 'a': 0, 'b': 1, 'c': 2, 'd': 3 };
                const optionInput = optionInputs[optionIndexMap[optionIndex]];
                if (optionInput) {
                    optionInput.classList.add('option-correct');
                }
            }
        }
        
        function removeQuestion(button) {
            const questionCard = button.closest('.question-card');
            const questionId = questionCard.getAttribute('data-question-id');
            
            if (confirm('Are you sure you want to remove this question?')) {
                if (questionId) {
                    // Existing question - mark for deletion
                    const questionIdInput = questionCard.querySelector('input[name="question_ids[]"]');
                    questionIdInput.value = 'delete_' + questionId;
                    questionCard.style.opacity = '0.5';
                    questionCard.style.pointerEvents = 'none';
                    questionCard.querySelector('button').disabled = true;
                    
                    // Show deletion indicator
                    const statusDiv = document.createElement('div');
                    statusDiv.className = 'absolute top-4 right-4 bg-red-100 text-red-800 px-3 py-1 rounded-full text-xs font-semibold';
                    statusDiv.innerHTML = '<i class="fas fa-trash mr-1"></i> Marked for deletion';
                    questionCard.appendChild(statusDiv);
                } else {
                    // New question - just remove
                    questionCard.remove();
                }
                
                updateQuestionNumbers();
            }
        }
        
        function addNewQuestion() {
            newQuestionCount++;
            const container = document.getElementById('newQuestionsContainer');
            const index = newQuestionCount;
            
            const newQuestion = document.createElement('div');
            newQuestion.className = 'question-card glass-effect rounded-2xl p-6 mb-6';
            newQuestion.innerHTML = `
                <input type="hidden" name="new_question_indices[]" value="${index}">
                
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-4">
                        <div class="w-10 h-10 rounded-full q-num-circle-new text-white flex items-center justify-center font-bold">
                            New
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800">New Question #${index}</h3>
                            <p class="text-sm text-gray-500">Fill in all details</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeQuestion(this)" 
                            class="w-10 h-10 rounded-xl bg-gradient-to-r from-red-500 to-pink-500 text-white hover:from-red-600 hover:to-pink-600 transition-all duration-300 shadow-md">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                
                <!-- Question Text -->
                <div class="mb-6">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">
                        Question Text *
                    </label>
                    <textarea name="new_questions[]" rows="3" required 
                              class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                              placeholder="Enter your question here..."></textarea>
                </div>
                
                <!-- Options -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Option A *
                        </label>
                        <input type="text" name="new_options_a[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Enter option A">
                    </div>
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Option B *
                        </label>
                        <input type="text" name="new_options_b[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent"
                               placeholder="Enter option B">
                    </div>
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Option C *
                        </label>
                        <input type="text" name="new_options_c[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent"
                               placeholder="Enter option C">
                    </div>
                    <div class="option-input">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Option D *
                        </label>
                        <input type="text" name="new_options_d[]" required 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                               placeholder="Enter option D">
                    </div>
                </div>
                
                <!-- Additional Settings -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Correct Answer *
                        </label>
                        <select name="new_correct_answers[]" required 
                                onchange="highlightCorrectOption(this)"
                                class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-transparent">
                            <option value="">Select Answer</option>
                            <option value="a">Option A</option>
                            <option value="b">Option B</option>
                            <option value="c">Option C</option>
                            <option value="d">Option D</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Marks
                        </label>
                        <input type="number" name="new_marks[]" value="1" min="1" 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">
                            Explanation (Optional)
                        </label>
                        <input type="text" name="new_explanations[]" 
                               class="w-full px-4 py-3 bg-white border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                               placeholder="Brief explanation">
                    </div>
                </div>
            `;
            
            container.appendChild(newQuestion);
            container.classList.remove('hidden');
            
            // Scroll to new question
            newQuestion.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        
        function updateQuestionNumbers() {
            const visibleQuestions = document.querySelectorAll('.question-card:not([style*="opacity: 0.5"])');
            visibleQuestions.forEach((card, index) => {
                const numberDiv = card.querySelector('.w-10.h-10.rounded-full');
                const title = card.querySelector('h3');
                if (numberDiv && title) {
                    const isNew = numberDiv.textContent === 'New';
                    if (!isNew) {
                        numberDiv.textContent = index + 1;
                        title.textContent = `Question #${index + 1}`;
                    }
                }
            });
        }
        
        // Form validation for date ranges
        document.getElementById('editTestForm').addEventListener('submit', function(e) {
            const startDate = document.querySelector('input[name="start_date"]').value;
            const endDate = document.querySelector('input[name="end_date"]').value;
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                if (end < start) {
                    e.preventDefault();
                    alert('End date cannot be earlier than start date!');
                    return false;
                }
            }
            
            // Validate that at least one question exists if no submissions
            const hasSubmissions = <?= $hasSubmissions ? 'true' : 'false' ?>;
            if (!hasSubmissions) {
                const existingQuestions = document.querySelectorAll('textarea[name="questions[]"]').length;
                const newQuestions = document.querySelectorAll('textarea[name="new_questions[]"]').length;
                const markedForDeletion = document.querySelectorAll('input[name="question_ids[]"][value^="delete_"]').length;
                
                if (existingQuestions - markedForDeletion + newQuestions === 0) {
                    e.preventDefault();
                    alert('Test must have at least one question!');
                    return false;
                }
            }
        });
        
        const initialCourseId = <?= json_encode($testData['course_id'] ?? null) ?>;
        const initialSelectedStudents = <?= json_encode($targetStudents ?? []) ?>;

        function toggleTargeting(val) {
            const container = document.getElementById('student_targeting_container');
            const checkboxes = document.querySelectorAll('.student-target-checkbox');
            if (val === 'specific') {
                container.classList.remove('hidden');
            } else {
                container.classList.add('hidden');
                checkboxes.forEach(cb => cb.checked = false);
            }
        }
        
        function selectAllTargetStudents(checked) {
            const checkboxes = document.querySelectorAll('.student-target-checkbox');
            checkboxes.forEach(cb => cb.checked = checked);
        }

        function fetchBatchDetails(batchId, callback = null) {
            if (!batchId) return;
            const courseSelect = document.getElementById('course_id');
            const studentGrid = document.getElementById('student_list_grid');
            
            // Clear current selections
            courseSelect.innerHTML = '<option value="">-- No specific course link --</option>';
            studentGrid.innerHTML = '<div class="col-span-full py-4 text-center text-gray-500 italic"><i class="fas fa-spinner fa-spin mr-2"></i>Loading students...</div>';
            
            fetch(`../actions/get_batch_details.php?batch_id=${encodeURIComponent(batchId)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Populate courses
                        data.courses.forEach(course => {
                            const opt = document.createElement('option');
                            opt.value = course.id;
                            opt.textContent = course.name;
                            if (initialCourseId && String(course.id) === String(initialCourseId)) {
                                opt.selected = true;
                            }
                            courseSelect.appendChild(opt);
                        });
                        
                        // Populate students list
                        studentGrid.innerHTML = '';
                        if (data.students.length > 0) {
                            data.students.forEach(student => {
                                const isChecked = initialSelectedStudents.includes(student.student_id);
                                const label = document.createElement('label');
                                label.className = 'flex items-center p-3 bg-white border border-gray-100 hover:border-[#456882] hover:shadow-sm rounded-xl cursor-pointer transition-all duration-200';
                                label.innerHTML = `
                                    <input type="checkbox" name="student_ids[]" value="${escapeHtml(student.student_id)}" ${isChecked ? 'checked' : ''} class="student-target-checkbox text-blue-600 focus:ring-blue-500 rounded mr-3">
                                    <div class="text-sm">
                                        <div class="font-semibold text-gray-800">${escapeHtml(student.first_name)} ${escapeHtml(student.last_name)}</div>
                                        <div class="text-xs text-gray-500">${escapeHtml(student.student_id)}</div>
                                    </div>
                                `;
                                studentGrid.appendChild(label);
                            });
                        } else {
                            studentGrid.innerHTML = '<div class="col-span-full py-4 text-center text-gray-500 italic">No active students found in this batch.</div>';
                        }
                        if (callback) callback();
                    } else {
                        studentGrid.innerHTML = `<div class="col-span-full py-4 text-center text-red-500 italic">Error loading details: ${escapeHtml(data.error)}</div>`;
                    }
                })
                .catch(err => {
                    console.error(err);
                    studentGrid.innerHTML = '<div class="col-span-full py-4 text-center text-red-500 italic">Failed to fetch details.</div>';
                });
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }

        // Initialize highlights for existing questions
        document.addEventListener('DOMContentLoaded', function() {
            // Load batch details on page load
            const initialBatchId = document.getElementById('batch_id').value;
            if (initialBatchId) {
                fetchBatchDetails(initialBatchId);
            }
            document.querySelectorAll('select[name="correct_answers[]"]').forEach(select => {
                highlightCorrectOption(select);
            });
            
            // Set min time for end date based on start date
            const startDateInput = document.querySelector('input[name="start_date"]');
            const endDateInput = document.querySelector('input[name="end_date"]');
            
            startDateInput.addEventListener('change', function() {
                if (this.value) {
                    endDateInput.min = this.value;
                }
            });
            
            // Initialize end date min based on start date if it exists
            if (startDateInput.value) {
                endDateInput.min = startDateInput.value;
            }
        });
    </script>
</body>
</html>