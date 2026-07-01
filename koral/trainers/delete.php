<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get JSON input data
    $data = json_decode(file_get_contents('php://input'), true);
    $trainerId = $data['id'] ?? null;
    
    // Validate trainer ID
    if (!$trainerId || !is_numeric($trainerId)) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid trainer ID']);
        exit;
    }
    
    // Start transaction to ensure data consistency
    $db->begin_transaction();
    
    try {
        // First get user_id for this trainer
        $stmt = $db->prepare("SELECT user_id FROM trainers WHERE id = ?");
        $stmt->bind_param('i', $trainerId);
        $stmt->execute();
        $result = $stmt->get_result();
        $trainer = $result->fetch_assoc();
        
        if (!$trainer) {
            throw new Exception('Trainer not found');
        }
        
        $userId = $trainer['user_id'];
        
        // Delete trainer record
        $stmt = $db->prepare("DELETE FROM trainers WHERE id = ?");
        $stmt->bind_param('i', $trainerId);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to delete trainer record');
        }
        
        // Delete user record
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param('i', $userId);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to delete user record');
        }
        
        // Commit transaction if both operations succeeded
        $db->commit();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Trainer deleted successfully']);
        
    } catch (Exception $e) {
        // Rollback transaction if any operation failed
        $db->rollback();
        
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to delete trainer: ' . $e->getMessage()]);
    }
} else {
    // Invalid request method
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}