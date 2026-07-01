<?php
require_once 'db_connection.php';
$stmt = $db->query("DESCRIBE admin_notifications");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
