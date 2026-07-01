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

    // Get input data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || json_last_error() !== JSON_ERROR_NONE) {
        $input = $_POST;
    }

    $current_batch = $input['current_batch'] ?? null;
    $target_batch = $input['target_batch'] ?? null;
    $students = $input['students'] ?? [];
    $shift_date = $input['shift_date'] ?? date('Y-m-d H:i:s');
    
    if (!$current_batch) {
        throw new Exception("Current batch ID is required");
    }
    
    if (!$target_batch) {
        throw new Exception("Target batch ID is required");
    }
    
    if (empty($students)) {
        throw new Exception("No students selected for transfer");
    }
    
    if (!is_array($students)) {
        throw new Exception("Students data must be an array");
    }
    
    // Connect to database
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verify batches exist
    $batchCheck = $db->prepare("SELECT batch_id, batch_name, max_students, current_enrollment, status FROM batches WHERE batch_id IN (?, ?)");
    $batchCheck->execute([$current_batch, $target_batch]);
    $batches = $batchCheck->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($batches) !== 2) {
        throw new Exception("One or both batches not found");
    }
    
    // Get batch info
    $currentBatchInfo = null;
    $targetBatchInfo = null;
    foreach ($batches as $batch) {
        if ($batch['batch_id'] === $current_batch) {
            $currentBatchInfo = $batch;
        } else if ($batch['batch_id'] === $target_batch) {
            $targetBatchInfo = $batch;
        }
    }
    
    // Check target batch capacity
    $students_count = count($students);
    if (($targetBatchInfo['current_enrollment'] + $students_count) > $targetBatchInfo['max_students']) {
        throw new Exception("Target batch does not have enough capacity. Available: " . 
                          ($targetBatchInfo['max_students'] - $targetBatchInfo['current_enrollment']) . 
                          ", Required: $students_count");
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        $success_count = 0;
        $errors = [];
        $transferred_students = [];
        
        // Prepare statements
        $updateStudentStmt = $db->prepare("UPDATE students SET batch_name = ?, current_status='active' WHERE student_id = ? AND batch_name = ?");
        $insertHistoryStmt = $db->prepare("INSERT INTO student_batch_history 
                                          (student_id, from_batch_id, to_batch_id, transfer_date, transferred_by) 
                                          VALUES (?, ?, ?, ?, ?)");
        $notificationStmt = $db->prepare("INSERT INTO notifications 
                                         (user_id, type, title, message, reference_id, created_at) 
                                         VALUES (?, 'transfer', ?, ?, ?, NOW())");
        
        // Get admin ID from session
        $admin_id = $_SESSION['user_id'];
        
        foreach ($students as $student_id) {
            try {
                // Validate student ID format
                if (!preg_match('/^[A-Z0-9_-]+$/i', $student_id)) {
                    $errors[] = "Invalid student ID format: $student_id";
                    continue;
                }
                
                // Get student details for notification
                $studentDetailStmt = $db->prepare("SELECT first_name, last_name, user_id FROM students WHERE student_id = ?");
                $studentDetailStmt->execute([$student_id]);
                $student = $studentDetailStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    $errors[] = "Student $student_id not found";
                    continue;
                }
                
                // Update student's batch
                $updateResult = $updateStudentStmt->execute([$target_batch, $student_id, $current_batch]);
                
                if ($updateResult && $updateStudentStmt->rowCount() > 0) {
                    // Record in history
                    $insertHistoryStmt->execute([$student_id, $current_batch, $target_batch, $shift_date, $admin_id]);
                    
                    // Create notification for student
                    if ($student['user_id']) {
                        $notificationTitle = "Batch Transfer Notification";
                        $notificationMessage = "You have been transferred from batch {$currentBatchInfo['batch_name']} to {$targetBatchInfo['batch_name']}";
                        $notificationStmt->execute([
                            $student['user_id'],
                            $notificationTitle,
                            $notificationMessage,
                            $student_id
                        ]);
                    }
                    
                    $success_count++;
                    $transferred_students[] = $student['first_name'] . ' ' . $student['last_name'];
                } else {
                    $errors[] = "Student $student_id not found in current batch or already transferred";
                }
                
            } catch (PDOException $e) {
                $errors[] = "Error transferring student $student_id: " . $e->getMessage();
            }
        }
        
        if ($success_count > 0) {
            // Update batch enrollment counts
            $updateSourceStmt = $db->prepare("UPDATE batches SET current_enrollment = GREATEST(0, current_enrollment - ?) WHERE batch_id = ?");
            $updateTargetStmt = $db->prepare("UPDATE batches SET current_enrollment = current_enrollment + ? WHERE batch_id = ?");
            
            $updateSourceStmt->execute([$success_count, $current_batch]);
            $updateTargetStmt->execute([$success_count, $target_batch]);
            
            // REMOVED: The attendance update code that was incorrectly updating all attendance records
            // Attendance records should remain with their original batch for historical accuracy
            
            // Commit transaction
            $db->commit();
            
            $response = [
                'success' => true,
                'message' => "Successfully transferred $success_count student(s) from '{$currentBatchInfo['batch_name']}' to '{$targetBatchInfo['batch_name']}'",
                'transferred_count' => $success_count,
                'transferred_students' => $transferred_students
            ];
            
            if (!empty($errors)) {
                $response['warnings'] = $errors;
            }
            
        } else {
            $db->rollBack();
            $response['message'] = "Failed to transfer any students. " . implode("; ", $errors);
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        throw new Exception("Transaction failed: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Shift students error: " . $e->getMessage());
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit();
?>