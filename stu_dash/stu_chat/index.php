<?php
// student_chat/index.php
require_once '../db_connection.php';
require_once 'chat_functions.php';

// Check student login
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get student information with batch details
try {
    $student_query = $db->prepare("
        SELECT s.student_id, s.user_id, s.first_name, s.last_name,
               s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4,
               b1.batch_id as batch_id_1, b1.batch_name as batch_name_1_full,
               b2.batch_id as batch_id_2, b2.batch_name as batch_name_2_full,
               b3.batch_id as batch_id_3, b3.batch_name as batch_name_3_full,
               b4.batch_id as batch_id_4, b4.batch_name as batch_name_4_full
        FROM students s
        LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
        LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
        LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
        LEFT JOIN batches b4 ON s.batch_name_4 = b4.batch_id
        WHERE s.user_id = ?
        LIMIT 1
    ");
    
    $student_query->execute([$user_id]);
    $student = $student_query->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        die("Student information not found. Please contact administrator.");
    }
    
    $_SESSION['student_id'] = $student['student_id'];
    
    // Collect all batch IDs this student belongs to
    $student_batch_ids = [];
    $student_batch_names = [];
    
    $batch_fields = [
        'batch_id_1' => $student['batch_id_1'] ?? null,
        'batch_id_2' => $student['batch_id_2'] ?? null,
        'batch_id_3' => $student['batch_id_3'] ?? null,
        'batch_id_4' => $student['batch_id_4'] ?? null
    ];
    
    foreach ($batch_fields as $batch_id) {
        if (!empty($batch_id)) {
            $student_batch_ids[] = $batch_id;
        }
    }
    
} catch (PDOException $e) {
    error_log("Error fetching student: " . $e->getMessage());
    die("Database error. Please try again later.");
}

