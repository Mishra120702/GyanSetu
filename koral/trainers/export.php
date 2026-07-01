<?php
require_once '../db_connection.php';
require_once 'filters.php';
require_once 'functions.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
// Get filters from request
$filters = getTrainerFilters($_GET);

// Get all trainers with filters (no pagination)
$trainers = getFilteredTrainers($filters, null, 0);

// Set headers based on export type
$exportType = $_GET['export'] ?? 'csv';
$filename = 'trainers_' . date('Ymd_His');

switch ($exportType) {
    case 'excel':
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="' . $filename . '.xls"');
        break;
    case 'pdf':
        // This would require a PDF library like TCPDF or mPDF
        // For simplicity, we'll just output as CSV
        // In a real implementation, you would generate a PDF here
    case 'csv':
    default:
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment;filename="' . $filename . '.csv"');
        break;
}

// Output the data
$output = fopen('php://output', 'w');

// Header row
fputcsv($output, [
    'ID',
    'Name',
    'Email',
    'Specialization',
    'Experience (Years)',
    'Active Batches',
    'Average Rating',
    'Status',
    'Created At'
]);

// Data rows
foreach ($trainers as $trainer) {
    $batchCount = getTrainerBatchCount($trainer['id']);
    $avgRating = getTrainerAverageRating($trainer['id']);
    
    fputcsv($output, [
        $trainer['id'],
        $trainer['name'],
        $trainer['email'],
        $trainer['specialization'] ?? 'N/A',
        $trainer['years_of_experience'],
        $batchCount,
        $avgRating ? round($avgRating, 1) : 'N/A',
        $trainer['is_active'] ? 'Active' : 'Inactive',
        $trainer['created_at']
    ]);
}

fclose($output);
exit;