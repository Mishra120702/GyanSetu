<?php
require_once 'db_connection.php';
try {
    $db->exec("ALTER TABLE student_notification_reads MODIFY student_id VARCHAR(50) NOT NULL");
    echo "Schema updated successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
