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
$conversation_id = isset($_GET['conversation_id']) ? (int)$_GET['conversation_id'] : 0;

if (!$conversation_id) {
    echo json_encode(['typing_users' => []]);
    exit;
}

$typing_users = [];
$current_time = time();

if (isset($_SESSION['typing'][$conversation_id])) {
    foreach ($_SESSION['typing'][$conversation_id] as $typing_user_id => $data) {
        if ($typing_user_id != $user_id && $data['is_typing'] && ($current_time - $data['timestamp'] < 5)) {
            // Get user name
            $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $stmt->execute([$typing_user_id]);
            $user = $stmt->fetch();
            if ($user) {
                $typing_users[] = $user['name'];
            }
        }
    }
}

echo json_encode(['typing_users' => $typing_users]);
?>