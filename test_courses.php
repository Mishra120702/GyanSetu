<?php
require 'db_connection.php';
$stmt = $db->prepare('SELECT * FROM batch_courses WHERE batch_id=?');
$stmt->execute(['B003']);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
