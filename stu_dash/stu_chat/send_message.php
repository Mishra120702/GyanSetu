<?php
// student_chat/send_message.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$conversation_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit;
}

if (strlen($message) > 2000) {
    echo json_encode(['success' => false, 'error' => 'Message too long']);
    exit;
}

try {
    // Verify access
    $checkStmt = $db->prepare("
        SELECT id FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $checkStmt->execute([$conversation_id, $user_id]);
    
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Insert message into main messages table
    $insert = $db->prepare("
        INSERT INTO messages (conversation_id, sender_id, message, created_at, is_read) 
        VALUES (?, ?, ?, NOW(), 0)
    ");
    $insert->execute([$conversation_id, $user_id, $message]);
    
    $message_id = $db->lastInsertId();
    
    // Update conversation timestamp
    $update = $db->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
    $update->execute([$conversation_id]);
    
    // Get the inserted message
    $fetch = $db->prepare("
        SELECT m.*, u.name as sender_name, u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.id = ?
    ");
    $fetch->execute([$message_id]);
    $new_message = $fetch->fetch(PDO::FETCH_ASSOC);
    
    // Format for display
    $new_message['formatted_time'] = date('g:i A', strtotime($new_message['created_at']));
    $new_message['sent_at'] = $new_message['created_at'];
    
    echo json_encode([
        'success' => true,
        'message' => $new_message
    ]);
    
} catch (PDOException $e) {
    error_log("Error sending message: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>