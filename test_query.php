<?php
require_once "db_connection.php";
$stmt = $db->prepare("SELECT DISTINCT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id JOIN batches b ON bc.batch_id = b.batch_id WHERE b.batch_mentor_id = 4");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
