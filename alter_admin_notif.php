<?php
require_once 'db_connection.php';

try {
    $db->exec("ALTER TABLE admin_notifications MODIFY target_id TEXT NULL");
    echo "Modified target_id successfully.\n";
    
    // check if image_path exists before adding
    $stmt = $db->query("SHOW COLUMNS FROM admin_notifications LIKE 'image_path'");
    if ($stmt->rowCount() == 0) {
        $db->exec("ALTER TABLE admin_notifications ADD image_path VARCHAR(255) NULL AFTER target_id");
        echo "Added image_path successfully.\n";
    } else {
        echo "image_path already exists.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
