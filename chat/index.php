<?php
/**
 * Admin Chat System - Main Interface
 * Handles real-time messaging between admin, students, and batch groups
 * Features: One-to-one chat, batch group chat, file attachments, message deletion
 * Updated: All admins included in conversations, IST timezone
 */

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Start session and include required files
session_start();
require_once '../db_connection.php';
require_once 'functions.php';

// Define constant for sidebar inclusion
define('CHAT_SIDEBAR_LOADED', true);

// Log page load for debugging
error_log("=== Admin Chat Index loaded with conversation_id: " . ($_GET['conversation_id'] ?? 'none') . " at " . date('Y-m-d H:i:s') . " IST ===");

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student';
$user_name = $_SESSION['username'] ?? $_SESSION['name'] ?? 'User';

// Only allow admin access
if ($user_role !== 'admin') {
    header("Location: ../dashboard.php");
    exit;
}

/**
 * AJAX Request Handlers
 * Process all AJAX requests from the frontend
 */
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    /**
     * Handle sending new messages
     */
    if ($_POST['ajax_action'] === 'send_message') {
        $conversation_id = intval($_POST['conversation_id']);
        $message = trim($_POST['message']);
        
        if (!$conversation_id || empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit;
        }
        
        try {
            // Get IST time
            $ist_time = date('Y-m-d H:i:s');
            
            // Insert message into database
            $stmt = $db->prepare("
                INSERT INTO messages (conversation_id, sender_id, message, is_read, created_at) 
                VALUES (?, ?, ?, 0, ?)
            ");
            $result = $stmt->execute([$conversation_id, $user_id, $message, $ist_time]);
            
            if (!$result) {
                throw new Exception("Failed to insert message");
            }
            
            $message_id = $db->lastInsertId();
            
            // Update conversation timestamp
            $stmt = $db->prepare("UPDATE conversations SET updated_at = ? WHERE id = ?");
            $stmt->execute([$ist_time, $conversation_id]);
            
            // Get the inserted message with sender details
            $stmt = $db->prepare("
                SELECT m.*, u.name as sender_name, u.role as sender_role
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.id = ?
            ");
            $stmt->execute([$message_id]);
            $new_message = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Format time for display
            $new_message['formatted_time'] = date('g:i A', strtotime($new_message['created_at']));
            $new_message['formatted_date'] = date('M j, Y', strtotime($new_message['created_at']));
            $new_message['ist_time'] = date('Y-m-d H:i:s', strtotime($new_message['created_at']));

            echo json_encode([
                'success' => true,
                'message' => $new_message
            ]);
        } catch (Exception $e) {
            error_log("Error sending message: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    /**
     * Handle fetching new messages (polling)
     */
    if ($_POST['ajax_action'] === 'get_messages') {
        $conversation_id = intval($_POST['conversation_id']);
        $last_id = intval($_POST['last_id']);
        
        try {
            $stmt = $db->prepare("
                SELECT m.*, u.name as sender_name, u.role as sender_role
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                WHERE m.conversation_id = ? AND m.id > ?
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$conversation_id, $last_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark messages as read
            if (!empty($messages)) {
                $stmt = $db->prepare("
                    UPDATE messages 
                    SET is_read = 1 
                    WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
                ");
                $stmt->execute([$conversation_id, $user_id]);
            }
            
            // Format messages for display
            foreach ($messages as &$msg) {
                $msg['formatted_time'] = date('g:i A', strtotime($msg['created_at']));
                $msg['formatted_date'] = date('M j, Y', strtotime($msg['created_at']));
                $msg['ist_time'] = date('Y-m-d H:i:s', strtotime($msg['created_at']));
            }
            
            echo json_encode([
                'success' => true,
                'messages' => $messages
            ]);
        } catch (PDOException $e) {
            error_log("Error fetching messages: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error']);
        }
        exit;
    }
    
    /**
     * Handle creating new conversations (one-to-one, batch, group)
     */
    if ($_POST['ajax_action'] === 'create_conversation') {
        $type = $_POST['type'] ?? '';
        
        try {
            // Get all admin users
            $stmt = $db->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
            $stmt->execute();
            $all_admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // One-to-One Chat Creation
            if ($type === 'one_to_one') {
                $other_user_id = intval($_POST['user_id']);
                
                if (!$other_user_id) {
                    echo json_encode(['success' => false, 'error' => 'Please select a user']);
                    exit;
                }
                
                // Check if conversation already exists with any admin
                $stmt = $db->prepare("
                    SELECT c.id 
                    FROM conversations c
                    JOIN conversation_members cm ON c.id = cm.conversation_id
                    WHERE c.type = 'one_to_one' 
                    AND c.batch_id IS NULL
                    AND cm.user_id IN (" . implode(',', array_fill(0, count($all_admins), '?')) . ")
                    AND EXISTS (
                        SELECT 1 FROM conversation_members cm2 
                        WHERE cm2.conversation_id = c.id AND cm2.user_id = ?
                    )
                    LIMIT 1
                ");
                
                $params = array_merge($all_admins, [$other_user_id]);
                $stmt->execute($params);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    echo json_encode(['success' => true, 'conversation_id' => $existing['id']]);
                    exit;
                }
                
                // Create new conversation
                $db->beginTransaction();
                
                $ist_time = date('Y-m-d H:i:s');
                
                $stmt = $db->prepare("INSERT INTO conversations (type, created_by, created_at, updated_at) VALUES ('one_to_one', ?, ?, ?)");
                $stmt->execute([$user_id, $ist_time, $ist_time]);
                $conversation_id = $db->lastInsertId();
                
                // Add ALL admins as members
                $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, joined_at) VALUES (?, ?, ?)");
                
                // Add all admins
                foreach ($all_admins as $admin_id) {
                    $stmt->execute([$conversation_id, $admin_id, $ist_time]);
                }
                
                // Add the other user
                $stmt->execute([$conversation_id, $other_user_id, $ist_time]);
                
                $db->commit();
                
                echo json_encode(['success' => true, 'conversation_id' => $conversation_id]);
            }
            
            // Batch Chat Creation
            else if ($type === 'batch') {
                $batch_id = $_POST['batch_id'] ?? '';
                $batch_name = $_POST['batch_name'] ?? '';
                
                if (!$batch_id || !$batch_name) {
                    echo json_encode(['success' => false, 'error' => 'Please select a batch']);
                    exit;
                }
                
                error_log("Creating batch chat - ID: $batch_id, Name: $batch_name");
                
                // Check if batch conversation already exists
                $stmt = $db->prepare("
                    SELECT c.id 
                    FROM conversations c
                    WHERE c.type = 'group' AND c.batch_id = ?
                ");
                $stmt->execute([$batch_id]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    echo json_encode(['success' => true, 'conversation_id' => $existing['id']]);
                    exit;
                }
                
                // Search by batch_id in students table
                $stmt = $db->prepare("
                    SELECT u.id 
                    FROM users u
                    INNER JOIN students s ON u.id = s.user_id
                    WHERE (
                        s.batch_name = ? OR 
                        s.batch_name_2 = ? OR 
                        s.batch_name_3 = ? OR 
                        s.batch_name_4 = ?
                    )
                    AND u.status = 'active'
                ");
                
                $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
                $students = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                error_log("Found " . count($students) . " students with batch ID: " . $batch_id);
                
                if (empty($students)) {
                    echo json_encode([
                        'success' => false, 
                        'error' => 'No students found with batch ID: ' . $batch_id
                    ]);
                    exit;
                }
                
                // Create batch conversation
                $db->beginTransaction();
                
                $conversation_name = "Batch: " . $batch_name;
                $ist_time = date('Y-m-d H:i:s');
                
                $stmt = $db->prepare("
                    INSERT INTO conversations (type, name, batch_id, created_by, created_at, updated_at) 
                    VALUES ('group', ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$conversation_name, $batch_id, $user_id, $ist_time, $ist_time]);
                $conversation_id = $db->lastInsertId();
                
                // Add ALL admins as members
                $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, joined_at) VALUES (?, ?, ?)");
                
                // Add all admins
                foreach ($all_admins as $admin_id) {
                    $stmt->execute([$conversation_id, $admin_id, $ist_time]);
                }
                
                // Add all students as members
                $member_count = 0;
                foreach ($students as $student_id) {
                    $stmt->execute([$conversation_id, $student_id, $ist_time]);
                    $member_count++;
                }
                
                $db->commit();
                
                echo json_encode([
                    'success' => true, 
                    'conversation_id' => $conversation_id,
                    'message' => 'Batch chat created for ' . $batch_name . ' with ' . $member_count . ' students'
                ]);
                exit;
            }
            
            // Custom Group Chat Creation
            else if ($type === 'group') {
                $name = trim($_POST['name'] ?? '');
                $members = isset($_POST['members']) ? json_decode($_POST['members'], true) : [];
                
                if (!$name) {
                    echo json_encode(['success' => false, 'error' => 'Group name required']);
                    exit;
                }
                
                if (empty($members)) {
                    echo json_encode(['success' => false, 'error' => 'Select at least one member']);
                    exit;
                }
                
                // Create group conversation
                $db->beginTransaction();
                
                $ist_time = date('Y-m-d H:i:s');
                
                $stmt = $db->prepare("INSERT INTO conversations (type, name, created_by, created_at, updated_at) VALUES ('group', ?, ?, ?, ?)");
                $stmt->execute([$name, $user_id, $ist_time, $ist_time]);
                $conversation_id = $db->lastInsertId();
                
                // Add ALL admins as members
                $stmt = $db->prepare("INSERT INTO conversation_members (conversation_id, user_id, joined_at) VALUES (?, ?, ?)");
                
                // Add all admins
                foreach ($all_admins as $admin_id) {
                    $stmt->execute([$conversation_id, $admin_id, $ist_time]);
                }
                
                // Add selected members
                foreach ($members as $member_id) {
                    if (!in_array($member_id, $all_admins)) {
                        $stmt->execute([$conversation_id, $member_id, $ist_time]);
                    }
                }
                
                $db->commit();
                
                echo json_encode(['success' => true, 'conversation_id' => $conversation_id]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid conversation type']);
            }
        } catch (Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            error_log("Error creating conversation: " . $e->getMessage());
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    
    /**
     * Handle deleting messages from a conversation
     */
    if ($_POST['ajax_action'] === 'delete_messages') {
        $conversation_id = intval($_POST['conversation_id']);
        
        // Verify admin has access to this conversation
        $stmt = $db->prepare("
            SELECT id FROM conversation_members 
            WHERE conversation_id = ? AND user_id = ?
        ");
        $stmt->execute([$conversation_id, $user_id]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            exit;
        }
        
        // Option 1: Soft delete (mark as deleted)
        if (isset($_POST['soft_delete']) && $_POST['soft_delete'] === 'true') {
            $stmt = $db->prepare("UPDATE messages SET is_deleted = 1 WHERE conversation_id = ?");
            $stmt->execute([$conversation_id]);
        } 
        // Option 2: Hard delete (permanently remove)
        else {
            $stmt = $db->prepare("DELETE FROM messages WHERE conversation_id = ?");
            $stmt->execute([$conversation_id]);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
}

/**
 * Fetch all users for new conversation modal
 */
$users = [];
$batches = [];
try {
    // Get all active users except current admin
    $stmt = $db->prepare("
        SELECT u.id, u.name, u.role,
               s.student_id, s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4
        FROM users u 
        LEFT JOIN students s ON u.id = s.user_id
        WHERE u.id != ? AND u.status = 'active' 
        ORDER BY u.role, u.name
    ");
    $stmt->execute([$user_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all active batches for batch chat
    $stmt = $db->prepare("
        SELECT DISTINCT batch_id, batch_name 
        FROM batches 
        WHERE status IN ('ongoing', 'upcoming')
        ORDER BY batch_name
    ");
    $stmt->execute();
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching users/batches: " . $e->getMessage());
}

/**
 * Fetch all conversations for the current user
 */
$conversations = [];
try {
    // Get all conversations where user is a member
    $stmt = $db->prepare("
        SELECT 
            c.*,
            (
                SELECT m.message 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                ORDER BY m.created_at DESC 
                LIMIT 1
            ) as last_message,
            (
                SELECT m.created_at 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                ORDER BY m.created_at DESC 
                LIMIT 1
            ) as last_message_time,
            (
                SELECT COUNT(*) 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                AND m.sender_id != ? 
                AND m.is_read = 0
                AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
            ) as unread_count,
            (
                SELECT batch_name 
                FROM batches 
                WHERE batch_id = c.batch_id
            ) as batch_name
        FROM conversations c
        INNER JOIN conversation_members cm ON c.id = cm.conversation_id
        WHERE cm.user_id = ? 
        AND cm.is_active = 1
        GROUP BY c.id
        ORDER BY 
            CASE WHEN last_message_time IS NULL THEN 1 ELSE 0 END,
            last_message_time DESC
    ");
    
    $stmt->execute([$user_id, $user_id]);
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Query found " . count($conversations) . " conversations for user " . $user_id);
    
    // Get other participants details for conversations
    foreach ($conversations as &$conv) {
        // Get all participants except current user
        $stmt2 = $db->prepare("
            SELECT u.id, u.name, u.role,
                   s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4
            FROM users u
            JOIN conversation_members cm ON u.id = cm.user_id
            LEFT JOIN students s ON u.id = s.user_id
            WHERE cm.conversation_id = ? AND cm.user_id != ?
            ORDER BY u.role
        ");
        $stmt2->execute([$conv['id'], $user_id]);
        $other_participants = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $conv['other_participants'] = $other_participants;
        
        if ($conv['type'] === 'one_to_one') {
            // For one-to-one, show the non-admin user
            foreach ($other_participants as $participant) {
                if ($participant['role'] !== 'admin') {
                    $conv['other_user'] = $participant;
                    break;
                }
            }
            if (!isset($conv['other_user']) && !empty($other_participants)) {
                $conv['other_user'] = $other_participants[0];
            }
        } else if ($conv['type'] === 'group') {
            // For group chats, set display name
            if (!empty($conv['batch_id'])) {
                $conv['display_name'] = $conv['name'];
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Error fetching conversations: " . $e->getMessage());
    $conversations = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Chat System - ASD Academy (IST)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Chat Container Styles */
        .chat-container {
            height: calc(100vh - 120px);
        }
        .message-bubble {
            max-width: 70%;
            word-wrap: break-word;
        }
        .message-time {
            font-size: 0.7rem;
            opacity: 0.7;
        }
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .online {
            background-color: #10b981;
        }
        .offline {
            background-color: #9ca3af;
        }
        #messageContainer {
            scroll-behavior: smooth;
        }
        
        /* Typing Indicator Animation */
        .typing-indicator {
            display: none;
            padding: 10px;
        }
        .typing-indicator span {
            height: 8px;
            width: 8px;
            background: #3b82f6;
            border-radius: 50%;
            display: inline-block;
            margin: 0 2px;
            animation: typing 1.4s infinite ease-in-out both;
        }
        .typing-indicator span:nth-child(1) { animation-delay: 0s; }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.6); opacity: 0.6; }
            40% { transform: scale(1); opacity: 1; }
        }
        
        /* Message Animation */
        .message-enter {
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Active Conversation Style */
        .conversation-item.active {
            background-color: #e5f2ff;
            border-left: 4px solid #3b82f6;
        }
        
        /* Error Message Style */
        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.5rem;
            display: none;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal.show {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 0.5rem;
            max-width: 400px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .hidden {
            display: none;
        }
        
        /* Delete Button Style */
        .delete-conversation-btn {
            opacity: 0;
            transition: opacity 0.2s;
        }
        .conversation-item:hover .delete-conversation-btn {
            opacity: 1;
        }
        
        /* Admin badge */
        .admin-badge {
            background-color: #818cf8;
            color: white;
            font-size: 0.6rem;
            padding: 2px 4px;
            border-radius: 4px;
            margin-left: 4px;
        }
        
        /* Loading spinner */
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include '../sidebar.php'; ?>
    
    <div class="md:ml-64 p-4 transition-all duration-300">
        <div class="bg-white rounded-lg shadow-lg chat-container flex overflow-hidden">
            
            <!-- Include Chat Sidebar -->
            <?php include 'chat_sidebar.php'; ?>
            
            <!-- Right Side - Chat Area -->
            <div class="flex-1 flex flex-col">
                <!-- Chat Header -->
                <div id="chatHeader" class="p-4 border-b bg-white flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-full bg-blue-500 flex items-center justify-center text-white font-bold">
                            <span id="chatAvatar">?</span>
                        </div>
                        <div>
                            <h3 id="chatTitle" class="font-semibold">Select a conversation</h3>
                            <p id="chatStatus" class="text-sm text-gray-500"></p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <!-- IST Time Display -->
                        <span class="text-sm text-gray-500 mr-2" id="istTimeDisplay"></span>
                        
                        <!-- Chat Info Button -->
                        <button onclick="showChatInfoModal()" class="p-2 hover:bg-gray-100 rounded-full" title="Chat Info">
                            <i class="fas fa-info-circle text-gray-600"></i>
                        </button>
                        <!-- Delete All Messages Button -->
                        <button onclick="deleteAllMessages()" class="p-2 hover:bg-gray-100 rounded-full" title="Delete all messages">
                            <i class="fas fa-trash-alt text-gray-600"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Messages Container -->
                <div id="messageContainer" class="flex-1 overflow-y-auto p-4 bg-gray-50">
                    <div class="text-center text-gray-500 mt-10">
                        <i class="fas fa-comments text-4xl mb-3"></i>
                        <p>Select a conversation to start chatting</p>
                    </div>
                </div>
                
                <!-- Error Message Display -->
                <div id="errorMessage" class="error-message px-4"></div>
                
                <!-- Typing Indicator -->
                <div id="typingIndicator" class="typing-indicator px-4">
                    <span></span>
                    <span></span>
                    <span></span>
                    <span class="text-sm text-gray-500 ml-2">typing...</span>
                </div>
                
                <!-- Message Input Area -->
                <div class="p-4 border-t bg-white">
                    <form id="messageForm" onsubmit="return sendMessage(event)" class="flex items-center space-x-2">
                        <input type="hidden" id="currentConversationId" value="">
                        
                        <!-- Attachment Button -->
                        <button type="button" onclick="document.getElementById('fileInput').click()" class="p-2 hover:bg-gray-100 rounded-full">
                            <i class="fas fa-paperclip text-gray-600"></i>
                        </button>
                        <input type="file" id="fileInput" style="display: none;" onchange="uploadAttachment()">
                        
                        <!-- Message Input -->
                        <input type="text" 
                               id="messageInput" 
                               placeholder="Type a message..." 
                               class="flex-1 p-2 border rounded-lg focus:outline-none focus:border-blue-500"
                               autocomplete="off"
                               onkeyup="handleTyping()">
                        
                        <!-- Send Button -->
                        <button type="submit" class="bg-blue-600 text-white p-2 rounded-lg hover:bg-blue-700 transition" id="sendButton">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- New Chat Modal -->
    <div id="newChatModal" class="modal">
        <div class="modal-content">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold">Start New Chat</h3>
                <button onclick="closeNewChatModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            </div>
            <div class="p-4">
                <div id="modalErrorMessage" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-3 hidden"></div>
                
                <!-- Info about all admins -->
                <div class="mb-4 p-3 bg-blue-50 text-blue-700 rounded-lg text-sm">
                    <i class="fas fa-info-circle mr-1"></i> All active admins will be added to this conversation automatically.
                </div>
                
                <!-- Chat Type Selection -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Chat Type</label>
                    <select id="chatType" onchange="toggleChatType()" class="w-full border rounded-lg p-2">
                        <option value="one_to_one">One-to-One Chat</option>
                        <option value="batch">Batch Chat</option>
                        <option value="group">Group Chat</option>
                    </select>
                </div>
                
                <!-- One-to-One Selection -->
                <div id="oneToOneSection">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select User</label>
                    <select id="selectedUserId" class="w-full border rounded-lg p-2">
                        <option value="">Choose a user...</option>
                        <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" data-role="<?= $user['role'] ?>">
                            <?= htmlspecialchars($user['name']) ?> (<?= ucfirst($user['role']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Batch Selection -->
                <div id="batchSection" style="display: none;">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Batch</label>
                    <select id="selectedBatch" class="w-full border rounded-lg p-2 mb-3">
                        <option value="">Choose a batch...</option>
                        <?php foreach ($batches as $batch): ?>
                        <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                            <?= htmlspecialchars($batch['batch_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-sm text-gray-500">This will create a group chat with all students in this batch and all admins</p>
                </div>
                
                <!-- Group Chat Section -->
                <div id="groupSection" style="display: none;">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Group Name</label>
                        <input type="text" id="groupName" class="w-full border rounded-lg p-2" placeholder="Enter group name">
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Members</label>
                        <div class="max-h-60 overflow-y-auto border rounded-lg p-2">
                            <?php foreach ($users as $user): ?>
                            <label class="flex items-center space-x-2 p-2 hover:bg-gray-50">
                                <input type="checkbox" name="groupMembers" value="<?= $user['id'] ?>" class="rounded">
                                <span><?= htmlspecialchars($user['name']) ?> (<?= ucfirst($user['role']) ?>)</span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-2">Note: All admins will be added automatically</p>
                    </div>
                </div>
            </div>
            <div class="p-4 border-t flex justify-end space-x-2">
                <button onclick="closeNewChatModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">Cancel</button>
                <button onclick="createNewChat()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700" id="createChatBtn">Start Chat</button>
            </div>
        </div>
    </div>
    
    <!-- Chat Info Modal -->
    <div id="chatInfoModal" class="modal">
        <div class="modal-content max-w-2xl">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold">Chat Information</h3>
                <button onclick="closeChatInfoModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            </div>
            <div class="p-4">
                <div id="chatInfoLoading" class="text-center py-4">
                    <div class="loading-spinner"></div>
                    <p class="mt-2">Loading...</p>
                </div>
                <div id="chatInfoContent" style="display: none;">
                    <!-- Participants List -->
                    <div class="mb-6">
                        <h4 class="font-semibold text-gray-700 mb-2">Participants</h4>
                        <div id="participantsList" class="space-y-2 max-h-60 overflow-y-auto border rounded-lg p-3">
                            <!-- Participants will be loaded here -->
                        </div>
                    </div>
                    
                    <!-- Chat Actions -->
                    <div class="border-t pt-4">
                        <h4 class="font-semibold text-gray-700 mb-2">Actions</h4>
                        <div class="space-y-2">
                            <button onclick="clearChat(false)" class="w-full text-left px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition">
                                <i class="fas fa-eraser text-gray-600 mr-2"></i>
                                Clear chat for me
                            </button>
                            <button onclick="deleteAllMessages()" class="w-full text-left px-4 py-2 bg-red-100 hover:bg-red-200 text-red-700 rounded-lg transition">
                                <i class="fas fa-trash-alt mr-2"></i>
                                Delete all messages
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="modal">
        <div class="modal-content max-w-md">
            <div class="p-4 border-b flex justify-between items-center">
                <h3 class="text-lg font-semibold text-red-600">Delete Conversation</h3>
                <button onclick="closeDeleteConfirmModal()" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
            </div>
            <div class="p-6">
                <p class="mb-4">Are you sure you want to permanently delete <span id="deleteConversationName" class="font-bold"></span>?</p>
                <p class="text-sm text-red-600 mb-4">⚠️ This will delete ALL messages for ALL participants. This action cannot be undone!</p>
                
                <div class="flex justify-end space-x-2">
                    <button onclick="closeDeleteConfirmModal()" class="px-4 py-2 border rounded-lg hover:bg-gray-50">Cancel</button>
                    <button onclick="confirmDeleteConversation()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">Delete</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // ============================================
        // Global Variables
        // ============================================
        let currentConversationId = null;
        let lastMessageId = 0;
        let typingTimeout = null;
        let isTyping = false;
        let userId = <?= $user_id ?>;
        let pollingInterval = null;
        let conversationToDelete = null;
        let conversationNameToDelete = '';
        
        // ============================================
        // IST Time Functions
        // ============================================
        
        /**
         * Update IST time display
         */
        function updateISTTime() {
            const now = new Date();
            const options = { 
                timeZone: 'Asia/Kolkata',
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false
            };
            const istTime = now.toLocaleString('en-IN', options);
            const timeDisplay = document.getElementById('istTimeDisplay');
            if (timeDisplay) {
                timeDisplay.textContent = 'IST: ' + istTime;
            }
        }
        
        /**
         * Format date to IST
         */
        function formatISTTime(timestamp) {
            const date = new Date(timestamp);
            return date.toLocaleTimeString('en-IN', { 
                timeZone: 'Asia/Kolkata',
                hour: '2-digit', 
                minute: '2-digit'
            });
        }
        
        /**
         * Format date to IST date
         */
        function formatISTDate(timestamp) {
            const date = new Date(timestamp);
            const today = new Date();
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            
            if (date.toDateString() === today.toDateString()) {
                return 'Today';
            } else if (date.toDateString() === yesterday.toDateString()) {
                return 'Yesterday';
            } else {
                return date.toLocaleDateString('en-IN', { 
                    timeZone: 'Asia/Kolkata',
                    month: 'long', 
                    day: 'numeric', 
                    year: 'numeric' 
                });
            }
        }
        
        // ============================================
        // Modal Functions
        // ============================================
        
        /**
         * Open the new chat modal
         */
        function openNewChatModal() {
            console.log('Opening new chat modal');
            const modal = document.getElementById('newChatModal');
            if (modal) {
                modal.classList.add('show');
                // Reset form
                document.getElementById('chatType').value = 'one_to_one';
                document.getElementById('oneToOneSection').style.display = 'block';
                document.getElementById('batchSection').style.display = 'none';
                document.getElementById('groupSection').style.display = 'none';
                document.getElementById('selectedUserId').value = '';
                document.getElementById('selectedBatch').value = '';
                document.getElementById('groupName').value = '';
                document.querySelectorAll('input[name="groupMembers"]').forEach(cb => cb.checked = false);
                
                // Clear any previous error messages
                const errorDiv = document.getElementById('modalErrorMessage');
                if (errorDiv) {
                    errorDiv.classList.add('hidden');
                    errorDiv.textContent = '';
                }
            } else {
                console.error('Modal not found');
            }
        }
        
        /**
         * Close the new chat modal
         */
        function closeNewChatModal() {
            console.log('Closing new chat modal');
            document.getElementById('newChatModal').classList.remove('show');
        }
        
        /**
         * Close delete confirmation modal
         */
        function closeDeleteConfirmModal() {
            document.getElementById('deleteConfirmModal').classList.remove('show');
            conversationToDelete = null;
            conversationNameToDelete = '';
        }
        
        /**
         * Toggle between chat types in modal
         */
        function toggleChatType() {
            const type = document.getElementById('chatType').value;
            console.log('Chat type changed to:', type);
            
            document.getElementById('oneToOneSection').style.display = type === 'one_to_one' ? 'block' : 'none';
            document.getElementById('batchSection').style.display = type === 'batch' ? 'block' : 'none';
            document.getElementById('groupSection').style.display = type === 'group' ? 'block' : 'none';
        }
        
        /**
         * Create a new chat conversation
         */
        function createNewChat() {
            const type = document.getElementById('chatType').value;
            const createBtn = document.getElementById('createChatBtn');
            const errorDiv = document.getElementById('modalErrorMessage');
            
            // Hide previous error
            if (errorDiv) {
                errorDiv.classList.add('hidden');
                errorDiv.textContent = '';
            }
            
            // Validate based on type
            if (type === 'one_to_one') {
                const userId = document.getElementById('selectedUserId').value;
                if (!userId) {
                    showModalError('Please select a user');
                    return;
                }
            } else if (type === 'batch') {
                const batchSelect = document.getElementById('selectedBatch');
                if (!batchSelect || !batchSelect.value) {
                    showModalError('Please select a batch');
                    return;
                }
            } else if (type === 'group') {
                const groupName = document.getElementById('groupName').value;
                const members = document.querySelectorAll('input[name="groupMembers"]:checked');
                
                if (!groupName) {
                    showModalError('Please enter group name');
                    return;
                }
                if (members.length === 0) {
                    showModalError('Please select at least one member');
                    return;
                }
            }
            
            // Disable button and show loading
            createBtn.disabled = true;
            createBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Creating...';
            
            // Prepare form data
            const formData = new FormData();
            formData.append('ajax_action', 'create_conversation');
            formData.append('type', type);
            
            if (type === 'one_to_one') {
                const userId = document.getElementById('selectedUserId').value;
                formData.append('user_id', userId);
            } else if (type === 'batch') {
                const batchSelect = document.getElementById('selectedBatch');
                const batchId = batchSelect.value;
                const batchName = batchSelect.options[batchSelect.selectedIndex]?.text || '';
                
                formData.append('batch_id', batchId);
                formData.append('batch_name', batchName);
            } else if (type === 'group') {
                const groupName = document.getElementById('groupName').value;
                const members = document.querySelectorAll('input[name="groupMembers"]:checked');
                
                formData.append('name', groupName);
                
                const memberIds = Array.from(members).map(m => m.value);
                formData.append('members', JSON.stringify(memberIds));
            }
            
            console.log('Sending create conversation request');
            
            // Send request
            fetch(window.location.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => {
                console.log('Create conversation response:', data);
                if (data.success) {
                    closeNewChatModal();
                    // Reload page to show new conversation
                    window.location.href = 'index.php?conversation_id=' + data.conversation_id;
                } else {
                    showModalError(data.error || 'Unknown error');
                }
            })
            .catch(error => {
                console.error('Error creating conversation:', error);
                showModalError('Network error. Please try again.');
            })
            .finally(() => {
                createBtn.disabled = false;
                createBtn.innerHTML = 'Start Chat';
            });
        }
        
        /**
         * Show error message in modal
         */
        function showModalError(message) {
            console.error('Modal error:', message);
            const errorDiv = document.getElementById('modalErrorMessage');
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.classList.remove('hidden');
                
                // Auto-hide after 5 seconds
                setTimeout(() => {
                    errorDiv.classList.add('hidden');
                }, 5000);
            } else {
                alert('Error: ' + message);
            }
        }
        
        // ============================================
        // Conversation Functions
        // ============================================
        
        /**
         * Load a conversation
         */
        function loadConversation(conversationId, element) {
            console.log('Loading conversation:', conversationId);
            
            if (!conversationId) {
                console.error('No conversation ID provided');
                return;
            }
            
            // Update URL without reloading
            const url = new URL(window.location);
            url.searchParams.set('conversation_id', conversationId);
            window.history.pushState({}, '', url);
            
            currentConversationId = conversationId;
            document.getElementById('currentConversationId').value = conversationId;
            
            // Update UI - remove active class from all
            document.querySelectorAll('.conversation-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Mark selected item as active
            if (element) {
                element.classList.add('active');
            } else {
                const foundElement = document.querySelector(`[data-conversation-id="${conversationId}"]`);
                if (foundElement) {
                    foundElement.classList.add('active');
                }
            }
            
            // Update chat header
            updateChatHeader(conversationId);
            
            // Reset last message ID
            lastMessageId = 0;
            
            // Show loading state
            document.getElementById('messageContainer').innerHTML = `
                <div class="text-center text-gray-500 mt-10">
                    <div class="loading-spinner"></div>
                    <p class="mt-3">Loading messages...</p>
                </div>
            `;
            
            // Load messages
            fetchMessages(true);
            
            // Start polling for new messages
            startPolling();
        }
        
        /**
         * Update chat header with conversation info
         */
        function updateChatHeader(conversationId) {
            console.log('Updating chat header for conversation:', conversationId);
            
            const selectedItem = document.querySelector(`[data-conversation-id="${conversationId}"]`);
            
            if (selectedItem) {
                const nameElement = selectedItem.querySelector('h3');
                if (nameElement) {
                    const name = nameElement.textContent;
                    console.log('Found conversation name:', name);
                    
                    document.getElementById('chatTitle').textContent = name;
                    document.getElementById('chatAvatar').textContent = name.charAt(0).toUpperCase();
                    document.getElementById('chatStatus').textContent = 'Online';
                }
            }
        }
        
        /**
         * Delete a conversation with confirmation
         */
        function deleteConversation(conversationId, conversationName) {
            conversationToDelete = conversationId;
            conversationNameToDelete = conversationName || 'this conversation';
            document.getElementById('deleteConversationName').textContent = conversationNameToDelete;
            document.getElementById('deleteConfirmModal').classList.add('show');
        }
        
        /**
         * Confirm and execute conversation deletion
         */
        function confirmDeleteConversation() {
            if (!conversationToDelete) {
                closeDeleteConfirmModal();
                return;
            }
            
            console.log('Deleting conversation:', conversationToDelete);
            
            const deleteBtn = document.querySelector(`[data-conversation-id="${conversationToDelete}"] .delete-conversation-btn`);
            if (deleteBtn) {
                deleteBtn.disabled = true;
                deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            }
            
            const formData = new URLSearchParams();
            formData.append('conversation_id', conversationToDelete);
            
            fetch('delete_conversation.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData.toString(),
                credentials: 'same-origin'
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Delete response:', data);
                
                if (data.success) {
                    showToast('Conversation deleted successfully', 'success');
                    closeDeleteConfirmModal();
                    
                    if (currentConversationId === conversationToDelete) {
                        currentConversationId = null;
                        document.getElementById('currentConversationId').value = '';
                        document.getElementById('messageContainer').innerHTML = `
                            <div class="text-center text-gray-500 mt-10">
                                <i class="fas fa-comments text-4xl mb-3"></i>
                                <p>Select a conversation to start chatting</p>
                            </div>
                        `;
                        document.getElementById('chatTitle').textContent = 'Select a conversation';
                        document.getElementById('chatAvatar').textContent = '?';
                        document.getElementById('chatStatus').textContent = '';
                        
                        stopPolling();
                    }
                    
                    const conversationElement = document.querySelector(`[data-conversation-id="${conversationToDelete}"]`);
                    if (conversationElement) {
                        conversationElement.style.transition = 'all 0.3s ease';
                        conversationElement.style.opacity = '0';
                        conversationElement.style.transform = 'translateX(-20px)';
                        
                        setTimeout(() => {
                            conversationElement.remove();
                            
                            const remainingConversations = document.querySelectorAll('.conversation-item');
                            if (remainingConversations.length === 0) {
                                document.getElementById('conversationsList').innerHTML = `
                                    <div class="p-4 text-center text-gray-500">
                                        <i class="fas fa-inbox text-3xl mb-2"></i>
                                        <p>No conversations yet</p>
                                        <p class="text-sm mt-2">Click "New Chat" to start one</p>
                                    </div>
                                `;
                            }
                        }, 300);
                    }
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete conversation'));
                    closeDeleteConfirmModal();
                }
            })
            .catch(error => {
                console.error('Error deleting conversation:', error);
                alert('Network error. Please try again.');
                closeDeleteConfirmModal();
            });
        }

        /**
         * Show toast notification
         */
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 animate__animated animate__fadeInRight`;
            
            if (type === 'success') {
                toast.classList.add('bg-green-500', 'text-white');
            } else if (type === 'error') {
                toast.classList.add('bg-red-500', 'text-white');
            } else {
                toast.classList.add('bg-blue-500', 'text-white');
            }
            
            toast.innerHTML = `
                <div class="flex items-center space-x-2">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.add('animate__fadeOutRight');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 3000);
        }
        
        // ============================================
        // Message Functions
        // ============================================
        
        /**
         * Fetch messages for current conversation
         */
        function fetchMessages(initial = false) {
            if (!currentConversationId) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'get_messages');
            formData.append('conversation_id', currentConversationId);
            formData.append('last_id', lastMessageId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.messages && data.messages.length > 0) {
                        displayMessages(data.messages, initial);
                        
                        const lastMsg = data.messages[data.messages.length - 1];
                        if (lastMsg.id > lastMessageId) {
                            lastMessageId = lastMsg.id;
                        }
                    } else if (initial) {
                        document.getElementById('messageContainer').innerHTML = `
                            <div class="text-center text-gray-500 mt-10">
                                <i class="fas fa-comments text-4xl mb-3"></i>
                                <p>No messages yet. Start the conversation!</p>
                            </div>
                        `;
                    }
                } else {
                    console.error('Error fetching messages:', data.error);
                }
            })
            .catch(error => {
                console.error('Error fetching messages:', error);
            });
        }
        
        /**
         * Display messages in the container
         */
        function displayMessages(messages, clearExisting = false) {
            const container = document.getElementById('messageContainer');
            
            if (clearExisting) {
                container.innerHTML = '';
            }
            
            let lastDate = '';
            
            messages.forEach(message => {
                const messageDate = new Date(message.created_at);
                const dateDisplay = formatISTDate(message.created_at);
                
                if (dateDisplay !== lastDate) {
                    lastDate = dateDisplay;
                    const dateDiv = document.createElement('div');
                    dateDiv.className = 'text-center my-4';
                    dateDiv.innerHTML = `<span class="bg-gray-200 text-gray-600 text-xs px-3 py-1 rounded-full">${dateDisplay}</span>`;
                    container.appendChild(dateDiv);
                }
                
                const messageHtml = createMessageHtml(message);
                container.insertAdjacentHTML('beforeend', messageHtml);
            });
            
            container.scrollTop = container.scrollHeight;
        }
        
        /**
         * Create HTML for a message
         */
        function createMessageHtml(message) {
            const isSender = message.sender_id == userId;
            const alignment = isSender ? 'justify-end' : 'justify-start';
            const bgColor = isSender ? 'bg-blue-600 text-white' : 'bg-white';
            const time = formatISTTime(message.created_at);
            
            let senderInfo = '';
            if (!isSender && message.sender_name) {
                const isAdmin = message.sender_role === 'admin' ? 
                    '<span class="admin-badge">Admin</span>' : '';
                senderInfo = `<div class="text-xs font-semibold mb-1 text-blue-600">${escapeHtml(message.sender_name)} ${isAdmin}</div>`;
            }
            
            let statusIcon = '';
            if (isSender) {
                if (message.is_read) {
                    statusIcon = '<i class="fas fa-check-double text-blue-300 ml-1"></i>';
                } else {
                    statusIcon = '<i class="fas fa-check ml-1"></i>';
                }
            }
            
            return `
                <div class="flex ${alignment} mb-4 message-enter">
                    <div class="message-bubble ${bgColor} rounded-lg p-3 shadow max-w-[70%]">
                        ${senderInfo}
                        <p class="text-sm break-words">${escapeHtml(message.message)}</p>
                        <div class="flex items-center justify-end space-x-1 mt-1">
                            <span class="message-time ${isSender ? 'text-blue-200' : 'text-gray-500'}">
                                ${time} IST
                            </span>
                            ${statusIcon}
                        </div>
                    </div>
                </div>
            `;
        }
        
        /**
         * Send a new message
         */
        function sendMessage(event) {
            event.preventDefault();
            
            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            const sendButton = document.getElementById('sendButton');
            const errorDiv = document.getElementById('errorMessage');
            
            if (!message || !currentConversationId) {
                errorDiv.textContent = !currentConversationId ? 
                    'Please select a conversation first' : 'Please enter a message';
                errorDiv.style.display = 'block';
                setTimeout(() => {
                    errorDiv.style.display = 'none';
                }, 3000);
                return false;
            }
            
            messageInput.disabled = true;
            sendButton.disabled = true;
            errorDiv.style.display = 'none';
            
            const formData = new FormData();
            formData.append('ajax_action', 'send_message');
            formData.append('conversation_id', currentConversationId);
            formData.append('message', message);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageInput.value = '';
                    
                    const container = document.getElementById('messageContainer');
                    
                    const emptyState = container.querySelector('.text-center.text-gray-500');
                    if (emptyState) {
                        emptyState.remove();
                    }
                    
                    const today = new Date();
                    const lastDateDiv = container.querySelector('.text-center:last-child');
                    if (!lastDateDiv || !lastDateDiv.textContent.includes('Today')) {
                        const dateDiv = document.createElement('div');
                        dateDiv.className = 'text-center my-4';
                        dateDiv.innerHTML = '<span class="bg-gray-200 text-gray-600 text-xs px-3 py-1 rounded-full">Today</span>';
                        container.appendChild(dateDiv);
                    }
                    
                    const messageHtml = createMessageHtml(data.message);
                    container.insertAdjacentHTML('beforeend', messageHtml);
                    
                    container.scrollTop = container.scrollHeight;
                    
                    if (data.message.id > lastMessageId) {
                        lastMessageId = data.message.id;
                    }
                } else {
                    errorDiv.textContent = data.error || 'Failed to send message';
                    errorDiv.style.display = 'block';
                    setTimeout(() => {
                        errorDiv.style.display = 'none';
                    }, 3000);
                }
            })
            .catch(error => {
                console.error('Error sending message:', error);
                errorDiv.textContent = 'Network error. Please try again.';
                errorDiv.style.display = 'block';
                setTimeout(() => {
                    errorDiv.style.display = 'none';
                }, 3000);
            })
            .finally(() => {
                messageInput.disabled = false;
                sendButton.disabled = false;
                messageInput.focus();
            });
            
            return false;
        }
        
        /**
         * Upload file attachment
         */
        function uploadAttachment() {
            const fileInput = document.getElementById('fileInput');
            const file = fileInput.files[0];
            
            if (!file || !currentConversationId) {
                alert('Please select a file and conversation first');
                return;
            }
            
            if (file.size > 10 * 1024 * 1024) {
                alert('File size must be less than 10MB');
                return;
            }
            
            const formData = new FormData();
            formData.append('conversation_id', currentConversationId);
            formData.append('attachment', file);
            
            const sendButton = document.getElementById('sendButton');
            const originalHtml = sendButton.innerHTML;
            sendButton.disabled = true;
            sendButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            fetch('upload_attachment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    fetchMessages(false);
                } else {
                    alert('Upload failed: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                alert('Upload failed. Please try again.');
            })
            .finally(() => {
                fileInput.value = '';
                sendButton.disabled = false;
                sendButton.innerHTML = originalHtml;
            });
        }
        
        // ============================================
        // Chat Info Modal Functions
        // ============================================
        
        /**
         * Show chat info modal with participants
         */
        function showChatInfoModal() {
            if (!currentConversationId) {
                alert('Please select a conversation first');
                return;
            }
            
            const modal = document.getElementById('chatInfoModal');
            const loading = document.getElementById('chatInfoLoading');
            const content = document.getElementById('chatInfoContent');
            
            modal.classList.add('show');
            loading.style.display = 'block';
            content.style.display = 'none';
            
            fetch('get_participants.php?conversation_id=' + currentConversationId)
                .then(response => response.json())
                .then(data => {
                    loading.style.display = 'none';
                    if (data.success) {
                        displayParticipants(data.participants);
                        content.style.display = 'block';
                    } else {
                        alert('Error loading participants: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    loading.style.display = 'none';
                    alert('Error loading participants');
                });
        }
        
        /**
         * Close chat info modal
         */
        function closeChatInfoModal() {
            document.getElementById('chatInfoModal').classList.remove('show');
        }
        
        /**
         * Display participants in the modal
         */
        function displayParticipants(participants) {
            const list = document.getElementById('participantsList');
            list.innerHTML = '';
            
            participants.forEach(p => {
                const badge = p.is_you ? 
                    '<span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded ml-2">You</span>' : '';
                const roleBadge = p.role === 'admin' ? 
                    '<span class="text-xs bg-purple-100 text-purple-600 px-2 py-1 rounded ml-2">Admin</span>' : 
                    '<span class="text-xs bg-green-100 text-green-600 px-2 py-1 rounded ml-2">' + p.role + '</span>';
                
                const participantDiv = document.createElement('div');
                participantDiv.className = 'flex items-center justify-between p-2 bg-gray-50 rounded-lg';
                participantDiv.innerHTML = `
                    <div>
                        <div class="flex items-center">
                            <span class="font-medium">${escapeHtml(p.name)}</span>
                            ${badge}
                            ${roleBadge}
                        </div>
                        <div class="text-sm text-gray-600">${p.role}</div>
                    </div>
                    <div class="status-dot online"></div>
                `;
                list.appendChild(participantDiv);
            });
        }
        
        /**
         * Clear chat for current user
         */
        function clearChat(clearBoth = false) {
            if (!confirm(clearBoth ? 
                'Are you sure? This will delete all messages for everyone!' : 
                'Clear all messages for you?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('conversation_id', currentConversationId);
            formData.append('soft_delete', clearBoth ? 'false' : 'true');
            
            fetch('clear_chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeChatInfoModal();
                    document.getElementById('messageContainer').innerHTML = `
                        <div class="text-center text-gray-500 mt-10">
                            <i class="fas fa-comments text-4xl mb-3"></i>
                            <p>Chat has been cleared</p>
                        </div>
                    `;
                    lastMessageId = 0;
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error clearing chat:', error);
                alert('Error clearing chat');
            });
        }
        
        /**
         * Delete all messages
         */
        function deleteAllMessages() {
            if (!currentConversationId) {
                alert('Please select a conversation first');
                return;
            }
            
            if (!confirm('Are you sure you want to delete all messages in this conversation?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('ajax_action', 'delete_messages');
            formData.append('conversation_id', currentConversationId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    closeChatInfoModal();
                    document.getElementById('messageContainer').innerHTML = `
                        <div class="text-center text-gray-500 mt-10">
                            <i class="fas fa-comments text-4xl mb-3"></i>
                            <p>All messages deleted</p>
                        </div>
                    `;
                    lastMessageId = 0;
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error deleting messages:', error);
                alert('Error deleting messages');
            });
        }
        
        /**
         * Handle typing indicator
         */
        function handleTyping() {
            if (!currentConversationId) return;
            
            if (!isTyping) {
                isTyping = true;
                sendTypingStatus(true);
            }
            
            clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => {
                isTyping = false;
                sendTypingStatus(false);
            }, 3000);
        }
        
        /**
         * Send typing status
         */
        function sendTypingStatus(isTyping) {
            const formData = new FormData();
            formData.append('conversation_id', currentConversationId);
            formData.append('is_typing', isTyping);
            
            fetch('typing_notification.php', {
                method: 'POST',
                body: formData
            })
            .catch(error => console.error('Error sending typing status:', error));
        }
        
        // ============================================
        // Utility Functions
        // ============================================
        
        /**
         * Escape HTML special characters
         */
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        /**
         * Start polling for new messages
         */
        function startPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
            }
            pollingInterval = setInterval(() => fetchMessages(false), 2000);
        }
        
        /**
         * Stop polling for new messages
         */
        function stopPolling() {
            if (pollingInterval) {
                clearInterval(pollingInterval);
                pollingInterval = null;
            }
        }
        
        // ============================================
        // Event Listeners and Initialization
        // ============================================
        
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded');
            
            // Start IST time updater
            updateISTTime();
            setInterval(updateISTTime, 1000);
            
            const messageInput = document.getElementById('messageInput');
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                    
                    const sendButton = document.getElementById('sendButton');
                    if (sendButton) {
                        sendButton.disabled = this.value.trim() === '';
                    }
                });
                
                messageInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        document.getElementById('messageForm').dispatchEvent(new Event('submit'));
                    }
                });
            }
            
            const searchInput = document.getElementById('searchConversations');
            if (searchInput) {
                searchInput.addEventListener('input', function(e) {
                    const search = e.target.value.toLowerCase();
                    document.querySelectorAll('.conversation-item').forEach(item => {
                        const text = item.textContent.toLowerCase();
                        item.style.display = text.includes(search) ? 'block' : 'none';
                    });
                });
            }
            
            const urlParams = new URLSearchParams(window.location.search);
            const conversationId = urlParams.get('conversation_id');
            
            if (conversationId) {
                console.log('Loading conversation from URL:', conversationId);
                
                const conversationElement = document.querySelector(`[data-conversation-id="${conversationId}"]`);
                
                if (conversationElement) {
                    loadConversation(conversationId, conversationElement);
                } else {
                    setTimeout(() => {
                        const retryElement = document.querySelector(`[data-conversation-id="${conversationId}"]`);
                        if (retryElement) {
                            loadConversation(conversationId, retryElement);
                        }
                    }, 500);
                }
            }
            
            window.addEventListener('click', function(event) {
                const modal = document.getElementById('newChatModal');
                if (event.target === modal) {
                    closeNewChatModal();
                }
                
                const infoModal = document.getElementById('chatInfoModal');
                if (event.target === infoModal) {
                    closeChatInfoModal();
                }
                
                const deleteModal = document.getElementById('deleteConfirmModal');
                if (event.target === deleteModal) {
                    closeDeleteConfirmModal();
                }
            });
            
            window.addEventListener('beforeunload', function() {
                stopPolling();
            });
            
            window.addEventListener('popstate', function() {
                const urlParams = new URLSearchParams(window.location.search);
                const conversationId = urlParams.get('conversation_id');
                if (conversationId) {
                    const conversationElement = document.querySelector(`[data-conversation-id="${conversationId}"]`);
                    if (conversationElement) {
                        loadConversation(conversationId, conversationElement);
                    }
                }
            });
        });
    </script>
</body>
</html>