<?php
/**
 * Chat Sidebar Component
 * Displays the list of conversations for the admin
 */

// Ensure this file is included, not accessed directly
if (!defined('CHAT_SIDEBAR_LOADED') && isset($db) && isset($user_id)) {
    
    // Fetch conversations if not already available
    if (!isset($conversations) || empty($conversations)) {
        try {
            // Get all conversations where user is a member
            $stmt = $db->prepare("
                SELECT DISTINCT 
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
                ORDER BY 
                    CASE 
                        WHEN (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) IS NULL 
                        THEN 1 
                        ELSE 0 
                    END,
                    (SELECT created_at FROM messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) DESC
            ");
            
            $stmt->execute([$user_id, $user_id]);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get other participants details for each conversation
            foreach ($conversations as &$conv) {
                // Get all participants except current user
                $stmt2 = $db->prepare("
                    SELECT u.id, u.name, u.role,
                           s.batch_name, s.batch_name_2, s.batch_name_3, s.batch_name_4
                    FROM users u
                    JOIN conversation_members cm ON u.id = cm.user_id
                    LEFT JOIN students s ON u.id = s.user_id
                    WHERE cm.conversation_id = ? AND cm.user_id != ?
                    ORDER BY u.role DESC, u.name ASC
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
                    } else {
                        $conv['display_name'] = $conv['name'] ?? 'Group Chat';
                    }
                }
            }
            
            // Sort conversations by last message time (most recent first)
            usort($conversations, function($a, $b) {
                $timeA = isset($a['last_message_time']) ? strtotime($a['last_message_time']) : 0;
                $timeB = isset($b['last_message_time']) ? strtotime($b['last_message_time']) : 0;
                return $timeB - $timeA;
            });
            
        } catch (PDOException $e) {
            error_log("Error fetching conversations in sidebar: " . $e->getMessage());
            $conversations = [];
        }
    }
}
?>

