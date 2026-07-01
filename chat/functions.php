<?php
// functions.php

function timeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j', $time);
    }
}

function getConversationName($conversation_id, $user_id, $db) {
    try {
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
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['display_name'] : 'Conversation';
    } catch (PDOException $e) {
        error_log("Error in getConversationName: " . $e->getMessage());
        return 'Conversation';
    }
}
?>