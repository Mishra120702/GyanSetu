<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

header('Content-Type: application/json');

try {
    $user_id = $_SESSION['user_id'];
    
    // Get trainer ID from trainers table
    $trainer_stmt = $db->prepare("SELECT id FROM trainers WHERE user_id = ?");
    $trainer_stmt->execute([$user_id]);
    $trainer = $trainer_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$trainer) {
        echo json_encode(['success' => false, 'message' => 'Trainer not found']);
        exit;
    }
    
    $trainer_id = $trainer['id'];

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    switch ($action) {
        case 'fetch':
            fetchAttendance($db, $trainer_id);
            break;
            
        case 'update':
            updateAttendance($db, $trainer_id);
            break;
            
        case 'student_history':
            getStudentHistory($db, $trainer_id);
            break;
            
        case 'get_students':
            getStudents($db, $trainer_id);
            break;
            
        case 'get_batches':
            getTrainerBatches($db, $trainer_id);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (PDOException $e) {
    error_log("Database error in trainer_attendance_api.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error in trainer_attendance_api.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Fetch attendance records for a specific batch and date
 * CRITICAL: Verifies trainer has access to the batch before returning data
 */
function fetchAttendance($db, $trainer_id) {
    $batchId = $_GET['batch_id'] ?? '';
    $courseId = $_GET['course_id'] ?? '';
    $date = $_GET['date'] ?? '';
    
    if (empty($date)) {
        echo json_encode(['success' => false, 'message' => 'Date is required']);
        return;
    }
    
    if (empty($batchId) || empty($courseId)) {
        echo json_encode(['success' => false, 'message' => 'Batch ID and Course ID are required']);
        return;
    }
    
    // CRITICAL: Verify trainer has access to this batch+course combination
    $verifySql = "SELECT COUNT(*) FROM batch_courses WHERE batch_id = ? AND course_id = ? AND trainer_id = ?";
    $verifyStmt = $db->prepare($verifySql);
    $verifyStmt->execute([$batchId, $courseId, $trainer_id]);
    
    if ($verifyStmt->fetchColumn() == 0) {
        error_log("Access denied: Trainer $trainer_id attempted to access batch $batchId and course $courseId");
        echo json_encode(['success' => false, 'message' => 'Access denied to this class']);
        return;
    }
    
    // Get batch name for display
    $batchNameSql = "SELECT batch_name FROM batches WHERE batch_id = ?";
    $batchNameStmt = $db->prepare($batchNameSql);
    $batchNameStmt->execute([$batchId]);
    $batchName = $batchNameStmt->fetchColumn();
    
    // Get attendance records with student details
    $sql = "SELECT 
                a.id, 
                a.student_id, 
                a.student_name,
                a.batch_id,
                a.course_id,
                a.date,
                a.status,
                a.camera_status,
                a.remarks,
                s.batch_name,
                s.batch_name_2,
                s.batch_name_3,
                ? as current_batch_name
            FROM course_attendance a
            LEFT JOIN students s ON a.student_id = s.student_id
            WHERE a.date = ? AND a.batch_id = ? AND a.course_id = ?
            ORDER BY a.student_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$batchName, $date, $batchId, $courseId]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no records exist, create them for this batch and course
    if (empty($attendance)) {
        $attendance = createAttendanceRecords($db, $batchId, $courseId, $date, $trainer_id);
    }
    
    echo json_encode(['success' => true, 'data' => $attendance]);
}

/**
 * Create attendance records for students in a batch and course
 * Only creates records for students in the specified batch
 */
function createAttendanceRecords($db, $batchId, $courseId, $date, $trainer_id) {
    // Double-check trainer has access to this batch+course
    $verifySql = "SELECT COUNT(*) FROM batch_courses WHERE batch_id = ? AND course_id = ? AND trainer_id = ?";
    $verifyStmt = $db->prepare($verifySql);
    $verifyStmt->execute([$batchId, $courseId, $trainer_id]);
    
    if ($verifyStmt->fetchColumn() == 0) {
        return [];
    }
    
    // Get batch name
    $batchNameSql = "SELECT batch_name FROM batches WHERE batch_id = ?";
    $batchNameStmt = $db->prepare($batchNameSql);
    $batchNameStmt->execute([$batchId]);
    $batchName = $batchNameStmt->fetchColumn();
    
    // Get active students ONLY in this specific batch
    $studentSql = "SELECT student_id, CONCAT(first_name, ' ', last_name) as student_name,
                          batch_name, batch_name_2, batch_name_3
                   FROM students 
                   WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ?) 
                   AND current_status = 'active'";
    $studentStmt = $db->prepare($studentSql);
    $studentStmt->execute([$batchId, $batchId, $batchId]);
    $students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $attendanceRecords = [];
    
    foreach ($students as $student) {
        // Check if record already exists
        $checkSql = "SELECT id FROM course_attendance WHERE date = ? AND batch_id = ? AND course_id = ? AND student_id = ?";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([$date, $batchId, $courseId, $student['student_id']]);
        $existingId = $checkStmt->fetchColumn();
        
        if ($existingId) {
            // Get existing record
            $existingSql = "SELECT a.*, s.batch_name, s.batch_name_2, s.batch_name_3 
                           FROM course_attendance a
                           LEFT JOIN students s ON a.student_id = s.student_id
                           WHERE a.id = ?";
            $existingStmt = $db->prepare($existingSql);
            $existingStmt->execute([$existingId]);
            $record = $existingStmt->fetch(PDO::FETCH_ASSOC);
            $record['current_batch_name'] = $batchName;
            $attendanceRecords[] = $record;
        } else {
            // Create new record
            $insertSql = "INSERT INTO course_attendance (date, batch_id, course_id, student_id, student_name, status, camera_status, remarks) 
                          VALUES (?, ?, ?, ?, ?, 'Absent', 'Off', '')";
            $insertStmt = $db->prepare($insertSql);
            $insertStmt->execute([$date, $batchId, $courseId, $student['student_id'], $student['student_name']]);
            
            $attendanceRecords[] = [
                'id' => $db->lastInsertId(),
                'student_id' => $student['student_id'],
                'student_name' => $student['student_name'],
                'batch_id' => $batchId,
                'course_id' => $courseId,
                'date' => $date,
                'status' => 'Absent',
                'camera_status' => 'Off',
                'remarks' => '',
                'batch_name' => $student['batch_name'],
                'batch_name_2' => $student['batch_name_2'],
                'batch_name_3' => $student['batch_name_3'],
                'current_batch_name' => $batchName
            ];
        }
    }
    
    return $attendanceRecords;
}

/**
 * Update attendance records
 * Verifies trainer has access to each record before updating
 */
function updateAttendance($db, $trainer_id) {
    $changes = json_decode($_POST['changes'], true);
    
    if (empty($changes)) {
        echo json_encode(['success' => false, 'message' => 'No changes provided']);
        return;
    }
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($changes as $change) {
        try {
            // CRITICAL: Verify trainer has access to this attendance record
            $verifySql = "SELECT COUNT(*) FROM course_attendance a 
                         JOIN batch_courses bc ON a.batch_id = bc.batch_id AND a.course_id = bc.course_id
                         WHERE a.id = ? AND bc.trainer_id = ?";
            $verifyStmt = $db->prepare($verifySql);
            $verifyStmt->execute([$change['id'], $trainer_id]);
            
            if ($verifyStmt->fetchColumn() == 0) {
                error_log("Access denied: Trainer $trainer_id attempted to update attendance record {$change['id']}");
                $errorCount++;
                continue;
            }
            
            // Ensure camera_status is 'Off' if status is not 'Present'
            $cameraStatus = ($change['status'] === 'Present') ? $change['camera_status'] : 'Off';
            
            $stmt = $db->prepare("UPDATE course_attendance 
                                 SET status = ?, camera_status = ?, remarks = ?
                                 WHERE id = ?");
            
            if ($stmt->execute([
                $change['status'],
                $cameraStatus,
                $change['remarks'] ?? '',
                $change['id']
            ])) {
                $successCount++;
            } else {
                $errorCount++;
            }
        } catch (PDOException $e) {
            error_log("Error updating attendance record {$change['id']}: " . $e->getMessage());
            $errorCount++;
        }
    }
    
    if ($errorCount > 0) {
        echo json_encode([
            'success' => false, 
            'message' => "Updated $successCount records, failed to update $errorCount records"
        ]);
    } else {
        echo json_encode([
            'success' => true, 
            'message' => "Successfully updated $successCount attendance records"
        ]);
    }
}

/**
 * Get attendance history for a specific student
 * Verifies trainer has access to the student's batches
 */
function getStudentHistory($db, $trainer_id) {
    $studentId = $_GET['student_id'] ?? '';
    $startDate = $_GET['start_date'] ?? '';
    $endDate = $_GET['end_date'] ?? '';
    
    if (empty($studentId)) {
        echo json_encode(['success' => false, 'message' => 'Student ID is required']);
        return;
    }
    
    // CRITICAL: Verify trainer has access to this student
    // Check if student belongs to any batch assigned to this trainer
    $verifySql = "SELECT COUNT(*) FROM students s 
                 WHERE s.student_id = ? 
                 AND EXISTS (
                     SELECT 1 FROM batch_courses bc 
                     WHERE bc.trainer_id = ? 
                     AND (bc.batch_id = s.batch_name OR bc.batch_id = s.batch_name_2 OR bc.batch_id = s.batch_name_3)
                 )";
    $verifyStmt = $db->prepare($verifySql);
    $verifyStmt->execute([$studentId, $trainer_id]);
    
    if ($verifyStmt->fetchColumn() == 0) {
        error_log("Access denied: Trainer $trainer_id attempted to view history for student $studentId");
        echo json_encode(['success' => false, 'message' => 'Access denied to this student']);
        return;
    }
    
    // Get attendance history
    $sql = "SELECT 
                a.date,
                a.batch_id,
                c.name as course_name,
                a.status,
                a.camera_status,
                a.remarks,
                b.batch_name
            FROM course_attendance a
            LEFT JOIN batches b ON a.batch_id = b.batch_id
            LEFT JOIN courses c ON a.course_id = c.id
            WHERE a.student_id = ?";
    
    $params = [$studentId];
    
    if (!empty($startDate)) {
        $sql .= " AND a.date >= ?";
        $params[] = $startDate;
    }
    
    if (!empty($endDate)) {
        $sql .= " AND a.date <= ?";
        $params[] = $endDate;
    }
    
    $sql .= " ORDER BY a.date DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $history]);
}

/**
 * Get students from trainer's assigned batches only
 */
function getStudents($db, $trainer_id) {
    // Get students ONLY from batches assigned to this trainer
    $sql = "SELECT DISTINCT s.student_id, CONCAT(s.first_name, ' ', s.last_name) as student_name,
                   s.batch_name, s.batch_name_2, s.batch_name_3
            FROM students s
            JOIN batch_courses bc ON (bc.batch_id = s.batch_name OR bc.batch_id = s.batch_name_2 OR bc.batch_id = s.batch_name_3)
            WHERE bc.trainer_id = ?
            AND s.current_status = 'active'
            ORDER BY s.first_name, s.last_name";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$trainer_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $students]);
}

/**
 * Get batches and courses assigned to this trainer only
 */
function getTrainerBatches($db, $trainer_id) {
    $sql = "SELECT b.batch_id, b.batch_name, c.id as course_id, c.name as course_name, 
                   b.status, b.current_enrollment, b.max_students 
            FROM batch_courses bc
            JOIN batches b ON bc.batch_id = b.batch_id
            JOIN courses c ON bc.course_id = c.id
            WHERE bc.trainer_id = ? 
            AND b.status IN ('ongoing', 'upcoming')
            ORDER BY 
                CASE b.status 
                    WHEN 'ongoing' THEN 1 
                    WHEN 'upcoming' THEN 2 
                    ELSE 3 
                END,
                b.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$trainer_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $batches]);
}
?>