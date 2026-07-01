<?php
require_once "db_connection.php";

$stmt = $db->query("SELECT * FROM batch_courses LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt2 = $db->query("SELECT DISTINCT bc.trainer_id FROM batch_courses bc WHERE bc.trainer_id IS NOT NULL");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
?>
