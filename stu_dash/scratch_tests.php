<?php
require_once 'c:\\xampp\\htdocs\\_public_html (4)\\db_connection.php';
$stmt = $db->query('SELECT id, title, course_id, batch_id, is_active FROM tests');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
