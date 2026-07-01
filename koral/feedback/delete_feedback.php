<?php
require_once '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

if (isset($_POST['id'])) {
    $stmt = $db->prepare("DELETE FROM feedback WHERE id = ?");
    $stmt->execute([$_POST['id']]);
    echo "success";
}
?>