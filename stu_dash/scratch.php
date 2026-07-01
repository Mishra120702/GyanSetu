<?php
require_once 'c:\\xampp\\htdocs\\_public_html (4)\\db_connection.php';
$stmt = $db->query('SELECT id, name FROM courses');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt2 = $db->query('SELECT t.course_name, t.topic_name FROM batch_topics t LIMIT 5');
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
