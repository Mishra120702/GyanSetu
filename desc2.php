<?php
require_once 'db_connection.php';
$stmt = $db->query("DESCRIBE leave_applications");
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($result);
?>
