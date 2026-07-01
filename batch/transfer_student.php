<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Invalid request method");
    }

    $student_id = $_POST['student_id'] ?? null;
    $target_batch_id = $_POST['target_batch_id'] ?? $_POST['to_batch_id'] ?? null;
    $current_batch = $_POST['current_batch'] ?? $_POST['from_batch_id'] ?? null;
    $transfer_date = $_POST['transfer_date'] ?? date('Y-m-d H:i:s');
    $transfer_reason = $_POST['transfer_reason'] ?? $_POST['reason'] ?? '';
    
    if (!$student_id || !$target_batch_id || !$current_batch) {
        throw new Exception("Missing required fields");
    }
    
    // Validate inputs
    if (!preg_match('/^[A-Z0-9_-]+$/i', $student_id)) {
        throw new Exception("Invalid student ID format");
    }
    
    if (!preg_match('/^[A-Z0-9_-]+$/i', $target_batch_id)) {
        throw new Exception("Invalid target batch ID format");
    }
    
    if (!preg_match('/^[A-Z0-9_-]+$/i', $current_batch)) {
        throw new Exception("Invalid current batch ID format");
    }
    
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Get student details
        $studentStmt = $db->prepare("
            SELECT first_name, last_name, email, user_id,
            CASE 
                WHEN batch_name = ? THEN 'batch_name'
                WHEN batch_name_2 = ? THEN 'batch_name_2'
                WHEN batch_name_3 = ? THEN 'batch_name_3'
                WHEN batch_name_4 = ? THEN 'batch_name_4'
            END as matched_batch_field
            FROM students 
            WHERE student_id = ? AND (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?)
        ");
        $studentStmt->execute([
            $current_batch, $current_batch, $current_batch, $current_batch,
            $student_id,
            $current_batch, $current_batch, $current_batch, $current_batch
        ]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception("Student not found in the specified batch");
        }
        
        // Verify target batch exists and has capacity
        $batchStmt = $db->prepare("SELECT batch_id, batch_name, max_students, current_enrollment FROM batches WHERE batch_id = ? AND status IN ('upcoming', 'ongoing')");
        $batchStmt->execute([$target_batch_id]);
        $target_batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$target_batch) {
            throw new Exception("Target batch not found or not active");
        }
        
        if ($target_batch['current_enrollment'] >= $target_batch['max_students']) {
            throw new Exception("Target batch is full. Cannot transfer student.");
        }
        
        // Update student batch
        $matched_field = $student['matched_batch_field'];
        $updateStmt = $db->prepare("UPDATE students SET {$matched_field} = ?, current_status = 'active' WHERE student_id = ? AND {$matched_field} = ?");
        $updateStmt->execute([$target_batch_id, $student_id, $current_batch]);
        
        if ($updateStmt->rowCount() > 0) {
            // Record in history
            $historyStmt = $db->prepare("INSERT INTO student_batch_history 
                                        (student_id, from_batch_id, to_batch_id, transfer_date, transferred_by, transfer_reason) 
                                        VALUES (?, ?, ?, ?, ?, ?)");
            $historyStmt->execute([
                $student_id, 
                $current_batch, 
                $target_batch_id, 
                $transfer_date, 
                $_SESSION['user_id'],
                $transfer_reason
            ]);
            
            // Update batch enrollment counts
            $decrementStmt = $db->prepare("UPDATE batches SET current_enrollment = GREATEST(0, current_enrollment - 1) WHERE batch_id = ?");
            $decrementStmt->execute([$current_batch]);
            
            $incrementStmt = $db->prepare("UPDATE batches SET current_enrollment = current_enrollment + 1 WHERE batch_id = ?");
            $incrementStmt->execute([$target_batch_id]);
            
            // Create notification for student
            if ($student['user_id']) {
                $notificationStmt = $db->prepare("INSERT INTO notifications 
                                                (user_id, type, title, message, reference_id, created_at) 
                                                VALUES (?, 'transfer', ?, ?, ?, NOW())");
                
                $notificationTitle = "Batch Transfer Notification";
                $notificationMessage = "You have been transferred from batch {$current_batch} to {$target_batch_id}";
                $notificationStmt->execute([
                    $student['user_id'],
                    $notificationTitle,
                    $notificationMessage,
                    $student_id
                ]);
            }
            
            // REMOVED: The attendance update code that was incorrectly updating all attendance records
            // Attendance records should remain with their original batch for historical accuracy
            
            // Commit transaction
            $db->commit();
            
            $response = [
                'success' => true, 
                'message' => "Student {$student['first_name']} {$student['last_name']} transferred successfully from {$current_batch} to {$target_batch_id}"
            ];
            
        } else {
            $db->rollBack();
            $response['message'] = 'Student not found or already transferred';
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Transfer student error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit();
?>