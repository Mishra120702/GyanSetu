<?php
require 'db_connection.php';
$stmt = $db->query("SELECT * FROM batch_courses");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$seen = [];
$dups = [];
foreach($rows as $r) {
    $key = $r['batch_id'] . '-' . $r['course_id'];
    if (isset($seen[$key])) {
        $dups[] = $r['id'];
    } else {
        $seen[$key] = true;
    }
}
if(!empty($dups)) {
    $ids = implode(',', $dups);
    $db->exec("DELETE FROM batch_courses WHERE id IN ($ids)");
    echo "Deleted duplicates: $ids";
} else {
    echo "No duplicates found.";
}
?>
