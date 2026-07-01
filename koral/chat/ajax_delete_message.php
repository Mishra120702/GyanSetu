<?php
require_once '../db_connection.php';

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!isset($_POST['message_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

$message_id = intval($_POST['message_id']);
$user_id = $_SESSION['user_id'];

try {
    // Verify user is the sender of this message
    $query = "SELECT sender_id FROM chat_messages WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$message_id]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message || $message['sender_id'] != $user_id) {
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit();
    }

    // Get conversation ID for updating timestamp
    $query = "SELECT conversation_id FROM chat_messages WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$message_id]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    $conversation_id = $conversation['conversation_id'];

    // Delete message
    $query = "DELETE FROM chat_messages WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$message_id]);

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