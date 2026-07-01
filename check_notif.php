<?php
require_once 'db_connection.php';
$stmt = $db->query("SELECT * FROM admin_notifications ORDER BY id DESC LIMIT 2");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
