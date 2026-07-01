<?php
require 'db_connection.php';
$stmt = $db->query('DESCRIBE students');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
