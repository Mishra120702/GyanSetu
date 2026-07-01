<?php
// soft_delete_conversation.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = intval($_POST['conversation_id']);

try {
    // Instead of deleting, mark all messages as deleted for this user
    $stmt = $db->prepare("
        UPDATE messages 
        SET is_deleted = 1 
        WHERE conversation_id = ?
    ");
    $stmt->execute([$conversation_id]);
    
    // Or remove user from conversation members (hide conversation)
    $stmt = $db->prepare("
        UPDATE conversation_members 
        SET is_active = 0 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversation_id, $user_id]);
    
    echo json_encode(['success' => true, 'message' => 'Conversation hidden']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>