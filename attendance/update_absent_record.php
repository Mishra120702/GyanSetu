<?php
// update_absent_record.php
include '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['id']) || !isset($data['status'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    try {
        $stmt = $db->prepare("UPDATE attendance SET status = :status, remarks = :remarks WHERE id = :id");
        $stmt->bindParam(':status', $data['status']);
        $stmt->bindParam(':remarks', $data['remarks']);
        $stmt->bindParam(':id', $data['id']);
        $stmt->execute();

        echo json_encode(['success' => true, 'message' => 'Record updated successfully']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>