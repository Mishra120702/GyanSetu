<?php
require_once '../db_connection.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$type = $_GET['type'] ?? '';

try {
    $data = [];
    
    if ($type === 'batch') {
        $stmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name ASC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    else if ($type === 'course') {
        $stmt = $db->query("SELECT id, name FROM courses ORDER BY name ASC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    else if ($type === 'trainer') {
        $stmt = $db->query("SELECT id, name FROM trainers WHERE is_active = 1 ORDER BY name ASC");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    else if ($type === 'student') {
        // Fetch active students and their primary batch
        $stmt = $db->query("
            SELECT student_id, first_name, last_name, batch_name, batch_name_2, batch_name_3, batch_name_4 
            FROM students 
            WHERE current_status = 'active' 
            ORDER BY first_name ASC, last_name ASC
        ");
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    else {
        throw new Exception("Invalid target type");
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
