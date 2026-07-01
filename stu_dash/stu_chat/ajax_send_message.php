<?php
require_once '../db_connection.php';
require_once '../chat/chat_functions.php';

session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized', 'error_code' => 'UNAUTHORIZED']);
    exit;
}

// Validate required parameters
if (!isset($_POST['conversation_id']) || !isset($_POST['message'])) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters', 'error_code' => 'MISSING_PARAMETERS']);
    exit;
}

// Validate CSRF token if provided
if (isset($_POST['csrf_token'])) {
    if (!isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token', 'error_code' => 'INVALID_CSRF']);
        exit;
    }
}

// Sanitize and validate inputs
$conversation_id = filter_var($_POST['conversation_id'], FILTER_VALIDATE_INT);
$message = trim($_POST['message']);
$user_id = $_SESSION['user_id'];

// Validate conversation ID
if ($conversation_id === false || $conversation_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation ID', 'error_code' => 'INVALID_CONVERSATION_ID']);
    exit;
}

// Validate message content
if (empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty', 'error_code' => 'EMPTY_MESSAGE']);
    exit;
}

// Check message length
if (strlen($message) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Message too long. Maximum 2000 characters allowed.', 'error_code' => 'MESSAGE_TOO_LONG']);
    exit;
}

try {
    // Verify user has access to this conversation
    $query = $db->prepare("
        SELECT c.id 
        FROM chat_conversations c
        WHERE c.id = ? 
        AND (
            (c.conversation_type = 'admin_student' AND c.student_id = (SELECT student_id FROM students WHERE user_id = ?))
            OR 
            (c.conversation_type = 'admin_batch' AND c.batch_id = (SELECT batch_name FROM students WHERE user_id = ?))
        )
    ");
    $query->execute([$conversation_id, $user_id, $user_id]);
    $conversation = $query->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to conversation', 'error_code' => 'CONVERSATION_ACCESS_DENIED']);
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error in conversation verification: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.', 'error_code' => 'DB_ERROR']);
    exit;
}

// Send the message
try {
    $message_id = sendMessage($conversation_id, $user_id, $message);
    
    if ($message_id) {
        // Get the complete message details to return to client
        $query = $db->prepare("
            SELECT m.id, m.sender_id, m.message, m.sent_at, m.is_read, 
                   u.name as sender_name,
                   DATE_FORMAT(m.sent_at, '%h:%i %p') as formatted_time
            FROM chat_messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.id = ?
        ");
        $query->execute([$message_id]);
        $message_data = $query->fetch(PDO::FETCH_ASSOC);
        
        if ($message_data) {
            $message_data['formatted_time'] = date('g:i A', strtotime($message_data['sent_at']));
            $message_data['is_own_message'] = ($message_data['sender_id'] == $user_id);
            $message_data['message_date'] = date('Y-m-d', strtotime($message_data['sent_at']));
        }
        
        echo json_encode([
            'success' => true,
            'message_id' => $message_id,
            'message_data' => $message_data
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to send message',
            'error_code' => 'SEND_FAILED'
        ]);
    }
} catch (Exception $e) {
    error_log("Error sending message: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Server error. Please try again.',
        'error_code' => 'SERVER_ERROR'
    ]);
}
?>