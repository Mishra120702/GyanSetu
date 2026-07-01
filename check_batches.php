<?php
require_once 'db_connection.php';
$db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
$stmt = $db->query("DESCRIBE batches");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
