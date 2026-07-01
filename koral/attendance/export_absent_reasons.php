<?php
// export_absent_reasons.php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

try {
    // Get POST data
    $startDate = $_POST['startDate'] ?? null;
    $endDate = $_POST['endDate'] ?? null;
    $batchId = $_POST['batchId'] ?? null;
    
    // Build base query
    $query = "SELECT 
                DATE_FORMAT(a.date, '%Y-%m-%d') as date, 
                a.batch_id, 
                CONCAT(s.first_name, ' ', s.last_name) as student_name, 
                a.status,
                a.remarks,
                b.course_name
              FROM attendance a
              LEFT JOIN batches b ON a.batch_id = b.batch_id
              LEFT JOIN students s ON a.student_id = s.student_id
              WHERE a.status = 'Absent'";
    
    // Add filters
    $params = [];
    
    if ($startDate && $endDate) {
        $query .= " AND a.date BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $startDate;
        $params[':endDate'] = $endDate;
    }
    
    if ($batchId) {
        $query .= " AND a.batch_id = :batchId";
        $params[':batchId'] = $batchId;
    }
    
    // Add sorting
    $query .= " ORDER BY a.date DESC, student_name ASC";
    
    // Prepare and execute query
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="absent_students_' . date('Y-m-d') . '.csv"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, ['Date', 'Batch ID', 'Student Name', 'Status', 'Remarks', 'Course']);
    
    // Add data rows
    foreach ($results as $row) {
        fputcsv($output, [
            $row['date'],
            $row['batch_id'],
            $row['student_name'],
            $row['status'],
            $row['remarks'] ?? '-',
            $row['course_name']
        ]);
    }
    
    fclose($output);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>