<?php
require_once '../db_connection.php';
require_once 'functions.php';

// Check admin permissions
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$trainerId = (int)$_GET['id'];

// Fetch trainer data
$stmt = $db->prepare("SELECT t.*, u.email, u.created_at as user_created 
                      FROM trainers t 
                      JOIN users u ON t.user_id = u.id 
                      WHERE t.id = :id");
$stmt->bindParam(':id', $trainerId, PDO::PARAM_INT);
$stmt->execute();
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header('Location: index.php');
    exit;
}

// Get trainer stats
$batchCount = getTrainerBatchCount($trainerId);
$avgRating = getTrainerAverageRating($trainerId);

// Get batches assigned to this trainer
$stmt = $db->prepare("SELECT b.* 
                      FROM batches b
                      WHERE b.batch_mentor_id = :id
                      ORDER BY b.start_date DESC
                      LIMIT 5");
$stmt->bindParam(':id', $trainerId, PDO::PARAM_INT);
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent feedback for batches taught by this trainer - FIXED QUERY
$stmt = $db->prepare("SELECT f.*, s.first_name, s.last_name, b.batch_name 
                      FROM feedback f
                      JOIN batches b ON f.batch_id = b.batch_id
                      JOIN students s ON f.student_name = CONCAT(s.first_name, ' ', s.last_name)
                      WHERE b.batch_mentor_id = :id
                      ORDER BY f.date DESC
                      LIMIT 5");
$stmt->bindParam(':id', $trainerId, PDO::PARAM_INT);
$stmt->execute();
$feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);



// Get trainer documents
$stmt = $db->prepare("SELECT * FROM trainer_documents WHERE trainer_id = ? ORDER BY document_type");
$stmt->execute([$trainerId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $document_type = $_POST['document_type'];
    $allowed_types = ['resume', 'certification', 'degree', 'id_proof', 'other'];
    
    if (in_array($document_type, $allowed_types)) {
        $upload_dir = '../uploads/trainer_documents/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Check if file was uploaded without errors
        if ($_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['error_message'] = "File upload error: " . $_FILES['document_file']['error'];
            $_SESSION['show_upload_modal'] = true;
            header("Location: view.php?id=$trainerId");
            exit();
        }
        
        // Validate file type
        $allowed_extensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $file_extension = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $_SESSION['error_message'] = "Invalid file type. Allowed types: PDF, JPG, JPEG, PNG, DOC, DOCX";
            $_SESSION['show_upload_modal'] = true;
            header("Location: view.php?id=$trainerId");
            exit();
        }
        
        $file_name = $trainerId . '_' . $document_type . '_' . time() . '_' . basename($_FILES['document_file']['name']);
        $target_file = $upload_dir . $file_name;
        
        // Check if file already exists for this document type
        $stmt = $db->prepare("SELECT * FROM trainer_documents WHERE trainer_id = ? AND document_type = ?");
        $stmt->execute([$trainerId, $document_type]);
        $existing_doc = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_doc) {
            // Delete old file
            if (file_exists($existing_doc['file_path'])) {
                unlink($existing_doc['file_path']);
            }
            
            // Update record
            $stmt = $db->prepare("UPDATE trainer_documents SET file_path = ? WHERE document_id = ?");
            $stmt->execute([$target_file, $existing_doc['document_id']]);
        } else {
            // Insert new record
            $stmt = $db->prepare("INSERT INTO trainer_documents (trainer_id, document_type, file_path) VALUES (?, ?, ?)");
            $stmt->execute([$trainerId, $document_type, $target_file]);
        }
        
        if (move_uploaded_file($_FILES['document_file']['tmp_name'], $target_file)) {
            $_SESSION['success_message'] = "Document uploaded successfully!";
            header("Location: view.php?id=$trainerId");
            exit();
        } else {
            $_SESSION['error_message'] = "Sorry, there was an error uploading your file.";
            $_SESSION['show_upload_modal'] = true;
            header("Location: view.php?id=$trainerId");
            exit();
        }
    } else {
        $_SESSION['error_message'] = "Invalid document type selected.";
        $_SESSION['show_upload_modal'] = true;
    }
}

