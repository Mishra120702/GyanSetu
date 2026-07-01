<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $stmt = $db->prepare("UPDATE feedback SET action_taken = ? WHERE id = ?");
    $stmt->execute([$_POST['action_taken'], $_POST['id']]);
}

header("Location: ../feedback/feedback.php");
exit();
?>