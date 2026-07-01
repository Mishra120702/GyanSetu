<?php
// student_chat/create_conversation.php
session_start();
require_once '../db_connection.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = 'Invalid security token';
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$admin_id = isset($_POST['admin_id']) ? intval($_POST['admin_id']) : 0;

if (!$admin_id) {
    $_SESSION['error'] = 'Please select an admin';
    header("Location: index.php");
    exit;
}

try {
    // Get student_id
    $stmt = $db->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $_SESSION['error'] = 'Student record not found';
        header("Location: index.php");
        exit;
    }
    
    // Check if conversation already exists
    $stmt = $db->prepare("
        SELECT id FROM chat_conversations 
        WHERE conversation_type = 'admin_student' 
        AND admin_id = ? 
        AND student_id = ?
    ");
    $stmt->execute([$admin_id, $student['student_id']]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $_SESSION['active_conversation_id'] = $existing['id'];
        $_SESSION['success'] = 'Conversation already exists';
        header("Location: index.php?conversation_id=" . $existing['id']);
        exit;
    }
    
    // Create new conversation
    $stmt = $db->prepare("
        INSERT INTO chat_conversations 
        (conversation_type, admin_id, student_id, created_at, updated_at, is_active) 
        VALUES ('admin_student', ?, ?, NOW(), NOW(), 1)
    ");
    $stmt->execute([$admin_id, $student['student_id']]);
    
    $conversation_id = $db->lastInsertId();
    
    $_SESSION['active_conversation_id'] = $conversation_id;
    $_SESSION['success'] = 'Conversation created successfully';
    header("Location: index.php?conversation_id=" . $conversation_id);
    
} catch (PDOException $e) {
    error_log("Error creating conversation: " . $e->getMessage());
    $_SESSION['error'] = 'Failed to create conversation. Please try again.';
    header("Location: index.php");
}
?>