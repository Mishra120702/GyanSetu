<?php
require_once '../db_connection.php';
require_once 'chat_functions.php';

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'trainer') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

$trainer_id = $_SESSION['user_id'];

if (isset($_POST['admin_id'])) {
    $admin_id = intval($_POST['admin_id']);
    $conversation_id = getOrCreateAdminConversation($trainer_id, $admin_id);
    echo json_encode(['success' => true, 'conversation_id' => $conversation_id]);
} elseif (isset($_POST['batch_id'])) {
    $batch_id = intval($_POST['batch_id']);
    $conversation_id = getOrCreateBatchConversation($trainer_id, $batch_id);
    echo json_encode(['success' => true, 'conversation_id' => $conversation_id]);
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
}
?>