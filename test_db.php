<?php
require 'db_connection.php';
$stmt = $db->query('SELECT * FROM course_main_topics WHERE batch_id IS NOT NULL ORDER BY id DESC LIMIT 5');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
