<?php
require 'db_connection.php';
$stmt = $db->query("SELECT * FROM courses");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
