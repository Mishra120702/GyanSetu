<?php
require_once '../db_connection.php';
require_once 'chat_functions.php';

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!isset($_POST['conversation_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

$conversation_id = intval($_POST['conversation_id']);
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$user_id = $_SESSION['user_id'];

// Verify user is participant in this conversation
$query = "SELECT 1 FROM chat_participants WHERE conversation_id = ? AND user_id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$conversation_id, $user_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (empty($message) && empty($_FILES['attachment'])) {
    echo json_encode(['success' => false, 'error' => 'Message or attachment is required']);
    exit();
}

if (!empty($message) && strlen($message) > 1000) {
    echo json_encode(['success' => false, 'error' => 'Message too long']);
    exit();
}

// Handle file attachment if present
$attachments = [];
$has_attachments = 0;

try {
    $db->beginTransaction();
    
    // Insert message
    $query = "INSERT INTO chat_messages (conversation_id, sender_id, message, has_attachments) VALUES (?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id, $user_id, $message, 0]); // Default to 0, will update if we have attachments
    $message_id = $db->lastInsertId();
    
    // Handle file upload if present
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['attachment'];
        $file_name = basename($file['name']);
        $file_size = $file['size'];
        $file_tmp = $file['tmp_name'];
        $file_type = $file['type'];
        
        // Validate file
        $max_size = 10 * 1024 * 1024; // 10MB
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                         'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                         'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                         'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                         'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed'];
        
        if ($file_size > $max_size) {
            throw new Exception('File size exceeds 10MB limit');
        }
        
        if (!in_array($file_type, $allowed_types)) {
            throw new Exception('File type not allowed');
        }
        
        // Create uploads directory if it doesn't exist
        $upload_dir = '../uploads/chat_attachments/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_name = uniqid() . '.' . $file_ext;
        $upload_path = $upload_dir . $unique_name;
        
        if (move_uploaded_file($file_tmp, $upload_path)) {
            // Insert attachment record
            $query = "INSERT INTO chat_attachments (message_id, file_name, file_path, file_size, file_type) 
                      VALUES (?, ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([$message_id, $file_name, $unique_name, $file_size, $file_type]);
            
            $attachments[] = [
                'id' => $db->lastInsertId(),
                'file_name' => $file_name,
                'file_path' => $unique_name,
                'file_size' => $file_size,
                'file_type' => $file_type
            ];
            
            $has_attachments = 1;
            
            // Update message to indicate it has attachments
            $query = "UPDATE chat_messages SET has_attachments = 1 WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$message_id]);
        } else {
            throw new Exception('Failed to upload file');
        }
    }
    
    // Update conversation timestamp
    $query = "UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id]);
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message_id' => $message_id,
        'attachments' => $attachments,
        'csrf_token' => $_SESSION['csrf_token']
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}