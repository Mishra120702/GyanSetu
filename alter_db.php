<?php
require 'db_connection.php';
try {
    $db->exec("ALTER TABLE batch_uploads ADD COLUMN course_id INT NULL");
    $db->exec("ALTER TABLE batch_uploads ADD FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE");
    echo "Success";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
