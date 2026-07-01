<?php
// student_chat/get_conversation_info.php
session_start();
require_once '../db_connection.php';
require_once 'chat_functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$conversation_id = isset($_GET['conversation_id']) ? intval($_GET['conversation_id']) : 0;

if (!$conversation_id) {
    echo json_encode(['success' => false, 'error' => 'Conversation ID required']);
    exit;
}

try {
    // Get conversation name
    $display_name = getConversationName($conversation_id, $user_id);
    
    // Get conversation type
    $stmt = $db->prepare("SELECT type FROM conversations WHERE id = ?");
    $stmt->execute([$conversation_id]);
    $conv_type = $stmt->fetchColumn();
    
    // Get participants
    $participants = getConversationParticipants($conversation_id, $user_id);
    
    // Add initials for each participant
    foreach ($participants as &$p) {
        $name_parts = explode(' ', $p['name']);
        $initials = strtoupper(substr($name_parts[0], 0, 1));
        if (isset($name_parts[1])) {
            $initials .= strtoupper(substr($name_parts[1], 0, 1));
        }
        $p['initials'] = $initials;
    }
    
    echo json_encode([
        'success' => true,
        'name' => $display_name,
        'type' => $conv_type === 'one_to_one' ? 'Private Chat' : 'Group Chat',
        'participants' => $participants,
        'participant_count' => count($participants)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching conversation info: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>