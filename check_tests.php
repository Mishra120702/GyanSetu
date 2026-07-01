<?php require 'db_connection.php'; $stmt = $db->query('DESCRIBE tests'); print_r($stmt->fetchAll(PDO::FETCH_ASSOC)); ?>
