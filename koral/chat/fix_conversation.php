<?php
// fix_conversation.php
session_start();
require_once '../db_connection.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Please login first. <a href='../login.php'>Go to Login</a>");
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['id']) ? intval($_GET['id']) : 5;

echo "<h2>🔧 Fixing Conversation Access</h2>";

try {
    // Get current user info
    $stmt = $db->prepare("SELECT id, name, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "<p>👤 Current user: <strong>{$user['name']}</strong> (ID: {$user['id']}, Role: {$user['role']})</p>";
    
    // Check if conversation exists
    $stmt = $db->prepare("SELECT * FROM conversations WHERE id = ?");
    $stmt->execute([$conversation_id]);
    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        die("<p style='color:red'>❌ Conversation ID {$conversation_id} not found!</p>");
    }
    
    echo "<p>💬 Conversation found: Type: {$conversation['type']}</p>";
    
    // Check if user is already a member
    $stmt = $db->prepare("
        SELECT * FROM conversation_members 
        WHERE conversation_id = ? AND user_id = ?
    ");
    $stmt->execute([$conversation_id, $user_id]);
    $member = $stmt->fetch();
    
    if ($member) {
        echo "<p style='color:orange'>⚠️ You are already a member of this conversation!</p>";
    } else {
        // Add user as member
        $stmt = $db->prepare("
            INSERT INTO conversation_members (conversation_id, user_id, joined_at, is_active) 
            VALUES (?, ?, NOW(), 1)
        ");
        $result = $stmt->execute([$conversation_id, $user_id]);
        
        if ($result) {
            echo "<p style='color:green'>✅ Successfully added you to conversation {$conversation_id}!</p>";
        } else {
            echo "<p style='color:red'>❌ Failed to add you to conversation</p>";
        }
    }
    
    // List all members
    echo "<h3>📋 Current Members:</h3><ul>";
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.role, cm.joined_at
        FROM conversation_members cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.conversation_id = ?
        ORDER BY cm.joined_at
    ");
    $stmt->execute([$conversation_id]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($members as $member) {
        $isYou = ($member['id'] == $user_id) ? " <strong>(You)</strong>" : "";
        echo "<li>ID: {$member['id']} - {$member['name']} ({$member['role']}){$isYou}</li>";
    }
    echo "</ul>";
    
    echo "<p style='margin-top:20px'>👉 <a href='index.php?conversation_id={$conversation_id}' style='background:#3b82f6; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Go to Conversation {$conversation_id}</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Error: " . $e->getMessage() . "</p>";
}
?>