<?php
require 'db_connection.php';

// Deduplicate course_attendance
$db->query("
    DELETE t1 FROM course_attendance t1
    INNER JOIN course_attendance t2 
    WHERE 
        t1.id > t2.id AND 
        t1.student_id = t2.student_id AND 
        t1.date = t2.date AND 
        t1.course_id = t2.course_id AND 
        t1.batch_id = t2.batch_id
");

// Add unique constraint if not exists
try {
    $db->query("ALTER TABLE course_attendance ADD UNIQUE KEY unique_attendance (student_id, date, course_id, batch_id)");
    echo "Deduplicated and unique key added.";
} catch (PDOException $e) {
    echo "Error adding unique key (maybe it exists?): " . $e->getMessage();
}
?>
