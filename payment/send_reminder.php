<?php
// send_reminder.php - Send payment reminders
include '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$student_id = $data['student_id'] ?? '';
$installment_id = $data['installment_id'] ?? '';
$message = $data['message'] ?? '';

if (empty($student_id) || empty($installment_id) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    // Get student and installment details
    $stmt = $db->prepare("
        SELECT s.*, fi.*, b.batch_name as batch_full_name
        FROM students s
        LEFT JOIN fee_installments fi ON s.student_id = fi.student_id
        LEFT JOIN batches b ON s.batch_name = b.batch_id
        WHERE s.student_id = ? AND fi.id = ?
    ");
    $stmt->execute([$student_id, $installment_id]);
    $details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$details) {
        throw new Exception('Student or installment not found');
    }
    
    // Here you would integrate with your email/SMS service
    // For now, we'll just log the reminder
    
    $logStmt = $db->prepare("
        INSERT INTO payment_reminders 
        (student_id, installment_id, reminder_message, sent_by, sent_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $logStmt->execute([$student_id, $installment_id, $message, $_SESSION['user_id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Reminder logged successfully',
        'student' => $details['first_name'] . ' ' . $details['last_name'],
        'installment' => '#' . $details['installment_number'],
        'due_date' => $details['due_date']
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>