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

if (!$trainerId || !is_numeric($trainerId)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid trainer ID'
    ]);
    exit;
}

try {

    $db->beginTransaction();

    // Get trainer user id
    $stmt = $db->prepare("
        SELECT user_id
        FROM trainers
        WHERE id = ?
    ");

    $stmt->execute([$trainerId]);

    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        throw new Exception('Trainer not found');
    }

    $userId = $trainer['user_id'];

    // Delete trainer
    $stmt = $db->prepare("
        DELETE FROM trainers
        WHERE id = ?
    ");

    $stmt->execute([$trainerId]);

    // Delete user
    $stmt = $db->prepare("
        DELETE FROM users
        WHERE id = ?
    ");

    $stmt->execute([$userId]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Trainer deleted successfully'
    ]);

} catch (Exception $e) {

    $db->rollBack();

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>