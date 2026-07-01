<?php
require 'db_connection.php';
$stmt = $db->query("SHOW COLUMNS FROM trainers");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
