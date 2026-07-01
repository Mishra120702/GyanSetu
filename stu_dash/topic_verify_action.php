<?php
session_start();
require_once '../db_connection.php';
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$student_id = $_SESSION['student_id'];
$action     = $_POST['action']   ?? '';
$batch_id   = $_POST['batch_id'] ?? '';

if (!in_array($action, ['verified', 'rejected']) || empty($batch_id)) {
    echo json_encode(['success' => false, 'error' => 'Invalid input']);
    exit;
}

try {
    // Update ALL pending verifications for this student in this batch (for any covered topic in this notification batch)
    $stmt = $db->prepare("
        UPDATE topic_verifications 
        SET status = ?, responded_at = NOW()
        WHERE student_id = ? AND batch_id = ? AND status = 'pending'
    ");
    $stmt->execute([$action, $student_id, $batch_id]);
    
    // Save response_action so the UI remembers
    if (!empty($_POST['notif_id'])) {
        $notif_id = $_POST['notif_id'];
        $n_stmt = $db->prepare("SELECT id FROM student_notification_reads WHERE notification_id = ? AND student_id = ?");
        $n_stmt->execute([$notif_id, $student_id]);
        $read_id = $n_stmt->fetchColumn();
        if ($read_id) {
            $db->prepare("UPDATE student_notification_reads SET response_action = ? WHERE id = ?")->execute([$action, $read_id]);
        } else {
            $db->prepare("INSERT INTO student_notification_reads (notification_id, student_id, response_action) VALUES (?, ?, ?)")->execute([$notif_id, $student_id, $action]);
        }
    }
    
    $rows = $stmt->rowCount();
    if ($rows > 0) {
        $userId = $_SESSION['user_id'] ?? null;
        logSystemActivity($db, $userId, 'TOPIC_VERIFIED', "Student $student_id $action $rows topic(s) in batch $batch_id.");
    }
    
    echo json_encode(['success' => true, 'action' => $action, 'rows' => $rows]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
