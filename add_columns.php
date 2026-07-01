<?php require 'db_connection.php'; $db->query('ALTER TABLE tests ADD COLUMN test_category VARCHAR(50) DEFAULT NULL, ADD COLUMN chapter_id INT DEFAULT NULL'); echo 'done'; ?>
