<?php
// upload_attachment.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;

if (!$conversation_id || !isset($_FILES['attachment'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

$file = $_FILES['attachment'];
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain', 'application/zip'];
$max_size = 10 * 1024 * 1024; // 10MB

// Validate file
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload failed']);
    exit;
}

if (!in_array($file['type'], $allowed_types)) {
    echo json_encode(['success' => false, 'error' => 'File type not allowed']);
    exit;
}

if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'File too large (max 10MB)']);
    exit;
}

// Create upload directory if not exists
$upload_dir = '../uploads/chat_attachments/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '_' . time() . '.' . $extension;
$filepath = $upload_dir . $filename;

if (move_uploaded_file($file['tmp_name'], $filepath)) {
    try {
        $db->beginTransaction();
        
        // Create message
        $stmt = $db->prepare("
            INSERT INTO messages (conversation_id, sender_id, message, created_at, is_read) 
            VALUES (?, ?, ?, NOW(), 0)
        ");
        $message_text = "📎 Sent an attachment: " . $file['name'];
        $stmt->execute([$conversation_id, $user_id, $message_text]);
        $message_id = $db->lastInsertId();
        
        // Save attachment
        $stmt = $db->prepare("
            INSERT INTO chat_attachments (message_id, file_name, file_path, file_size, file_type, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $relative_path = 'uploads/chat_attachments/' . $filename;
        $stmt->execute([$message_id, $file['name'], $relative_path, $file['size'], $file['type']]);
        
        // Update conversation timestamp
        $stmt = $db->prepare("UPDATE conversations SET updated_at = NOW() WHERE id = ?");
        $stmt->execute([$conversation_id]);
        
        $db->commit();
        
        // Get the created message
        $stmt = $db->prepare("
            SELECT m.*, u.name as sender_name, u.role as sender_role,
                   ca.id as attachment_id, ca.file_name, ca.file_path, ca.file_size, ca.file_type
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            LEFT JOIN chat_attachments ca ON m.id = ca.message_id
            WHERE m.id = ?
        ");
        $stmt->execute([$message_id]);
        $new_message = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $new_message['formatted_time'] = date('g:i A', strtotime($new_message['created_at']));
        $new_message['is_attachment'] = true;
        
        echo json_encode([
            'success' => true,
            'message' => $new_message
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error saving attachment: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
}
?>