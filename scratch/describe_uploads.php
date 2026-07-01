<?php
require_once __DIR__ . '/../db_connection.php';
echo "--- UPLOAD_STUDENTS TABLE ---\n";
$q = $db->query("DESCRIBE upload_students");
while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
