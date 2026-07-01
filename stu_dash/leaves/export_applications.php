<?php
session_start();
require_once '../../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    die('Unauthorized');
}

$student_id = $_SESSION['student_id'] ?? '';

$query = $db->prepare("
    SELECT application_no, batch_id, start_date, end_date, total_days, 
           reason_category, status, created_at
    FROM leave_applications 
    WHERE student_id = :student_id
    ORDER BY created_at DESC
");
$query->execute([':student_id' => $student_id]);
$applications = $query->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=my_leave_applications.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add headers
fputcsv($output, [
    'Application No',
    'Batch ID',
    'Start Date',
    'End Date',
    'Total Days',
    'Reason Category',
    'Status',
    'Applied On'
]);

// Add data
foreach ($applications as $app) {
    fputcsv($output, [
        $app['application_no'],
        $app['batch_id'],
        date('d-m-Y', strtotime($app['start_date'])),
        date('d-m-Y', strtotime($app['end_date'])),
        $app['total_days'],
        $app['reason_category'],
        ucfirst($app['status']),
        date('d-m-Y H:i', strtotime($app['created_at']))
    ]);
}

fclose($output);
exit();
?>