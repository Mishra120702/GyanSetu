<?php
require 'db_connection.php';
$batchIds = ['B003', 'B002', 'B001'];
$placeholders = str_repeat('?,', count($batchIds) - 1) . '?';
$coursesQuery = "SELECT bc.batch_id, c.name as course_name 
                 FROM batch_courses bc 
                 JOIN courses c ON bc.course_id = c.id 
                 WHERE bc.batch_id IN ($placeholders) 
                 ORDER BY bc.id ASC";
$coursesStmt = $db->prepare($coursesQuery);
$coursesStmt->execute($batchIds);
$coursesResult = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

print_r($coursesResult);
?>
