<?php
session_start();
require_once '../../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$application_id = $_POST['id'] ?? 0;

$db->beginTransaction();

try {
    // Check if application exists and belongs to student
    $check_stmt = $db->prepare("
        SELECT id FROM leave_applications 
        WHERE id = :id AND student_id = :student_id AND status = 'pending'
    ");
    $check_stmt->execute([
        ':id' => $application_id,
        ':student_id' => $_SESSION['student_id'] ?? ''
    ]);
    
    if (!$check_stmt->fetch()) {
        throw new Exception('Application not found or cannot be cancelled');
    }
    
    // Update application status
    $update_stmt = $db->prepare("
        UPDATE leave_applications 
        SET status = 'cancelled' 
        WHERE id = :id
    ");
    $update_stmt->execute([':id' => $application_id]);
    
    // Add to history
    $history_stmt = $db->prepare("
        INSERT INTO leave_application_history (application_id, action, action_by)
        VALUES (:application_id, 'cancelled', :action_by)
    ");
    $history_stmt->execute([
        ':application_id' => $application_id,
        ':action_by' => $_SESSION['user_id']
    ]);
    
    $db->commit();
    echo json_encode(['success' => true, 'message' => 'Application cancelled successfully']);
    
} catch (Exception $e) {
    $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>