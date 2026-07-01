<?php
require_once '../db_connection.php';

$id = $_GET['id'] ?? 0;

$stmt = $db->prepare("SELECT action_taken FROM feedback WHERE id = ?");
$stmt->execute([$id]);
$feedback = $stmt->fetch(PDO::FETCH_ASSOC);

if ($feedback) {
    echo json_encode(['success' => true, 'action_taken' => $feedback['action_taken']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Feedback not found']);
}
?>