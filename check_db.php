<?php
require_once 'db_connection.php';
$db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
$stmt = $db->query('SHOW TABLES');
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt2 = $db->query('DESCRIBE tests');
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
?>
