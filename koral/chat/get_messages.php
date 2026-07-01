<?php
// get_messages.php
session_start();
require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// Log the request
error_log("get_messages.php - GET: " . print_r($_GET, true));

if (!isset($_SESSION['user_id'])) {
    error_log("get_messages.php - Not authenticated");
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;
$last_id = isset($_GET['last_id']) ? intval($_GET['last_id']) : 0;

error_log("get_messages.php - conversation_id: $conversation_id, last_id: $last_id, user_id: $user_id");

if (!$conversation_id) {
    error_log("get_messages.php - No conversation ID");
    echo json_encode(['success' => false, 'error' => 'Conversation ID required']);
    exit;
}

try {
    // First check if user has access to this conversation
    $checkStmt = $db->prepare("
        SELECT id FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ? AND is_active = 1
    ");
    $checkStmt->execute([$conversation_id, $user_id]);
    
    if (!$checkStmt->fetch()) {
        error_log("get_messages.php - User $user_id not in conversation $conversation_id");
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Check if messages table has is_read column
    $columns = $db->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_COLUMN);
    $has_is_read = in_array('is_read', $columns);
    $has_is_deleted = in_array('is_deleted', $columns);
    
    error_log("get_messages.php - has_is_read: " . ($has_is_read ? 'yes' : 'no'));
    
    // Get messages
    if ($has_is_read && $has_is_deleted) {
        $query = "
            SELECT m.*, u.name as sender_name, u.role as sender_role
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ? AND m.id > ? AND m.is_deleted = 0
            ORDER BY m.created_at ASC
        ";
    } else {
        $query = "
            SELECT m.id, m.conversation_id, m.sender_id, m.message, m.created_at,
                   u.name as sender_name, u.role as sender_role
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ? AND m.id > ?
            ORDER BY m.created_at ASC
        ";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id, $last_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("get_messages.php - Found " . count($messages) . " messages");
    
    // Mark messages as read if the column exists
    if (!empty($messages) && $has_is_read) {
        $updateStmt = $db->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
        ");
        $updateStmt->execute([$conversation_id, $user_id]);
        error_log("get_messages.php - Marked messages as read");
    }
    
    // Format messages
    foreach ($messages as &$msg) {
        $msg['formatted_time'] = date('g:i A', strtotime($msg['created_at']));
        $msg['formatted_date'] = date('Y-m-d', strtotime($msg['created_at']));
        $msg['ist_time'] = date('Y-m-d H:i:s', strtotime($msg['created_at']));
        // Add is_read field if it doesn't exist
        if (!isset($msg['is_read'])) {
            $msg['is_read'] = 0;
        }
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);
    
} catch (PDOException $e) {
    error_log("get_messages.php - Database error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("get_messages.php - General error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>