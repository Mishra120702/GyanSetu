<?php
session_start();
require_once '../db_connection.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'mentor')) {
    header("Location: ../logout_a.php");
    exit();
}

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

$admin_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Helper function to fetch link metadata
function fetchLinkMetadata($url) {
    $metadata = [
        'title' => '',
        'description' => '',
        'image' => ''
    ];
    
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $html = curl_exec($ch);
        curl_close($ch);
        
        if ($html) {
            if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $matches)) {
                $metadata['title'] = trim($matches[1]);
            }
            if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $matches)) {
                $metadata['description'] = trim($matches[1]);
            } elseif (preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $matches)) {
                $metadata['description'] = trim($matches[1]);
            }
            if (preg_match('/<meta[^>]*property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $matches)) {
                $metadata['image'] = trim($matches[1]);
            }
        }
    }
    return $metadata;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    try {
        $action = $_POST['action'] ?? '';
        
        // Fetch feeds for AJAX
        if ($action === 'fetch_feeds') {
            $offset = intval($_POST['offset'] ?? 0);
            $limit = 10;
            $status_filter = $_POST['status'] ?? 'all';
            
            $sql = "SELECT f.*, u.name as admin_name,
                    (SELECT COUNT(*) FROM feed_reactions WHERE feed_id = f.id) as reaction_count,
                    (SELECT COUNT(*) FROM feed_comments WHERE feed_id = f.id) as comment_count
                    FROM feeds f
                    JOIN users u ON f.admin_id = u.id
                    WHERE f.admin_id = ?";
            
            if ($status_filter !== 'all') {
                $sql .= " AND f.status = ?";
                $params = [$admin_id, $status_filter];
            } else {
                $params = [$admin_id];
            }
            
            $sql .= " ORDER BY f.created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $feeds_query = $db->prepare($sql);
            $feeds_query->execute($params);
            $feeds = $feeds_query->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'feeds' => $feeds]);
            exit();
        }
        
        // Delete feed
        if ($action === 'delete_feed') {
            $feed_id = intval($_POST['feed_id']);
            $stmt = $db->prepare("DELETE FROM feeds WHERE id = ? AND admin_id = ?");
            $result = $stmt->execute([$feed_id, $admin_id]);
            echo json_encode(['success' => $result]);
            exit();
        }
        
        // Update feed status
        if ($action === 'update_status') {
            $feed_id = intval($_POST['feed_id']);
            $status = $_POST['status'];
            $stmt = $db->prepare("UPDATE feeds SET status = ? WHERE id = ? AND admin_id = ?");
            $result = $stmt->execute([$status, $feed_id, $admin_id]);
            echo json_encode(['success' => $result]);
            exit();
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

// Handle feed creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_feed') {
    $content = trim($_POST['content']);
    $status = $_POST['status'];
    $link_url = !empty($_POST['link_url']) ? $_POST['link_url'] : null;
    
    $link_title = null;
    $link_description = null;
    $link_image = null;
    
    if ($link_url) {
        $metadata = fetchLinkMetadata($link_url);
        $link_title = !empty($_POST['link_title']) ? $_POST['link_title'] : $metadata['title'];
        $link_description = !empty($_POST['link_description']) ? $_POST['link_description'] : $metadata['description'];
        $link_image = !empty($_POST['link_image']) ? $_POST['link_image'] : $metadata['image'];
    }
    
    $media_url = null;
    $media_type = 'none';
    
    if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/feeds/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['media_file']['name'], PATHINFO_EXTENSION));
        $file_name = time() . '_' . uniqid() . '.' . $file_extension;
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['media_file']['tmp_name'], $target_path)) {
            $media_url = 'uploads/feeds/' . $file_name;
            $media_type = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']) ? 'image' : 'video';
        }
    }
    
    $stmt = $db->prepare("
        INSERT INTO feeds (admin_id, content, media_url, media_type, link_url, link_title, link_description, link_image, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([
        $admin_id, $content, $media_url, $media_type, 
        $link_url, $link_title, $link_description, $link_image, $status
    ]);
    
    if ($result) {
        header("Location: a_feeds.php?success=1");
        exit();
    } else {
        header("Location: a_feeds.php?error=1");
        exit();
    }
}

// Get total feeds count
$count_query = $db->prepare("SELECT COUNT(*) FROM feeds WHERE admin_id = ?");
$count_query->execute([$admin_id]);
$total_feeds = $count_query->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Manage Feeds | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        * { font-family: 'Inter', sans-serif; }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .neumorphic-card {
            background: #f8f9ff;
            box-shadow: 10px 10px 30px #d1d9e6, -10px -10px 30px #ffffff;
            border-radius: 25px;
            transition: all 0.3s ease;
        }
        
        .neumorphic-card:hover {
            transform: translateY(-5px);
        }
        
        .neumorphic-input {
            background: #f0f2f8;
            box-shadow: inset 5px 5px 12px #d1d9e6, inset -5px -5px 12px #ffffff;
            border: none;
        }
        
        .neumorphic-input:focus {
            outline: none;
            background: #f8f9ff;
        }
        
        .neumorphic-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 5px 5px 15px #d1d9e6, -5px -5px 15px #ffffff;
            transition: all 0.3s ease;
        }
        
        .neumorphic-button:hover {
            transform: translateY(-2px);
        }
        
        .auto-resize-textarea {
            min-height: 120px;
            max-height: 500px;
            overflow-y: auto;
            resize: vertical;
            line-height: 1.6;
        }
        
        .feed-item {
            transition: all 0.3s ease;
        }
        
        .feed-item-enter {
            opacity: 0;
            transform: translateY(20px);
        }
        
        .feed-item-enter-active {
            opacity: 1;
            transform: translateY(0);
            transition: all 0.3s ease;
        }
        
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f0f2f8;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
        }
        
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="p-6">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="neumorphic-card p-6 mb-6 flex justify-between items-center">
            <div>
                <h1 class="text-3xl font-bold bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">
                    <i class="fas fa-newspaper mr-3 text-purple-600"></i>Manage Feeds
                </h1>
                <p class="text-gray-500 mt-2">Create and manage announcements, updates, and posts for students</p>
            </div>
            <a href="../admin_dashboard.php" class="px-4 py-2 bg-gray-200 rounded-xl hover:bg-gray-300 transition-all">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>

        <!-- Create Feed Form -->
        <div class="neumorphic-card p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">
                <i class="fas fa-plus-circle text-purple-600 mr-2"></i>Create New Feed
            </h2>
            
            <form method="POST" enctype="multipart/form-data" id="feedForm">
                <input type="hidden" name="action" value="create_feed">
                
                <div class="mb-4">
                    <textarea name="content" id="feedContent" required 
                        class="auto-resize-textarea neumorphic-input w-full px-4 py-3 rounded-2xl text-gray-700"
                        placeholder="What's on your mind? Share updates, announcements, or important information..."
                        rows="4"></textarea>
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Add Media (Image/Video)</label>
                    <div class="border-2 border-dashed border-purple-300 rounded-2xl p-4 text-center hover:border-purple-500 transition-all cursor-pointer" id="dropZone">
                        <input type="file" name="media_file" id="mediaFile" accept="image/*,video/*" class="hidden">
                        <i class="fas fa-cloud-upload-alt text-4xl text-purple-400 mb-2"></i>
                        <p class="text-gray-500">Click or drag & drop to upload</p>
                        <p class="text-xs text-gray-400">Supports: JPG, PNG, GIF, MP4 (Max 10MB)</p>
                    </div>
                    <div id="mediaPreview" class="mt-3 hidden relative">
                        <div class="relative inline-block">
                            <img id="previewImage" src="" alt="Preview" class="rounded-2xl max-h-40 object-cover">
                            <video id="previewVideo" controls class="rounded-2xl max-h-40 hidden"></video>
                            <button type="button" onclick="removeMedia()" class="absolute -top-2 -right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center hover:bg-red-600">
                                <i class="fas fa-times text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-700 font-semibold mb-2">Add Link (Optional)</label>
                    <input type="url" name="link_url" id="linkUrl" 
                        class="neumorphic-input w-full px-4 py-3 rounded-2xl text-gray-700"
                        placeholder="https://example.com">
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-gray-700 font-semibold mb-2">Status</label>
                        <select name="status" class="neumorphic-input w-full px-4 py-3 rounded-2xl">
                            <option value="published">Published (Visible to all)</option>
                            <option value="draft">Draft (Only you can see)</option>
                            <option value="archived">Archived (Hidden)</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="neumorphic-button w-full py-3 rounded-2xl text-white font-semibold text-lg">
                    <i class="fas fa-paper-plane mr-2"></i>Post Feed
                </button>
            </form>
        </div>

        <!-- Feeds List -->
        <div>
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-2xl font-bold text-white">
                    <i class="fas fa-list mr-2"></i>Your Feeds
                    <span class="text-sm bg-white bg-opacity-20 px-2 py-1 rounded-full ml-2" id="totalFeedsCount"><?= $total_feeds ?> total</span>
                </h2>
                <div class="flex space-x-2">
                    <button onclick="filterFeeds('all')" class="filter-btn px-3 py-1 bg-white bg-opacity-20 rounded-lg text-white hover:bg-opacity-30 transition-all" data-filter="all">All</button>
                    <button onclick="filterFeeds('published')" class="filter-btn px-3 py-1 bg-white bg-opacity-20 rounded-lg text-white hover:bg-opacity-30 transition-all" data-filter="published">Published</button>
                    <button onclick="filterFeeds('draft')" class="filter-btn px-3 py-1 bg-white bg-opacity-20 rounded-lg text-white hover:bg-opacity-30 transition-all" data-filter="draft">Drafts</button>
                    <button onclick="filterFeeds('archived')" class="filter-btn px-3 py-1 bg-white bg-opacity-20 rounded-lg text-white hover:bg-opacity-30 transition-all" data-filter="archived">Archived</button>
                </div>
            </div>
            
            <div id="feedsContainer" class="space-y-4"></div>
            
            <div id="loadingIndicator" class="text-center py-8 hidden">
                <div class="loading-spinner"></div>
                <p class="text-white mt-2">Loading feeds...</p>
            </div>
            
            <div id="noMoreFeeds" class="text-center py-8 hidden">
                <p class="text-white opacity-70">No more feeds to load</p>
            </div>
        </div>
    </div>

    <script>
        let currentFilter = 'all';
        let currentOffset = 0;
        let isLoading = false;
        let hasMore = true;
        let loadedFeedIds = new Set();
        
        // Load feeds on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadFeeds();
            
            // Setup scroll listener for infinite scroll
            window.addEventListener('scroll', function() {
                if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 500) {
                    if (!isLoading && hasMore) {
                        loadFeeds();
                    }
                }
            });
        });
        
        function loadFeeds() {
            if (isLoading) return;
            
            isLoading = true;
            document.getElementById('loadingIndicator').classList.remove('hidden');
            
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=fetch_feeds&offset=${currentOffset}&status=${currentFilter}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.feeds) {
                    if (data.feeds.length === 0) {
                        hasMore = false;
                        document.getElementById('noMoreFeeds').classList.remove('hidden');
                    } else {
                        // Filter out already loaded feeds
                        const newFeeds = data.feeds.filter(feed => !loadedFeedIds.has(feed.id));
                        
                        if (newFeeds.length > 0) {
                            newFeeds.forEach(feed => {
                                loadedFeedIds.add(feed.id);
                                addFeedToContainer(feed);
                            });
                            currentOffset += newFeeds.length;
                        }
                        
                        if (data.feeds.length < 10) {
                            hasMore = false;
                            document.getElementById('noMoreFeeds').classList.remove('hidden');
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
            })
            .finally(() => {
                isLoading = false;
                document.getElementById('loadingIndicator').classList.add('hidden');
            });
        }
        
        function addFeedToContainer(feed) {
            const container = document.getElementById('feedsContainer');
            const feedHtml = generateFeedHtml(feed);
            container.insertAdjacentHTML('beforeend', feedHtml);
            
            // Add animation
            const lastFeed = container.lastElementChild;
            lastFeed.style.opacity = '0';
            lastFeed.style.transform = 'translateY(20px)';
            setTimeout(() => {
                lastFeed.style.opacity = '1';
                lastFeed.style.transform = 'translateY(0)';
            }, 10);
        }
        
        function generateFeedHtml(feed) {
            const statusColors = {
                published: 'bg-green-100 text-green-700',
                draft: 'bg-yellow-100 text-yellow-700',
                archived: 'bg-gray-100 text-gray-700'
            };
            
            const createdDate = new Date(feed.created_at);
            const formattedDate = createdDate.toLocaleString('en-IN', { 
                day: 'numeric', month: 'short', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            });
            
            let mediaHtml = '';
            if (feed.media_url && feed.media_url !== '') {
                if (feed.media_type === 'image') {
                    mediaHtml = `
                        <div class="mb-4 cursor-pointer overflow-hidden rounded-2xl">
                            <img src="../${escapeHtml(feed.media_url)}" alt="Feed image" 
                                class="rounded-2xl max-h-96 w-full object-cover transition-transform duration-300 hover:scale-105">
                        </div>
                    `;
                } else if (feed.media_type === 'video') {
                    mediaHtml = `
                        <div class="mb-4">
                            <video controls class="rounded-2xl w-full max-h-96">
                                <source src="../${escapeHtml(feed.media_url)}">
                            </video>
                        </div>
                    `;
                }
            }
            
            let linkHtml = '';
            if (feed.link_url && feed.link_url !== '') {
                linkHtml = `
                    <div class="mb-4 border border-gray-200 rounded-2xl overflow-hidden hover:shadow-xl transition-all">
                        <a href="${escapeHtml(feed.link_url)}" target="_blank" class="block">
                            <div class="flex flex-col md:flex-row">
                                ${feed.link_image ? `<div class="md:w-32 h-32 overflow-hidden"><img src="${escapeHtml(feed.link_image)}" alt="" class="w-full h-full object-cover"></div>` : ''}
                                <div class="p-4 flex-1">
                                    <p class="font-semibold text-gray-800">${escapeHtml(feed.link_title || 'Click to view link')}</p>
                                    <p class="text-sm text-gray-500 mt-1">${escapeHtml(feed.link_description || '')}</p>
                                    <p class="text-xs text-purple-600 mt-2 truncate">${escapeHtml(feed.link_url)}</p>
                                </div>
                            </div>
                        </a>
                    </div>
                `;
            }
            
            return `
                <div class="neumorphic-card p-6 feed-item" data-feed-id="${feed.id}" data-status="${feed.status}">
                    <div class="flex justify-between items-start mb-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-xl shadow-lg">
                                ${escapeHtml(feed.admin_name.charAt(0).toUpperCase())}
                            </div>
                            <div>
                                <p class="font-bold text-gray-800">${escapeHtml(feed.admin_name)}</p>
                                <p class="text-sm text-gray-500">
                                    <i class="far fa-calendar-alt mr-1"></i>${formattedDate}
                                </p>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <span class="px-3 py-1 rounded-lg text-xs font-semibold ${statusColors[feed.status]}">
                                ${feed.status.charAt(0).toUpperCase() + feed.status.slice(1)}
                            </span>
                            <select onchange="updateStatus(${feed.id}, this.value)" class="px-2 py-1 rounded-lg text-sm bg-gray-100">
                                <option value="published" ${feed.status === 'published' ? 'selected' : ''}>Publish</option>
                                <option value="draft" ${feed.status === 'draft' ? 'selected' : ''}>Draft</option>
                                <option value="archived" ${feed.status === 'archived' ? 'selected' : ''}>Archive</option>
                            </select>
                            <button onclick="deleteFeed(${feed.id})" class="text-red-500 hover:text-red-700 transition-all">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <div class="text-gray-700 whitespace-pre-wrap leading-relaxed">
                            ${escapeHtml(feed.content).replace(/\n/g, '<br>')}
                        </div>
                    </div>
                    
                    ${mediaHtml}
                    ${linkHtml}
                    
                    <div class="flex space-x-6 pt-4 border-t border-gray-200">
                        <div class="flex items-center space-x-2 text-gray-500">
                            <i class="fas fa-heart text-red-400"></i>
                            <span>${feed.reaction_count || 0} reactions</span>
                        </div>
                        <div class="flex items-center space-x-2 text-gray-500">
                            <i class="fas fa-comment text-blue-400"></i>
                            <span>${feed.comment_count || 0} comments</span>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function filterFeeds(status) {
            currentFilter = status;
            currentOffset = 0;
            hasMore = true;
            loadedFeedIds.clear();
            
            // Update active filter button style
            document.querySelectorAll('.filter-btn').forEach(btn => {
                if (btn.dataset.filter === status) {
                    btn.classList.add('bg-opacity-40');
                    btn.classList.remove('bg-opacity-20');
                } else {
                    btn.classList.remove('bg-opacity-40');
                    btn.classList.add('bg-opacity-20');
                }
            });
            
            // Clear container
            document.getElementById('feedsContainer').innerHTML = '';
            document.getElementById('noMoreFeeds').classList.add('hidden');
            
            // Load feeds with new filter
            loadFeeds();
        }
        
        function deleteFeed(feedId) {
            Swal.fire({
                title: 'Delete Feed?',
                text: "This action cannot be undone!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: `action=delete_feed&feed_id=${feedId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const feedElement = document.querySelector(`.feed-item[data-feed-id="${feedId}"]`);
                            if (feedElement) {
                                feedElement.remove();
                                loadedFeedIds.delete(feedId.toString());
                            }
                            Swal.fire('Deleted!', 'Feed has been deleted.', 'success');
                            
                            // Update total count
                            const totalSpan = document.getElementById('totalFeedsCount');
                            let currentCount = parseInt(totalSpan.textContent);
                            totalSpan.textContent = currentCount - 1;
                        } else {
                            Swal.fire('Error!', 'Failed to delete feed.', 'error');
                        }
                    });
                }
            });
        }
        
        function updateStatus(feedId, status) {
            fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: `action=update_status&feed_id=${feedId}&status=${status}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const feedElement = document.querySelector(`.feed-item[data-feed-id="${feedId}"]`);
                    if (feedElement) {
                        feedElement.setAttribute('data-status', status);
                        const statusSpan = feedElement.querySelector('span:first-child');
                        if (statusSpan) {
                            statusSpan.className = `px-3 py-1 rounded-lg text-xs font-semibold ${
                                status === 'published' ? 'bg-green-100 text-green-700' : 
                                status === 'draft' ? 'bg-yellow-100 text-yellow-700' : 
                                'bg-gray-100 text-gray-700'
                            }`;
                            statusSpan.textContent = status.charAt(0).toUpperCase() + status.slice(1);
                        }
                    }
                    Swal.fire('Updated!', 'Feed status has been updated.', 'success');
                }
            });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Media upload handling
        const dropZone = document.getElementById('dropZone');
        const mediaFile = document.getElementById('mediaFile');
        
        if (dropZone) {
            dropZone.addEventListener('click', () => mediaFile.click());
            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('border-purple-600', 'bg-purple-50');
            });
            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('border-purple-600', 'bg-purple-50');
            });
            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                const file = e.dataTransfer.files[0];
                handleFile(file);
            });
        }
        
        if (mediaFile) {
            mediaFile.addEventListener('change', (e) => {
                const file = e.target.files[0];
                handleFile(file);
            });
        }
        
        function handleFile(file) {
            if (file) {
                if (file.size > 10 * 1024 * 1024) {
                    Swal.fire('Error', 'File size exceeds 10MB limit!', 'error');
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewDiv = document.getElementById('mediaPreview');
                    const previewImage = document.getElementById('previewImage');
                    const previewVideo = document.getElementById('previewVideo');
                    
                    if (file.type.startsWith('image/')) {
                        previewImage.src = e.target.result;
                        previewImage.classList.remove('hidden');
                        previewVideo.classList.add('hidden');
                    } else if (file.type.startsWith('video/')) {
                        previewVideo.src = e.target.result;
                        previewVideo.classList.remove('hidden');
                        previewImage.classList.add('hidden');
                    }
                    
                    previewDiv.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        }
        
        function removeMedia() {
            document.getElementById('mediaFile').value = '';
            document.getElementById('mediaPreview').classList.add('hidden');
        }
        
        // Auto-resize textarea
        const textarea = document.getElementById('feedContent');
        if (textarea) {
            textarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 500) + 'px';
            });
        }
        
        <?php if (isset($_GET['success'])): ?>
        Swal.fire('Success!', 'Feed posted successfully!', 'success');
        <?php elseif (isset($_GET['error'])): ?>
        Swal.fire('Error!', 'Failed to post feed. Please try again.', 'error');
        <?php endif; ?>
    </script>
</body>
</html>