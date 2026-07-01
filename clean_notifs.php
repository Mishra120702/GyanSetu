<?php
require 'db_connection.php';
try {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $db->exec("TRUNCATE TABLE student_notification_reads");
    $db->exec("TRUNCATE TABLE admin_notifications");
    $db->exec("TRUNCATE TABLE notifications");
    $db->exec("TRUNCATE TABLE topic_verifications");
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "Successfully deleted all old notifications and verifications!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
