<?php
require_once __DIR__ . '/../db_connection.php';
$stmt = $db->query("DESCRIBE tests");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
