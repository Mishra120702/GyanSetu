<?php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
// Set headers for JSON response
header('Content-Type: application/json');

try {
    // Get POST data
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Extract parameters with defaults
    $startDate = $data['startDate'] ?? null;
    $endDate = $data['endDate'] ?? null;
    $batchId = $data['batchId'] ?? null;
    $page = $data['page'] ?? 1;
    $perPage = $data['perPage'] ?? 10;
    $offset = ($page - 1) * $perPage;
    
    // Build base query
    $query = "SELECT 
                a.id, 
                DATE_FORMAT(a.date, '%Y-%m-%d') as date, 
                a.batch_id, 
                a.student_name, 
                a.remarks,
                b.course_name
              FROM attendance a
              LEFT JOIN batches b ON a.batch_id = b.batch_id
              WHERE a.status = 'Absent'";
    
    $countQuery = "SELECT COUNT(*) as total FROM attendance WHERE status = 'Absent'";
    
    // Add filters
    $params = [];
    $countParams = [];
    
    if ($startDate && $endDate) {
        $query .= " AND a.date BETWEEN :startDate AND :endDate";
        $countQuery .= " AND date BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $startDate;
        $params[':endDate'] = $endDate;
        $countParams[':startDate'] = $startDate;
        $countParams[':endDate'] = $endDate;
    }
    
    if ($batchId) {
        $query .= " AND a.batch_id = :batchId";
        $countQuery .= " AND batch_id = :batchId";
        $params[':batchId'] = $batchId;
        $countParams[':batchId'] = $batchId;
    }
    
    // Add sorting and pagination
    $query .= " ORDER BY a.date DESC, a.student_name ASC LIMIT :offset, :perPage";
    
    // Prepare and execute count query
    $stmt = $db->prepare($countQuery);
    foreach ($countParams as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total = $stmt->fetchColumn();
    
    // Prepare and execute main query
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->bindValue(':perPage', (int)$perPage, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'data' => $results,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}