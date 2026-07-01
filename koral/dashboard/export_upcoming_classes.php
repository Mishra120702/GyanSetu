<?php
include '../db_connection.php';
session_start();

// Get filter parameters
$startDate = $_POST['startDate'] ?? date('Y-m-d');
$endDate = $_POST['endDate'] ?? date('Y-m-d', strtotime('+30 days'));
$batchId = $_POST['batchId'] ?? '';

// Build query
$query = "
    SELECT 
        s.schedule_date as 'Date',
        CONCAT(TIME_FORMAT(s.start_time, '%h:%i %p'), ' - ', TIME_FORMAT(s.end_time, '%h:%i %p')) as 'Time',
        b.batch_id as 'Batch ID',
        b.course_name as 'Course',
        s.topic as 'Topic',
        IFNULL(t.name, 'Not assigned') as 'Trainer'
    FROM schedule s
    JOIN batches b ON s.batch_id = b.batch_id
    LEFT JOIN trainers t ON b.batch_mentor_id = t.id
    WHERE s.schedule_date BETWEEN :startDate AND :endDate
    AND s.is_cancelled = 0
";

$params = [
    'startDate' => $startDate,
    'endDate' => $endDate
];

if (!empty($batchId)) {
    $query .= " AND s.batch_id = :batchId";
    $params['batchId'] = $batchId;
}

$query .= " ORDER BY s.schedule_date ASC, s.start_time ASC";

// Execute query
$stmt = $db->prepare($query);
$stmt->execute($params);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="upcoming_classes_' . date('Y-m-d') . '.csv"');

// Open output stream
$output = fopen('php://output', 'w');

// Write CSV headers
if (!empty($classes)) {
    fputcsv($output, array_keys($classes[0]));
    
    // Write data rows
    foreach ($classes as $class) {
        fputcsv($output, $class);
    }
} else {
    fputcsv($output, ['No classes found matching your criteria']);
}

fclose($output);