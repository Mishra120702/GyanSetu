<?php
require_once 'db_connection.php';
$db->exec("ALTER TABLE admin_notifications MODIFY COLUMN target_type ENUM('all', 'batch', 'course', 'student', 'students', 'trainers', 'trainer') NOT NULL");
echo 'Success';
?>
