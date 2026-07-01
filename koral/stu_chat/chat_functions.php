<?php
// student_chat/chat_functions.php

require_once '../db_connection.php';

/**
 * Get or create a conversation between student and admin
 */
function getOrCreateStudentConversation($admin_id, $student_id) {
    global $db;
    
    try {
        // Check if conversation exists in the main conversations table
        $stmt = $db->prepare("
            SELECT c.id 
            FROM conversations c
            INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id
            INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id
            WHERE c.type = 'one_to_one'
            AND cm1.user_id = ? 
            AND cm2.user_id = ?
        ");
        $stmt->execute([$admin_id, $student_id]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conversation) {
            return $conversation['id'];
        }
        
        // Check using student_id from students table
        $stmt = $db->prepare("
            SELECT u.id 
            FROM users u
            INNER JOIN students s ON u.id = s.user_id
            WHERE s.student_id = ?
        ");
        $stmt->execute([$student_id]);
        $student_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student_user) {
            return false;
        }
        
        // Check again with user_id
        $stmt = $db->prepare("
            SELECT c.id 
            FROM conversations c
            INNER JOIN conversation_members cm1 ON c.id = cm1.conversation_id
            INNER JOIN conversation_members cm2 ON c.id = cm2.conversation_id
            WHERE c.type = 'one_to_one'
            AND cm1.user_id = ? 
            AND cm2.user_id = ?
        ");
        $stmt->execute([$admin_id, $student_user['id']]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($conversation) {
            return $conversation['id'];
        }
        
        // Create new conversation
        $db->beginTransaction();
        
        $stmt = $db->prepare("INSERT INTO conversations (type, created_by, created_at, updated_at) VALUES ('one_to_one', ?, NOW(), NOW())");
        $stmt->execute([$admin_id]);
        $conversation_id = $db->lastInsertId();
        
        // Add members
        $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)");
        $stmt->execute([$conversation_id, $admin_id]);
        $stmt->execute([$conversation_id, $student_user['id']]);
        
        $db->commit();
        
        return $conversation_id;
        
    } catch (PDOException $e) {
        if (isset($db)) {
            $db->rollBack();
        }
        error_log("Error in getOrCreateStudentConversation: " . $e->getMessage());
        return false;
    }
}

/**
 * Get messages for a conversation
 */
function getConversationMessages($conversation_id, $user_id) {
    global $db;
    
    try {
        // First verify user has access to this conversation
        $stmt = $db->prepare("
            SELECT id FROM conversation_members 
            WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$conversation_id, $user_id]);
        
        if (!$stmt->fetch()) {
            error_log("User $user_id does not have access to conversation $conversation_id");
            return [];
        }
        
        // Get messages from the main messages table
        $query = "
            SELECT m.*, u.name as sender_name, u.role as sender_role
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE m.conversation_id = ?
            ORDER BY m.created_at ASC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$conversation_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $messages;
        
    } catch (PDOException $e) {
        error_log("Error in getConversationMessages: " . $e->getMessage());
        return [];
    }
}

/**
 * Get unread message count for a student
 */
function getUnreadCount($user_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM messages m
            JOIN conversations c ON m.conversation_id = c.id
            JOIN conversation_members cm ON c.id = cm.conversation_id
            WHERE cm.user_id = ?
            AND m.sender_id != ?
            AND m.is_read = 0
        ");
        $stmt->execute([$user_id, $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? (int)$result['count'] : 0;
    } catch (PDOException $e) {
        error_log("Error in getUnreadCount: " . $e->getMessage());
        return 0;
    }
}

/**
 * Mark messages as read
 */
function markMessagesAsRead($conversation_id, $user_id) {
    global $db;
    
    try {
        $update = $db->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE conversation_id = ? 
            AND sender_id != ? 
            AND is_read = 0
        ");
        $update->execute([$conversation_id, $user_id]);
        
        return $update->rowCount();
    } catch (PDOException $e) {
        error_log("Error in markMessagesAsRead: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get conversation name
 */
function getConversationName($conversation_id, $user_id) {
    global $db;
    
    try {
        $stmt = $db->prepare("
            SELECT c.*, 
                   CASE 
                       WHEN c.type = 'one_to_one' THEN 
                           (SELECT u.name FROM users u 
                            JOIN conversation_members cm ON u.id = cm.user_id 
                            WHERE cm.conversation_id = c.id AND cm.user_id != ?)
                       WHEN c.type = 'group' AND c.batch_id IS NOT NULL THEN
                           CONCAT('Batch: ', (SELECT batch_name FROM batches WHERE batch_id = c.batch_id))
                       ELSE c.name
                   END as display_name
            FROM conversations c
            WHERE c.id = ?
        ");
        $stmt->execute([$user_id, $conversation_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['display_name'] : 'Conversation';
    } catch (PDOException $e) {
        error_log("Error in getConversationName: " . $e->getMessage());
        return 'Conversation';
    }
}

/**
 * Get conversation participants
 */
function getConversationParticipants($conversation_id, $user_id) {
    global $db;
    
    try {
        // Verify access
        $stmt = $db->prepare("
            SELECT id FROM conversation_members 
            WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$conversation_id, $user_id]);
        
        if (!$stmt->fetch()) {
            return [];
        }
        
        // Get participants
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.role,
                   CASE WHEN u.id = ? THEN 1 ELSE 0 END as is_you
            FROM conversation_members cm
            JOIN users u ON cm.user_id = u.id
            WHERE cm.conversation_id = ?
            ORDER BY is_you DESC, u.name
        ");
        $stmt->execute([$user_id, $conversation_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error in getConversationParticipants: " . $e->getMessage());
        return [];
    }
}
?>