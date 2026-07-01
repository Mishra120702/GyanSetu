<?php
require 'db_connection.php';
try {
    $stmt = $db->query("SELECT * FROM admin_notifications");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
