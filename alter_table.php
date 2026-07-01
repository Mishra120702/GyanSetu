<?php
require_once 'db_connection.php';
try {
    $db->exec('ALTER TABLE main_topics ADD COLUMN course_id INT(11) NULL AFTER batch_name;');
    echo "Column course_id added to main_topics.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
