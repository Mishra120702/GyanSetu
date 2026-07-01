<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../logout.php");
    exit();
}

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? 'student'; // Default to student if not set

// Helper function for time ago
function getTimeAgo($timestamp) {
    if (!$timestamp) return 'Just now';
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $timestamp);
}

// Handle AJAX requests
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_ajax) {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        // Handle add/update/remove reaction
        if ($action === 'add_reaction') {
            $feed_id = intval($_POST['feed_id']);
            $reaction_type = $_POST['reaction_type'];
            
            // Validate reaction type
            $valid_reactions = ['like', 'love', 'care', 'haha', 'wow', 'sad', 'angry'];
            if (!in_array($reaction_type, $valid_reactions)) {
                $reaction_type = 'like';
            }
            
            // Check if user already reacted to this feed
            $check_stmt = $db->prepare("SELECT id, reaction_type FROM feed_reactions WHERE feed_id = ? AND user_id = ?");
            $check_stmt->execute([$feed_id, $user_id]);
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // User already has a reaction
                if ($existing['reaction_type'] === $reaction_type) {
                    // Same reaction - remove it (unlike)
                    $delete_stmt = $db->prepare("DELETE FROM feed_reactions WHERE feed_id = ? AND user_id = ?");
                    $delete_result = $delete_stmt->execute([$feed_id, $user_id]);
                    
                    if (!$delete_result) {
                        throw new Exception('Failed to delete reaction');
                    }
                    
                    // Get updated reaction count
                    $count_stmt = $db->prepare("SELECT COUNT(*) FROM feed_reactions WHERE feed_id = ?");
                    $count_stmt->execute([$feed_id]);
                    $new_count = $count_stmt->fetchColumn();
                    
                    echo json_encode([
                        'success' => true, 
                        'action' => 'removed',
                        'reaction_count' => $new_count,
                        'user_reaction' => null
                    ]);
                } else {
                    // Different reaction - update it
                    $update_stmt = $db->prepare("UPDATE feed_reactions SET reaction_type = ? WHERE feed_id = ? AND user_id = ?");
                    $update_result = $update_stmt->execute([$reaction_type, $feed_id, $user_id]);
                    
                    if (!$update_result) {
                        throw new Exception('Failed to update reaction');
                    }
                    
                    // Get updated reaction count
                    $count_stmt = $db->prepare("SELECT COUNT(*) FROM feed_reactions WHERE feed_id = ?");
                    $count_stmt->execute([$feed_id]);
                    $new_count = $count_stmt->fetchColumn();
                    
                    echo json_encode([
                        'success' => true, 
                        'action' => 'updated',
                        'reaction_count' => $new_count,
                        'user_reaction' => $reaction_type
                    ]);
                }
            } else {
                // No existing reaction - add new one
                $insert_stmt = $db->prepare("INSERT INTO feed_reactions (feed_id, user_id, user_role, reaction_type) VALUES (?, ?, ?, ?)");
                $insert_result = $insert_stmt->execute([$feed_id, $user_id, $user_role, $reaction_type]);
                
                if (!$insert_result) {
                    throw new Exception('Failed to insert reaction');
                }
                
                // Get updated reaction count
                $count_stmt = $db->prepare("SELECT COUNT(*) FROM feed_reactions WHERE feed_id = ?");
                $count_stmt->execute([$feed_id]);
                $new_count = $count_stmt->fetchColumn();
                
                echo json_encode([
                    'success' => true, 
                    'action' => 'added',
                    'reaction_count' => $new_count,
                    'user_reaction' => $reaction_type
                ]);
            }
            exit();
        }
        
        // Handle add comment
        if ($action === 'add_comment') {
            $feed_id = intval($_POST['feed_id']);
            $comment = trim($_POST['comment']);
            
            if (empty($comment)) {
                throw new Exception('Comment cannot be empty');
            }
            
            $insert_stmt = $db->prepare("INSERT INTO feed_comments (feed_id, user_id, user_role, comment) VALUES (?, ?, ?, ?)");
            $insert_stmt->execute([$feed_id, $user_id, $user_role, $comment]);
            $comment_id = $db->lastInsertId();
            
            // Get user details
            $user_stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get updated comment count
            $count_stmt = $db->prepare("SELECT COUNT(*) FROM feed_comments WHERE feed_id = ?");
            $count_stmt->execute([$feed_id]);
            $comment_count = $count_stmt->fetchColumn();
            
            echo json_encode([
                'success' => true, 
                'comment_id' => $comment_id,
                'user_name' => $user['name'],
                'user_role' => $user_role,
                'created_at' => date('Y-m-d H:i:s'),
                'comment_count' => $comment_count
            ]);
            exit();
        }
        
        // Handle load more feeds
        if ($action === 'load_more') {
            $offset = intval($_POST['offset']);
            $limit = 10;
            
            $feeds_query = $db->prepare("
                SELECT f.*, u.name as admin_name,
                    (SELECT reaction_type FROM feed_reactions WHERE feed_id = f.id AND user_id = ?) as user_reaction,
                    (SELECT COUNT(*) FROM feed_reactions WHERE feed_id = f.id) as reaction_count,
                    (SELECT COUNT(*) FROM feed_comments WHERE feed_id = f.id) as comment_count
                FROM feeds f
                JOIN users u ON f.admin_id = u.id
                WHERE f.status = 'published'
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $feeds_query->execute([$user_id, $limit, $offset]);
            $feeds = $feeds_query->fetchAll(PDO::FETCH_ASSOC);
            
            // Get comments for each feed
            foreach ($feeds as &$feed) {
                $comments_query = $db->prepare("
                    SELECT fc.*, u.name as user_name
                    FROM feed_comments fc
                    JOIN users u ON fc.user_id = u.id
                    WHERE fc.feed_id = ?
                    ORDER BY fc.created_at ASC
                ");
                $comments_query->execute([$feed['id']]);
                $feed['comments'] = $comments_query->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode(['success' => true, 'feeds' => $feeds]);
            exit();
        }
        
        echo json_encode(['success' => false, 'error' => 'Invalid action: ' . $action]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Get initial feeds
$feeds_query = $db->prepare("
    SELECT f.*, u.name as admin_name,
        (SELECT reaction_type FROM feed_reactions WHERE feed_id = f.id AND user_id = ?) as user_reaction,
        (SELECT COUNT(*) FROM feed_reactions WHERE feed_id = f.id) as reaction_count,
        (SELECT COUNT(*) FROM feed_comments WHERE feed_id = f.id) as comment_count
    FROM feeds f
    JOIN users u ON f.admin_id = u.id
    WHERE f.status = 'published'
    ORDER BY f.created_at DESC
    LIMIT 10
");
$feeds_query->execute([$user_id]);
$initial_feeds = $feeds_query->fetchAll(PDO::FETCH_ASSOC);

// Get comments for initial feeds
foreach ($initial_feeds as &$feed) {
    $comments_query = $db->prepare("
        SELECT fc.*, u.name as user_name
        FROM feed_comments fc
        JOIN users u ON fc.user_id = u.id
        WHERE fc.feed_id = ?
        ORDER BY fc.created_at ASC
    ");
    $comments_query->execute([$feed['id']]);
    $feed['comments'] = $comments_query->fetchAll(PDO::FETCH_ASSOC);
}

// Count total feeds
$count_query = $db->prepare("SELECT COUNT(*) FROM feeds WHERE status = 'published'");
$count_query->execute();
$total_feeds = $count_query->fetchColumn();
$has_more = count($initial_feeds) < $total_feeds;
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        .feeds-container {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9edf5 100%);
            min-height: 100vh;
        }
        
        .neumorphic-feed {
            background: #ffffff;
            box-shadow: 10px 10px 30px rgba(0, 0, 0, 0.05), -10px -10px 30px rgba(255, 255, 255, 0.8);
            border-radius: 28px;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        .neumorphic-feed:hover {
            transform: translateY(-5px);
        }
        
        .reaction-btn {
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .reaction-btn:hover {
            transform: scale(1.05);
            background-color: #f3f4f6;
        }
        
        .reaction-btn.active {
            animation: heartBeat 0.3s ease;
        }
        
        @keyframes heartBeat {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.2); }
        }
        
        .reaction-emoji {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 8px 12px;
            border-radius: 50px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            display: flex;
            gap: 8px;
            white-space: nowrap;
            z-index: 10;
            animation: fadeInUp 0.2s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        
        .reaction-emoji span {
            font-size: 24px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .reaction-emoji span:hover {
            transform: scale(1.2);
        }
        
        .comment-slide {
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .floating-emoji {
            animation: floatEmoji 1s ease-out forwards;
            position: fixed;
            pointer-events: none;
            font-size: 30px;
            z-index: 1000;
        }
        
        @keyframes floatEmoji {
            0% {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
            100% {
                opacity: 0;
                transform: translateY(-100px) scale(1.5);
            }
        }
        
        .loading-spinner {
            display: inline-block;
            width: 30px;
            height: 30px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            animation: slideInRight 0.3s ease;
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        .feed-item {
            transition: all 0.6s ease-out;
        }
        
        .comment-item-new {
            animation: highlight 1s ease;
        }
        
        @keyframes highlight {
            0% { background-color: rgba(139, 92, 246, 0.2); }
            100% { background-color: transparent; }
        }
        
        .reaction-count-updated {
            animation: countUpdate 0.3s ease;
        }
        
        @keyframes countUpdate {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); color: #667eea; }
            100% { transform: scale(1); }
        }
        
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #e9edf5;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
        }
    </style>

    <div class="feeds-container p-4 md:p-6">
        <!-- Header Section -->
        <div class="mb-8 text-center">
            <div class="inline-block">
                <div class="relative">
                    <div class="absolute inset-0 bg-gradient-to-r from-purple-500 to-pink-500 blur-2xl opacity-20 rounded-full"></div>
                    <h1 class="relative text-4xl md:text-5xl font-bold bg-gradient-to-r from-blue-600 via-purple-600 to-pink-600 bg-clip-text text-transparent mb-3">
                        <i class="fas fa-rss mr-3 text-purple-600"></i>Community Feeds
                    </h1>
                </div>
                <p class="text-gray-600 text-lg">Stay updated with latest announcements and updates from your academy</p>
            </div>
        </div>

        <!-- Feeds Container -->
        <div class="max-w-3xl mx-auto space-y-6" id="feedsContainer">
            <?php if (empty($initial_feeds)): ?>
                <div class="neumorphic-feed p-12 text-center">
                    <div class="animate-bounce">
                        <i class="fas fa-newspaper text-6xl text-gray-300 mb-4"></i>
                    </div>
                    <p class="text-gray-500 text-xl mb-2">No feeds available</p>
                    <p class="text-gray-400">Check back later for updates from your academy!</p>
                </div>
            <?php else: ?>
                <?php foreach ($initial_feeds as $feed): ?>
                <div class="neumorphic-feed p-6 feed-item" data-feed-id="<?= $feed['id'] ?>">
                    <!-- Feed Header -->
                    <div class="flex items-start space-x-3 mb-4">
                        <div class="relative">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg">
                                <?= strtoupper(substr($feed['admin_name'], 0, 1)) ?>
                            </div>
                            <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full border-2 border-white"></div>
                        </div>
                        <div class="flex-1">
                            <p class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($feed['admin_name']) ?></p>
                            <div class="flex items-center space-x-2 text-sm text-gray-500">
                                <i class="fas fa-user-shield text-purple-500"></i>
                                <span>Administrator</span>
                                <span>•</span>
                                <i class="far fa-clock"></i>
                                <span><?= getTimeAgo(strtotime($feed['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Feed Content -->
                    <div class="mb-4">
                        <p class="text-gray-700 whitespace-pre-wrap leading-relaxed"><?= nl2br(htmlspecialchars($feed['content'])) ?></p>
                    </div>

                    <!-- Media Display -->
                    <?php if ($feed['media_url'] && $feed['media_url'] !== ''): ?>
                        <?php if ($feed['media_type'] === 'image'): ?>
                            <div class="mb-4 group relative cursor-pointer overflow-hidden rounded-2xl" onclick="openMediaModal('../<?= htmlspecialchars($feed['media_url']) ?>', 'image')">
                                <img src="../<?= htmlspecialchars($feed['media_url']) ?>" alt="Feed image" class="w-full rounded-2xl transition-transform duration-300 group-hover:scale-105" loading="lazy">
                                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-20 transition-all duration-300 flex items-center justify-center">
                                    <i class="fas fa-search-plus text-white text-3xl opacity-0 group-hover:opacity-100 transition-all duration-300"></i>
                                </div>
                            </div>
                        <?php elseif ($feed['media_type'] === 'video'): ?>
                            <div class="mb-4">
                                <video controls class="w-full rounded-2xl" preload="metadata">
                                    <source src="../<?= htmlspecialchars($feed['media_url']) ?>">
                                    Your browser does not support the video tag.
                                </video>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <!-- Link Preview -->
                    <?php if ($feed['link_url'] && $feed['link_url'] !== ''): ?>
                        <div class="mb-4 border border-gray-200 rounded-2xl overflow-hidden hover:shadow-xl transition-all duration-300 cursor-pointer group" onclick="window.open('<?= htmlspecialchars($feed['link_url']) ?>', '_blank')">
                            <div class="flex flex-col md:flex-row">
                                <?php if ($feed['link_image']): ?>
                                <div class="md:w-32 h-32 overflow-hidden">
                                    <img src="<?= htmlspecialchars($feed['link_image']) ?>" alt="" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300" loading="lazy">
                                </div>
                                <?php endif; ?>
                                <div class="p-4 flex-1">
                                    <p class="font-semibold text-gray-800 group-hover:text-purple-600 transition-colors">
                                        <?= htmlspecialchars($feed['link_title'] ?: 'Click to view link') ?>
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($feed['link_description'] ?: '') ?></p>
                                    <p class="text-xs text-purple-600 mt-2 flex items-center">
                                        <i class="fas fa-link mr-1"></i>
                                        <?= htmlspecialchars(parse_url($feed['link_url'], PHP_URL_HOST) ?: $feed['link_url']) ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Reaction Stats -->
                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <div class="flex items-center space-x-1">
                            <div class="flex -space-x-2">
                                <div class="w-6 h-6 bg-red-500 rounded-full flex items-center justify-center text-white text-xs shadow-lg">❤️</div>
                                <div class="w-6 h-6 bg-yellow-500 rounded-full flex items-center justify-center text-white text-xs shadow-lg">😊</div>
                                <div class="w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs shadow-lg">👍</div>
                            </div>
                            <span class="text-sm text-gray-600 reaction-count" id="reaction-count-<?= $feed['id'] ?>"><?= $feed['reaction_count'] ?> <?= $feed['reaction_count'] == 1 ? 'person reacted' : 'people reacted' ?></span>
                        </div>
                        <div class="text-sm text-gray-600">
                            <i class="far fa-comment mr-1"></i>
                            <span class="comment-count" id="comment-count-<?= $feed['id'] ?>"><?= $feed['comment_count'] ?></span> comments
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex items-center justify-around pt-4 border-t border-gray-100 mt-3">
                        <div class="relative">
                            <button class="reaction-btn flex items-center space-x-2 px-6 py-2 rounded-xl transition-all <?= $feed['user_reaction'] ? 'text-purple-600 bg-purple-50' : 'text-gray-500 hover:bg-gray-50' ?>" 
                                data-feed-id="<?= $feed['id'] ?>"
                                data-has-reaction="<?= $feed['user_reaction'] ? 'true' : 'false' ?>"
                                data-reaction-type="<?= htmlspecialchars($feed['user_reaction'] ?? '') ?>"
                                onclick="toggleReaction(<?= $feed['id'] ?>, event)">
                                <i class="fas fa-heart text-lg"></i>
                                <span class="font-medium ml-2"><?= $feed['user_reaction'] ? ucfirst($feed['user_reaction']) . 'd' : 'React' ?></span>
                            </button>
                        </div>
                        <button class="flex items-center space-x-2 px-6 py-2 rounded-xl text-gray-500 hover:bg-gray-50 transition-all" onclick="toggleCommentBox(<?= $feed['id'] ?>)">
                            <i class="far fa-comment text-lg"></i>
                            <span class="font-medium">Comment</span>
                        </button>
                        <button class="flex items-center space-x-2 px-6 py-2 rounded-xl text-gray-500 hover:bg-gray-50 transition-all" onclick="shareFeed()">
                            <i class="fas fa-share-alt text-lg"></i>
                            <span class="font-medium">Share</span>
                        </button>
                    </div>

                    <!-- Comment Box -->
                    <div id="comment-box-<?= $feed['id'] ?>" class="hidden mt-4 pt-4 border-t border-gray-100 comment-slide">
                        <div class="flex space-x-3">
                            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                <?= strtoupper(substr($_SESSION['user_name'] ?? 'S', 0, 1)) ?>
                            </div>
                            <div class="flex-1">
                                <textarea id="comment-text-<?= $feed['id'] ?>" rows="2" 
                                    class="w-full px-4 py-2 bg-gray-50 rounded-2xl border border-gray-200 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-200 transition-all resize-none"
                                    placeholder="Write a comment..."></textarea>
                                <div class="flex justify-end space-x-2 mt-2">
                                    <button onclick="cancelComment(<?= $feed['id'] ?>)" class="px-4 py-1 text-gray-600 hover:text-gray-800 transition-colors">Cancel</button>
                                    <button onclick="postComment(<?= $feed['id'] ?>)" class="post-comment-btn px-4 py-1 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-xl hover:shadow-lg transition-all">
                                        <i class="fas fa-paper-plane mr-1"></i>Post
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Comments List -->
                    <div id="comments-<?= $feed['id'] ?>" class="mt-4 space-y-3">
                        <?php foreach ($feed['comments'] as $comment): ?>
                        <div class="flex space-x-3 comment-item" data-comment-id="<?= $comment['id'] ?>">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-xs flex-shrink-0 bg-gradient-to-r from-gray-500 to-gray-600">
                                <?= strtoupper(substr($comment['user_name'], 0, 1)) ?>
                            </div>
                            <div class="flex-1">
                                <div class="bg-gray-50 rounded-2xl p-3">
                                    <p class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($comment['user_name']) ?></p>
                                    <p class="text-gray-600 text-sm mt-1"><?= nl2br(htmlspecialchars($comment['comment'])) ?></p>
                                </div>
                                <p class="text-xs text-gray-400 mt-1 ml-2">
                                    <?= getTimeAgo(strtotime($comment['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- Load More Button -->
        <?php if ($has_more): ?>
        <div class="text-center mt-6" id="loadMoreContainer">
            <button id="loadMoreBtn" class="px-8 py-3 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-xl font-semibold hover:shadow-lg transition-all">
                <span id="loadMoreText">Load More</span>
                <div id="loadMoreSpinner" class="hidden loading-spinner ml-2"></div>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Media Modal -->
    <div id="mediaModal" class="fixed inset-0 bg-black bg-opacity-95 z-50 hidden items-center justify-center" onclick="closeMediaModal()">
        <div class="relative max-w-full max-h-full p-4">
            <img id="modalImage" src="" alt="" class="max-w-full max-h-full object-contain hidden">
            <video id="modalVideo" controls class="max-w-full max-h-full object-contain hidden"></video>
            <button onclick="closeMediaModal()" class="absolute top-4 right-4 text-white text-3xl hover:text-gray-300 transition-colors z-10">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>

    <script>
        // Reaction emojis with their types
        const reactions = [
            { emoji: '❤️', type: 'love', name: 'Love' },
            { emoji: '👍', type: 'like', name: 'Like' },
            { emoji: '😂', type: 'haha', name: 'Haha' },
            { emoji: '😮', type: 'wow', name: 'Wow' },
            { emoji: '😢', type: 'sad', name: 'Sad' },
            { emoji: '😡', type: 'angry', name: 'Angry' },
            { emoji: '🥰', type: 'care', name: 'Care' }
        ];
        
        let activePicker = null;
        let isLoading = false;
        let currentOffset = <?= count($initial_feeds) ?>;
        let hasMore = <?= $has_more ? 'true' : 'false' ?>;
        let loadedFeedIds = new Set();
        
        // Initialize loaded feed IDs
        document.querySelectorAll('.feed-item').forEach(feed => {
            const feedId = feed.getAttribute('data-feed-id');
            if (feedId) loadedFeedIds.add(feedId);
        });
        
        // Function to get AJAX URL - using current page URL
        function getAjaxUrl() {
            return window.location.href;
        }
        
        // Toggle reaction picker
        function toggleReaction(feedId, event) {
            event.stopPropagation();
            const button = event.currentTarget;
            const existingPicker = button.querySelector('.reaction-emoji');
            
            if (existingPicker) {
                existingPicker.remove();
                activePicker = null;
                return;
            }
            
            if (activePicker) activePicker.remove();
            
            const picker = document.createElement('div');
            picker.className = 'reaction-emoji';
            picker.innerHTML = reactions.map(r => 
                `<span onclick="event.stopPropagation(); setReaction(${feedId}, '${r.type}', '${r.emoji}', event)" title="${r.name}">${r.emoji}</span>`
            ).join('');
            
            button.style.position = 'relative';
            button.appendChild(picker);
            activePicker = picker;
            
            setTimeout(() => {
                const closePicker = (e) => {
                    if (picker && !picker.contains(e.target) && e.target !== button) {
                        picker.remove();
                        activePicker = null;
                        document.removeEventListener('click', closePicker);
                    }
                };
                document.addEventListener('click', closePicker);
            }, 100);
        }
        
        // Set reaction in database
        function setReaction(feedId, reactionType, reactionEmoji, event) {
            const button = document.querySelector(`.reaction-btn[data-feed-id="${feedId}"]`);
            if (!button) {
                console.error('Button not found for feed:', feedId);
                return;
            }
            
            // Show loading state
            const originalHtml = button.innerHTML;
            button.innerHTML = '<i class="fas fa-spinner fa-spin text-lg"></i><span class="font-medium ml-2">...</span>';
            button.disabled = true;
            
            // Create form data for POST request
            const formData = new URLSearchParams();
            formData.append('action', 'add_reaction');
            formData.append('feed_id', feedId);
            formData.append('reaction_type', reactionType);
            
            console.log('Sending reaction request:', feedId, reactionType);
            
            fetch(getAjaxUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData.toString()
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Reaction response:', data);
                
                if (data.success) {
                    // Show floating emoji
                    if (event) showFloatingEmoji(reactionEmoji, event);
                    
                    // Update button state based on action
                    if (data.action === 'removed') {
                        button.classList.remove('text-purple-600', 'bg-purple-50');
                        button.setAttribute('data-has-reaction', 'false');
                        button.setAttribute('data-reaction-type', '');
                        button.innerHTML = '<i class="fas fa-heart text-lg"></i><span class="font-medium ml-2">React</span>';
                        showToast('Reaction removed', 'info');
                    } else {
                        if (data.action === 'added') {
                            showToast(`Added ${reactionEmoji} reaction!`, 'success');
                        } else if (data.action === 'updated') {
                            showToast(`Changed reaction to ${reactionEmoji}`, 'success');
                        }
                        button.classList.add('text-purple-600', 'bg-purple-50');
                        button.setAttribute('data-has-reaction', 'true');
                        button.setAttribute('data-reaction-type', reactionType);
                        button.innerHTML = `<i class="fas fa-heart text-lg"></i><span class="font-medium ml-2">${reactionType.charAt(0).toUpperCase() + reactionType.slice(1)}d</span>`;
                    }
                    
                    // Update reaction count with animation
                    updateReactionCount(feedId, data.reaction_count);
                    
                    // Animate button
                    button.classList.add('active');
                    setTimeout(() => button.classList.remove('active'), 300);
                    
                    // Remove picker
                    if (activePicker) {
                        activePicker.remove();
                        activePicker = null;
                    }
                } else {
                    showToast(data.error || 'Failed to add reaction', 'error');
                    button.innerHTML = originalHtml;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to add reaction. Please try again.', 'error');
                button.innerHTML = originalHtml;
            })
            .finally(() => {
                button.disabled = false;
            });
        }
        
        function updateReactionCount(feedId, newCount) {
            const countElement = document.getElementById(`reaction-count-${feedId}`);
            if (countElement) {
                const text = `${newCount} ${newCount == 1 ? 'person reacted' : 'people reacted'}`;
                countElement.textContent = text;
                countElement.classList.add('reaction-count-updated');
                setTimeout(() => countElement.classList.remove('reaction-count-updated'), 300);
            }
        }
        
        function showFloatingEmoji(emoji, event) {
            const floatingEmoji = document.createElement('div');
            floatingEmoji.className = 'floating-emoji';
            floatingEmoji.textContent = emoji;
            const x = event.clientX || window.innerWidth / 2;
            const y = event.clientY || window.innerHeight - 100;
            floatingEmoji.style.left = x + 'px';
            floatingEmoji.style.top = y + 'px';
            document.body.appendChild(floatingEmoji);
            setTimeout(() => floatingEmoji.remove(), 1000);
        }
        
        function toggleCommentBox(feedId) {
            const commentBox = document.getElementById(`comment-box-${feedId}`);
            if (commentBox) {
                commentBox.classList.toggle('hidden');
                if (!commentBox.classList.contains('hidden')) {
                    document.getElementById(`comment-text-${feedId}`).focus();
                }
            }
        }
        
        function cancelComment(feedId) {
            const commentBox = document.getElementById(`comment-box-${feedId}`);
            const textarea = document.getElementById(`comment-text-${feedId}`);
            if (commentBox) commentBox.classList.add('hidden');
            if (textarea) textarea.value = '';
        }
        
        function postComment(feedId) {
            const commentText = document.getElementById(`comment-text-${feedId}`).value.trim();
            if (!commentText) {
                showToast('Please enter a comment', 'warning');
                return;
            }
            
            const postButton = event.currentTarget;
            const originalHtml = postButton.innerHTML;
            postButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Posting...';
            postButton.disabled = true;
            
            const formData = new URLSearchParams();
            formData.append('action', 'add_comment');
            formData.append('feed_id', feedId);
            formData.append('comment', commentText);
            
            fetch(getAjaxUrl(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData.toString()
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById(`comment-text-${feedId}`).value = '';
                    document.getElementById(`comment-box-${feedId}`).classList.add('hidden');
                    
                    // Update comment count
                    const commentCountSpan = document.getElementById(`comment-count-${feedId}`);
                    commentCountSpan.textContent = data.comment_count;
                    
                    const userInitial = '<?= strtoupper(substr($_SESSION['user_name'] ?? 'S', 0, 1)) ?>';
                    const commentsContainer = document.getElementById(`comments-${feedId}`);
                    const newComment = document.createElement('div');
                    newComment.className = 'flex space-x-3 comment-item comment-item-new';
                    newComment.setAttribute('data-comment-id', data.comment_id);
                    newComment.innerHTML = `
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-xs flex-shrink-0 bg-gradient-to-r from-gray-500 to-gray-600">
                            ${escapeHtml(userInitial)}
                        </div>
                        <div class="flex-1">
                            <div class="bg-gray-50 rounded-2xl p-3">
                                <p class="font-semibold text-gray-800 text-sm">${escapeHtml(data.user_name)}</p>
                                <p class="text-gray-600 text-sm mt-1">${escapeHtml(commentText).replace(/\n/g, '<br>')}</p>
                            </div>
                            <p class="text-xs text-gray-400 mt-1 ml-2">Just now</p>
                        </div>
                    `;
                    commentsContainer.appendChild(newComment);
                    
                    showToast('Comment posted successfully!', 'success');
                    newComment.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => newComment.classList.remove('comment-item-new'), 1000);
                } else {
                    showToast(data.error || 'Failed to post comment', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Failed to post comment', 'error');
            })
            .finally(() => {
                postButton.innerHTML = originalHtml;
                postButton.disabled = false;
            });
        }
        
        function shareFeed() {
            if (navigator.share) {
                navigator.share({
                    title: 'Check this out on ASD Academy!',
                    text: 'Interesting update from your academy',
                    url: window.location.href
                }).catch(() => {});
            } else {
                const dummy = document.createElement('textarea');
                dummy.value = window.location.href;
                document.body.appendChild(dummy);
                dummy.select();
                document.execCommand('copy');
                document.body.removeChild(dummy);
                showToast('Link copied to clipboard!', 'success');
            }
        }
        
        function openMediaModal(src, type) {
            const modal = document.getElementById('mediaModal');
            const modalImage = document.getElementById('modalImage');
            const modalVideo = document.getElementById('modalVideo');
            
            if (type === 'image') {
                modalImage.src = src;
                modalImage.classList.remove('hidden');
                modalVideo.classList.add('hidden');
                if (modalVideo.pause) modalVideo.pause();
            } else if (type === 'video') {
                modalVideo.src = src;
                modalVideo.classList.remove('hidden');
                modalImage.classList.add('hidden');
            }
            
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('overflow-hidden');
        }
        
        function closeMediaModal() {
            const modal = document.getElementById('mediaModal');
            const modalVideo = document.getElementById('modalVideo');
            if (modalVideo.pause) modalVideo.pause();
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('overflow-hidden');
        }
        
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast-notification px-6 py-3 rounded-xl text-white shadow-lg transform transition-all duration-300 ${
                type === 'success' ? 'bg-green-500' : 
                type === 'error' ? 'bg-red-500' : 
                type === 'warning' ? 'bg-yellow-500' : 'bg-blue-500'
            }`;
            toast.innerHTML = `
                <div class="flex items-center space-x-2">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle'}"></i>
                    <span>${escapeHtml(message)}</span>
                </div>
            `;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function getTimeAgo(timestamp) {
            if (!timestamp) return 'Just now';
            const date = new Date(timestamp);
            const now = new Date();
            const diff = Math.floor((now - date) / 1000);
            
            if (diff < 60) return 'Just now';
            if (diff < 3600) return Math.floor(diff / 60) + ' minutes ago';
            if (diff < 86400) return Math.floor(diff / 3600) + ' hours ago';
            if (diff < 604800) return Math.floor(diff / 86400) + ' days ago';
            return date.toLocaleDateString('en-IN', { month: 'short', day: 'numeric', year: 'numeric' });
        }
        
        // Load more feeds
        const loadMoreBtn = document.getElementById('loadMoreBtn');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function() {
                if (isLoading) return;
                
                isLoading = true;
                const loadMoreText = document.getElementById('loadMoreText');
                const loadMoreSpinner = document.getElementById('loadMoreSpinner');
                
                loadMoreText.classList.add('hidden');
                loadMoreSpinner.classList.remove('hidden');
                this.disabled = true;
                
                const formData = new URLSearchParams();
                formData.append('action', 'load_more');
                formData.append('offset', currentOffset);
                
                fetch(getAjaxUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData.toString()
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.feeds && data.feeds.length > 0) {
                        const container = document.getElementById('feedsContainer');
                        const loadMoreContainer = document.getElementById('loadMoreContainer');
                        
                        const newFeeds = data.feeds.filter(feed => !loadedFeedIds.has(feed.id.toString()));
                        
                        if (newFeeds.length === 0) {
                            if (loadMoreContainer) loadMoreContainer.remove();
                            return;
                        }
                        
                        newFeeds.forEach(feed => {
                            loadedFeedIds.add(feed.id.toString());
                            const feedHtml = generateFeedHtml(feed);
                            container.insertAdjacentHTML('beforeend', feedHtml);
                        });
                        
                        currentOffset += newFeeds.length;
                        
                        if (data.feeds.length < 10) {
                            hasMore = false;
                            if (loadMoreContainer) loadMoreContainer.remove();
                        }
                        
                        document.querySelectorAll('.feed-item:not([data-animated])').forEach(feed => {
                            feed.setAttribute('data-animated', 'true');
                            feed.style.opacity = '0';
                            feed.style.transform = 'translateY(30px)';
                            setTimeout(() => {
                                feed.style.opacity = '1';
                                feed.style.transform = 'translateY(0)';
                            }, 50);
                        });
                        
                        showToast(`${newFeeds.length} new ${newFeeds.length === 1 ? 'feed' : 'feeds'} loaded`, 'success');
                    } else {
                        const loadMoreContainer = document.getElementById('loadMoreContainer');
                        if (loadMoreContainer) loadMoreContainer.remove();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('Failed to load more feeds', 'error');
                })
                .finally(() => {
                    isLoading = false;
                    loadMoreText.classList.remove('hidden');
                    loadMoreSpinner.classList.add('hidden');
                    if (loadMoreBtn) loadMoreBtn.disabled = false;
                });
            });
        }
        
        function generateFeedHtml(feed) {
            const userReactionClass = feed.user_reaction ? 'text-purple-600 bg-purple-50' : 'text-gray-500 hover:bg-gray-50';
            const buttonText = feed.user_reaction ? 
                `${feed.user_reaction.charAt(0).toUpperCase() + feed.user_reaction.slice(1)}d` : 'React';
            const timeAgo = getTimeAgo(feed.created_at);
            
            let mediaHtml = '';
            if (feed.media_url && feed.media_url !== '') {
                if (feed.media_type === 'image') {
                    mediaHtml = `
                        <div class="mb-4 group relative cursor-pointer overflow-hidden rounded-2xl" onclick="openMediaModal('../${escapeHtml(feed.media_url)}', 'image')">
                            <img src="../${escapeHtml(feed.media_url)}" alt="Feed image" class="w-full rounded-2xl transition-transform duration-300 group-hover:scale-105" loading="lazy">
                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-20 transition-all duration-300 flex items-center justify-center">
                                <i class="fas fa-search-plus text-white text-3xl opacity-0 group-hover:opacity-100 transition-all duration-300"></i>
                            </div>
                        </div>
                    `;
                } else if (feed.media_type === 'video') {
                    mediaHtml = `
                        <div class="mb-4">
                            <video controls class="w-full rounded-2xl" preload="metadata">
                                <source src="../${escapeHtml(feed.media_url)}">
                                Your browser does not support the video tag.
                            </video>
                        </div>
                    `;
                }
            }
            
            let linkHtml = '';
            if (feed.link_url && feed.link_url !== '') {
                let linkHost = feed.link_url;
                try {
                    linkHost = new URL(feed.link_url).hostname;
                } catch(e) {
                    linkHost = feed.link_url;
                }
                linkHtml = `
                    <div class="mb-4 border border-gray-200 rounded-2xl overflow-hidden hover:shadow-xl transition-all duration-300 cursor-pointer group" onclick="window.open('${escapeHtml(feed.link_url)}', '_blank')">
                        <div class="flex flex-col md:flex-row">
                            ${feed.link_image ? `<div class="md:w-32 h-32 overflow-hidden"><img src="${escapeHtml(feed.link_image)}" alt="" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300" loading="lazy"></div>` : ''}
                            <div class="p-4 flex-1">
                                <p class="font-semibold text-gray-800 group-hover:text-purple-600 transition-colors">${escapeHtml(feed.link_title || 'Click to view link')}</p>
                                <p class="text-sm text-gray-500 mt-1 line-clamp-2">${escapeHtml(feed.link_description || '')}</p>
                                <p class="text-xs text-purple-600 mt-2 flex items-center"><i class="fas fa-link mr-1"></i>${escapeHtml(linkHost)}</p>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            let commentsHtml = '';
            if (feed.comments && feed.comments.length > 0) {
                commentsHtml = feed.comments.map(comment => `
                    <div class="flex space-x-3 comment-item" data-comment-id="${comment.id}">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-white font-bold text-xs flex-shrink-0 bg-gradient-to-r from-gray-500 to-gray-600">
                            ${escapeHtml(comment.user_name.charAt(0).toUpperCase())}
                        </div>
                        <div class="flex-1">
                            <div class="bg-gray-50 rounded-2xl p-3">
                                <p class="font-semibold text-gray-800 text-sm">${escapeHtml(comment.user_name)}</p>
                                <p class="text-gray-600 text-sm mt-1">${escapeHtml(comment.comment).replace(/\n/g, '<br>')}</p>
                            </div>
                            <p class="text-xs text-gray-400 mt-1 ml-2">${getTimeAgo(comment.created_at)}</p>
                        </div>
                    </div>
                `).join('');
            }
            
            return `
                <div class="neumorphic-feed p-6 feed-item" data-feed-id="${feed.id}" data-animated="true">
                    <div class="flex items-start space-x-3 mb-4">
                        <div class="relative">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg">
                                ${escapeHtml(feed.admin_name.charAt(0).toUpperCase())}
                            </div>
                            <div class="absolute -bottom-1 -right-1 w-4 h-4 bg-green-500 rounded-full border-2 border-white"></div>
                        </div>
                        <div class="flex-1">
                            <p class="font-bold text-gray-800 text-lg">${escapeHtml(feed.admin_name)}</p>
                            <div class="flex items-center space-x-2 text-sm text-gray-500">
                                <i class="fas fa-user-shield text-purple-500"></i>
                                <span>Administrator</span>
                                <span>•</span>
                                <i class="far fa-clock"></i>
                                <span>${timeAgo}</span>
                            </div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <p class="text-gray-700 whitespace-pre-wrap leading-relaxed">${escapeHtml(feed.content).replace(/\n/g, '<br>')}</p>
                    </div>
                    ${mediaHtml}
                    ${linkHtml}
                    <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                        <div class="flex items-center space-x-1">
                            <div class="flex -space-x-2">
                                <div class="w-6 h-6 bg-red-500 rounded-full flex items-center justify-center text-white text-xs shadow-lg">❤️</div>
                                <div class="w-6 h-6 bg-yellow-500 rounded-full flex items-center justify-center text-white text-xs shadow-lg">😊</div>
                                <div class="w-6 h-6 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs shadow-lg">👍</div>
                            </div>
                            <span class="text-sm text-gray-600 reaction-count" id="reaction-count-${feed.id}">${feed.reaction_count} ${feed.reaction_count == 1 ? 'person reacted' : 'people reacted'}</span>
                        </div>
                        <div class="text-sm text-gray-600">
                            <i class="far fa-comment mr-1"></i>
                            <span class="comment-count" id="comment-count-${feed.id}">${feed.comment_count}</span> comments
                        </div>
                    </div>
                    <div class="flex items-center justify-around pt-4 border-t border-gray-100 mt-3">
                        <div class="relative">
                            <button class="reaction-btn flex items-center space-x-2 px-6 py-2 rounded-xl transition-all ${userReactionClass}" 
                                data-feed-id="${feed.id}"
                                data-has-reaction="${feed.user_reaction ? 'true' : 'false'}"
                                data-reaction-type="${escapeHtml(feed.user_reaction || '')}"
                                onclick="toggleReaction(${feed.id}, event)">
                                <i class="fas fa-heart text-lg"></i>
                                <span class="font-medium ml-2">${buttonText}</span>
                            </button>
                        </div>
                        <button class="flex items-center space-x-2 px-6 py-2 rounded-xl text-gray-500 hover:bg-gray-50 transition-all" onclick="toggleCommentBox(${feed.id})">
                            <i class="far fa-comment text-lg"></i>
                            <span class="font-medium">Comment</span>
                        </button>
                        <button class="flex items-center space-x-2 px-6 py-2 rounded-xl text-gray-500 hover:bg-gray-50 transition-all" onclick="shareFeed()">
                            <i class="fas fa-share-alt text-lg"></i>
                            <span class="font-medium">Share</span>
                        </button>
                    </div>
                    <div id="comment-box-${feed.id}" class="hidden mt-4 pt-4 border-t border-gray-100 comment-slide">
                        <div class="flex space-x-3">
                            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                ${escapeHtml('<?= strtoupper(substr($_SESSION['user_name'] ?? 'S', 0, 1)) ?>')}
                            </div>
                            <div class="flex-1">
                                <textarea id="comment-text-${feed.id}" rows="2" 
                                    class="w-full px-4 py-2 bg-gray-50 rounded-2xl border border-gray-200 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-200 transition-all resize-none"
                                    placeholder="Write a comment..."></textarea>
                                <div class="flex justify-end space-x-2 mt-2">
                                    <button onclick="cancelComment(${feed.id})" class="px-4 py-1 text-gray-600 hover:text-gray-800 transition-colors">Cancel</button>
                                    <button onclick="postComment(${feed.id})" class="px-4 py-1 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-xl hover:shadow-lg transition-all">
                                        <i class="fas fa-paper-plane mr-1"></i>Post
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="comments-${feed.id}" class="mt-4 space-y-3">
                        ${commentsHtml}
                    </div>
                </div>
            `;
        }
        
        // Scroll animation for existing feeds
        document.querySelectorAll('.feed-item').forEach(feed => {
            feed.style.opacity = '0';
            feed.style.transform = 'translateY(30px)';
            setTimeout(() => {
                feed.style.opacity = '1';
                feed.style.transform = 'translateY(0)';
            }, 100);
        });
        
        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeMediaModal();
        });
        
        document.getElementById('mediaModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeMediaModal();
        });
    </script>
</div>

<?php include '../footer.php'; ?>