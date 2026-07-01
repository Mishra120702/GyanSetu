<?php
include '../db_connection.php';
session_start();

// Get filter parameters
$data = json_decode(file_get_contents('php://input'), true);
$startDate = $data['startDate'] ?? date('Y-m-d');
$endDate = $data['endDate'] ?? date('Y-m-d', strtotime('+30 days'));
$batchId = $data['batchId'] ?? '';

// Build query
$query = "
    SELECT s.*, b.course_name, b.batch_id, t.name as trainer_name
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

// Return response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'data' => $classes
]);