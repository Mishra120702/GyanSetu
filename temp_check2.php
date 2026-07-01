<?php
session_start();
$_SESSION['student_id'] = 'STD003';
require 'db_connection.php';
try {
    $student_id = 'STD003';
    $student_batches = ['App Development'];
    $placeholders = '?';
    $query = "
        SELECT an.*, 
               CASE WHEN snr.id IS NOT NULL THEN 1 ELSE 0 END as is_read,
               snr.response_action
        FROM admin_notifications an
        LEFT JOIN student_notification_reads snr 
               ON an.id = snr.notification_id AND snr.student_id = ?
        WHERE an.target_type = 'all'
           OR (an.target_type = 'student' AND FIND_IN_SET(?, an.target_id) > 0)
           OR (an.target_type = 'batch' AND an.target_id IN ($placeholders))
           OR (an.target_type = 'course' AND an.target_id IN (
               SELECT course_id FROM batch_courses WHERE batch_id IN ($placeholders)
           ))
        ORDER BY an.created_at DESC
        LIMIT 50
    ";
    
    $params = [$student_id, $student_id];
    foreach ($student_batches as $batch) $params[] = $batch;
    foreach ($student_batches as $batch) $params[] = $batch;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
