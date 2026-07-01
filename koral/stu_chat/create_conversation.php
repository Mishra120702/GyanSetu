<?php
// student_chat/create_conversation.php
session_start();
require_once '../db_connection.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$user_id = $_SESSION['user_id'];
$admin_id = isset($_POST['admin_id']) ? intval($_POST['admin_id']) : 0;

if (!$admin_id) {
    echo json_encode(['success' => false, 'error' => 'Please select an admin']);
    exit;
}

try {
    // Check if conversation already exists in main conversations table
    $stmt = $db->prepare("
        SELECT c.id 
        FROM conversations c
        INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id
        INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id
        WHERE c.type = 'one_to_one'
        AND cm1.user_id = ? 
        AND cm2.user_id = ?
    ");
    $stmt->execute([$admin_id, $user_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        echo json_encode(['success' => true, 'conversation_id' => $existing['id']]);
        exit;
    }
    
    // Create new conversation in main conversations table
    $db->beginTransaction();
    
    $stmt = $db->prepare("INSERT INTO conversations (type, created_by, created_at, updated_at) VALUES ('one_to_one', ?, NOW(), NOW())");
    $stmt->execute([$admin_id]);
    $conversation_id = $db->lastInsertId();
    
    // Add members
    $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)");
    $stmt->execute([$conversation_id, $admin_id]);
    $stmt->execute([$conversation_id, $user_id]);
    
    $db->commit();
    
    echo json_encode(['success' => true, 'conversation_id' => $conversation_id]);
    
} catch (PDOException $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    error_log("Error creating conversation: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>