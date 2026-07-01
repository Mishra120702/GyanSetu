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
$user_id = $_SESSION['user_id'];

try {
    $result = deleteConversation($conversation_id, $user_id);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Conversation deleted successfully',
            'csrf_token' => $_SESSION['csrf_token']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to delete conversation']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>