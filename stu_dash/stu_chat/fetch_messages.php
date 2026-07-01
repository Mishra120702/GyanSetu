<?php
/**
 * Student Chat - Fetch Messages AJAX Endpoint
 * Returns new messages in JSON format for real-time updates
 */

// Turn off error display and ensure we only output JSON
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once '../db_connection.php';
require_once '../chat/chat_functions.php';

session_start();
header('Content-Type: application/json');

// Check student login
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Validate required parameters
if (!isset($_GET['conversation_id']) || !isset($_GET['last_message_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

$conversation_id = filter_var($_GET['conversation_id'], FILTER_VALIDATE_INT);
$last_message_id = filter_var($_GET['last_message_id'], FILTER_VALIDATE_INT);
$user_id = (int)$_SESSION['user_id'];

if ($conversation_id === false || $conversation_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid conversation ID']);
    exit;
}

if ($last_message_id === false || $last_message_id < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid last message ID']);
    exit;
}

try {
    global $db;
    
    // Get student info for access verification
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
    $access_query = $db->prepare("
        SELECT c.id, c.conversation_type
        FROM chat_conversations c
        WHERE c.id = ? AND (
            (c.conversation_type = 'admin_student' AND c.student_id = ?)
            OR 
            (c.conversation_type = 'admin_batch' AND c.batch_id = ?)
        )
    ");
    $access_query->execute([$conversation_id, $student['student_id'], $student['batch_name']]);
    $conversation = $access_query->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized access to conversation']);
        exit;
    }
    
    // Get new messages since last_message_id
    $query = $db->prepare("
        SELECT m.id, m.sender_id, m.message, m.sent_at, m.is_read, m.message_type,
               u.name as sender_name, u.role as sender_role
        FROM chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.sent_at ASC
    ");
    $query->execute([$conversation_id, $last_message_id]);
    $messages = $query->fetchAll(PDO::FETCH_ASSOC);
    
    $read_message_ids = [];
    foreach ($messages as &$msg) {
        // Force integer conversion
        $msg['sender_id'] = (int)$msg['sender_id'];
        $msg['id'] = (int)$msg['id'];
        
        // CRITICAL FIX: Use strict type comparison
        $is_own_message = ($msg['sender_id'] === $user_id);
        $msg['is_own_message'] = $is_own_message;
        
        // Mark non-own messages as read
        if (!$is_own_message && !$msg['is_read']) {
            $update = $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE id = ?");
            $update->execute([$msg['id']]);
            $msg['is_read'] = 1;
            $read_message_ids[] = $msg['id'];
        }
        
        // Add formatted data
        $msg['formatted_time'] = date('g:i A', strtotime($msg['sent_at']));
        $msg['formatted_date'] = date('M j, Y', strtotime($msg['sent_at']));
        $msg['html'] = nl2br(htmlspecialchars($msg['message']));
        $msg['date'] = date('Y-m-d', strtotime($msg['sent_at']));
        $msg['is_read'] = (bool)$msg['is_read'];
    }
    
    // Get the highest message ID for client tracking
    $new_last_message_id = $last_message_id;
    if (!empty($messages)) {
        $new_last_message_id = end($messages)['id'];
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'last_message_id' => $new_last_message_id,
        'read_message_ids' => $read_message_ids,
        'message_count' => count($messages),
        'conversation_type' => $conversation['conversation_type'],
        'current_user_id' => $user_id
    ]);
    
} catch (PDOException $e) {
    error_log("Database error in fetch_messages.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error in fetch_messages.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error occurred']);
}
?>