<?php
// debug_batch_issue.php
session_start();
require_once '../db_connection.php';

echo "<h2>Batch Debug Information</h2>";

// Get the batch we're trying to use
$batch_id = 'B2024005';
$batch_name = 'Cybersecurity Batch';

echo "<h3>1. Checking Batch: $batch_name ($batch_id)</h3>";

// Check batches table
$stmt = $db->prepare("SELECT * FROM batches WHERE batch_id = ?");
$stmt->execute([$batch_id]);
$batch = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p>Batch in batches table: " . ($batch ? "FOUND" : "NOT FOUND") . "</p>";
if ($batch) {
    echo "<pre>";
    print_r($batch);
    echo "</pre>";
}

// Check students table structure
echo "<h3>2. Students Table Structure:</h3>";
$columns = $db->query("SHOW COLUMNS FROM students")->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
foreach ($columns as $column) {
    echo "<tr>";
    echo "<td>" . $column['Field'] . "</td>";
    echo "<td>" . $column['Type'] . "</td>";
    echo "<td>" . $column['Null'] . "</td>";
    echo "<td>" . $column['Key'] . "</td>";
    echo "<td>" . ($column['Default'] ?? 'NULL') . "</td>";
    echo "</tr>";
}
echo "</table>";

// Check what batch columns actually contain
echo "<h3>3. Sample of batch column values:</h3>";

$batch_columns = ['batch_name', 'batch_name_2', 'batch_name_3', 'batch_name_4'];

foreach ($batch_columns as $column) {
    $stmt = $db->query("
        SELECT DISTINCT $column 
        FROM students 
        WHERE $column IS NOT NULL AND $column != '' 
        LIMIT 10
    ");
    $values = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<p><strong>$column:</strong> " . implode(', ', $values) . "</p>";
}

// Check if any students have B2024005 in any batch column
echo "<h3>4. Students with batch ID '$batch_id':</h3>";

$stmt = $db->prepare("
    SELECT s.student_id, s.first_name, s.last_name, 
           s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4,
           u.id as user_id, u.name as user_name
    FROM students s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.batch_name = ? 
       OR s.batch_name_2 = ? 
       OR s.batch_name_3 = ? 
       OR s.batch_name_4 = ?
");

$stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found: " . count($students) . " students</p>";

if (count($students) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Student ID</th><th>Name</th><th>Batch 1</th><th>Batch 2</th><th>Batch 3</th><th>Batch 4</th></tr>";
    foreach ($students as $student) {
        echo "<tr>";
        echo "<td>" . $student['student_id'] . "</td>";
        echo "<td>" . ($student['user_name'] ?? $student['first_name'] . ' ' . $student['last_name']) . "</td>";
        echo "<td>" . ($student['batch_name'] ?: '-') . "</td>";
        echo "<td>" . ($student['batch_name_2'] ?: '-') . "</td>";
        echo "<td>" . ($student['batch_name_3'] ?: '-') . "</td>";
        echo "<td>" . ($student['batch_name_4'] ?: '-') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>No students found with batch ID $batch_id</p>";
}

// Show all students
echo "<h3>5. All students (first 10):</h3>";
$all = $db->query("
    SELECT student_id, first_name, last_name, 
           batch_name, batch_name_2, batch_name_3, batch_name_4 
    FROM students 
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Student ID</th><th>Name</th><th>Batch 1</th><th>Batch 2</th><th>Batch 3</th><th>Batch 4</th></tr>";
foreach ($all as $student) {
    echo "<tr>";
    echo "<td>" . $student['student_id'] . "</td>";
    echo "<td>" . $student['first_name'] . " " . $student['last_name'] . "</td>";
    echo "<td>" . ($student['batch_name'] ?: '-') . "</td>";
    echo "<td>" . ($student['batch_name_2'] ?: '-') . "</td>";
    echo "<td>" . ($student['batch_name_3'] ?: '-') . "</td>";
    echo "<td>" . ($student['batch_name_4'] ?: '-') . "</td>";
    echo "</tr>";
}
echo "</table>";
?>