// Get ALL conversations for this student
try {
    $query = $db->prepare("
        SELECT DISTINCT
            c.id,
            c.type,
            c.name as conversation_name,
            c.batch_id,
            CASE 
                WHEN c.type = 'one_to_one' THEN 
                    CONCAT('Chat with: ', (SELECT u.name FROM users u 
                                         JOIN conversation_members cm ON u.id = cm.user_id 
                                         WHERE cm.conversation_id = c.id AND cm.user_id != ?))
                WHEN c.type = 'group' AND c.batch_id IS NOT NULL THEN
                    CONCAT('Batch Group: ', COALESCE(b.batch_name, 'Batch'))
                WHEN c.type = 'group' THEN
                    CONCAT('Group: ', c.name)
                ELSE 'Conversation'
            END as display_name,
            (
                SELECT COUNT(*) 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                AND m.sender_id != ? 
                AND m.is_read = 0
                AND m.id > COALESCE(
                    (SELECT cleared_at FROM clear_chat_history 
                     WHERE conversation_id = c.id AND user_id = ?), 
                    0
                )
            ) as unread_count,
            (
                SELECT m.message 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                AND m.id > COALESCE(
                    (SELECT cleared_at FROM clear_chat_history 
                     WHERE conversation_id = c.id AND user_id = ?), 
                    0
                )
                ORDER BY m.created_at DESC 
                LIMIT 1
            ) as last_message,
            (
                SELECT m.created_at 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                AND m.id > COALESCE(
                    (SELECT cleared_at FROM clear_chat_history 
                     WHERE conversation_id = c.id AND user_id = ?), 
                    0
                )
                ORDER BY m.created_at DESC 
                LIMIT 1
            ) as last_message_time,
            (
                SELECT COUNT(DISTINCT user_id) 
                FROM conversation_members 
                WHERE conversation_id = c.id AND is_active = 1
            ) as member_count
        FROM conversations c
        INNER JOIN conversation_members cm ON c.id = cm.conversation_id
        LEFT JOIN batches b ON c.batch_id = b.batch_id
        WHERE cm.user_id = ? AND cm.is_active = 1
        ORDER BY 
            CASE WHEN last_message_time IS NULL THEN 1 ELSE 0 END,
            last_message_time DESC
    ");
    
    $query->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    $conversations = $query->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching conversations: " . $e->getMessage());
    $conversations = [];
}

// Get admins for new conversation modal
try {
    $admins = $db->prepare("
        SELECT id, name 
        FROM users 
        WHERE role IN ('admin', 'mentor') 
        AND status = 'active' 
        ORDER BY name
    ");
    $admins->execute();
    $admins_list = $admins->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching admins: " . $e->getMessage());
    $admins_list = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Chat - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        .animate-slide-in {
            animation: slideIn 0.3s ease-out;
        }
        .animate-pulse-slow {
            animation: pulse 2s infinite;
        }
        .message-bubble {
            max-width: 70%;
            word-wrap: break-word;
        }
        @media (max-width: 768px) {
            .message-bubble {
                max-width: 85%;
            }
        }
        .typing-indicator {
            display: flex;
            gap: 4px;
            padding: 12px 16px;
            background: #f3f4f6;
            border-radius: 20px;
            width: fit-content;
        }
        .typing-dot {
            width: 8px;
            height: 8px;
            background: #6b7280;
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        .conversation-item {
            transition: all 0.2s ease;
        }
        .conversation-item:hover {
            transform: translateX(4px);
        }
        .conversation-item.active {
            border-left-color: #3b82f6;
            background: #eff6ff;
        }
        .message-container {
            scroll-behavior: smooth;
        }
        .message-container::-webkit-scrollbar {
            width: 6px;
        }
        .message-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .message-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        .message-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar Toggle for Mobile -->
        <button id="sidebarToggle" class="md:hidden fixed top-4 left-4 z-50 bg-blue-600 text-white p-2 rounded-lg shadow-lg">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Conversations Sidebar -->
        <div id="conversationsSidebar" class="fixed md:relative inset-y-0 left-0 transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out z-40 w-80 bg-white border-r border-gray-200 flex flex-col h-full">
            <div class="p-4 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-comments text-blue-600"></i>
                    Messages
                </h2>
                <button onclick="showNewChatModal()" class="mt-4 w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center justify-center gap-2 transition-colors">
                    <i class="fas fa-plus"></i>
                    New Chat
                </button>
            </div>
            
            <!-- Conversations List -->
            <div class="flex-1 overflow-y-auto p-2" id="conversationsList">
                <?php if (empty($conversations)): ?>
                    <div class="text-center py-8 px-4">
                        <div class="text-6xl mb-4 text-gray-300">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <p class="text-gray-500 mb-2">No conversations yet</p>
                        <p class="text-sm text-gray-400">
                            Start a chat with your admin or wait for batch group to be created.
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): 
                        $display_name = $conv['display_name'] ?? 'Conversation';
                        $initials = '';
                        $words = explode(' ', $display_name);
                        foreach ($words as $word) {
                            if (!empty($word) && ctype_alpha($word[0])) {
                                $initials .= strtoupper($word[0]);
                            }
                            if (strlen($initials) >= 2) break;
                        }
                        if (strlen($initials) < 2) $initials = strtoupper(substr($display_name, 0, 2));
                        
                        $badge = '';
                        if ($conv['type'] === 'group') {
                            $badge = !empty($conv['batch_id']) ? 
                                '<span class="ml-2 px-1.5 py-0.5 bg-purple-100 text-purple-800 text-xs rounded">Batch</span>' :
                                '<span class="ml-2 px-1.5 py-0.5 bg-gray-100 text-gray-800 text-xs rounded">Group</span>';
                        }
                        
                        $last_time = '';
                        if ($conv['last_message_time']) {
                            $time = strtotime($conv['last_message_time']);
                            $today = strtotime('today');
                            $yesterday = strtotime('yesterday');
                            
                            if ($time >= $today) {
                                $last_time = date('g:i A', $time);
                            } elseif ($time >= $yesterday) {
                                $last_time = 'Yesterday';
                            } else {
                                $last_time = date('M j', $time);
                            }
                        }
                    ?>
                        <div class="conversation-item p-3 mb-1 rounded-lg cursor-pointer hover:bg-gray-50 border-l-4 border-transparent <?= (isset($_GET['conversation_id']) && $_GET['conversation_id'] == $conv['id']) ? 'active' : '' ?>" 
                             data-conversation-id="<?= $conv['id'] ?>"
                             onclick="loadConversation(<?= $conv['id'] ?>)">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-lg flex-shrink-0">
                                    <?= htmlspecialchars($initials) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <h3 class="font-semibold text-gray-900 truncate">
                                            <?= htmlspecialchars($display_name) ?>
                                            <?= $badge ?>
                                        </h3>
                                        <?php if ($last_time): ?>
                                            <span class="text-xs text-gray-500 flex-shrink-0 ml-2"><?= $last_time ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-sm text-gray-600 truncate">
                                        <?= htmlspecialchars($conv['last_message'] ?? 'No messages yet') ?>
                                    </p>
                                </div>
                                <?php if (($conv['unread_count'] ?? 0) > 0): ?>
                                    <span class="flex-shrink-0 ml-2 px-2 py-1 bg-red-500 text-white text-xs font-bold rounded-full animate-pulse-slow">
                                        <?= $conv['unread_count'] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main Chat Area -->
        <div class="flex-1 flex flex-col h-full bg-gray-50">
            <?php if (isset($_GET['conversation_id']) && $_GET['conversation_id']): 
                $conversation_id = intval($_GET['conversation_id']);
                
                // Verify access
                $check_access = $db->prepare("
                    SELECT id FROM conversation_members 
                    WHERE conversation_id = ? AND user_id = ? AND is_active = 1
                ");
                $check_access->execute([$conversation_id, $user_id]);
                
                if ($check_access->fetch()):
                    // Get conversation details
                    $conv_query = $db->prepare("
                        SELECT c.*, 
                               CASE 
                                   WHEN c.type = 'one_to_one' THEN 
                                       (SELECT u.name FROM users u 
                                        JOIN conversation_members cm ON u.id = cm.user_id 
                                        WHERE cm.conversation_id = c.id AND cm.user_id != ?)
                                   WHEN c.type = 'group' AND c.batch_id IS NOT NULL THEN
                                       CONCAT('Batch: ', COALESCE(b.batch_name, 'Batch'))
                                   ELSE c.name
                               END as display_name,
                               b.batch_name as batch_display
                        FROM conversations c
                        LEFT JOIN batches b ON c.batch_id = b.batch_id
                        WHERE c.id = ?
                    ");
                    $conv_query->execute([$user_id, $conversation_id]);
                    $active_conversation = $conv_query->fetch(PDO::FETCH_ASSOC);
                    
                    // Get messages (excluding cleared ones)
                    $messages_query = $db->prepare("
                        SELECT m.*, u.name as sender_name, u.role as sender_role
                        FROM messages m
                        JOIN users u ON m.sender_id = u.id
                        WHERE m.conversation_id = ?
                        AND m.id > COALESCE(
                            (SELECT cleared_at FROM clear_chat_history 
                             WHERE conversation_id = ? AND user_id = ?), 
                            0
                        )
                        ORDER BY m.created_at ASC
                    ");
                    $messages_query->execute([$conversation_id, $conversation_id, $user_id]);
                    $messages = $messages_query->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Mark messages as read
                    $mark_read = $db->prepare("
                        UPDATE messages 
                        SET is_read = 1 
                        WHERE conversation_id = ? 
                        AND sender_id != ? 
                        AND is_read = 0
                        AND id > COALESCE(
                            (SELECT cleared_at FROM clear_chat_history 
                             WHERE conversation_id = ? AND user_id = ?), 
                            0
                        )
                    ");
                    $mark_read->execute([$conversation_id, $user_id, $conversation_id, $user_id]);
                    
                    $display_name = $active_conversation['display_name'] ?? 'Conversation';
                    $initials = '';
                    $words = explode(' ', $display_name);
                    foreach ($words as $word) {
                        if (!empty($word) && ctype_alpha($word[0])) {
                            $initials .= strtoupper($word[0]);
                        }
                        if (strlen($initials) >= 2) break;
                    }
                    if (strlen($initials) < 2) $initials = strtoupper(substr($display_name, 0, 2));
                    
                    $last_message_id = !empty($messages) ? end($messages)['id'] : 0;
                    ?>
                    
                    <!-- Chat Header -->
                    <div class="bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-lg">
                                <?= htmlspecialchars($initials) ?>
                            </div>
                            <div>
                                <h2 class="text-xl font-semibold text-gray-800"><?= htmlspecialchars($display_name) ?></h2>
                                <p class="text-sm text-gray-500">
                                    <?= $active_conversation['type'] === 'group' ? 'Group' : 'Private' ?> Chat
                                </p>
                            </div>
                        </div>
                        <button onclick="showChatInfoModal(<?= $conversation_id ?>)" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                            <i class="fas fa-info-circle text-gray-600 text-xl"></i>
                        </button>
                    </div>

                    <!-- Messages Container -->
                    <div id="messagesContainer" class="flex-1 overflow-y-auto p-6 space-y-4 message-container" 
                         data-conversation-id="<?= $conversation_id ?>"
                         data-last-message-id="<?= $last_message_id ?>">
                        <?php 
                        $last_date = '';
                        foreach ($messages as $msg):
                            $current_date = date('Y-m-d', strtotime($msg['created_at']));
                            $is_own = ($msg['sender_id'] == $user_id);
                            
                            if ($current_date != $last_date):
                                $last_date = $current_date;
                                $display_date = date('F j, Y', strtotime($msg['created_at']));
                                if (date('Y-m-d') == $current_date) {
                                    $display_date = 'Today';
                                } elseif (date('Y-m-d', strtotime('-1 day')) == $current_date) {
                                    $display_date = 'Yesterday';
                                }
                        ?>
                                <div class="flex justify-center">
                                    <span class="px-3 py-1 bg-gray-200 text-gray-600 text-xs rounded-full">
                                        <?= $display_date ?>
                                    </span>
                                </div>
                        <?php endif; ?>
                        
                            <div class="flex <?= $is_own ? 'justify-end' : 'justify-start' ?>" data-message-id="<?= $msg['id'] ?>">
                                <div class="message-bubble <?= $is_own ? 'bg-blue-600 text-white' : 'bg-white text-gray-800' ?> rounded-2xl px-4 py-2 shadow-sm">
                                    <?php if (!$is_own && $msg['sender_name']): ?>
                                        <div class="text-xs font-semibold mb-1 <?= $is_own ? 'text-blue-100' : 'text-blue-600' ?>">
                                            <?= htmlspecialchars($msg['sender_name']) ?>
                                            <?php if ($msg['sender_role'] == 'admin'): ?>
                                                <span class="ml-1 px-1.5 py-0.5 bg-blue-500 text-white text-xs rounded">Admin</span>
                                            <?php elseif ($msg['sender_role'] == 'mentor'): ?>
                                                <span class="ml-1 px-1.5 py-0.5 bg-green-500 text-white text-xs rounded">Mentor</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="text-sm whitespace-pre-wrap break-words">
                                        <?= nl2br(htmlspecialchars($msg['message'])) ?>
                                    </div>
                                    
                                    <div class="flex items-center justify-end gap-1 mt-1 <?= $is_own ? 'text-blue-100' : 'text-gray-500' ?> text-xs">
                                        <span><?= date('g:i A', strtotime($msg['created_at'])) ?></span>
                                        <?php if ($is_own): ?>
                                            <span class="ml-1">
                                                <i class="fas fa-check<?= $msg['is_read'] ? '-double' : '' ?> <?= $msg['is_read'] ? 'text-blue-300' : '' ?>"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div id="typingIndicator" class="typing-indicator hidden">
                            <div class="typing-dot"></div>
                            <div class="typing-dot"></div>
                            <div class="typing-dot"></div>
                        </div>
                    </div>

                    <!-- Chat Input -->
                    <div class="bg-white border-t border-gray-200 px-6 py-4">
                        <form id="messageForm" class="flex items-end gap-2">
                            <input type="hidden" name="conversation_id" value="<?= $conversation_id ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <div class="flex-1 bg-gray-100 rounded-2xl px-4 py-2">
                                <textarea name="message" 
                                          id="messageInput"
                                          rows="1"
                                          placeholder="Type your message..."
                                          class="w-full bg-transparent border-0 focus:ring-0 resize-none max-h-32 outline-none text-sm"
                                          maxlength="2000"></textarea>
                            </div>
                            <button type="submit" 
                                    id="sendButton"
                                    disabled
                                    class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white rounded-2xl transition-colors">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Access Denied -->
                    <div class="flex-1 flex items-center justify-center">
                        <div class="text-center">
                            <div class="text-6xl mb-4 text-red-300">
                                <i class="fas fa-exclamation-circle"></i>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-800 mb-2">Access Denied</h3>
                            <p class="text-gray-600">You don't have access to this conversation.</p>
                            <a href="index.php" class="inline-block mt-4 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                Go Back
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- Welcome Screen -->
                <div class="flex-1 flex items-center justify-center">
                    <div class="text-center max-w-md px-4">
                        <div class="text-7xl mb-6 text-blue-300">
                            <i class="fas fa-comments"></i>
                        </div>
                        <h2 class="text-3xl font-bold text-gray-800 mb-3">Welcome to Chat</h2>
                        <p class="text-gray-600 mb-8">Select a conversation from the sidebar or start a new one to begin chatting.</p>
                        <button onclick="showNewChatModal()" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors inline-flex items-center gap-2">
                            <i class="fas fa-plus"></i>
                            Start New Conversation
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Chat Modal -->
    <div id="newChatModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl w-full max-w-md mx-4">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800">Start New Conversation</h3>
            </div>
            <form action="create_conversation.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="p-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Admin/Mentor</label>
                    <select name="admin_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Choose an admin...</option>
                        <?php foreach ($admins_list as $admin): ?>
                            <option value="<?= $admin['id'] ?>"><?= htmlspecialchars($admin['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-2 text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i>
                        This will create a private conversation with the selected admin.
                    </p>
                </div>
                <div class="p-6 border-t border-gray-200 flex justify-end gap-3">
                    <button type="button" onclick="hideNewChatModal()" class="px-4 py-2 text-gray-600 hover:text-gray-800 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                        Start Conversation
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Chat Info Modal -->
    <div id="chatInfoModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl w-full max-w-md mx-4">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-xl font-semibold text-gray-800">Conversation Info</h3>
            </div>
            <div class="p-6">
                <div id="modalContent" class="text-center mb-6">
                    <div class="w-20 h-20 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-2xl font-bold mx-auto mb-3" id="modalAvatar">
                        ?
                    </div>
                    <h4 class="text-lg font-semibold text-gray-800" id="modalConvName"></h4>
                    <p class="text-sm text-gray-500" id="modalConvType"></p>
                </div>
                
                <div>
                    <h5 class="font-semibold text-gray-700 mb-3">Participants</h5>
                    <div id="participantsList" class="space-y-2 max-h-60 overflow-y-auto">
                        <!-- Participants will be loaded here -->
                    </div>
                </div>
            </div>
            <div class="p-6 border-t border-gray-200 flex justify-end">
                <button onclick="hideChatInfoModal()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded-lg transition-colors">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed top-4 right-4 z-50 hidden animate-slide-in"></div>

    <script>
        // Chat State Management
        const chatState = {
            userId: <?= $user_id ?>,
            conversationId: <?= isset($conversation_id) ? $conversation_id : 'null' ?>,
            lastMessageId: <?= isset($last_message_id) ? $last_message_id : 0 ?>,
            pollingInterval: null,
            messageCache: new Set(),
            typingTimeout: null
        };

        // Initialize message cache
        <?php if (isset($messages) && !empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                chatState.messageCache.add(<?= $msg['id'] ?>);
            <?php endforeach; ?>
        <?php endif; ?>

        // DOM Elements
        const messagesContainer = document.getElementById('messagesContainer');
        const messageInput = document.getElementById('messageInput');
        const sendButton = document.getElementById('sendButton');
        const messageForm = document.getElementById('messageForm');
        const typingIndicator = document.getElementById('typingIndicator');
        const conversationsList = document.getElementById('conversationsList');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const conversationsSidebar = document.getElementById('conversationsSidebar');

        // Mobile sidebar toggle
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                conversationsSidebar.classList.toggle('-translate-x-full');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth < 768) {
                if (!conversationsSidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
                    conversationsSidebar.classList.add('-translate-x-full');
                }
            }
        });

        // Toast notification function
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                info: 'bg-blue-500'
            };
            
            toast.className = `fixed top-4 right-4 z-50 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg animate-slide-in`;
            toast.innerHTML = message;
            toast.classList.remove('hidden');
            
            setTimeout(() => {
                toast.classList.add('hidden');
            }, 5000);
        }

        // Modal functions
        function showNewChatModal() {
            document.getElementById('newChatModal').classList.remove('hidden');
        }

        function hideNewChatModal() {
            document.getElementById('newChatModal').classList.add('hidden');
        }

        function showChatInfoModal(conversationId) {
            const modal = document.getElementById('chatInfoModal');
            
            fetch(`get_conversation_info.php?conversation_id=${conversationId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalConvName').textContent = data.name;
                        document.getElementById('modalConvType').textContent = data.type;
                        document.getElementById('modalAvatar').textContent = data.initials || '?';
                        
                        const participantsList = document.getElementById('participantsList');
                        participantsList.innerHTML = '';
                        
                        data.participants.forEach(p => {
                            const div = document.createElement('div');
                            div.className = 'flex items-center gap-3 p-2 bg-gray-50 rounded-lg';
                            div.innerHTML = `
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-semibold text-sm flex-shrink-0">
                                    ${p.initials}
                                </div>
                                <div class="flex-1">
                                    <div class="font-medium text-gray-800">${p.name}</div>
                                    <div class="text-xs text-gray-500">${p.role}</div>
                                </div>
                            `;
                            participantsList.appendChild(div);
                        });
                    }
                })
                .catch(error => console.error('Error:', error));
            
            modal.classList.remove('hidden');
        }

        function hideChatInfoModal() {
            document.getElementById('chatInfoModal').classList.add('hidden');
        }

        // Load conversation
        function loadConversation(conversationId) {
            window.location.href = `index.php?conversation_id=${conversationId}`;
        }

        // Auto-resize textarea
        function autoResizeTextarea(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 128) + 'px';
        }

        // Scroll to bottom
        function scrollToBottom(smooth = true) {
            if (messagesContainer) {
                messagesContainer.scrollTo({
                    top: messagesContainer.scrollHeight,
                    behavior: smooth ? 'smooth' : 'auto'
                });
            }
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Fetch new messages
        async function fetchNewMessages() {
            if (!chatState.conversationId) return;

            try {
                const response = await fetch(`get_messages.php?conversation_id=${chatState.conversationId}&last_id=${chatState.lastMessageId}`);
                const data = await response.json();

                if (data.success && data.messages && data.messages.length > 0) {
                    let lastDate = '';
                    const dateDividers = messagesContainer.querySelectorAll('.flex.justify-center span');
                    if (dateDividers.length > 0) {
                        lastDate = dateDividers[dateDividers.length - 1].textContent;
                    }

                    data.messages.forEach(msg => {
                        if (!chatState.messageCache.has(parseInt(msg.id))) {
                            chatState.messageCache.add(parseInt(msg.id));
                            
                            const msgDate = new Date(msg.created_at || msg.sent_at);
                            let displayDate = '';
                            const today = new Date();
                            const yesterday = new Date(today);
                            yesterday.setDate(yesterday.getDate() - 1);

                            if (msgDate.toDateString() === today.toDateString()) {
                                displayDate = 'Today';
                            } else if (msgDate.toDateString() === yesterday.toDateString()) {
                                displayDate = 'Yesterday';
                            } else {
                                displayDate = msgDate.toLocaleDateString('en-US', { 
                                    month: 'long', 
                                    day: 'numeric', 
                                    year: 'numeric' 
                                });
                            }

                            if (displayDate !== lastDate) {
                                lastDate = displayDate;
                                const divider = document.createElement('div');
                                divider.className = 'flex justify-center';
                                divider.innerHTML = `<span class="px-3 py-1 bg-gray-200 text-gray-600 text-xs rounded-full">${displayDate}</span>`;
                                messagesContainer.appendChild(divider);
                            }

                            const isOwn = parseInt(msg.sender_id) === chatState.userId;
                            const messageDiv = document.createElement('div');
                            messageDiv.className = `flex ${isOwn ? 'justify-end' : 'justify-start'}`;
                            messageDiv.dataset.messageId = msg.id;

                            let senderHtml = '';
                            if (!isOwn && msg.sender_name) {
                                senderHtml = `
                                    <div class="text-xs font-semibold mb-1 text-blue-600">
                                        ${escapeHtml(msg.sender_name)}
                                        ${msg.sender_role === 'admin' ? '<span class="ml-1 px-1.5 py-0.5 bg-blue-500 text-white text-xs rounded">Admin</span>' : ''}
                                        ${msg.sender_role === 'mentor' ? '<span class="ml-1 px-1.5 py-0.5 bg-green-500 text-white text-xs rounded">Mentor</span>' : ''}
                                    </div>
                                `;
                            }

                            messageDiv.innerHTML = `
                                <div class="message-bubble ${isOwn ? 'bg-blue-600 text-white' : 'bg-white text-gray-800'} rounded-2xl px-4 py-2 shadow-sm">
                                    ${senderHtml}
                                    <div class="text-sm whitespace-pre-wrap break-words">${escapeHtml(msg.message).replace(/\n/g, '<br>')}</div>
                                    <div class="flex items-center justify-end gap-1 mt-1 ${isOwn ? 'text-blue-100' : 'text-gray-500'} text-xs">
                                        <span>${msgDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span>
                                        ${isOwn ? `<span class="ml-1"><i class="fas fa-check${msg.is_read ? '-double text-blue-300' : ''}"></i></span>` : ''}
                                    </div>
                                </div>
                            `;

                            messagesContainer.appendChild(messageDiv);

                            if (msg.id > chatState.lastMessageId) {
                                chatState.lastMessageId = parseInt(msg.id);
                            }
                        }
                    });

                    scrollToBottom();
                }
            } catch (error) {
                console.error('Error fetching messages:', error);
            }
        }

        // Start polling
        function startPolling() {
            if (chatState.pollingInterval) {
                clearInterval(chatState.pollingInterval);
            }
            chatState.pollingInterval = setInterval(fetchNewMessages, 3000);
        }

        // Stop polling
        function stopPolling() {
            if (chatState.pollingInterval) {
                clearInterval(chatState.pollingInterval);
                chatState.pollingInterval = null;
            }
        }

        // Initialize chat
        if (messagesContainer && chatState.conversationId) {
            startPolling();
            setTimeout(scrollToBottom, 100);
        }

        // Message input handling
        if (messageInput) {
            messageInput.addEventListener('input', function() {
                autoResizeTextarea(this);
                if (sendButton) {
                    sendButton.disabled = this.value.trim() === '';
                }
            });

            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    messageForm?.dispatchEvent(new Event('submit'));
                }
            });
        }

        // Message form submission
        if (messageForm) {
            messageForm.addEventListener('submit', async function(e) {
                e.preventDefault();

                const message = messageInput?.value.trim();
                if (!message) return;

                const formData = new FormData(this);

                messageInput.disabled = true;
                sendButton.disabled = true;

                try {
                    const response = await fetch('send_message.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        messageInput.value = '';
                        messageInput.style.height = 'auto';
                        messageInput.disabled = false;
                        sendButton.disabled = true;
                        messageInput.focus();

                        // Add message to UI immediately
                        const now = new Date();
                        let hasTodayDivider = false;
                        const dividers = messagesContainer.querySelectorAll('.flex.justify-center span');
                        dividers.forEach(div => {
                            if (div.textContent.includes('Today')) {
                                hasTodayDivider = true;
                            }
                        });

                        if (!hasTodayDivider && messagesContainer.children.length > 0) {
                            const divider = document.createElement('div');
                            divider.className = 'flex justify-center';
                            divider.innerHTML = '<span class="px-3 py-1 bg-gray-200 text-gray-600 text-xs rounded-full">Today</span>';
                            messagesContainer.appendChild(divider);
                        }

                        const messageDiv = document.createElement('div');
                        messageDiv.className = 'flex justify-end';
                        messageDiv.dataset.messageId = data.message.id;

                        messageDiv.innerHTML = `
                            <div class="message-bubble bg-blue-600 text-white rounded-2xl px-4 py-2 shadow-sm">
                                <div class="text-sm whitespace-pre-wrap break-words">${escapeHtml(message).replace(/\n/g, '<br>')}</div>
                                <div class="flex items-center justify-end gap-1 mt-1 text-blue-100 text-xs">
                                    <span>Just now</span>
                                    <span class="ml-1"><i class="fas fa-check"></i></span>
                                </div>
                            </div>
                        `;

                        messagesContainer.appendChild(messageDiv);

                        chatState.messageCache.add(parseInt(data.message.id));
                        chatState.lastMessageId = Math.max(chatState.lastMessageId, parseInt(data.message.id));

                        scrollToBottom();
                    } else {
                        showToast(data.error || 'Failed to send message', 'error');
                        messageInput.disabled = false;
                        sendButton.disabled = false;
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showToast('Network error. Please try again.', 'error');
                    messageInput.disabled = false;
                    sendButton.disabled = false;
                }
            });
        }

        // Session messages
        <?php if (isset($_SESSION['success'])): ?>
            showToast('<?= addslashes($_SESSION['success']) ?>', 'success');
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            showToast('<?= addslashes($_SESSION['error']) ?>', 'error');
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            stopPolling();
        });

        // Close modals when clicking outside
        window.addEventListener('click', function(e) {
            const newChatModal = document.getElementById('newChatModal');
            const chatInfoModal = document.getElementById('chatInfoModal');
            
            if (e.target === newChatModal) {
                hideNewChatModal();
            }
            if (e.target === chatInfoModal) {
                hideChatInfoModal();
            }
        });
    </script>
</body>
</html>