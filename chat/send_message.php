<?php
// send_message.php
session_start();
require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
$message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$conversation_id || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    // Get IST time
    $ist_time = date('Y-m-d H:i:s');
    
    // Check if messages table has is_read column
    $columns = $db->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_COLUMN);
    $has_is_read = in_array('is_read', $columns);
    
    // Insert message
    if ($has_is_read) {
        $stmt = $db->prepare("
            INSERT INTO messages (conversation_id, sender_id, message, is_read, created_at) 
            VALUES (?, ?, ?, 0, ?)
        ");
        $stmt->execute([$conversation_id, $user_id, $message, $ist_time]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO messages (conversation_id, sender_id, message, created_at) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$conversation_id, $user_id, $message, $ist_time]);
    }
    
    $message_id = $db->lastInsertId();
    
    // Update conversation timestamp
    $stmt = $db->prepare("UPDATE conversations SET updated_at = ? WHERE id = ?");
    $stmt->execute([$ist_time, $conversation_id]);
    
    // Get the inserted message
    $stmt = $db->prepare("
        SELECT m.*, u.name as sender_name, u.role as sender_role
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.id = ?
    ");
    $stmt->execute([$message_id]);
    $new_message = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Format time
    $new_message['formatted_time'] = date('g:i A', strtotime($new_message['created_at']));
    $new_message['formatted_date'] = date('M j, Y', strtotime($new_message['created_at']));
    $new_message['ist_time'] = $ist_time;
    if (!isset($new_message['is_read'])) {
        $new_message['is_read'] = 0;
    }
    
    echo json_encode([
        'success' => true,
        'message' => $new_message
    ]);
    
} catch (PDOException $e) {
    error_log("Error sending message: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>