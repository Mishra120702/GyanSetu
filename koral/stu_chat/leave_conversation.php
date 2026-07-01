<?php
/**
 * Student Chat - Leave Conversation
 * Allows student to leave/deactivate their conversation
 */

require_once '../db_connection.php';
require_once '../chat/chat_functions.php';

header('Content-Type: application/json');

session_start();

// Check student login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Validate parameters
if (!isset($_POST['conversation_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing conversation_id']);
    exit;
}

$conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
$user_id = $_SESSION['user_id'];

if ($conversation_id === false || $conversation_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid conversation_id']);
    exit;
}

try {
    // Get student information
    $student_query = $db->prepare("
        SELECT s.student_id, 
               COALESCE(s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4) as batch_name
        FROM students s
        WHERE s.user_id = ?
        LIMIT 1
    ");
    $student_query->execute([$user_id]);
    $student = $student_query->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Student information not found']);
        exit;
    }
    
    // Verify student has access to this conversation
    $query = $db->prepare("
        SELECT c.id FROM chat_conversations c
        WHERE c.id = ? AND (
            (c.conversation_type = 'admin_student' AND c.student_id = ?)
            OR (c.conversation_type = 'admin_batch' AND c.batch_id = ?)
        )
    ");
    $query->execute([$conversation_id, $student['student_id'], $student['batch_name']]);
    $conversation = $query->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to conversation']);
        exit;
    }
    
    // Leave conversation by deactivating participant
    $update = $db->prepare("
        UPDATE chat_participants 
        SET is_active = FALSE 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $update->execute([$conversation_id, $user_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Left conversation successfully'
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in stu_chat/leave_conversation.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
} catch (Exception $e) {
    error_log("Error in stu_chat/leave_conversation.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
}
?>
