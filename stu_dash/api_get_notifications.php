<?php
require_once '../db_connection.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$student_id = $_SESSION['student_id'];
// Sometimes student_id is stored in a session variable with a different name, usually 'student_id' or 'user_id' for student.
// Let's make sure we have the correct identifier. The table 'students' has 'student_id' column (e.g. STD001) or id.
// In previous student dash context, $_SESSION['student_id'] is used as the student's unique ID.
// Wait, the new table `student_notification_reads` uses `student_id INT`. 
// Is `student_id` in `students` table an INT or VARCHAR? I checked schema earlier, it's `student_id VARCHAR(20)`. Oh, my `student_notification_reads` table has `student_id INT`.
// Let's modify the `student_notification_reads` table to have `student_id VARCHAR(20)`.

// First, get the student's batches
try {
    $stmt = $db->prepare("SELECT batch_name, batch_name_2, batch_name_3, batch_name_4 FROM students WHERE student_id = :student_id");
    $stmt->execute([':student_id' => $student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit;
    }
    
    $student_batches = array_filter([
        $student['batch_name'],
        $student['batch_name_2'],
        $student['batch_name_3'],
        $student['batch_name_4']
    ]);
    
    if (empty($student_batches)) {
        // If no batches, use a dummy value so IN clause doesn't fail
        $student_batches = ['NO_BATCH'];
    }
    
    $placeholders = implode(',', array_fill(0, count($student_batches), '?'));
    
    // Now fetch notifications
    $query = "
        SELECT an.*, 
               CASE WHEN snr.id IS NOT NULL THEN 1 ELSE 0 END as is_read,
               snr.response_action
        FROM admin_notifications an
        LEFT JOIN student_notification_reads snr 
               ON an.id = snr.notification_id AND snr.student_id = ?
        WHERE an.target_type = 'all'
           OR an.target_type = 'students'
           OR (an.target_type = 'student' AND FIND_IN_SET(?, an.target_id) > 0)
           OR (an.target_type = 'batch' AND an.target_id IN ($placeholders))
           OR (an.target_type = 'course' AND an.target_id IN (
               SELECT course_id FROM batch_courses WHERE batch_id IN ($placeholders)
           ))
        ORDER BY an.created_at DESC
        LIMIT 50
    ";
    
    $params = [$student_id, $student_id];
    // Add parameters for the two IN clauses
    foreach ($student_batches as $batch) $params[] = $batch;
    foreach ($student_batches as $batch) $params[] = $batch;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count unread
    $unread_count = 0;
    foreach ($notifications as $n) {
        if ($n['is_read'] == 0) {
            $unread_count++;
        }
    }
    
    echo json_encode([
        'success' => true, 
        'data' => $notifications,
        'unread_count' => $unread_count
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
