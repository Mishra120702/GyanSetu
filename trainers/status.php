<?php
require_once '../db_connection.php';

session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
    exit;
}

$trainerId = $_POST['id'] ?? null;
$status = $_POST['status'] ?? null;

if (!$trainerId || !is_numeric($trainerId) || !is_numeric($status)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid parameters'
    ]);
    exit;
}

try {

    $stmt = $db->prepare("
        UPDATE trainers
        SET is_active = ?, updated_at = NOW()
        WHERE id = ?
    ");

    $result = $stmt->execute([
        (int)$status,
        (int)$trainerId
    ]);

    if (!$result) {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update trainer status'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => ((int)$status === 1)
            ? 'Trainer activated successfully'
            : 'Trainer deactivated successfully'
    ]);

} catch (Exception $e) {

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>