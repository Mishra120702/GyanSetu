<?php
require_once '../db_connection.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedback_id = $_POST['feedback_id'] ?? 0;
    $action_taken = $_POST['action_taken'] ?? '';
    
    $stmt = $db->prepare("UPDATE feedback SET action_taken = ? WHERE id = ?");
    $success = $stmt->execute([$action_taken, $feedback_id]);
    
    if ($success) {
        header("Location: feedback_reports.php?success=Action updated successfully");
    } else {
        header("Location: feedback_reports.php?error=Error updating action");
    }
    exit();
}
?>