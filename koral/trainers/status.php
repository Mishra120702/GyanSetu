<?php
require_once '../db_connection.php';

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trainerId = $_POST['id'] ?? null;
    $status = $_POST['status'] ?? null;
    
    if (!$trainerId || !is_numeric($trainerId) || !is_numeric($status)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        exit;
    }
    
    try {
        $stmt = $db->prepare("UPDATE trainers SET is_active = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('ii', $status, $trainerId);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            // Also update user status if needed (assuming user table has is_active field)
            $stmt = $db->prepare("UPDATE users u 
                                  JOIN trainers t ON u.id = t.user_id
                                  SET u.is_active = ?
                                  WHERE t.id = ?");
            $stmt->bind_param('ii', $status, $trainerId);
            $stmt->execute();
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No changes made or trainer not found']);
        }
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to update status: ' . $e->getMessage()]);
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}