<?php
// clear_chat.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;
$clear_both = isset($_POST['clear_both']) ? (bool)$_POST['clear_both'] : false;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation']);
    exit;
}

try {
    if ($clear_both && $_SESSION['user_role'] === 'admin') {
        // Admin can clear for everyone
        $stmt = $db->prepare("DELETE FROM messages WHERE conversation_id = ?");
        $stmt->execute([$conversation_id]);
        
        echo json_encode(['success' => true, 'message' => 'Chat cleared for everyone']);
    } else {
        // Clear just for this user
        $stmt = $db->prepare("
            INSERT INTO clear_chat_history (conversation_id, user_id, cleared_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE cleared_at = NOW()
        ");
        $stmt->execute([$conversation_id, $user_id]);
        
        echo json_encode(['success' => true, 'message' => 'Chat cleared']);
    }
} catch (PDOException $e) {
    error_log("Error clearing chat: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>