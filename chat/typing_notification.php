<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;
$is_typing = isset($_POST['is_typing']) ? (bool)$_POST['is_typing'] : false;

if (!$conversation_id) {
    exit;
}

// You can store typing status in a cache like Redis or a temporary table
// For now, we'll just update a session variable
$_SESSION['typing'][$conversation_id][$user_id] = [
    'is_typing' => $is_typing,
    'timestamp' => time()
];

echo json_encode(['success' => true]);
?>