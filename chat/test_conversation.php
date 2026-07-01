<?php
// test_conversation.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$conversation_id) {
    echo json_encode(['error' => 'No conversation ID']);
    exit;
}

try {
    $result = [];
    
    // Check if user is member
    $stmt = $db->prepare("
        SELECT * FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversation_id, $user_id]);
    $result['is_member'] = $stmt->fetch() ? true : false;
    
    // Get conversation details
    $stmt = $db->prepare("SELECT * FROM conversations WHERE id = ?");
    $stmt->execute([$conversation_id]);
    $result['conversation'] = $stmt->fetch();
    
    // Count messages
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM messages WHERE conversation_id = ?");
    $stmt->execute([$conversation_id]);
    $result['message_count'] = $stmt->fetch()['count'];
    
    // Get other members
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.role 
        FROM users u
        JOIN conversation_members cm ON u.id = cm.user_id
        WHERE cm.conversation_id = ? AND cm.user_id != ?
    ");
    $stmt->execute([$conversation_id, $user_id]);
    $result['other_members'] = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'data' => $result]);
    
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>