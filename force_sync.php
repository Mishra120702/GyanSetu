<?php
require_once 'db_connection.php';
require_once 'batch/sync_curriculum.php';

try {
    $stmt = $db->query("SELECT batch_id, course_id FROM batch_courses");
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = 0;
    foreach ($assignments as $a) {
        sync_course_curriculum_to_batch($db, $a['batch_id'], $a['course_id']);
        $count++;
    }
    
    echo "Successfully synced curriculum for $count batch-course assignments.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
