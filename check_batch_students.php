<?php
require_once 'db_connection.php';
$stmt = $db->query("SHOW TABLES LIKE 'batch_students'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $db->query("SHOW TABLES LIKE 'student_courses'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
