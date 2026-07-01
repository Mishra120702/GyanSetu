<?php
require 'db_connection.php';
$stmt = $db->query('SELECT student_name, COUNT(*) as count FROM course_attendance GROUP BY student_name, date, course_id, batch_id HAVING count > 1');
print_r($stmt->fetchAll());
?>
