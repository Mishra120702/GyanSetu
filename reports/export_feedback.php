<?php
require_once '../db_connection.php';

// Get the same filters as the main page
$batch_id = $_GET['batch_id'] ?? '';
$course = $_GET['course'] ?? '';
$rating = $_GET['rating'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$action_taken = $_GET['action_taken'] ?? '';

// Build the same query as the main page
$query = "SELECT f.*, s.first_name, s.last_name, b.course_name as batch_course
          FROM feedback f 
          LEFT JOIN students s ON CONCAT(s.first_name, ' ', s.last_name) = f.student_name 
          LEFT JOIN batches b ON f.batch_id = b.batch_id 
          WHERE 1=1";

$params = [];

// Apply the same filters
if (!empty($batch_id)) {
    $query .= " AND f.batch_id = ?";
    $params[] = $batch_id;
}

if (!empty($course)) {
    $query .= " AND f.course_name = ?";
    $params[] = $course;
}

if (!empty($rating) && $rating !== 'all') {
    $query .= " AND f.rating = ?";
    $params[] = $rating;
}

if (!empty($start_date)) {
    $query .= " AND f.date >= ?";
    $params[] = $start_date;
}

if (!empty($end_date)) {
    $query .= " AND f.date <= ?";
    $params[] = $end_date;
}

if (!empty($action_taken) && $action_taken !== 'all') {
    if ($action_taken === 'yes') {
        $query .= " AND f.action_taken IS NOT NULL AND f.action_taken != ''";
    } else {
        $query .= " AND (f.action_taken IS NULL OR f.action_taken = '')";
    }
}

$query .= " ORDER BY f.date DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=feedback_report_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, [
    'Date', 
    'Student Name', 
    'Batch ID', 
    'Course', 
    'Rating', 
    'Class Rating',
    'Assignment Understanding',
    'Practical Understanding',
    'Satisfaction',
    'Regular Attendance',
    'Feedback Text',
    'Suggestions',
    'Action Taken'
]);

// Add data rows
foreach ($feedbacks as $feedback) {
    fputcsv($output, [
        $feedback['date'],
        $feedback['student_name'],
        $feedback['batch_id'],
        $feedback['course_name'],
        $feedback['rating'] ?? 'N/A',
        $feedback['class_rating'] ?? 'N/A',
        $feedback['assignment_understanding'] ?? 'N/A',
        $feedback['practical_understanding'] ?? 'N/A',
        $feedback['satisfied'] ?? 'N/A',
        $feedback['is_regular'] ?? 'N/A',
        $feedback['feedback_text'] ?? '',
        $feedback['suggestions'] ?? '',
        $feedback['action_taken'] ?? ''
    ]);
}

fclose($output);
exit();
?>