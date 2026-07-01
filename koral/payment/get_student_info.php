<?php
// get_student_info.php - Fetch student information via AJAX
include '../db_connection.php';
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = ['success' => false, 'error' => '', 'student' => null];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id'])) {
    try {
        $student_id = trim($_POST['student_id']);
        
        if (empty($student_id)) {
            throw new Exception("Student ID is required");
        }
        
        // Query to fetch student information with batch details
        $stmt = $db->prepare("
            SELECT 
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.phone_number,
                s.batch_name,
                b.batch_id,
                c.name as course_name
            FROM students s
            LEFT JOIN batches b ON s.batch_name = b.batch_name
            LEFT JOIN courses c ON s.course = c.id
            WHERE s.student_id = ? AND s.current_status = 'active'
        ");
        
        $stmt->execute([$student_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            $response['success'] = true;
            $response['student'] = [
                'student_id' => $student['student_id'],
                'first_name' => $student['first_name'],
                'last_name' => $student['last_name'],
                'email' => $student['email'],
                'phone_number' => $student['phone_number'],
                'batch_name' => $student['batch_name'],
                'batch_id' => $student['batch_id'],
                'course_name' => $student['course_name']
            ];
        } else {
            $response['error'] = "Student not found or inactive. Please check your Student ID.";
            
            // Try to find similar student IDs for suggestion
            $stmt = $db->prepare("
                SELECT student_id, first_name, last_name 
                FROM students 
                WHERE student_id LIKE ? OR email LIKE ? 
                LIMIT 3
            ");
            $searchTerm = "%" . $student_id . "%";
            $stmt->execute([$searchTerm, $searchTerm]);
            $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($suggestions)) {
                $response['suggestions'] = $suggestions;
                $response['error'] .= " Did you mean: " . implode(', ', array_column($suggestions, 'student_id')) . "?";
            }
        }
        
    } catch (Exception $e) {
        $response['error'] = "Database error: " . $e->getMessage();
    }
} else {
    $response['error'] = "Invalid request";
}

echo json_encode($response);
?>