<?php
require_once 'db_connection.php';
$tables = ['courses', 'main_topics', 'sub_topics'];
foreach ($tables as $t) {
    echo "--- $t ---\n";
    $stmt = $db->query("DESCRIBE $t");
    if ($stmt) {
        print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo "Table not found\n";
    }
}
?>
