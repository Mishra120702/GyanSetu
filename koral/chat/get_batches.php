<?php
// get_batches.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

    $stmt = $db->query("
        SELECT DISTINCT batch_id, batch_name 
        FROM batches 
        ORDER BY batch_name
    ");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'batches' => $batches]);
    
} catch (PDOException $e) {
    error_log("Error fetching batches: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>