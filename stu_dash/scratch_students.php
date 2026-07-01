<?php
require_once 'c:\\xampp\\htdocs\\_public_html (4)\\db_connection.php';
$stmt = $db->query('SELECT student_id, first_name, user_id, batch_name, batch_name_2, batch_name_3, batch_name_4 FROM students');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
