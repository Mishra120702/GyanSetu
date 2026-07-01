<?php
require_once 'db_connection.php';
try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    function desc($db, $table) {
        echo "TABLE: $table\n";
        $stmt = $db->query("DESCRIBE $table");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  " . $row['Field'] . " - " . $row['Type'] . "\n";
        }
        echo "\n";
    }
    
    desc($db, 'courses');
    desc($db, 'batch_courses');
    desc($db, 'uploads');
    desc($db, 'batch_uploads');
} catch (PDOException $e) {
    echo $e->getMessage();
}
