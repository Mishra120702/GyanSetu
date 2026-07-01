<?php
require_once 'db_connection.php';
$stmt = $db->query("SHOW TABLES LIKE '%admin_notif%'");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
