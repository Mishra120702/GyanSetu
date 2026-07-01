<?php
// student_chat/get_messages.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Conversation ID required']);
    exit;
}

try {
    // Verify user has access to this conversation
    $checkStmt = $db->prepare("
        SELECT id FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $checkStmt->execute([$conversation_id, $user_id]);
    
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Get new messages
    $messages_query = $db->prepare("
        SELECT m.*, u.name as sender_name, u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.conversation_id = ? AND m.id > ?
        ORDER BY m.created_at ASC
    ");
    $messages_query->execute([$conversation_id, $last_id]);
    $messages = $messages_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark messages as read
    if (!empty($messages)) {
        $mark_read = $db->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? 
            AND sender_id != ? 
            AND is_read = 0
        ");
        $mark_read->execute([$conversation_id, $user_id]);
    }
    
    // Format messages
    foreach ($messages as &$msg) {
        $msg['formatted_time'] = date('g:i A', strtotime($msg['created_at']));
        $msg['formatted_date'] = date('Y-m-d', strtotime($msg['created_at']));
        $msg['sent_at'] = $msg['created_at']; // For compatibility
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'last_message_id' => !empty($messages) ? end($messages)['id'] : $last_id
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching messages: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>