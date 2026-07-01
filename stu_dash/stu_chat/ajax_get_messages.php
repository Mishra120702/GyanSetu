<?php
require_once '../db_connection.php';
require_once '../chat/chat_functions.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized', 'error_code' => 'UNAUTHORIZED']);
    exit;
}

if (!isset($_POST['conversation_id']) || !isset($_POST['last_message_id'])) {
    echo json_encode(['error' => 'Missing parameters', 'error_code' => 'MISSING_PARAMETERS']);
    exit;
}

$conversation_id = intval($_POST['conversation_id']);
$last_message_id = intval($_POST['last_message_id']);
$user_id = $_SESSION['user_id'];

try {
    // Verify user has access to this conversation
    $query = $db->prepare("
        SELECT c.id 
        FROM chat_conversations c
        WHERE c.id = ? 
        AND (
            (c.conversation_type = 'admin_student' AND c.student_id = (SELECT student_id FROM students WHERE user_id = ?))
            OR 
            (c.conversation_type = 'admin_batch' AND c.batch_id = (SELECT batch_name FROM students WHERE user_id = ?))
        )
    ");
    $query->execute([$conversation_id, $user_id, $user_id]);
    $conversation = $query->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        echo json_encode(['error' => 'Unauthorized access to conversation', 'error_code' => 'CONVERSATION_ACCESS_DENIED']);
        exit;
    }

    // Get new messages since last_message_id
    $query = $db->prepare("
        SELECT m.id, m.sender_id, m.message, m.sent_at, m.is_read, 
               u.name as sender_name, c.conversation_type,
               DATE_FORMAT(m.sent_at, '%h:%i %p') as formatted_time
        FROM chat_messages m
        JOIN users u ON m.sender_id = u.id
        JOIN chat_conversations c ON m.conversation_id = c.id
        WHERE m.conversation_id = ? 
        AND m.id > ?
        ORDER BY m.sent_at ASC
    ");
    $query->execute([$conversation_id, $last_message_id]);
    $messages = $query->fetchAll(PDO::FETCH_ASSOC);

    // Mark new messages as read if they're not from the current user
    $read_messages = [];
    foreach ($messages as $msg) {
        if ($msg['sender_id'] != $user_id && !$msg['is_read']) {
            $update = $db->prepare("UPDATE chat_messages SET is_read = 1 WHERE id = ?");
            $update->execute([$msg['id']]);
            $msg['is_read'] = 1;
            $read_messages[] = $msg['id'];
        }
        // Add additional useful fields
        $msg['is_own_message'] = ($msg['sender_id'] == $user_id);
        $msg['message_date'] = date('Y-m-d', strtotime($msg['sent_at']));
    }

    echo json_encode([
        'success' => true,
        'messages' => $messages,
        'read_messages' => $read_messages,
        'last_message_id' => $last_message_id
    ]);

} catch (PDOException $e) {
    error_log("Database error in ajax_get_messages: " . $e->getMessage());
    echo json_encode(['error' => 'Database error', 'error_code' => 'DB_ERROR']);
} catch (Exception $e) {
    error_log("Error in ajax_get_messages: " . $e->getMessage());
    echo json_encode(['error' => 'Server error', 'error_code' => 'SERVER_ERROR']);
}
?>