<?php
require 'db_connection.php';
try {
    $db->exec("ALTER TABLE tickets MODIFY COLUMN status ENUM('open', 'in_progress', 'resolved') DEFAULT 'open'");
    echo 'Done';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
