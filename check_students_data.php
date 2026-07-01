<?php
require_once 'db_connection.php';
$stmt = $db->query("SELECT student_id, first_name, batch_name, batch_name_2, batch_name_3, batch_name_4 FROM students LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
