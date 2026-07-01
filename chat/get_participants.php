<?php
// get_participants.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation']);
    exit;
}

try {
    // Check if user is member
    $stmt = $db->prepare("
        SELECT id FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversation_id, $user_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }
    
    // Get participants
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.role,
               CASE WHEN u.id = ? THEN 1 ELSE 0 END as is_you,
               s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4
        FROM conversation_members cm
        JOIN users u ON cm.user_id = u.id
        LEFT JOIN students s ON u.id = s.user_id
        WHERE cm.conversation_id = ?
        ORDER BY is_you DESC, u.name
    ");
    $stmt->execute([$user_id, $conversation_id]);
    $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get conversation info
    $stmt = $db->prepare("
        SELECT c.*, 
               CASE WHEN c.batch_id IS NOT NULL THEN 
                   (SELECT batch_name FROM batches WHERE batch_id = c.batch_id)
               ELSE NULL END as batch_name
        FROM conversations c
        WHERE c.id = ?
    ");
    $stmt->execute([$conversation_id]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'participants' => $participants,
        'conversation' => $conversation
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching participants: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>