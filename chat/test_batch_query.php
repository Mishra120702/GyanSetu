<?php
// test_batch_query.php
session_start();
require_once '../db_connection.php';

$batch_id = 'B2024005';

echo "<h2>Testing Batch Query for $batch_id</h2>";

// Test the exact query
$stmt = $db->prepare("
    SELECT 
        s.student_id,
        s.user_id,
        s.first_name,
        s.last_name,
        u.id as user_id_from_users,
        u.name as user_name,
        u.email
    FROM students s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.batch_name = ? OR s.batch_name_2 = ? OR s.batch_name_3 = ? OR s.batch_name_4 = ?
");

$stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Results:</h3>";
echo "<pre>";
print_r($results);
echo "</pre>";

if (empty($results)) {
    echo "<p style='color:red'>No results found!</p>";
} else {
    echo "<p>Found " . count($results) . " students</p>";
    
    foreach ($results as $row) {
        echo "<hr>";
        echo "Student: " . $row['first_name'] . " " . $row['last_name'] . "<br>";
        echo "Student user_id: " . ($row['user_id'] ?? 'NULL') . "<br>";
        echo "Users table id: " . ($row['user_id_from_users'] ?? 'NULL') . "<br>";
        echo "User name: " . ($row['user_name'] ?? 'NULL') . "<br>";
        
        if (empty($row['user_id_from_users'])) {
            echo "<p style='color:orange'>WARNING: This student has no linked user account!</p>";
        }
    }
}
?>