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
        
        .chat-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 1.2rem;
            font-weight: 600;
        }
        
        .chat-messages {
            background-color: white;
            padding: 20px;
            height: 500px;
            overflow-y: auto;
            scroll-behavior: smooth;
        }
        
        .message {
            margin-bottom: 15px;
            animation: fadeIn 0.3s ease;
            transition: all 0.2s;
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
        }
        
        .message-in .message-bubble {
            background-color: #f1f3f9;
            color: var(--dark-text);
            border-bottom-left-radius: 5px;
        }
        
        .message-out .message-bubble {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-bottom-right-radius: 5px;
        }
        
        .message-info {
            font-size: 0.75rem;
            margin-top: 5px;
            display: flex;
            align-items: center;
        }
        
        .message-in .message-info {
            color: var(--light-text);
        }
        
        .message-out .message-info {
            color: rgba(255, 255, 255, 0.7);
        }
        
        /* Enhanced attachment styling */
        .attachment {
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 8px;
            padding: 10px;
            margin-top: 8px;
            background-color: rgba(255,255,255,0.5);
            transition: all 0.2s;
        }
        
        .message-out .attachment {
            background-color: rgba(67, 97, 238, 0.1);
            border-color: rgba(67, 97, 238, 0.3);
        }
        
        .attachment:hover {
            background-color: rgba(67, 97, 238, 0.05);
            border-color: rgba(67, 97, 238, 0.5);
        }
        
        .attachment-icon {
            font-size: 1.5rem;
            margin-right: 10px;
        }
        
        .attachment-name {
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 200px;
        }
        
        /* Attachment type colors */
        .attachment-pdf {
            color: #e74c3c;
        }
        
        .attachment-image {
            color: #3498db;
        }
        
        .attachment-doc {
            color: #2c3e50;
        }
        
        .attachment-xls {
            color: #27ae60;
        }
        
        .attachment-ppt {
            color: #e67e22;
        }
        
        .attachment-zip {
            color: #9b59b6;
        }
        
        /* Message actions */
        .message-actions {
            position: absolute;
            top: 5px;
            right: 5px;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .message:hover .message-actions {
            opacity: 1;
        }
        
        .message-actions .dropdown-toggle::after {
            display: none;
        }
        
        .message-actions .btn-link {
            color: rgba(255,255,255,0.7);
            padding: 2px 5px;
        }
        
        .message-in .message-actions .btn-link {
            color: rgba(0,0,0,0.4);
        }
        
        /* File preview section */
        .file-preview {
            border: 1px dashed #dee2e6;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
            transition: all 0.2s;
        }
        
        .file-preview:hover {
            background-color: #e9ecef;
        }
        
        .file-info {
            flex-grow: 1;
            min-width: 0;
        }
        
        /* Improved message bubbles with actions */
        .message-bubble {
            position: relative;
            padding-right: 30px; /* Space for action menu */
        }
        
        /* Better tooltip styling */
        .tooltip-inner {
            max-width: 300px;
            padding: 8px 12px;
        }
        
        /* Preview modal styling */
        .preview-container {
            width: 100%;
            height: 70vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .preview-container img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        
        /* Rest of your existing styles... */
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
<?php include 'navbar.php'; ?>
<?php include '../header.php';?>
<?php include '../sidebar.php'; ?>
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