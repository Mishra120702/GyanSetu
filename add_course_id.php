<?php
require 'db_connection.php';
try {
    $db->query("ALTER TABLE exams ADD COLUMN course_id VARCHAR(50) NULL AFTER batch_id");
    echo "Added course_id column successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column course_id already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
