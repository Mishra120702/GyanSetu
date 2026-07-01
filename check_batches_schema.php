<?php
require_once 'db_connection.php';
$stmt = $db->query("DESCRIBE batches");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
