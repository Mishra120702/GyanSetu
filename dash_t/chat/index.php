<?php
require_once '../db_connection.php';
require_once 'chat_functions.php';

// Check trainer login
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../log2.php");
    exit;
}
$user_id = $_SESSION['user_id'];

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle new conversation creation
if (isset($_POST['create_conversations'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Invalid CSRF token';
        header("Location: index.php");
        exit;
    }

    $success_count = 0;
    $error_messages = [];
    $created_conversation_id = null;

    if (isset($_POST['admin_ids'])) {
        foreach ($_POST['admin_ids'] as $admin_id) {
            $admin_id = intval($admin_id);
            try {
                // Check if conversation already exists
                $existing = $db->query("SELECT id FROM chat_conversations 
                                      WHERE conversation_type = 'admin_student' 
                                      AND admin_id = $admin_id 
                                      AND student_id IS NULL 
                                      AND batch_id IS NULL")->fetch();
                
                if ($existing) {
                    $created_conversation_id = $existing['id'];
                } else {
                    // Create new conversation
                    $db->query("INSERT INTO chat_conversations 
                              (conversation_type, admin_id, created_at, updated_at) 
                              VALUES 
                              ('admin_student', $admin_id, NOW(), NOW())");
                    $created_conversation_id = $db->lastInsertId();
                    
                    // Add participants
                    $db->query("INSERT INTO chat_participants (conversation_id, user_id) 
                              VALUES ($created_conversation_id, $user_id)");
                    $db->query("INSERT INTO chat_participants (conversation_id, user_id) 
                              VALUES ($created_conversation_id, $admin_id)");
                }
                
                if ($created_conversation_id) {
                    $success_count++;
                }
            } catch (PDOException $e) {
                $error_messages[] = "Failed to create conversation for admin ID: $admin_id";
            }
        }
    }
    
    if (isset($_POST['batch_ids'])) {
        foreach ($_POST['batch_ids'] as $batch_id) {
            $batch_id = $db->quote($batch_id);
            try {
                // Check if conversation already exists
                $existing = $db->query("SELECT id FROM chat_conversations 
                                      WHERE conversation_type = 'admin_batch' 
                                      AND batch_id = $batch_id")->fetch();
                
                if ($existing) {
                    $created_conversation_id = $existing['id'];
                } else {
                    // Create new conversation
                    $db->query("INSERT INTO chat_conversations 
                              (conversation_type, batch_id, created_at, updated_at) 
                              VALUES 
                              ('admin_batch', $batch_id, NOW(), NOW())");
                    $created_conversation_id = $db->lastInsertId();
                    
                    // Add trainer as participant
                    $db->query("INSERT INTO chat_participants (conversation_id, user_id) 
                              VALUES ($created_conversation_id, $user_id)");
                    
                    // Add all students in the batch as participants
                    $students = $db->query("SELECT s.user_id FROM students s 
                                          WHERE s.batch_name = $batch_id")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($students as $student_user_id) {
                        if ($student_user_id) {
                            $db->query("INSERT INTO chat_participants (conversation_id, user_id) 
                                        VALUES ($created_conversation_id, $student_user_id)");
                        }
                    }
                    
                    // Add admin as participant
                    $admin_id = 1; // Default admin ID
                    $db->query("INSERT INTO chat_participants (conversation_id, user_id) 
                              VALUES ($created_conversation_id, $admin_id)");
                }
                
                if ($created_conversation_id) {
                    $success_count++;
                }
            } catch (PDOException $e) {
                $error_messages[] = "Failed to create conversation for batch ID: $batch_id";
            }
        }
    }
    
    if ($success_count > 0) {
        $_SESSION['success'] = "Successfully created $success_count conversation(s)";
        // Redirect to the first created conversation
        if ($created_conversation_id) {
            header("Location: index.php?conversation=" . $created_conversation_id);
            exit;
        }
    }
    if (!empty($error_messages)) {
        $_SESSION['error'] = implode("<br>", $error_messages);
    }
    
    header("Location: index.php");
    exit;
}

// Handle message sending
if (isset($_POST['send_message']) && isset($_POST['conversation_id'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Invalid CSRF token';
        header("Location: index.php?conversation=" . $_POST['conversation_id']);
        exit;
    }
    
    $conversation_id = intval($_POST['conversation_id']);
    $message = trim($_POST['message']);
    
    // Verify user is a participant in this conversation
    $query = "SELECT 1 FROM chat_participants 
              WHERE conversation_id = ? AND user_id = ? AND is_active = TRUE";
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($message)) {
        // Insert message first
        $query = "INSERT INTO chat_messages (conversation_id, sender_id, message) 
                  VALUES (?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$conversation_id, $user_id, $message]);
        $message_id = $db->lastInsertId();
        
        // Handle file upload if any
        $has_attachments = 0;
        if (!empty($_FILES['attachments']['name'][0])) {
            $has_attachments = handleFileUpload($message_id);
            
            // Update message to indicate it has attachments
            if ($has_attachments) {
                $query = "UPDATE chat_messages SET has_attachments = 1 WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$message_id]);
            }
        }
        
        // Update conversation timestamp
        $query = "UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$conversation_id]);
        
        $_SESSION['success'] = "Message sent successfully";
    } else {
        $_SESSION['error'] = "Failed to send message";
    }
    
    header("Location: index.php?conversation=" . $conversation_id);
    exit;
}

// Handle conversation deletion/leaving
if (isset($_GET['delete_conversation'])) {
    $conversation_id = intval($_GET['delete_conversation']);
    
    try {
        // Instead of deleting, mark user as inactive in the conversation
        $query = "UPDATE chat_participants SET is_active = FALSE 
                  WHERE conversation_id = ? AND user_id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$conversation_id, $user_id]);
        
        $_SESSION['success'] = "Conversation removed successfully";
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }
    
    header("Location: index.php");
    exit;
}

// Handle message deletion
if (isset($_GET['delete_message'])) {
    $message_id = intval($_GET['delete_message']);
    
    // Verify user owns this message
    $query = "SELECT conversation_id FROM chat_messages WHERE id = ? AND sender_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$message_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $query = "DELETE FROM chat_messages WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$message_id]);
        
        $_SESSION['success'] = "Message deleted successfully";
        header("Location: index.php?conversation=" . $result['conversation_id']);
    } else {
        $_SESSION['error'] = "You can only delete your own messages";
        header("Location: index.php");
    }
    exit;
}

// Get active conversation if specified
$active_conversation_id = isset($_GET['conversation']) ? intval($_GET['conversation']) : null;
$active_conversation = null;
$active_messages = [];
$participants = [];

if ($active_conversation_id) {
    // Verify user is a participant in this conversation
    $query = "SELECT 1 FROM chat_participants 
              WHERE conversation_id = ? AND user_id = ? AND is_active = TRUE";
    $stmt = $db->prepare($query);
    $stmt->execute([$active_conversation_id, $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $active_conversation = getConversationName($active_conversation_id);
        $active_messages = getConversationMessages($active_conversation_id);
        markMessagesAsRead($active_conversation_id, $user_id);
        
        // Get participants for this conversation
        $participants = getConversationParticipants($active_conversation_id);
    } else {
        $_SESSION['error'] = "You don't have access to this conversation";
        $active_conversation_id = null;
    }
}

// Get conversations for this user
$conversations = getUserConversations($user_id);

// Get available admins and batches for creating new conversations
$admins = $db->query("SELECT id, name FROM users WHERE role = 'admin' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
$batches = $db->query("SELECT batch_id, course_name FROM batches WHERE status = 'ongoing' OR status = 'upcoming'")->fetchAll(PDO::FETCH_ASSOC);

// Function to handle file upload
function handleFileUpload($message_id) {
    global $db;
    
    $upload_dir = "../uploads/chat_attachments/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $has_attachments = 0;
    
    foreach ($_FILES['attachments']['name'] as $key => $name) {
        if ($_FILES['attachments']['error'][$key] === UPLOAD_ERR_OK) {
            $tmp_name = $_FILES['attachments']['tmp_name'][$key];
            $file_size = $_FILES['attachments']['size'][$key];
            $file_type = $_FILES['attachments']['type'][$key];
            
            // Generate unique filename
            $file_ext = pathinfo($name, PATHINFO_EXTENSION);
            $unique_name = uniqid() . '_' . time() . '.' . $file_ext;
            $file_path = $upload_dir . $unique_name;
            
            if (move_uploaded_file($tmp_name, $file_path)) {
                // Insert into chat_attachments table
                $query = "INSERT INTO chat_attachments (message_id, file_name, file_path, file_size, file_type) 
                          VALUES (?, ?, ?, ?, ?)";
                $stmt = $db->prepare($query);
                $stmt->execute([$message_id, $name, $file_path, $file_size, $file_type]);
                
                $has_attachments = 1;
            }
        }
    }
    
    return $has_attachments;
}

// Function to get conversation participants
function getConversationParticipants($conversation_id) {
    global $db;
    
    $query = "SELECT cp.user_id, u.name, u.role, 
                     CASE 
                         WHEN s.student_id IS NOT NULL THEN CONCAT(s.first_name, ' ', s.last_name)
                         ELSE u.name
                     END as display_name
              FROM chat_participants cp
              JOIN users u ON cp.user_id = u.id
              LEFT JOIN students s ON u.id = s.user_id
              WHERE cp.conversation_id = ? AND cp.is_active = TRUE
              ORDER BY u.role DESC, display_name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$conversation_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to get user conversations
function getUserConversations($user_id) {
    global $db;
    
    $query = "SELECT cc.id, cc.conversation_type, 
                     CASE 
                         WHEN cc.conversation_type = 'admin_student' AND cc.student_id IS NULL THEN u.name
                         WHEN cc.conversation_type = 'admin_batch' THEN CONCAT('Batch: ', b.batch_id, ' - ', b.course_name)
                         ELSE 'Unknown Conversation'
                     END as name,
                     (SELECT COUNT(*) FROM chat_messages cm 
                      WHERE cm.conversation_id = cc.id AND cm.is_read = 0 
                      AND cm.sender_id != ?) as unread
              FROM chat_conversations cc
              LEFT JOIN users u ON cc.admin_id = u.id
              LEFT JOIN batches b ON cc.batch_id = b.batch_id
              WHERE cc.id IN (SELECT conversation_id FROM chat_participants WHERE user_id = ? AND is_active = TRUE)
              ORDER BY cc.updated_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$user_id, $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

include 'header.php';
?>
<?php include '../t_sidebar.php'; ?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar with conversations -->
        <div class="col-md-3 bg-light border-right p-0" style="height: calc(100vh - 80px); overflow-y: auto;">
            <div class="p-3 border-bottom">
                <h5>Conversations</h5>
                <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#newConversationModal">
                    <i class="fas fa-plus"></i> New Conversation
                </button>
            </div>
            
            <div class="list-group list-group-flush">
                <?php if (empty($conversations)): ?>
                    <div class="p-3 text-center text-muted">
                        No conversations yet. Start a new conversation!
                    </div>
                <?php else: ?>
                    <?php foreach ($conversations as $conv): 
                        $initials = '';
                        $bg_color = 'bg-primary';
                        
                        if ($conv['conversation_type'] == 'admin_student') {
                            $nameParts = explode(' ', $conv['name']);
                            $initials = strtoupper(
                                substr($nameParts[0], 0, 1) . 
                                (count($nameParts) > 1 ? substr(end($nameParts), 0, 1) : '')
                            );
                            $bg_color = 'bg-info';
                        } else {
                            $initials = 'B';
                            $bg_color = 'bg-success';
                        }
                    ?>
                        <a href="index.php?conversation=<?= $conv['id'] ?>" 
                           class="list-group-item list-group-item-action d-flex align-items-center 
                                  <?= $active_conversation_id == $conv['id'] ? 'active' : '' ?>">
                            <div class="rounded-circle d-flex align-items-center justify-content-center 
                                        <?= $bg_color ?> text-white mr-3" 
                                 style="width: 40px; height: 40px; font-size: 14px;">
                                <?= $initials ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0"><?= htmlspecialchars($conv['name']) ?></h6>
                                    <?php if ($conv['unread'] > 0): ?>
                                        <span class="badge badge-danger"><?= $conv['unread'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted">
                                    <?= getLastMessagePreview($conv['id']) ?: 'No messages yet' ?>
                                </small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Main chat area -->
        <div class="col-md-9 p-0 d-flex flex-column" style="height: calc(100vh - 80px);">
            <?php if ($active_conversation_id): ?>
                <!-- Chat header -->
                <div class="border-bottom p-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0"><?= htmlspecialchars($active_conversation) ?></h5>
                        <small class="text-muted">
                            <?= count($participants) ?> participant<?= count($participants) !== 1 ? 's' : '' ?>
                        </small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-info mr-2" data-toggle="modal" data-target="#participantsModal">
                            <i class="fas fa-users"></i> Participants
                        </button>
                        <a href="index.php?delete_conversation=<?= $active_conversation_id ?>" 
                           class="btn btn-sm btn-outline-danger"
                           onclick="return confirm('Are you sure you want to leave this conversation?')">
                            <i class="fas fa-sign-out-alt"></i> Leave
                        </a>
                    </div>
                </div>

                <!-- Messages area -->
                <div class="flex-grow-1 p-3" style="overflow-y: auto;" id="messages-container">
                    <?php if (empty($active_messages)): ?>
                        <div class="text-center text-muted mt-5">
                            <i class="fas fa-comments fa-3x mb-3"></i>
                            <p>No messages yet. Start the conversation!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($active_messages as $message): 
                            $is_own = $message['sender_id'] == $user_id;
                            $time = date('M j, g:i A', strtotime($message['sent_at']));
                        ?>
                            <div class="d-flex mb-3 <?= $is_own ? 'justify-content-end' : 'justify-content-start' ?>">
                                <div class="max-w-75">
                                    <?php if (!$is_own): ?>
                                        <small class="text-muted"><?= htmlspecialchars($message['sender_name']) ?></small>
                                    <?php endif; ?>
                                    
                                    <div class="card <?= $is_own ? 'bg-primary text-white' : 'bg-light' ?>">
                                        <div class="card-body p-2">
                                            <p class="card-text mb-1"><?= nl2br(htmlspecialchars($message['message'])) ?></p>
                                            
                                            <?php if ($message['has_attachments']): ?>
                                                <?php 
                                                $attachments = $db->query("SELECT * FROM chat_attachments WHERE message_id = " . $message['id'])->fetchAll(PDO::FETCH_ASSOC);
                                                foreach ($attachments as $attachment): 
                                                ?>
                                                    <div class="mt-2">
                                                        <a href="<?= $attachment['file_path'] ?>" 
                                                           target="_blank" 
                                                           class="<?= $is_own ? 'text-light' : 'text-primary' ?>">
                                                            <i class="fas fa-paperclip"></i> 
                                                            <?= htmlspecialchars($attachment['file_name']) ?>
                                                            <small>(<?= formatFileSize($attachment['file_size']) ?>)</small>
                                                        </a>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <small class="text-muted"><?= $time ?></small>
                                        
                                        <?php if ($is_own): ?>
                                            <a href="index.php?delete_message=<?= $message['id'] ?>" 
                                               class="text-danger ml-2"
                                               onclick="return confirm('Delete this message?')">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Message input -->
                <div class="border-top p-3">
                    <form method="POST" enctype="multipart/form-data" id="message-form">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="conversation_id" value="<?= $active_conversation_id ?>">
                        
                        <div class="form-group mb-2">
                            <textarea class="form-control" name="message" rows="2" 
                                      placeholder="Type your message..." required></textarea>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <label for="attachments" class="btn btn-sm btn-outline-secondary mb-0">
                                    <i class="fas fa-paperclip"></i> Attach Files
                                </label>
                                <input type="file" id="attachments" name="attachments[]" 
                                       multiple style="display: none;" 
                                       onchange="previewAttachments(this)">
                                <div id="attachment-preview" class="d-none mt-2">
                                    <small class="text-muted">Selected files: </small>
                                    <span id="file-names"></span>
                                </div>
                            </div>
                            
                            <button type="submit" name="send_message" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Send
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Welcome screen when no conversation is selected -->
                <div class="d-flex align-items-center justify-content-center h-100">
                    <div class="text-center text-muted">
                        <i class="fas fa-comments fa-4x mb-3"></i>
                        <h4>Welcome to Chat</h4>
                        <p>Select a conversation from the sidebar or start a new one</p>
                        <button class="btn btn-primary mt-2" data-toggle="modal" data-target="#newConversationModal">
                            <i class="fas fa-plus"></i> New Conversation
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Conversation Modal -->
<div class="modal fade" id="newConversationModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Start New Conversation</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Select Admins</label>
                        <?php foreach ($admins as $admin): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" 
                                       name="admin_ids[]" value="<?= $admin['id'] ?>" 
                                       id="admin_<?= $admin['id'] ?>">
                                <label class="form-check-label" for="admin_<?= $admin['id'] ?>">
                                    <?= htmlspecialchars($admin['name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="form-group mt-3">
                        <label>Select Batches</label>
                        <?php foreach ($batches as $batch): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" 
                                       name="batch_ids[]" value="<?= $batch['batch_id'] ?>" 
                                       id="batch_<?= $batch['batch_id'] ?>">
                                <label class="form-check-label" for="batch_<?= $batch['batch_id'] ?>">
                                    <?= htmlspecialchars($batch['batch_id']) ?> - <?= htmlspecialchars($batch['course_name']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="create_conversations" class="btn btn-primary">Create Conversations</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Participants Modal -->
<?php if ($active_conversation_id): ?>
<div class="modal fade" id="participantsModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Conversation Participants</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <ul class="list-group">
                    <?php foreach ($participants as $participant): 
                        $role_class = '';
                        if ($participant['role'] === 'admin') $role_class = 'text-primary';
                        if ($participant['role'] === 'trainer') $role_class = 'text-success';
                    ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <?= htmlspecialchars($participant['display_name']) ?>
                            <span class="badge badge-pill <?= $role_class ?>">
                                <?= ucfirst($participant['role']) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Scroll to bottom of messages
function scrollToBottom() {
    const container = document.getElementById('messages-container');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
}

// Preview selected attachments
function previewAttachments(input) {
    const preview = document.getElementById('attachment-preview');
    const fileNames = document.getElementById('file-names');
    
    if (input.files.length > 0) {
        let names = '';
        for (let i = 0; i < input.files.length; i++) {
            names += input.files[i].name;
            if (i < input.files.length - 1) names += ', ';
        }
        fileNames.textContent = names;
        preview.classList.remove('d-none');
    } else {
        preview.classList.add('d-none');
    }
}

// Auto-scroll to bottom when page loads
document.addEventListener('DOMContentLoaded', function() {
    scrollToBottom();
    
    // Auto-focus message textarea
    const textarea = document.querySelector('textarea[name="message"]');
    if (textarea) {
        textarea.focus();
    }
});
</script>

<?php include 'footer.php'; ?>