<!-- Left Sidebar - Conversations List -->
<div class="w-1/3 border-r bg-gray-50 flex flex-col h-full">
    <div class="p-4 border-b bg-white">
        <h2 class="text-xl font-semibold text-gray-800">Chats</h2>
        <p class="text-xs text-gray-500 mt-1">IST: <span id="sidebarTime"><?php echo date('Y-m-d H:i:s'); ?></span></p>
        
        <!-- New Chat Button -->
        <button onclick="openNewChatModal()" class="mt-2 w-full bg-blue-600 text-white py-2 px-4 rounded-lg hover:bg-blue-700 transition flex items-center justify-center space-x-2">
            <i class="fas fa-plus"></i>
            <span>New Chat</span>
        </button>
        
        <!-- Search Conversations -->
        <div class="mt-3 relative">
            <input type="text" 
                   id="searchConversations" 
                   placeholder="Search conversations..." 
                   class="w-full pl-10 pr-4 py-2 border rounded-lg focus:outline-none focus:border-blue-500">
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
        </div>
    </div>
    
    <!-- Conversations List -->
    <div id="conversationsList" class="flex-1 overflow-y-auto">
        <?php if (empty($conversations)): ?>
        <div class="p-4 text-center text-gray-500">
            <i class="fas fa-comments text-3xl mb-2"></i>
            <p>No conversations yet</p>
            <p class="text-sm mt-2">Click "New Chat" to start one</p>
        </div>
        <?php else: ?>
            <?php 
            // Track displayed conversation IDs to prevent duplicates
            $displayed_ids = [];
            foreach ($conversations as $conv): 
                // Skip if already displayed
                if (in_array($conv['id'], $displayed_ids)) {
                    continue;
                }
                $displayed_ids[] = $conv['id'];
                
                // Determine display name based on conversation type
                $display_name = 'Unknown';
                if ($conv['type'] === 'one_to_one') {
                    if (isset($conv['other_user'])) {
                        $display_name = $conv['other_user']['name'];
                    } else {
                        $display_name = 'Deleted User';
                    }
                } elseif ($conv['type'] === 'group') {
                    $display_name = $conv['name'] ?? 'Group Chat';
                    if (!empty($conv['batch_name'])) {
                        $display_name = 'Batch: ' . $conv['batch_name'];
                    }
                }
                
                // Get initials for avatar
                $initial = '?';
                if (!empty($display_name) && $display_name !== 'Unknown' && $display_name !== 'Deleted User') {
                    $words = explode(' ', $display_name);
                    if (count($words) >= 2) {
                        $initial = strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
                    } else {
                        $initial = strtoupper(substr($display_name, 0, 2));
                    }
                }
                
                // Format last message time
                $last_time = '';
                if (!empty($conv['last_message_time'])) {
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
                
                // Count other participants
                $other_count = isset($conv['other_participants']) ? count($conv['other_participants']) : 0;
                
                // Get avatar color based on conversation type
                $avatarColor = $conv['type'] === 'group' ? 'bg-purple-500' : 'bg-blue-500';
            ?>
            <div class="conversation-item p-3 border-b hover:bg-gray-100 cursor-pointer transition relative <?= ($conv['unread_count'] ?? 0) > 0 ? 'bg-blue-50' : '' ?>" 
                 onclick="loadConversation(<?= $conv['id'] ?>, this)"
                 data-conversation-id="<?= $conv['id'] ?>"
                 data-conversation-name="<?= htmlspecialchars($display_name) ?>"
                 data-conversation-type="<?= $conv['type'] ?>">
                
                <div class="flex items-center justify-between">
                    <!-- Left side - Avatar and info (clickable) -->
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <!-- Avatar -->
                        <div class="relative">
                            <div class="w-12 h-12 rounded-full <?= $avatarColor ?> flex items-center justify-center text-white font-bold text-lg">
                                <?= htmlspecialchars($initial) ?>
                            </div>
                            <?php if ($conv['type'] === 'one_to_one'): ?>
                            <span class="status-dot online absolute bottom-0 right-0 border-2 border-white"></span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Conversation Info -->
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-baseline">
                                <h3 class="font-semibold truncate">
                                    <?= htmlspecialchars($display_name) ?>
                                    <?php if ($conv['type'] === 'group'): ?>
                                        <span class="text-xs bg-gray-200 px-1 rounded ml-1">Group</span>
                                    <?php endif; ?>
                                </h3>
                                <?php if (!empty($last_time)): ?>
                                <span class="text-xs text-gray-500 whitespace-nowrap ml-2">
                                    <?= $last_time ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-gray-600 truncate">
                                <?= htmlspecialchars($conv['last_message'] ?? 'No messages yet') ?>
                            </p>
                            <div class="flex items-center mt-1">
                                <?php if ($other_count > 0): ?>
                                    <span class="text-xs text-gray-500">
                                        <i class="fas fa-users mr-1"></i><?= $other_count ?> participant<?= $other_count > 1 ? 's' : '' ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (($conv['unread_count'] ?? 0) > 0): ?>
                                    <span class="ml-2 inline-flex items-center bg-blue-600 text-white text-xs px-2 py-1 rounded-full">
                                        <?= $conv['unread_count'] ?> new
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right side - Delete button (stops propagation) -->
                    <button onclick="event.stopPropagation(); deleteConversation(<?= $conv['id'] ?>, '<?= htmlspecialchars(addslashes($display_name)) ?>')"
                            class="delete-conversation-btn text-gray-400 hover:text-red-600 transition p-2 rounded-full hover:bg-red-50 ml-2"
                            title="Delete Conversation">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Update sidebar time every second
function updateSidebarTime() {
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
    const sidebarTime = document.getElementById('sidebarTime');
    if (sidebarTime) {
        sidebarTime.textContent = istTime;
    }
}
setInterval(updateSidebarTime, 1000);

// Function to refresh conversations list
function refreshConversationsList() {
    fetch('get_conversations.php')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.conversations) {
                updateConversationsList(data.conversations);
            }
        })
        .catch(error => console.error('Error refreshing conversations:', error));
}

