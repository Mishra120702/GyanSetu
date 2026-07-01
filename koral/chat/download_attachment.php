<?php
require_once '../db_connection.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit();
}

if (!isset($_GET['id'])) {
    header("HTTP/1.1 400 Bad Request");
    exit();
}

$attachment_id = intval($_GET['id']);

// Get attachment info and verify user has access
$query = "SELECT a.* FROM chat_attachments a
          JOIN chat_messages m ON a.message_id = m.id
          JOIN chat_participants p ON m.conversation_id = p.conversation_id
          WHERE a.id = ? AND p.user_id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$attachment_id, $_SESSION['user_id']]);
$attachment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$attachment) {
    header("HTTP/1.1 404 Not Found");
    exit();
}

$file_path = '../uploads/chat_attachments/' . $attachment['file_path'];

if (!file_exists($file_path)) {
    header("HTTP/1.1 404 Not Found");
    exit();
}

// Set headers for download
header('Content-Description: File Transfer');
header('Content-Type: ' . $attachment['file_type']);
header('Content-Disposition: attachment; filename="' . basename($attachment['file_name']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit();