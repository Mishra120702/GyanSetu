<?php
require 'db_connection.php';
$stmt = $db->query("SELECT * FROM batch_courses");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
