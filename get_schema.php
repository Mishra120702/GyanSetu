<?php
require_once "db_connection.php";

$tables = ['trainers', 'trainer_courses', 'courses', 'batch_courses'];
foreach ($tables as $table) {
    echo "TABLE $table:\n";
    try {
        $stmt = $db->query("DESCRIBE $table");
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
