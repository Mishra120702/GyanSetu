<?php
require 'db_connection.php';
try {
    $stmt = $db->query("SHOW COLUMNS FROM schedule");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
