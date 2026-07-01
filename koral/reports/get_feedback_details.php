<?php
require_once '../db_connection.php';

$id = $_GET['id'] ?? 0;

$stmt = $db->prepare("SELECT * FROM feedback WHERE id = ?");
$stmt->execute([$id]);
$feedback = $stmt->fetch(PDO::FETCH_ASSOC);

if ($feedback) {
    echo json_encode(['success' => true, 'feedback' => $feedback]);
} else {
    echo json_encode(['success' => false, 'message' => 'Feedback not found']);
}
?>get_feedback_action.php