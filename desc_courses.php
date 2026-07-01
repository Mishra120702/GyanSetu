<?php
require_once 'db_connection.php';
$stmt = $db->query("DESCRIBE courses");
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($result);
?>
