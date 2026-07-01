<?php
require_once '../db_connection.php';

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
$user_id = $_SESSION['user_id'];

try {
    // Verify user is admin of this conversation
    $query = "SELECT 1 FROM chat_conversations 
              WHERE id = ? AND admin_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Only the conversation admin can clear history']);
        exit();
    }

    // Delete all messages
    $query = "DELETE FROM chat_messages WHERE conversation_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id]);

    // Delete all attachments
    $query = "DELETE a FROM chat_attachments a
              JOIN chat_messages m ON a.message_id = m.id
              WHERE m.conversation_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id]);

    // Update conversation timestamp
    $query = "UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id]);

    echo json_encode([
        'success' => true,
        'csrf_token' => $_SESSION['csrf_token']
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>