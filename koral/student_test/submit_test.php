<?php
// submit_test.php - Final test submission with proper marks calculation
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['attempt_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing attempt ID']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Verify this attempt belongs to the student and is still in progress
        $verifyStmt = $db->prepare("
            SELECT ta.*, t.total_marks as test_total_marks
            FROM test_attempts ta
            JOIN tests t ON ta.test_id = t.id
            WHERE ta.id = ? AND ta.student_id = (SELECT student_id FROM students WHERE user_id = ?)
            AND ta.status = 'in_progress'
        ");
        $verifyStmt->execute([$data['attempt_id'], $_SESSION['user_id']]);
        $attempt = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$attempt) {
            throw new Exception('Invalid attempt or test already submitted');
        }
        
        // Get all answers for this attempt with question details
        $answersStmt = $db->prepare("
            SELECT 
                ta.question_id, 
                ta.selected_answer, 
                tq.correct_answer, 
                tq.marks,
                tq.question_text
            FROM test_answers ta
            JOIN test_questions tq ON ta.question_id = tq.id
            WHERE ta.attempt_id = ?
        ");
        $answersStmt->execute([$data['attempt_id']]);
        $answers = $answersStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all questions for this test to ensure we account for unattempted questions
        $allQuestionsStmt = $db->prepare("
            SELECT id, marks, correct_answer 
            FROM test_questions 
            WHERE test_id = ?
        ");
        $allQuestionsStmt->execute([$attempt['test_id']]);
        $allQuestions = $allQuestionsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate total marks from all questions
        $totalMarks = 0;
        foreach ($allQuestions as $question) {
            $totalMarks += $question['marks'];
        }
        
        // Initialize counters
        $questionsAttempted = 0;
        $correctAnswers = 0;
        $wrongAnswers = 0;
        $unansweredQuestions = 0;
        $obtainedMarks = 0;
        
        // Create a map of answered questions
        $answeredMap = [];
        foreach ($answers as $answer) {
            $answeredMap[$answer['question_id']] = $answer;
        }
        
        // Process each question
        foreach ($allQuestions as $question) {
            $questionId = $question['id'];
            $marks = $question['marks'];
            
            if (isset($answeredMap[$questionId]) && !empty($answeredMap[$questionId]['selected_answer'])) {
                // Question was attempted
                $questionsAttempted++;
                $selectedAnswer = $answeredMap[$questionId]['selected_answer'];
                $correctAnswer = $question['correct_answer'];
                
                if ($selectedAnswer === $correctAnswer) {
                    // Correct answer
                    $correctAnswers++;
                    $obtainedMarks += $marks;
                    
                    // Update answer record as correct
                    $updateStmt = $db->prepare("
                        UPDATE test_answers 
                        SET is_correct = 1, marks_obtained = ?
                        WHERE attempt_id = ? AND question_id = ?
                    ");
                    $updateStmt->execute([$marks, $data['attempt_id'], $questionId]);
                } else {
                    // Wrong answer
                    $wrongAnswers++;
                    
                    // Update answer record as wrong
                    $updateStmt = $db->prepare("
                        UPDATE test_answers 
                        SET is_correct = 0, marks_obtained = 0
                        WHERE attempt_id = ? AND question_id = ?
                    ");
                    $updateStmt->execute([$data['attempt_id'], $questionId]);
                }
            } else {
                // Question was not attempted
                $unansweredQuestions++;
                
                // Check if there's an answer record (shouldn't happen, but just in case)
                if (isset($answeredMap[$questionId])) {
                    // Update existing record as unattempted
                    $updateStmt = $db->prepare("
                        UPDATE test_answers 
                        SET selected_answer = '', is_correct = 0, marks_obtained = 0
                        WHERE attempt_id = ? AND question_id = ?
                    ");
                    $updateStmt->execute([$data['attempt_id'], $questionId]);
                } else {
                    // Insert record for unattempted question
                    $insertStmt = $db->prepare("
                        INSERT INTO test_answers (attempt_id, question_id, selected_answer, is_correct, marks_obtained)
                        VALUES (?, ?, '', 0, 0)
                    ");
                    $insertStmt->execute([$data['attempt_id'], $questionId]);
                }
            }
        }
        
        // Calculate percentage based on total marks
        $percentage = $totalMarks > 0 ? ($obtainedMarks / $totalMarks) * 100 : 0;
        
        // Verify total marks match test total marks
        if ($totalMarks != $attempt['test_total_marks']) {
            error_log("Warning: Test total marks mismatch. Calculated: $totalMarks, Test total: " . $attempt['test_total_marks']);
            // Use the test's total marks from tests table for consistency
            $totalMarks = $attempt['test_total_marks'];
        }
        
        // Update test attempt with final results
        $updateAttemptStmt = $db->prepare("
            UPDATE test_attempts 
            SET submitted_at = NOW(),
                questions_attempted = ?,
                correct_answers = ?,
                wrong_answers = ?,
                total_marks = ?,
                obtained_marks = ?,
                percentage = ?,
                status = 'submitted'
            WHERE id = ?
        ");
        $updateAttemptStmt->execute([
            $questionsAttempted,
            $correctAnswers,
            $wrongAnswers,
            $totalMarks,
            $obtainedMarks,
            round($percentage, 2),
            $data['attempt_id']
        ]);
        
        $db->commit();
        
        // Prepare response with detailed statistics
        echo json_encode([
            'success' => true,
            'percentage' => round($percentage, 2),
            'stats' => [
                'total_questions' => count($allQuestions),
                'attempted' => $questionsAttempted,
                'correct' => $correctAnswers,
                'wrong' => $wrongAnswers,
                'unanswered' => $unansweredQuestions,
                'total_marks' => $totalMarks,
                'obtained_marks' => $obtainedMarks
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Submit test error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error submitting test: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>