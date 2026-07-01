<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASD Academy - Chat</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    :root {
        --primary-color: #4361ee;
        --secondary-color: #3f37c9;
        --accent-color: #4cc9f0;
        --light-bg: #f8f9fa;
        --dark-text: #212529;
        --light-text: #6c757d;
        --success-color: #4bb543;
        --danger-color: #f94144;
        --message-in-bg: #ffffff;
        --message-out-bg: #4361ee;
    }
    
    body {
        background-color: #f5f7fb;
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .chat-container {
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    /* Conversation list */
    .conversation-list {
        background-color: white;
        border-right: 1px solid rgba(0,0,0,0.05);
    }
    
    .conversation-item {
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border-left: 4px solid transparent;
        cursor: pointer;
        padding: 12px 15px;
        position: relative;
        overflow: hidden;
    }
    
    .conversation-item:before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary-color);
        transform: scaleY(0);
        transform-origin: top;
        transition: transform 0.3s ease;
    }
    
    .conversation-item:hover {
        background-color: rgba(67, 97, 238, 0.05);
    }
    
    .conversation-item:hover:before {
        transform: scaleY(1);
    }
    
    .conversation-item.active {
        background-color: rgba(67, 97, 238, 0.1);
        border-left-color: var(--primary-color);
    }
    
    .conversation-item.active:before {
        transform: scaleY(1);
    }
    
    .conversation-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4361ee, #3f37c9);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-right: 15px;
        font-size: 1.2rem;
        box-shadow: 0 4px 8px rgba(67, 97, 238, 0.2);
    }
    
    .conversation-name {
        font-weight: 600;
        color: var(--dark-text);
        margin-bottom: 4px;
        transition: all 0.3s ease;
    }
    
    .conversation-preview {
        font-size: 0.85rem;
        color: var(--light-text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        transition: all 0.3s ease;
    }
    
    .conversation-time {
        font-size: 0.75rem;
        color: var(--light-text);
    }
    
    .unread-badge {
        background-color: var(--danger-color);
        color: white;
        border-radius: 10px;
        padding: 3px 8px;
        font-size: 0.7rem;
        font-weight: 600;
        animation: pulse 1.5s infinite;
        box-shadow: 0 2px 4px rgba(249, 65, 68, 0.3);
    }
    
    /* Chat area */
    .chat-area {
        background-color: #f5f7fb;
        position: relative;
    }
    
    .chat-header {
        background: white;
        padding: 15px 20px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        box-shadow: 0 2px 10px rgba(0,0,0,0.03);
        z-index: 10;
    }
    
    .chat-messages {
        background-color: #f5f7fb;
        padding: 20px;
        height: 500px;
        overflow-y: auto;
        scroll-behavior: smooth;
        background-image: url('data:image/svg+xml;utf8,<svg width="100" height="100" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><path d="M30,30 Q50,10 70,30 T90,30" fill="none" stroke="rgba(67,97,238,0.03)" stroke-width="2"/></svg>');
        background-size: 200px;
        background-repeat: repeat;
    }
    
    .message {
        margin-bottom: 15px;
        animation: fadeInUp 0.4s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        transition: all 0.2s;
        transform-origin: bottom;
    }
    
    .message-in {
        display: flex;
        justify-content: flex-start;
    }
    
    .message-out {
        display: flex;
        justify-content: flex-end;
    }
    
    .message-bubble {
        max-width: 70%;
        padding: 12px 15px;
        border-radius: 18px;
        position: relative;
        word-wrap: break-word;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    
    .message-in .message-bubble {
        background-color: var(--message-in-bg);
        color: var(--dark-text);
        border-bottom-left-radius: 5px;
    }
    
    .message-out .message-bubble {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-bottom-right-radius: 5px;
        box-shadow: 0 2px 6px rgba(67, 97, 238, 0.3);
    }
    
    .message-info {
        font-size: 0.75rem;
        margin-top: 5px;
        display: flex;
        align-items: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    
    .message:hover .message-info {
        opacity: 1;
    }
    
    .message-in .message-info {
        color: var(--light-text);
    }
    
    .message-out .message-info {
        color: rgba(255, 255, 255, 0.7);
    }
    
    .message-status {
        margin-left: 6px;
    }
    
    .chat-input {
        border-top: 1px solid rgba(0,0,0,0.05);
        background-color: white;
        padding: 15px;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.03);
    }
    
    .chat-input .form-control {
        border-radius: 20px;
        padding: 12px 20px;
        border: 1px solid #ddd;
        transition: all 0.3s;
        background-color: #f8f9fa;
    }
    
    .chat-input .form-control:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.15);
        background-color: white;
    }
    
    .chat-input .btn {
        border-radius: 20px;
        padding: 10px 20px;
        font-weight: 500;
        transition: all 0.3s;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        border: none;
        box-shadow: 0 2px 6px rgba(67, 97, 238, 0.3);
    }
    
    .chat-input .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(67, 97, 238, 0.4);
    }
    
    .chat-input .btn:active {
        transform: translateY(0);
    }
    
    /* Animations */
    @keyframes fadeInUp {
        from { 
            opacity: 0; 
            transform: translateY(10px) scale(0.95);
        }
        to { 
            opacity: 1; 
            transform: translateY(0) scale(1);
        }
    }
    
    @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }
    
    @keyframes float {
        0% { transform: translateY(0px); }
        50% { transform: translateY(-5px); }
        100% { transform: translateY(0px); }
    }
    
    .typing-indicator {
        display: inline-flex;
        padding: 10px 15px;
        background-color: white;
        border-radius: 18px;
        margin-bottom: 15px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        animation: float 2s infinite ease-in-out;
    }
    
    .typing-dot {
        width: 8px;
        height: 8px;
        background-color: var(--light-text);
        border-radius: 50%;
        margin: 0 3px;
        animation: typingAnimation 1.4s infinite ease-in-out;
    }
    
    .typing-dot:nth-child(1) { animation-delay: 0s; }
    .typing-dot:nth-child(2) { animation-delay: 0.2s; }
    .typing-dot:nth-child(3) { animation-delay: 0.4s; }
    
    @keyframes typingAnimation {
        0%, 60%, 100% { transform: translateY(0); }
        30% { transform: translateY(-5px); }
    }
    
    /* Message date divider */
    .date-divider {
        display: flex;
        align-items: center;
        margin: 20px 0;
        color: var(--light-text);
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    .date-divider:before,
    .date-divider:after {
        content: '';
        flex: 1;
        height: 1px;
        background: rgba(0,0,0,0.1);
        margin: 0 10px;
    }
    
    /* Floating action button */
    .floating-btn {
        position: fixed;
        bottom: 30px;
        right: 30px;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 5px 20px rgba(67, 97, 238, 0.3);
        z-index: 1000;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .floating-btn:hover {
        transform: translateY(-5px) scale(1.05);
        box-shadow: 0 8px 25px rgba(67, 97, 238, 0.4);
    }
    
    .floating-btn i {
        font-size: 1.5rem;
    }
    
    /* Custom scrollbar */
    ::-webkit-scrollbar {
        width: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.03);
        border-radius: 10px;
    }
    
    ::-webkit-scrollbar-thumb {
        background: rgba(0,0,0,0.1);
        border-radius: 10px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: rgba(0,0,0,0.2);
    }
    
    /* Empty state */
    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        text-align: center;
        padding: 30px;
    }
    
    .empty-state-icon {
        font-size: 4rem;
        color: rgba(0,0,0,0.1);
        margin-bottom: 20px;
        animation: float 3s infinite ease-in-out;
    }
    .sender-name {
    font-weight: bold;
    font-size: 0.8rem;
    margin-bottom: 3px;
    color: #555;
}
    
    .empty-state-text {
        color: var(--light-text);
        font-size: 1.1rem;
        margin-bottom: 15px;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .conversation-list {
            border-right: none;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .chat-messages {
            height: calc(100vh - 250px);
        }
    }
</style>
</head>
<body class="bg-gray-50 text-gray-800">
<?php include '../header.php';?>
<?php include __DIR__ . '/../s_sidebar.php'; ?>
<div class="flex-1 ml-0 md:ml-64 min-h-screen">
    <!-- Header -->
    <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-comments text-blue-500"></i>
            <span>Chat</span>
        </h1>
        <div class="flex items-center space-x-4">
            <div class="user-avatar">
                <?= strtoupper(substr($_SESSION['user_name'], 0, 1)) ?>
            </div>
            <a href="../logout.php" class="text-sm text-red-600 hover:underline flex items-center space-x-1">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>    
        </div>
    </header>
    <div class="p-4 md:p-6">