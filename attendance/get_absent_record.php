<?php
include '../db_connection.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;

    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Attendance ID is required']);
        exit;
    }

    try {
        // Updated query to match the attendance table structure
        $stmt = $db->prepare("SELECT * FROM attendance WHERE id = ?");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            echo json_encode([
                'success' => true, 
                'record' => [
                    'id' => $record['id'],
                    'date' => $record['date'],
                    'batch_id' => $record['batch_id'],
                    'student_name' => $record['student_name'],
                    'status' => $record['status'],
                    'camera_status' => $record['camera_status'],
                    'remarks' => $record['remarks']
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Attendance record not found']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>