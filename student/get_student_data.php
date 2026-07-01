<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
header('Content-Type: application/json');

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $studentId = $_GET['id'] ?? null;
    if (!$studentId) {
        throw new Exception('Student ID not provided');
    }

    // Get student basic info
    $stmt = $conn->prepare("
        SELECT s.*, u.profile_picture 
        FROM students s
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE s.student_id = :student_id
    ");
    $stmt->bindParam(':student_id', $studentId);
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception('Student not found');
    }

    // Get current batch info
    $stmt = $conn->prepare("
        SELECT b.* FROM batches b
        JOIN students s ON b.batch_id = s.batch_name
        WHERE s.student_id = :student_id
    ");
    $stmt->bindParam(':student_id', $studentId);
    $stmt->execute();
    $current_batch = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get batch history
    $stmt = $conn->prepare("
        SELECT b.* FROM batches b
        JOIN student_batch_history sbh ON b.batch_id = sbh.to_batch_id
        WHERE sbh.student_id = :student_id
        ORDER BY sbh.transfer_date DESC
    ");
    $stmt->bindParam(':student_id', $studentId);
    $stmt->execute();
    $batch_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get attendance percentage
    $stmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'Present' THEN 1 END) as present_count,
            COUNT(*) as total_count
        FROM attendance 
        WHERE student_name = CONCAT(:first_name, ' ', :last_name)
        AND batch_id = :batch_id
    ");
    $stmt->bindParam(':first_name', $student['first_name']);
    $stmt->bindParam(':last_name', $student['last_name']);
    $stmt->bindParam(':batch_id', $current_batch['batch_id'] ?? '');
    $stmt->execute();
    $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
    $attendance_percent = $attendance['total_count'] > 0 
        ? round(($attendance['present_count'] / $attendance['total_count']) * 100) 
        : 0;

    // Get average exam score
    $stmt = $conn->prepare("
        SELECT AVG(score) as avg_score 
        FROM exam_students 
        WHERE student_name = CONCAT(:first_name, ' ', :last_name)
    ");
    $stmt->bindParam(':first_name', $student['first_name']);
    $stmt->bindParam(':last_name', $student['last_name']);
    $stmt->execute();
    $score = $stmt->fetch(PDO::FETCH_ASSOC);
    $avg_score = $score['avg_score'] ? (float)$score['avg_score'] : null;

    // Count completed and active batches
    $stmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_batches,
            COUNT(CASE WHEN status = 'ongoing' THEN 1 END) as active_batches
        FROM student_batch_history sbh
        JOIN batches b ON sbh.to_batch_id = b.batch_id
        WHERE sbh.student_id = :student_id
    ");
    $stmt->bindParam(':student_id', $studentId);
    $stmt->execute();
    $batch_counts = $stmt->fetch(PDO::FETCH_ASSOC);

     echo json_encode([
        'student' => $student,
        'current_batch' => $current_batch,
        'batch_history' => $batch_history,
        'attendance_percent' => $attendance_percent,
        'avg_score' => $avg_score,
        'completed_batches' => $batch_counts['completed_batches'] ?? 0,
        'active_batches' => $batch_counts['active_batches'] ?? 0
    ]);

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}