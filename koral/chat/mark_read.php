<?php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    // Update message status to read
    $stmt = $db->prepare("
        UPDATE message_status ms
        JOIN messages m ON m.id = ms.message_id
        SET ms.status = 'read', ms.updated_at = NOW()
        WHERE m.conversation_id = ? AND ms.user_id = ? AND ms.status != 'read'
    ");
    $stmt->execute([$conversation_id, $user_id]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Error in mark_read: " . $e->getMessage());
    echo json_encode(['success' => false]);
}
?>