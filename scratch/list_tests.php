<?php
require_once __DIR__ . '/../db_connection.php';
$stmt = $db->query("SELECT t.id, t.title, t.course_id, c.name as course_name FROM tests t LEFT JOIN courses c ON t.course_id = c.id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
