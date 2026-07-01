<?php
include '../db_connection.php';
session_start();

$recordId = $_POST['id'] ?? 0;

if (!$recordId) {
    echo json_encode(['success' => false, 'message' => 'Invalid record ID']);
    exit;
}

$query = "SELECT * FROM attendance WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$recordId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if ($record) {
    echo json_encode(['success' => true, 'record' => $record]);
} else {
    echo json_encode(['success' => false, 'message' => 'Record not found']);
}
?>