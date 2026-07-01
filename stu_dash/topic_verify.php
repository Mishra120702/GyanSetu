<?php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$student_id = $_SESSION['student_id'] ?? null;
if (!$student_id) {
    // Try to get student_id from user_id
    $s = $db->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $s->execute([$_SESSION['user_id']]);
    $student_id = $s->fetchColumn();
}

if (!$student_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Student not found']);
    exit;
}

header('Content-Type: application/json');

// Handle verification response
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $verif_id = (int)($_POST['verif_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'verified' or 'rejected'
    $note = trim($_POST['note'] ?? '');
    
    if (!in_array($action, ['verified', 'rejected']) || !$verif_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid input']);
        exit;
    }
    
    $stmt = $db->prepare("
        UPDATE topic_verifications 
        SET status = ?, responded_at = NOW(), response_note = ?
        WHERE id = ? AND student_id = ? AND status = 'pending'
    ");
    $stmt->execute([$action, $note ?: null, $verif_id, $student_id]);
    
    echo json_encode(['success' => true, 'action' => $action]);
    exit;
}

// GET: Fetch pending verifications for this student
$stmt = $db->prepare("
    SELECT tv.id, tv.main_topic_id, tv.batch_id, tv.course_id, tv.status, tv.created_at,
           mt.chapter, mt.topic_name, mt.topic_type,
           c.name as course_name,
           b.batch_name
    FROM topic_verifications tv
    JOIN main_topics mt ON tv.main_topic_id = mt.id
    JOIN courses c ON tv.course_id = c.id
    JOIN batches b ON tv.batch_id = b.batch_id
    WHERE tv.student_id = ? AND tv.status = 'pending'
    ORDER BY tv.created_at DESC
");
$stmt->execute([$student_id]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['success' => true, 'pending' => $pending, 'count' => count($pending)]);
