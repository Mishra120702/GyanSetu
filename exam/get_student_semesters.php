<?php
require_once '../db_connection.php';
session_start();

// Check user role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

$student_id = isset($_GET['student_id']) ? trim($_GET['student_id']) : '';

$response = [];

if (!empty($student_id)) {
    try {
        // First, get student's batch_id (not batch_name)
        $sql = "SELECT batch_id FROM students WHERE student_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student && !empty($student['batch_id'])) {
            // Get semesters using batch_id
            $sql = "SELECT DISTINCT s.id, s.name, s.academic_year 
                    FROM semesters s 
                    JOIN semester_batches sb ON s.id = sb.semester_id 
                    WHERE sb.batch_id = ? 
                    AND s.status IN ('ongoing', 'completed')
                    ORDER BY s.start_date DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([$student['batch_id']]);
            $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no semesters found with batch_id, try using batch_name as fallback
            if (empty($semesters)) {
                // Get batch_name as fallback
                $sql = "SELECT batch_name FROM students WHERE student_id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$student_id]);
                $student = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($student && !empty($student['batch_name'])) {
                    $sql = "SELECT DISTINCT s.id, s.name, s.academic_year 
                            FROM semesters s 
                            JOIN semester_batches sb ON s.id = sb.semester_id 
                            WHERE sb.batch_id = ? 
                            AND s.status IN ('ongoing', 'completed')
                            ORDER BY s.start_date DESC";
                    
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$student['batch_name']]);
                    $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            
            $response = $semesters;
        } else {
            // If batch_id not found, try with batch_name directly
            $sql = "SELECT batch_name FROM students WHERE student_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$student_id]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student && !empty($student['batch_name'])) {
                $sql = "SELECT DISTINCT s.id, s.name, s.academic_year 
                        FROM semesters s 
                        JOIN semester_batches sb ON s.id = sb.semester_id 
                        WHERE sb.batch_id = ? 
                        AND s.status IN ('ongoing', 'completed')
                        ORDER BY s.start_date DESC";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([$student['batch_name']]);
                $semesters = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $response = $semesters;
            }
        }
    } catch (PDOException $e) {
        error_log("Database error in get_student_semesters.php: " . $e->getMessage());
        // Return empty array on error
        $response = [];
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>