// Function to update conversations list HTML
function updateConversationsList(conversations) {
    const listElement = document.getElementById('conversationsList');
    if (!listElement) return;
    
    if (!conversations || conversations.length === 0) {
        listElement.innerHTML = `
            <div class="p-4 text-center text-gray-500">
                <i class="fas fa-comments text-3xl mb-2"></i>
                <p>No conversations yet</p>
                <p class="text-sm mt-2">Click "New Chat" to start one</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    const displayedIds = [];
    
    conversations.forEach(conv => {
        if (displayedIds.includes(conv.id)) return;
        displayedIds.push(conv.id);
        
        // Determine display name
        let displayName = 'Unknown';
        if (conv.type === 'one_to_one') {
            if (conv.other_user) {
                displayName = conv.other_user.name;
            } else {
                displayName = 'Deleted User';
            }
        } else if (conv.type === 'group') {
            displayName = conv.name || 'Group Chat';
            if (conv.batch_name) {
                displayName = 'Batch: ' + conv.batch_name;
            }
        }
        
        // Get initials
        let initial = '?';
        if (displayName && displayName !== 'Unknown' && displayName !== 'Deleted User') {
            const words = displayName.split(' ');
            if (words.length >= 2) {
                initial = (words[0][0] + words[1][0]).toUpperCase();
            } else {
                initial = displayName.substring(0, 2).toUpperCase();
            }
        }
        
        // Format last message time
        let lastTime = '';
        if (conv.last_message_time) {
            const time = new Date(conv.last_message_time).getTime();
            const today = new Date().setHours(0,0,0,0);
            const yesterday = new Date(today - 86400000).setHours(0,0,0,0);
            
            if (time >= today) {
                lastTime = new Date(conv.last_message_time).toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
            } else if (time >= yesterday) {
                lastTime = 'Yesterday';
            } else {
                lastTime = new Date(conv.last_message_time).toLocaleDateString('en-US', {month: 'short', day: 'numeric'});
            }
        }
        
        const otherCount = conv.other_participants ? conv.other_participants.length : 0;
        const avatarColor = conv.type === 'group' ? 'bg-purple-500' : 'bg-blue-500';
        const unreadClass = conv.unread_count > 0 ? 'bg-blue-50' : '';
        
        html += `
            <div class="conversation-item p-3 border-b hover:bg-gray-100 cursor-pointer transition relative ${unreadClass}" 
                 onclick="loadConversation(${conv.id}, this)"
                 data-conversation-id="${conv.id}"
                 data-conversation-name="${escapeHtml(displayName)}"
                 data-conversation-type="${conv.type}">
                
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <div class="relative">
                            <div class="w-12 h-12 rounded-full ${avatarColor} flex items-center justify-center text-white font-bold text-lg">
                                ${escapeHtml(initial)}
                            </div>
                            ${conv.type === 'one_to_one' ? '<span class="status-dot online absolute bottom-0 right-0 border-2 border-white"></span>' : ''}
                        </div>
                        
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-baseline">
                                <h3 class="font-semibold truncate">
                                    ${escapeHtml(displayName)}
                                    ${conv.type === 'group' ? '<span class="text-xs bg-gray-200 px-1 rounded ml-1">Group</span>' : ''}
                                </h3>
                                ${lastTime ? '<span class="text-xs text-gray-500 whitespace-nowrap ml-2">' + escapeHtml(lastTime) + '</span>' : ''}
                            </div>
                            <p class="text-sm text-gray-600 truncate">
                                ${escapeHtml(conv.last_message || 'No messages yet')}
                            </p>
                            <div class="flex items-center mt-1">
                                ${otherCount > 0 ? '<span class="text-xs text-gray-500"><i class="fas fa-users mr-1"></i>' + otherCount + ' participant' + (otherCount > 1 ? 's' : '') + '</span>' : ''}
                                ${conv.unread_count > 0 ? '<span class="ml-2 inline-flex items-center bg-blue-600 text-white text-xs px-2 py-1 rounded-full">' + conv.unread_count + ' new</span>' : ''}
                            </div>
                        </div>
                    </div>
                    
                    <button onclick="event.stopPropagation(); deleteConversation(${conv.id}, '${escapeHtml(displayName.replace(/'/g, "\\'"))}')"
                            class="delete-conversation-btn text-gray-400 hover:text-red-600 transition p-2 rounded-full hover:bg-red-50 ml-2"
                            title="Delete Conversation">
                        <i class="fas fa-trash-alt"></i>
                    </button>
                </div>
            </div>
        `;
    });
    
    listElement.innerHTML = html;
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>