<?php
// get_conversation_details.php
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
    echo json_encode(['success' => false, 'error' => 'No conversation ID']);
    exit;
}

try {
    // Get conversation details
    $stmt = $db->prepare("
        SELECT c.*, 
               CASE 
                   WHEN c.type = 'one_to_one' THEN 
                       (SELECT u.name FROM users u 
                        JOIN conversation_members cm ON u.id = cm.user_id 
                        WHERE cm.conversation_id = c.id AND cm.user_id != ?)
                   ELSE c.name
               END as display_name
        FROM conversations c
        WHERE c.id = ?
    ");
    $stmt->execute([$user_id, $conversation_id]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'error' => 'Conversation not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'name' => $conversation['display_name'] ?? 'Conversation',
        'type' => $conversation['type'],
        'status' => 'Online'
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching conversation details: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>