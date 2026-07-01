<?php
require_once '../db_connection.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'trainer')) {
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$batch_id = isset($_GET['batch_id']) ? trim($_GET['batch_id']) : '';

try {
    $courses = [];
    $students = [];

    if ($batch_id !== '') {
        // Fetch courses linked to this batch
        $stmt_courses = $db->prepare("
            SELECT c.id, c.name 
            FROM batch_courses bc 
            JOIN courses c ON bc.course_id = c.id 
            WHERE bc.batch_id = ? 
            ORDER BY c.name
        ");
        $stmt_courses->execute([$batch_id]);
        $courses = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

        // Fetch active students in this batch
        $stmt_students = $db->prepare("
            SELECT student_id, first_name, last_name, email 
            FROM students 
            WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?)
              AND current_status = 'active'
            ORDER BY first_name, last_name
        ");
        $stmt_students->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
        $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fetch all courses
        $stmt_courses = $db->query("SELECT id, name FROM courses ORDER BY name");
        $courses = $stmt_courses->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all active students
        $stmt_students = $db->query("
            SELECT student_id, first_name, last_name, email 
            FROM students 
            WHERE current_status = 'active'
            ORDER BY first_name, last_name
        ");
        $students = $stmt_students->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'success' => true,
        'courses' => $courses,
        'students' => $students
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
