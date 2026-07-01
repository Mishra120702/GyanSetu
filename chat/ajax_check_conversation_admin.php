<?php
require_once '../db_connection.php';

header('Content-Type: application/json');

session_start();

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['conversation_id'])) {
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

$conversation_id = intval($_GET['conversation_id']);
$user_id = $_SESSION['user_id'];

// Check if user is admin of this conversation
$query = "SELECT 1 FROM chat_conversations 
          WHERE id = ? AND admin_id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$conversation_id, $user_id]);
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'is_admin' => $result ? true : false,
    'csrf_token' => $_SESSION['csrf_token']
]);
?>