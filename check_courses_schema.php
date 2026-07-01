<?php
require_once 'db_connection.php';
$stmt = $db->query("SHOW TABLES");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
$stmt = $db->query("DESCRIBE courses");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
