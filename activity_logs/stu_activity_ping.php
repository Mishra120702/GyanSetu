<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$student_id = $_SESSION['user_id'];
$page_url = $_POST['page_url'] ?? '';

if (empty($page_url)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing page_url']);
    exit;
}

// Sanitize URL
$page_url = sanitizeInput($page_url);

try {
    // Check the latest entry for this student
    $stmt = $db->prepare("SELECT id, page_url FROM student_activity_log WHERE student_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$student_id]);
    $latest = $stmt->fetch();

    $current_time = date('Y-m-d H:i:s');

    if ($latest && $latest['page_url'] === $page_url) {
        // Update last ping time
        $updateStmt = $db->prepare("UPDATE student_activity_log SET last_ping_time = ? WHERE id = ?");
        $updateStmt->execute([$current_time, $latest['id']]);
    } else {
        // Insert new entry
        $insertStmt = $db->prepare("INSERT INTO student_activity_log (student_id, page_url, session_start_time, last_ping_time) VALUES (?, ?, ?, ?)");
        $insertStmt->execute([$student_id, $page_url, $current_time, $current_time]);
    }

    echo json_encode(['status' => 'success']);

} catch(PDOException $e) {
    http_response_code(500);
    error_log("Activity Ping Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error']);
}
?>
