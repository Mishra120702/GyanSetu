<?php
/**
 * Delete Conversation
 * Handles permanent deletion of conversations and all associated messages
 */

session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? intval($_POST['conversation_id']) : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid conversation ID']);
    exit;
}

try {
    // Begin transaction
    $db->beginTransaction();
    
    // First, verify that this conversation exists and user has access
    $stmt = $db->prepare("
        SELECT c.id 
        FROM conversations c
        JOIN conversation_members cm ON c.id = cm.conversation_id
        WHERE c.id = ? AND cm.user_id = ?
    ");
    $stmt->execute([$conversation_id, $user_id]);
    
    if (!$stmt->fetch()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Conversation not found or access denied']);
        exit;
    }
    
    // Delete all messages in the conversation first (foreign key constraint)
    $stmt = $db->prepare("DELETE FROM messages WHERE conversation_id = ?");
    $stmt->execute([$conversation_id]);
    $messages_deleted = $stmt->rowCount();
    
    // Delete all conversation members
    $stmt = $db->prepare("DELETE FROM conversation_members WHERE conversation_id = ?");
    $stmt->execute([$conversation_id]);
    $members_deleted = $stmt->rowCount();
    
    // Finally, delete the conversation itself
    $stmt = $db->prepare("DELETE FROM conversations WHERE id = ?");
    $stmt->execute([$conversation_id]);
    $conversation_deleted = $stmt->rowCount();
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => 'Conversation deleted successfully',
        'stats' => [
            'messages_deleted' => $messages_deleted,
            'members_deleted' => $members_deleted,
            'conversation_deleted' => $conversation_deleted
        ]
    ]);
    
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Error deleting conversation: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>