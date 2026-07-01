<?php
include '../db_connection.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Get all notifications
$notifications = $db->query("
    SELECT n.*, 
           CASE 
               WHEN n.type = 'feedback' THEN f.student_name
               WHEN n.type = 'message' THEN u.name
           END as sender_name,
           CASE 
               WHEN n.type = 'feedback' THEN f.batch_id
               WHEN n.type = 'message' THEN NULL
           END as batch_id,
           CASE 
               WHEN n.type = 'feedback' THEN f.feedback_text
               WHEN n.type = 'message' THEN cm.message
           END as content,
           CASE 
               WHEN n.type = 'feedback' THEN f.date
               WHEN n.type = 'message' THEN cm.sent_at
           END as created_at
    FROM notifications n
    LEFT JOIN feedback f ON n.type = 'feedback' AND n.reference_id = f.id
    LEFT JOIN chat_messages cm ON n.type = 'message' AND n.reference_id = cm.id
    LEFT JOIN users u ON n.type = 'message' AND cm.sender_id = u.id
    WHERE n.user_id = {$_SESSION['user_id']}
    ORDER BY n.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Mark all as read when viewing all notifications
$db->query("UPDATE notifications SET is_read = 1 WHERE user_id = {$_SESSION['user_id']}");

// Include header and sidebar as in dashboard.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Notifications - ASD Academy</title>
    <style>
        .notification-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }
        .notification-item:hover {
            background-color: #f5f5f5;
        }
        .notification-time {
            font-size: 12px;
            color: #666;
        }
        .notification-type {
            font-weight: bold;
            margin-right: 5px;
        }
        .feedback-type {
            color: #ef4444;
        }
        .message-type {
            color: #3b82f6;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
<?php include '../header.php'; ?>
<?php include '../sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-bell text-blue-500"></i>
            <span>All Notifications</span>
        </h1>
    </header>

    <div class="p-6">
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <?php if (count($notifications) > 0): ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item">
                        <div class="flex items-start">
                            <div class="mr-3">
                                <?php if ($notification['type'] === 'feedback'): ?>
                                    <div class="bg-red-100 text-red-600 p-2 rounded-full">
                                        <i class="fas fa-comment-dots"></i>
                                    </div>
                                <?php else: ?>
                                    <div class="bg-blue-100 text-blue-600 p-2 rounded-full">
                                        <i class="fas fa-comment"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <?php if ($notification['type'] === 'feedback'): ?>
                                            <span class="notification-type feedback-type">Feedback:</span>
                                            <span>From <?= htmlspecialchars($notification['sender_name']) ?> (Batch <?= $notification['batch_id'] ?>)</span>
                                        <?php else: ?>
                                            <span class="notification-type message-type">Message:</span>
                                            <span>From <?= htmlspecialchars($notification['sender_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="notification-time"><?= date('M j, g:i A', strtotime($notification['created_at'])) ?></span>
                                </div>
                                <div class="mt-2 text-sm text-gray-600">
                                    <?= htmlspecialchars(substr($notification['content'], 0, 100)) ?>
                                    <?php if (strlen($notification['content']) > 100): ?>...<?php endif; ?>
                                </div>
                                <div class="mt-2">
                                    <?php if ($notification['type'] === 'feedback'): ?>
                                        <a href="../dashboard/pending_feedbacks.php" class="text-xs text-blue-500 hover:underline">View feedback</a>
                                    <?php else: ?>
                                        <a href="../chat/index.php" class="text-xs text-blue-500 hover:underline">View message</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="p-8 text-center text-gray-500">
                    No notifications found
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>
</body>
</html>