// Handle document deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $document_id = $_POST['document_id'];
    
    $stmt = $db->prepare("SELECT * FROM trainer_documents WHERE document_id = ? AND trainer_id = ?");
    $stmt->execute([$document_id, $trainerId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doc) {
        if (file_exists($doc['file_path'])) {
            unlink($doc['file_path']);
        }
        
        $stmt = $db->prepare("DELETE FROM trainer_documents WHERE document_id = ?");
        $stmt->execute([$document_id]);
        
        $_SESSION['success_message'] = "Document deleted successfully!";
        header("Location: view.php?id=$trainerId");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en" class="dark-theme">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($trainer['name']) ?> | ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 16rem;
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4cc9f0;
            --light-bg: #f8f9fa;
            --dark-text: #212529;
            --light-text: #6c757d;
            --success-color: #4bb543;
            --danger-color: #f94144;
            --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --gradient-5: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .admin-container {
            display: flex;
            min-height: 100vh;
        }
        
        .admin-content {
            flex: 1;
            margin-left: var(--sidebar-width);
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .admin-content {
                margin-left: 0;
            }
        }
        
        .trainer-avatar {
            object-fit: cover;
            border: 4px solid #e2e8f0;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            filter: brightness(1.1) contrast(1.05);
        }
        
        .trainer-avatar:hover {
            transform: scale(1.08) rotate(2deg);
            border-color: #93c5fd;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            filter: brightness(1.2) contrast(1.1) saturate(1.2);
        }
        
        .card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            overflow: hidden;
            position: relative;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }
        
        .card:hover::before {
            left: 100%;
        }
        
        .card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .card-header {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
        }
        
        .fade-in-section {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .fade-in-section.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        
        .stats-card {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 0.75rem;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: conic-gradient(from 0deg, transparent, rgba(67, 97, 238, 0.1), transparent);
            animation: rotate 4s linear infinite;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .stats-card:hover::before {
            opacity: 1;
        }
        
        .stats-card:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        /* Enhanced Document Styles */
        .document-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .document-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 1.5rem;
            padding: 2rem;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .document-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--gradient-1);
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.3s ease;
        }
        
        .document-card:nth-child(2)::before { background: var(--gradient-2); }
        .document-card:nth-child(3)::before { background: var(--gradient-3); }
        .document-card:nth-child(4)::before { background: var(--gradient-4); }
        .document-card:nth-child(5)::before { background: var(--gradient-5); }
        
        .document-card:hover::before {
            transform: scaleX(1);
        }
        
        .document-card:hover {
            transform: translateY(-10px) scale(1.03);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        .document-icon-wrapper {
            position: relative;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        
        .document-icon {
            font-size: 3.5rem;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            z-index: 2;
        }
        
        .document-icon-bg {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            transform: translate(-50%, -50%) scale(0);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0.1;
            z-index: 1;
        }
        
        .document-card:hover .document-icon-bg {
            transform: translate(-50%, -50%) scale(1);
        }
        
        .document-card:hover .document-icon {
            transform: scale(1.2) rotateY(360deg);
        }
        
        .document-type-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: white;
            background: var(--gradient-1);
            transform: translateY(-10px);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .document-card:hover .document-type-badge {
            transform: translateY(0);
            opacity: 1;
        }
        
        .document-actions {
            margin-top: 1.5rem;
            display: flex;
            gap: 0.75rem;
            justify-content: center;
        }
        
        .document-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.75rem;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .document-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
        }
        
        .document-btn:hover::before {
            left: 100%;
        }
        
        .document-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .btn-view {
            background: var(--gradient-3);
            color: white;
        }
        
        .btn-download {
            background: var(--gradient-4);
            color: white;
        }
        
        .btn-delete {
            background: var(--gradient-2);
            color: white;
        }
        
        /* Upload Area Styles */
        .upload-area {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border: 3px dashed #cbd5e0;
            border-radius: 1.5rem;
            padding: 3rem;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .upload-area::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: conic-gradient(from 0deg, transparent, rgba(67, 97, 238, 0.1), transparent);
            animation: rotate 6s linear infinite;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .upload-area:hover::before {
            opacity: 1;
        }
        
        .upload-area:hover {
            border-color: var(--primary-color);
            background: linear-gradient(135deg, #f0f4ff 0%, #e6f0ff 100%);
            transform: scale(1.02);
        }
        
        .upload-icon {
            font-size: 4rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .upload-area:hover .upload-icon {
            transform: scale(1.2) rotateY(180deg);
            color: var(--secondary-color);
        }
        
        /* Modal Enhancements */
        .modal-content {
            border-radius: 1.5rem;
            border: none;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            backdrop-filter: blur(20px);
        }
        
        .modal-header {
            background: var(--gradient-1);
            color: white;
            border-radius: 1.5rem 1.5rem 0 0;
            border-bottom: none;
            padding: 1.5rem;
        }
        
        .modal-title {
            display: flex;
            align-items: center;
            font-weight: 600;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            border-top: none;
            padding: 1.5rem;
            border-radius: 0 0 1.5rem 1.5rem;
        }
        
        .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }
        
        .btn-close:hover {
            opacity: 1;
        }
        
        .form-select, .form-control {
            border-radius: 0.75rem;
            border: 2px solid #e2e8f0;
            padding: 0.75rem 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background-color: #f8fafc;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            background-color: white;
            transform: scale(1.02);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state-icon {
            font-size: 5rem;
            color: #cbd5e0;
            margin-bottom: 2rem;
            opacity: 0.7;
            animation: float 3s ease-in-out infinite;
        }
        
        /* Animations */
        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }
        
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 5px rgba(67, 97, 238, 0.5); }
            50% { box-shadow: 0 0 20px rgba(67, 97, 238, 0.8), 0 0 30px rgba(67, 97, 238, 0.6); }
        }
        
        .pulse-glow {
            animation: pulse-glow 2s ease-in-out infinite;
        }
        
        /* File type specific colors */
        .file-pdf .document-icon { color: #dc2626; }
        .file-pdf .document-icon-bg { background: #dc2626; }
        
        .file-image .document-icon { color: #059669; }
        .file-image .document-icon-bg { background: #059669; }
        
        .file-word .document-icon { color: #2563eb; }
        .file-word .document-icon-bg { background: #2563eb; }
        
        .file-default .document-icon { color: #6b7280; }
        .file-default .document-icon-bg { background: #6b7280; }
        
        /* Success/Error Message Enhancements */
        .alert {
            border-radius: 1rem;
            border: none;
            padding: 1rem 1.5rem;
            margin-bottom: 2rem;
            animation: slideInDown 0.5s ease;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
        
        @keyframes slideInDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* Loading states */
        .loading-shimmer {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        
        /* Upload zone styles */
        .upload-zone {
            border: 2px dashed #cbd5e0;
            border-radius: 0.75rem;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: #f8fafc;
        }
        
        .upload-zone:hover {
            border-color: var(--primary-color);
            background-color: #f0f4ff;
        }
        
        .upload-zone.drag-over {
            border-color: var(--primary-color);
            background-color: #e6f0ff;
        }
        
        .file-info {
            display: none;
            background-color: #f1f5f9;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-top: 1rem;
        }
        
        .file-info.show {
            display: flex;
        }
        
        .file-name {
            font-weight: 500;
        }
        
        .file-size {
            color: #64748b;
            font-size: 0.875rem;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    
    <div class="admin-container">
        <?php include '../sidebar.php'; ?>
        
        <main class="admin-content bg-gray-50">
            <!-- Header Section -->
            <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30 rounded-2xl mb-6">
                <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                    <div class="p-2 bg-gradient-to-br from-blue-500 to-purple-600 rounded-xl text-white">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <span>Trainer Profile</span>
                </h1>
                <div class="flex items-center space-x-4">
                    <a href="edit.php?id=<?= $trainerId ?>" class="btn btn-primary hover:bg-blue-700 transition-all duration-300 transform hover:scale-105 rounded-xl px-6 py-3">
                        <i class="fas fa-edit mr-2"></i> Edit Profile
                    </a>
                    <a href="index.php" class="btn btn-outline-secondary hover:bg-gray-100 transition-all duration-300 transform hover:scale-105 rounded-xl px-6 py-3">
                        <i class="fas fa-arrow-left mr-2"></i> Back to List
                    </a>
                </div>
            </header>
            
            <div class="container-fluid py-4">
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                    <!-- Left Column -->
                    <div class="lg:col-span-1 space-y-6">
                        <!-- Profile Card -->
                        <div class="card bg-white rounded-2xl shadow-sm overflow-hidden fade-in-section">
                            <div class="p-6">
                                <div class="text-center">
                                    <div class="relative inline-block">
                                        <img src="<?= getTrainerPhoto($trainer) ?>" 
                                             class="rounded-full mb-4 trainer-avatar" 
                                             width="150" height="150" 
                                             alt="<?= htmlspecialchars($trainer['name']) ?>">
                                        <span class="absolute bottom-4 right-4 w-6 h-6 rounded-full border-2 border-white bg-<?= $trainer['is_active'] ? 'green' : 'gray' ?>-500 pulse-glow"></span>
                                    </div>
                                    
                                    <h2 class="text-2xl font-bold text-gray-800 mb-1"><?= htmlspecialchars($trainer['name']) ?></h2>
                                    <p class="text-gray-600 mb-4"><?= htmlspecialchars($trainer['email']) ?></p>
                                    
                                    <div class="flex justify-center mb-4">
                                        <span class="px-4 py-2 rounded-full text-sm font-medium bg-gradient-to-r from-<?= $trainer['is_active'] ? 'green' : 'gray' ?>-400 to-<?= $trainer['is_active'] ? 'green' : 'gray' ?>-600 text-white shadow-lg">
                                            <?= $trainer['is_active'] ? '🟢 Active' : '⚫ Inactive' ?>
                                        </span>
                                    </div>
                                    
                                    <!-- Enhanced Stats -->
                                    <div class="grid grid-cols-3 gap-4 mb-6">
                                        <div class="stats-card hover-grow">
                                            <div class="text-2xl font-bold text-blue-600 mb-1"><?= $batchCount ?></div>
                                            <div class="text-xs text-gray-500 uppercase tracking-wider">Batches</div>
                                        </div>
                                        <div class="stats-card hover-grow">
                                            <div class="text-2xl font-bold text-yellow-600 mb-1">
                                                <?= $avgRating ? round($avgRating, 1) : 'N/A' ?>
                                            </div>
                                            <div class="text-xs text-gray-500 uppercase tracking-wider">Rating</div>
                                        </div>
                                        <div class="stats-card hover-grow">
                                            <div class="text-2xl font-bold text-purple-600 mb-1"><?= $trainer['years_of_experience'] ?></div>
                                            <div class="text-xs text-gray-500 uppercase tracking-wider">Years</div>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-4 text-left">
                                        <?php if ($trainer['specialization']): ?>
                                            <div class="p-4 bg-gradient-to-r from-blue-50 to-purple-50 rounded-xl">
                                                <h4 class="text-sm font-medium text-gray-500 mb-1">Specialization</h4>
                                                <p class="text-gray-800 font-medium"><?= htmlspecialchars($trainer['specialization']) ?></p>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="p-4 bg-gradient-to-r from-green-50 to-blue-50 rounded-xl">
                                            <h4 class="text-sm font-medium text-gray-500 mb-1">Member Since</h4>
                                            <p class="text-gray-800 font-medium"><?= date('M d, Y', strtotime($trainer['user_created'])) ?></p>
                                        </div>
                                        
                                        <?php if ($trainer['bio']): ?>
                                            <div class="p-4 bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl">
                                                <h4 class="text-sm font-medium text-gray-500 mb-1">About</h4>
                                                <p class="text-gray-800"><?= nl2br(htmlspecialchars($trainer['bio'])) ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column -->
                    <div class="lg:col-span-3 space-y-6">
                        <!-- Batches Card -->
                        <div class="card bg-white rounded-2xl shadow-sm overflow-hidden fade-in-section">
                            <div class="card-header">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                        <i class="fas fa-users mr-2 text-blue-500"></i>
                                        Recent Batches
                                    </h3>
                                    <a href="batches.php?id=<?= $trainerId ?>" class="text-sm text-blue-600 hover:text-blue-800 transition-colors duration-300 flex items-center">
                                        View All <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="p-6">
                                <?php if (empty($batches)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-users-slash empty-state-icon"></i>
                                        <h4 class="text-lg font-medium text-gray-500 mb-2">No batches assigned yet</h4>
                                        <p class="text-gray-400">This trainer hasn't been assigned to any batches.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                                <tr>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch ID</th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch Name</th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Start Date</th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php foreach ($batches as $batch): ?>
                                                    <tr class="batch-row hover:bg-gradient-to-r hover:from-blue-50 hover:to-purple-50 transition-all duration-300">
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($batch['batch_id']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($batch['batch_name']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('M d, Y', strtotime($batch['start_date'])) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <span class="px-3 py-1 text-xs rounded-full font-medium 
                                                                <?= $batch['status'] === 'upcoming' ? 'bg-gradient-to-r from-blue-400 to-blue-600 text-white' : 
                                                                   ($batch['status'] === 'ongoing' ? 'bg-gradient-to-r from-green-400 to-green-600 text-white' : 'bg-gradient-to-r from-gray-400 to-gray-600 text-white') ?>">
                                                                <?= ucfirst($batch['status']) ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                            <a href="/admin/batches/view.php?id=<?= $batch['batch_id'] ?>" 
                                                               class="inline-flex items-center px-3 py-1 rounded-lg text-blue-600 hover:text-white hover:bg-blue-600 transition-all duration-300 transform hover:scale-105">
                                                                <i class="fas fa-eye mr-1"></i> View
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Feedback Card -->
                        <div class="card bg-white rounded-2xl shadow-sm overflow-hidden fade-in-section">
                            <div class="card-header">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                        <i class="fas fa-comments mr-2 text-green-500"></i>
                                        Recent Feedback
                                    </h3>
                                    <a href="../feedback/feedback.php?trainer_id=<?= $trainerId ?>" class="text-sm text-blue-600 hover:text-blue-800 transition-colors duration-300 flex items-center">
                                        View All <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="p-6">
                                <?php if (empty($feedbacks) && empty($weeklyFeedbacks)): ?>
                                    <div class="empty-state">
                                        <i class="fas fa-comment-slash empty-state-icon"></i>
                                        <h4 class="text-lg font-medium text-gray-500 mb-2">No feedback received yet</h4>
                                        <p class="text-gray-400">This trainer hasn't received any feedback from students.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-4">
                                        <?php foreach ($feedbacks as $feedback): ?>
                                            <div class="p-4 bg-gradient-to-r from-gray-50 to-white rounded-xl border border-gray-100 hover:shadow-lg transition-all duration-300 transform hover:scale-102">
                                                <div class="flex justify-between items-start mb-3">
                                                    <div>
                                                        <h4 class="font-semibold text-gray-800 flex items-center">
                                                            <i class="fas fa-user-circle mr-2 text-blue-500"></i>
                                                            <?= htmlspecialchars($feedback['first_name'] . ' ' . $feedback['last_name']) ?>
                                                        </h4>
                                                        <p class="text-sm text-gray-500 mt-1 flex items-center">
                                                            <i class="fas fa-book mr-1"></i>
                                                            <?= htmlspecialchars($feedback['batch_name']) ?>
                                                        </p>
                                                    </div>
                                                    <div class="text-right">
                                                        <?php if ($feedback['rating']): ?>
                                                            <div class="star-rating mb-1">
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <i class="fas fa-star text-lg <?= $i <= $feedback['rating'] ? 'text-yellow-400' : 'text-gray-300' ?> transition-all duration-200 hover:scale-110"></i>
                                                                <?php endfor; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <span class="text-xs text-gray-400 flex items-center">
                                                            <i class="fas fa-calendar mr-1"></i>
                                                            <?= date('M d, Y', strtotime($feedback['date'])) ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <?php if ($feedback['suggestions']): ?>
                                                    <div class="p-3 bg-white rounded-lg border-l-4 border-blue-500 shadow-sm">
                                                        <p class="text-gray-700 italic flex items-start">
                                                            <i class="fas fa-quote-left text-blue-400 mr-2 mt-1"></i>
                                                            <?= htmlspecialchars($feedback['suggestions']) ?>
                                                        </p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Enhanced Documents Card -->
                        <div class="card bg-white rounded-2xl shadow-sm overflow-hidden fade-in-section">
                            <div class="card-header">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                        <div class="p-2 bg-gradient-to-br from-purple-500 to-pink-600 rounded-lg text-white mr-3">
                                            <i class="fas fa-folder-open"></i>
                                        </div>
                                        Trainer Documents
                                    </h3>
                                    <button class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-xl font-medium transition-all duration-300 transform hover:scale-105 hover:shadow-lg" 
                                            data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                                        <i class="fas fa-cloud-upload-alt mr-2"></i> Upload Document
                                    </button>
                                </div>
                            </div>
                            <div class="p-6">
                                <?php if (isset($_SESSION['success_message'])): ?>
                                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <?= $_SESSION['success_message'] ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                    <?php unset($_SESSION['success_message']); ?>
                                <?php endif; ?>
                                
                                <?php if (isset($_SESSION['error_message'])): ?>
                                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        <?= $_SESSION['error_message'] ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                    <?php unset($_SESSION['error_message']); ?>
                                <?php endif; ?>
                                
                                <?php if (empty($documents)): ?>
                                    <div class="upload-area">
                                        <i class="fas fa-cloud-upload-alt upload-icon"></i>
                                        <h4 class="text-xl font-semibold text-gray-700 mb-2">No documents uploaded yet</h4>
                                        <p class="text-gray-500 mb-4">Upload documents to showcase certifications, resumes, and other credentials.</p>
                                        <button class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-xl font-medium transition-all duration-300 transform hover:scale-105" 
                                                data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
                                            <i class="fas fa-plus mr-2"></i> Upload Your First Document
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <div class="document-gallery">
                                        <?php foreach ($documents as $index => $doc): ?>
                                            <?php 
                                                $fileExt = strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
                                                $fileClass = 'file-default';
                                                $icon = 'fa-file';
                                                
                                                if ($fileExt === 'pdf') {
                                                    $fileClass = 'file-pdf';
                                                    $icon = 'fa-file-pdf';
                                                } elseif (in_array($fileExt, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                    $fileClass = 'file-image';
                                                    $icon = 'fa-file-image';
                                                } elseif (in_array($fileExt, ['doc', 'docx'])) {
                                                    $fileClass = 'file-word';
                                                    $icon = 'fa-file-word';
                                                }
                                                
                                                $gradients = ['var(--gradient-1)', 'var(--gradient-2)', 'var(--gradient-3)', 'var(--gradient-4)', 'var(--gradient-5)'];
                                                $badgeGradient = $gradients[$index % count($gradients)];
                                            ?>
                                            <div class="document-card <?= $fileClass ?>">
                                                <div class="document-type-badge" style="background: <?= $badgeGradient ?>;">
                                                    <?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?>
                                                </div>
                                                
                                                <div class="text-center">
                                                    <div class="document-icon-wrapper">
                                                        <div class="document-icon-bg"></div>
                                                        <i class="fas <?= $icon ?> document-icon"></i>
                                                    </div>
                                                    
                                                    <h5 class="text-lg font-semibold text-gray-800 mb-2">
                                                        <?= ucfirst(str_replace('_', ' ', $doc['document_type'])) ?>
                                                    </h5>
                                                    
                                                    <div class="flex items-center justify-center text-sm text-gray-500 mb-4">
                                                        <i class="fas fa-calendar mr-2"></i>
                                                        <span>Uploaded: <?= date('M j, Y', strtotime($doc['uploaded_at'])) ?></span>
                                                    </div>
                                                    
                                                    <div class="flex items-center justify-center text-sm text-gray-500 mb-4">
                                                        <i class="fas fa-file-alt mr-2"></i>
                                                        <span><?= strtoupper($fileExt) ?> Document</span>
                                                    </div>
                                                </div>
                                                
                                                <div class="document-actions">
                                                    <a href="<?= $doc['file_path'] ?>" target="_blank" class="document-btn btn-view">
                                                        <i class="fas fa-eye mr-1"></i> View
                                                    </a>
                                                    <a href="<?= $doc['file_path'] ?>" download class="document-btn btn-download">
                                                        <i class="fas fa-download mr-1"></i> Download
                                                    </a>
                                                    <form action="" method="POST" class="inline-block">
                                                        <input type="hidden" name="document_id" value="<?= $doc['document_id'] ?>">
                                                        <button type="submit" name="delete_document" class="document-btn btn-delete" 
                                                                onclick="return confirm('⚠️ Are you sure you want to delete this document? This action cannot be undone.')">
                                                            <i class="fas fa-trash mr-1"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Enhanced Upload Document Modal -->
    <div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="modal-header">
                        <h5 class="modal-title d-flex align-items-center" id="uploadDocumentModalLabel">
                            <i class="fas fa-cloud-upload-alt me-2"></i>
                            Upload Document
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-4">
                            <label for="document_type" class="form-label text-sm font-medium text-gray-700">
                                <i class="fas fa-tags mr-2"></i>Document Type
                            </label>
                            <select class="form-select" id="document_type" name="document_type" required>
                                <option value="">Select document type</option>
                                <option value="resume">📄 Resume/CV</option>
                                <option value="certification">🏆 Certification</option>
                                <option value="degree">🎓 Degree</option>
                                <option value="id_proof">🆔 ID Proof</option>
                                <option value="other">📁 Other Document</option>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="document_file" class="form-label text-sm font-medium text-gray-700">
                                <i class="fas fa-file-upload mr-2"></i>Document File
                            </label>
                            <div class="upload-zone" id="uploadZone">
                                <input class="form-control" type="file" id="document_file" name="document_file" 
                                       accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required style="display: none;">
                                <div class="upload-placeholder text-center p-4">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-blue-500 mb-3"></i>
                                    <p class="text-gray-600 mb-2">Click to browse or drag and drop</p>
                                    <p class="text-sm text-gray-400">Supported formats: PDF, JPG, JPEG, PNG, DOC, DOCX</p>
                                    <p class="text-sm text-gray-400">Maximum file size: 10MB</p>
                                </div>
                                <div class="file-info" id="fileInfo">
                                    <div class="d-flex align-items-center p-3 bg-light rounded">
                                        <i class="fas fa-file me-3 text-blue-500"></i>
                                        <div class="flex-grow-1">
                                            <div class="file-name font-medium"></div>
                                            <div class="file-size text-sm text-gray-500"></div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger" id="removeFile">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info d-flex align-items-start">
                            <i class="fas fa-info-circle me-2 mt-1"></i>
                            <div>
                                <strong>Tips:</strong>
                                <ul class="mb-0 mt-1">
                                    <li>Ensure documents are clear and readable</li>
                                    <li>PDF format is preferred for text documents</li>
                                    <li>Images should be high quality</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times mr-1"></i> Cancel
                        </button>
                        <button type="submit" name="upload_document" class="btn btn-primary" id="uploadBtn">
                            <i class="fas fa-upload mr-1"></i> Upload Document
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="/assets/js/admin.js"></script>
    <script>
        // Enhanced animations and interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });

            // Initialize modal if needed
            <?php if (isset($_SESSION['show_upload_modal'])): ?>
                const uploadModal = new bootstrap.Modal(document.getElementById('uploadDocumentModal'));
                uploadModal.show();
                <?php unset($_SESSION['show_upload_modal']); ?>
            <?php endif; ?>

            // Intersection Observer for staggered animations
            const fadeInSections = document.querySelectorAll('.fade-in-section');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.classList.add('is-visible');
                        }, index * 100);
                    }
                });
            }, {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            });
            
            fadeInSections.forEach(section => {
                observer.observe(section);
            });
            
            // Enhanced file upload functionality
            const uploadZone = document.getElementById('uploadZone');
            const fileInput = document.getElementById('document_file');
            const fileInfo = document.getElementById('fileInfo');
            const uploadPlaceholder = uploadZone.querySelector('.upload-placeholder');
            const removeFileBtn = document.getElementById('removeFile');
            const uploadBtn = document.getElementById('uploadBtn');
            
            // Initially hide file info
            fileInfo.style.display = 'none';
            
            // Click to upload
            uploadZone.addEventListener('click', (e) => {
                if (e.target !== removeFileBtn && e.target !== removeFileBtn.querySelector('i')) {
                    fileInput.click();
                }
            });
            
            // Drag and drop functionality
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                uploadZone.addEventListener(eventName, preventDefaults, false);
            });

            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }

            ['dragenter', 'dragover'].forEach(eventName => {
                uploadZone.addEventListener(eventName, highlight, false);
            });

            ['dragleave', 'drop'].forEach(eventName => {
                uploadZone.addEventListener(eventName, unhighlight, false);
            });

            function highlight() {
                uploadZone.classList.add('drag-over');
            }

            function unhighlight() {
                uploadZone.classList.remove('drag-over');
            }

            uploadZone.addEventListener('drop', handleDrop, false);

            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                
                if (files.length > 0) {
                    fileInput.files = files;
                    handleFileSelect(files[0]);
                }
            }
            
            // File input change
            fileInput.addEventListener('change', (e) => {
                if (e.target.files.length > 0) {
                    handleFileSelect(e.target.files[0]);
                }
            });
            
            // Handle file selection
            function handleFileSelect(file) {
                const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                const maxSize = 10 * 1024 * 1024; // 10MB
                
                if (!allowedTypes.includes(file.type)) {
                    alert('❌ Invalid file type. Please select a PDF, JPG, PNG, DOC, or DOCX file.');
                    return;
                }
                
                if (file.size > maxSize) {
                    alert('❌ File too large. Please select a file smaller than 10MB.');
                    return;
                }
                
                // Show file info
                uploadPlaceholder.style.display = 'none';
                fileInfo.style.display = 'block';
                
                const fileName = fileInfo.querySelector('.file-name');
                const fileSize = fileInfo.querySelector('.file-size');
                const fileIcon = fileInfo.querySelector('i');
                
                fileName.textContent = file.name;
                fileSize.textContent = formatFileSize(file.size);
                fileIcon.className = getFileIcon(file.type);
                
                // Enable upload button
                uploadBtn.disabled = false;
            }
            
            // Remove file
            removeFileBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                fileInput.value = '';
                uploadPlaceholder.style.display = 'block';
                fileInfo.style.display = 'none';
                uploadBtn.disabled = true;
            });
            
            // Format file size
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';
                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }
            
            // Get file icon
            function getFileIcon(type) {
                switch (type) {
                    case 'application/pdf':
                        return 'fas fa-file-pdf text-red-500';
                    case 'image/jpeg':
                    case 'image/jpg':
                    case 'image/png':
                        return 'fas fa-file-image text-green-500';
                    case 'application/msword':
                    case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                        return 'fas fa-file-word text-blue-500';
                    default:
                        return 'fas fa-file text-gray-500';
                }
            }
            
            // Form submission with loading state
            document.getElementById('uploadForm').addEventListener('submit', function() {
                uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Uploading...';
                uploadBtn.disabled = true;
            });
            
            // Modal enhancements
            const uploadModal = document.getElementById('uploadDocumentModal');
            
            uploadModal.addEventListener('hidden.bs.modal', function() {
                // Reset form
                this.querySelector('form').reset();
                uploadPlaceholder.style.display = 'block';
                fileInfo.style.display = 'none';
                uploadBtn.innerHTML = '<i class="fas fa-upload mr-1"></i> Upload Document';
                uploadBtn.disabled = false;
            });
            
            // Enhanced hover effects for document cards
            document.querySelectorAll('.document-card').forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-10px) scale(1.03) rotateY(5deg)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1) rotateY(0deg)';
                });
            });
            
            // Smooth scrolling for internal links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
            
            // Add loading shimmer effect to cards on page load
            document.querySelectorAll('.card').forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 150);
            });
            
            // Enhanced stats card animations
            document.querySelectorAll('.stats-card').forEach(card => {
                const numberElement = card.querySelector('.text-2xl');
                const targetNumber = parseInt(numberElement.textContent) || 0;
                
                if (targetNumber > 0) {
                    let currentNumber = 0;
                    const increment = targetNumber / 50;
                    const timer = setInterval(() => {
                        currentNumber += increment;
                        if (currentNumber >= targetNumber) {
                            numberElement.textContent = targetNumber;
                            clearInterval(timer);
                        } else {
                            numberElement.textContent = Math.floor(currentNumber);
                        }
                    }, 30);
                }
            });
            
            // Parallax effect for trainer avatar
            const avatar = document.querySelector('.trainer-avatar');
            if (avatar) {
                window.addEventListener('scroll', () => {
                    const scrolled = window.pageYOffset;
                    const rate = scrolled * -0.5;
                    avatar.style.transform = `translate3d(0, ${rate}px, 0)`;
                });
            }
            
            // Add ripple effect to buttons
            document.querySelectorAll('.document-btn, .btn').forEach(button => {
                button.addEventListener('click', function(e) {
                    const ripple = document.createElement('span');
                    const rect = this.getBoundingClientRect();
                    const size = Math.max(rect.width, rect.height);
                    const x = e.clientX - rect.left - size / 2;
                    const y = e.clientY - rect.top - size / 2;
                    
                    ripple.style.width = ripple.style.height = size + 'px';
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    ripple.classList.add('ripple');
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
            
            // Progressive image loading
            const images = document.querySelectorAll('img[data-src]');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('loading');
                        observer.unobserve(img);
                    }
                });
            });
            
            images.forEach(img => imageObserver.observe(img));
            
            // Theme toggle animation
            const themeElements = document.querySelectorAll('[data-theme-toggle]');
            themeElements.forEach(element => {
                element.addEventListener('click', () => {
                    document.documentElement.style.transition = 'all 0.3s ease';
                    setTimeout(() => {
                        document.documentElement.style.transition = '';
                    }, 300);
                });
            });
            
            // Enhanced alert dismissal
            document.querySelectorAll('.alert .btn-close').forEach(closeBtn => {
                closeBtn.addEventListener('click', function() {
                    const alert = this.closest('.alert');
                    alert.style.transform = 'translateX(100%)';
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                });
            });
            
            // Keyboard navigation enhancements
            document.addEventListener('keydown', function(e) {
                // ESC key to close modals
                if (e.key === 'Escape') {
                    const openModal = document.querySelector('.modal.show');
                    if (openModal) {
                        const modal = bootstrap.Modal.getInstance(openModal);
                        modal.hide();
                    }
                }
                
                // Ctrl/Cmd + U to open upload modal
                if ((e.ctrlKey || e.metaKey) && e.key === 'u') {
                    e.preventDefault();
                    const modal = new bootstrap.Modal(document.getElementById('uploadDocumentModal'));
                    modal.show();
                }
            });
            
            // Performance monitoring
            const perfObserver = new PerformanceObserver((list) => {
                const entries = list.getEntries();
                entries.forEach((entry) => {
                    if (entry.entryType === 'navigation') {
                        console.log('Page load time:', entry.loadEventEnd - entry.loadEventStart, 'ms');
                    }
                });
            });
            
            if ('PerformanceObserver' in window) {
                perfObserver.observe({ entryTypes: ['navigation'] });
            }
        });
        
        // CSS for ripple effect
        const style = document.createElement('style');
        style.textContent = `
            .ripple {
                position: absolute;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.6);
                transform: scale(0);
                animation: ripple-animation 0.6s linear;
                pointer-events: none;
            }
            
            @keyframes ripple-animation {
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
            
            .loading {
                filter: blur(5px);
                transition: filter 0.3s ease;
            }
            
            /* Scrollbar styling */
            ::-webkit-scrollbar {
                width: 8px;
                height: 8px;
            }
            
            ::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 10px;
            }
            
            ::-webkit-scrollbar-thumb {
                background: linear-gradient(135deg, #4361ee, #3f37c9);
                border-radius: 10px;
            }
            
            ::-webkit-scrollbar-thumb:hover {
                background: linear-gradient(135deg, #3f37c9, #4361ee);
            }
            
            /* Focus styles for accessibility */
            .document-btn:focus,
            .btn:focus,
            .form-control:focus,
            .form-select:focus {
                outline: 2px solid var(--primary-color);
                outline-offset: 2px;
            }
            
            /* High contrast mode support */
            @media (prefers-contrast: high) {
                .card {
                    border: 2px solid #000;
                }
                
                .document-card {
                    border: 2px solid #000;
                }
            }
            
            /* Reduced motion support */
            @media (prefers-reduced-motion: reduce) {
                *,
                *::before,
                *::after {
                    animation-duration: 0.01ms !important;
                    animation-iteration-count: 1 !important;
                    transition-duration: 0.01ms !important;
                }
            }
            
            /* Dark mode support */
            @media (prefers-color-scheme: dark) {
                .card {
                    background: #1a1a1a;
                    color: #e5e5e5;
                }
                
                .document-card {
                    background: #2a2a2a;
                    color: #e5e5e5;
                }
            }
        `;
        document.head.appendChild(style);
        
        // Service Worker for offline functionality (optional)
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js')
                .then(registration => {
                    console.log('SW registered:', registration);
                })
                .catch(registrationError => {
                    console.log('SW registration failed:', registrationError);
                });
        }
        
        // Error handling for failed image loads
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.src = '/assets/images/placeholder.svg';
                this.alt = 'Image not available';
            });
        });
    </script>
</body>
</html>