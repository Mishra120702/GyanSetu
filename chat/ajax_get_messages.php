<?php
require_once '../db_connection.php';
require_once 'chat_functions.php';

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['conversation_id']) || !isset($_GET['last_message_id'])) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

$conversation_id = intval($_GET['conversation_id']);
$last_message_id = intval($_GET['last_message_id']);
$user_id = $_SESSION['user_id'];

try {
    // Verify user is participant in this conversation
    $query = "SELECT 1 FROM chat_participants WHERE conversation_id = ? AND user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }

    $messages = getNewMessages($conversation_id, $last_message_id);

    // Mark messages as read for this user
    markMessagesAsRead($conversation_id, $user_id);

    echo json_encode([
        'messages' => $messages,
        'csrf_token' => $_SESSION['csrf_token'] // Return new CSRF token
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>