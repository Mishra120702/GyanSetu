<?php
include '../db_connection.php';
session_start();

// Get filter parameters
$startDate = $_POST['startDate'] ?? '';
$endDate = $_POST['endDate'] ?? '';
$batchId = $_POST['batchId'] ?? '';
$status = $_POST['status'] ?? 'Absent';
$page = $_POST['page'] ?? 1;
$perPage = $_POST['perPage'] ?? 10;
$offset = ($page - 1) * $perPage;

// Build WHERE clause
$whereClause = "WHERE 1=1";
$params = [];

if ($startDate && $endDate) {
    $whereClause .= " AND date BETWEEN ? AND ?";
    $params[] = $startDate;
    $params[] = $endDate;
}

if ($batchId) {
    $whereClause .= " AND batch_id = ?";
    $params[] = $batchId;
}

if ($status !== 'All') {
    $whereClause .= " AND status = ?";
    $params[] = $status;
}

// Get total count
$countQuery = "SELECT COUNT(*) as total FROM attendance $whereClause";
$countStmt = $db->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = $countStmt->fetch()['total'];

// Get data with pagination
$query = "SELECT * FROM attendance $whereClause ORDER BY date DESC, batch_id, student_name LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'data' => $results,
    'total' => $totalRecords,
    'page' => $page,
    'perPage' => $perPage
]);
?>