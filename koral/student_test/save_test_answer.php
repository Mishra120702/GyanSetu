<?php
// save_test_answer.php - Save answers during test
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (!isset($data['attempt_id']) || !isset($data['answers']) || !isset($data['time_spent'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }
    
    try {
        $db->beginTransaction();
        
        // Verify this attempt belongs to the student
        $verifyStmt = $db->prepare("
            SELECT id FROM test_attempts 
            WHERE id = ? AND student_id = (SELECT student_id FROM students WHERE user_id = ?)
        ");
        $verifyStmt->execute([$data['attempt_id'], $_SESSION['user_id']]);
        if (!$verifyStmt->fetch()) {
            throw new Exception('Invalid attempt ID');
        }
        
        foreach ($data['answers'] as $questionId => $answer) {
            // Get question marks for later use
            $marksStmt = $db->prepare("SELECT marks FROM test_questions WHERE id = ?");
            $marksStmt->execute([$questionId]);
            $questionMarks = $marksStmt->fetchColumn();
            
            // Check if answer already exists
            $checkStmt = $db->prepare("
                SELECT id, selected_answer FROM test_answers 
                WHERE attempt_id = ? AND question_id = ?
            ");
            $checkStmt->execute([$data['attempt_id'], $questionId]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Only update if answer changed
                if ($existing['selected_answer'] !== $answer) {
                    // Update existing answer - reset marks until final submission
                    $updateStmt = $db->prepare("
                        UPDATE test_answers 
                        SET selected_answer = ?, 
                            is_correct = 0, 
                            marks_obtained = 0,
                            answered_at = NOW() 
                        WHERE attempt_id = ? AND question_id = ?
                    ");
                    $updateStmt->execute([$answer, $data['attempt_id'], $questionId]);
                }
            } else {
                // Insert new answer (unmarked until submission)
                $insertStmt = $db->prepare("
                    INSERT INTO test_answers (attempt_id, question_id, selected_answer, marks_obtained, is_correct)
                    VALUES (?, ?, ?, 0, 0)
                ");
                $insertStmt->execute([$data['attempt_id'], $questionId, $answer]);
            }
        }
        
        // Update time spent
        $timeStmt = $db->prepare("
            UPDATE test_attempts 
            SET time_taken_seconds = ? 
            WHERE id = ?
        ");
        $timeStmt->execute([$data['time_spent'], $data['attempt_id']]);
        
        $db->commit();
        
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>