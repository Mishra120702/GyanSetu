<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get workshop ID from URL
$workshop_id = $_GET['id'] ?? null;
if (!$workshop_id) {
    header("Location: workshop_list.php");
    exit;
}

// Fetch workshop details
$stmt = $db->prepare("SELECT w.*, t.name as trainer_name 
                      FROM workshops w
                      LEFT JOIN trainers t ON w.trainer_id = t.id
                      WHERE w.workshop_id = ?");
$stmt->execute([$workshop_id]);
$workshop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$workshop) {
    header("Location: workshop_list.php");
    exit;
}

// Get all trainers for dropdown
$trainers = $db->query("SELECT id, name FROM trainers")->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle file upload
    $cover_image = $workshop['cover_image']; // Keep existing if no new upload
    
    if (isset($_FILES['cover_image_file']) && $_FILES['cover_image_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/workshops/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExt = pathinfo($_FILES['cover_image_file']['name'], PATHINFO_EXTENSION);
        $fileName = 'workshop_' . $workshop_id . '_' . time() . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['cover_image_file']['tmp_name'], $filePath)) {
            $cover_image = $filePath;
            // Delete old image if it exists and is not the default
            if ($workshop['cover_image'] && !str_contains($workshop['cover_image'], 'default-workshop.jpg')) {
                @unlink($workshop['cover_image']);
            }
        }
    }
    
    $stmt = $db->prepare("UPDATE workshops SET
        title = ?,
        description = ?,
        start_datetime = ?,
        end_datetime = ?,
        location = ?,
        max_participants = ?,
        current_registrations = ?,
        trainer_id = ?,
        fee = ?,
        status = ?,
        cover_image = ?,
        requirements = ?,
        certificate_available = ?
        WHERE workshop_id = ?");
    
    $stmt->execute([
        $_POST['title'],
        $_POST['description'],
        $_POST['start_datetime'],
        $_POST['end_datetime'],
        $_POST['location'],
        $_POST['max_participants'],
        $_POST['current_registrations'],
        $_POST['trainer_id'] ?: null,
        $_POST['fee'],
        $_POST['status'],
        $cover_image,
        $_POST['requirements'],
        isset($_POST['certificate_available']) ? 1 : 0,
        $workshop_id
    ]);
    
    // Handle material upload
    if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/workshop_materials/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExt = pathinfo($_FILES['material_file']['name'], PATHINFO_EXTENSION);
        $fileName = 'material_' . $workshop_id . '_' . time() . '.' . $fileExt;
        $filePath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['material_file']['tmp_name'], $filePath)) {
            $stmt = $db->prepare("INSERT INTO workshop_materials 
                                 (workshop_id, title, description, file_path, file_type, uploaded_by, is_public)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $workshop_id,
                $_POST['material_title'],
                $_POST['material_description'],
                $filePath,
                $_POST['material_file_type'],
                $_SESSION['user_id'],
                $_POST['material_is_public']
            ]);
        }
    }
    
    // Refresh the page to show updated data
    header("Location: edit_workshop.php?id=$workshop_id&success=1");
    exit();
}

// Get registration details for this workshop
$registrations = $db->prepare("SELECT wr.*, s.first_name, s.last_name, s.email 
                              FROM workshop_registrations wr
                              JOIN students s ON wr.student_id = s.student_id
                              WHERE wr.workshop_id = ?");
$registrations->execute([$workshop_id]);
$registrations = $registrations->fetchAll(PDO::FETCH_ASSOC);

// Get workshop materials
$materials = $db->prepare("SELECT * FROM workshop_materials 
                          WHERE workshop_id = ?
                          ORDER BY uploaded_at DESC");
$materials->execute([$workshop_id]);
$materials = $materials->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Workshop - ASD Academy</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="../css/workshop.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #e0e7ff;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fb;
            color: #4a5568;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg, #4361ee 0%, #3f37c9 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 1.5rem;
        }
        
        .card {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
        }
        
        .badge {
            padding: 0.35rem 0.65rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .badge i {
            margin-right: 0.25rem;
        }
        
        .badge-upcoming {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-ongoing {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-completed {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-cancelled {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background-color: var(--primary-light);
        }
        
        .action-link {
            color: var(--primary);
            transition: all 0.2s ease;
            margin: 0 0.25rem;
        }
        
        .action-link:hover {
            color: var(--secondary);
            transform: scale(1.1);
        }
        
        .action-link.delete {
            color: var(--danger);
        }
        
        .action-link.delete:hover {
            color: #d1145a;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #4a5568;
        }
        
        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            transition: all 0.2s ease;
            background-color: #f8fafc;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.2);
            background-color: white;
        }
        
        .progress-bar {
            height: 0.5rem;
            background-color: #e2e8f0;
            border-radius: 0.25rem;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background-color: var(--primary);
            border-radius: 0.25rem;
            transition: width 0.6s ease;
        }
        
        .tab-container {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 1.5rem;
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
            font-weight: 500;
            color: #64748b;
        }
        
        .tab:hover {
            color: var(--primary);
            border-bottom-color: var(--primary-light);
        }
        
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.4s;
        }
        
        .material-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 0.375rem;
            background-color: #f8fafc;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
        }
        
        .material-item:hover {
            background-color: #f1f5f9;
            transform: translateX(5px);
        }
        
        .material-icon {
            font-size: 1.5rem;
            margin-right: 1rem;
            color: #64748b;
        }
        
        .material-details {
            flex: 1;
        }
        
        .material-actions {
            display: flex;
        }
        
        .registration-item {
            display: flex;
            align-items: center;
            padding: 1rem;
            border-radius: 0.375rem;
            background-color: #f8fafc;
            margin-bottom: 0.75rem;
            transition: all 0.2s ease;
        }
        
        .registration-item:hover {
            background-color: #f1f5f9;
        }
        
        .registration-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 1rem;
        }
        
        .status-registered {
            background-color: #94a3b8;
        }
        
        .status-attended {
            background-color: #10b981;
        }
        
        .status-absent {
            background-color: #f43f5e;
        }
        
        .status-cancelled {
            background-color: #f97316;
        }
        
        .success-message {
            position: fixed;
            top: 20px;
            right: 20px;
            background-color: #10b981;
            color: white;
            padding: 1rem;
            border-radius: 0.375rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            display: flex;
            align-items: center;
            animation: slideInRight 0.3s, fadeOut 0.5s 3s forwards;
        }
        
        .success-message i {
            margin-right: 0.5rem;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            animation: fadeInDown 0.4s;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .close-modal {
            color: #718096;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .close-modal:hover {
            color: #4a5568;
            transform: rotate(90deg);
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .thumbnail-preview {
            width: 100%;
            max-height: 200px;
            object-fit: contain;
            margin-bottom: 1rem;
            border-radius: 0.5rem;
            border: 1px dashed #e2e8f0;
            padding: 0.5rem;
        }
        
        .file-input-container {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input-button {
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            padding: 0.5rem 1rem;
            background-color: #f8fafc;
            color: #4a5568;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .file-input-button:hover {
            background-color: #e2e8f0;
        }
        
        .file-input {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
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
        
        @keyframes fadeOut {
            from {
                opacity: 1;
            }
            to {
                opacity: 0;
            }
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .animate-pulse {
            animation: pulse 2s infinite;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen p-4 md:p-6">
        <?php if (isset($_GET['success'])): ?>
            <div class="success-message animate__animated animate__fadeInRight">
                <i class="fas fa-check-circle"></i>
                Workshop updated successfully!
            </div>
        <?php endif; ?>
        
        <!-- Dashboard Header -->
        <div class="dashboard-header animate__animated animate__fadeIn">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-white flex items-center">
                        <i class="fas fa-edit mr-3"></i>
                        Edit Workshop: <?= htmlspecialchars($workshop['title']) ?>
                    </h1>
                    <p class="text-blue-100 mt-2">Update workshop details and manage registrations</p>
                </div>
                <div class="flex space-x-2 mt-4 md:mt-0">
                    <a href="workshop_list.php" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                    <a href="workshop_view.php?id=<?= $workshop_id ?>" class="btn btn-primary">
                        <i class="fas fa-eye"></i> View Workshop
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Workshop ID and Status -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.1s">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full mr-4">
                        <i class="fas fa-id-badge text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Workshop ID</div>
                        <div class="text-xl font-semibold"><?= htmlspecialchars($workshop['workshop_id']) ?></div>
                    </div>
                </div>
            </div>
            
            <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.2s">
                <div class="flex items-center">
                    <div class="bg-blue-100 p-3 rounded-full mr-4">
                        <i class="fas fa-info-circle text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Current Status</div>
                        <?php 
                        $badgeClass = '';
                        if ($workshop['status'] === 'ongoing') $badgeClass = 'badge-ongoing';
                        else if ($workshop['status'] === 'completed') $badgeClass = 'badge-completed';
                        else if ($workshop['status'] === 'upcoming') $badgeClass = 'badge-upcoming';
                        else if ($workshop['status'] === 'cancelled') $badgeClass = 'badge-cancelled';
                        
                        $icon = '';
                        if ($workshop['status'] === 'ongoing') $icon = 'fa-play-circle';
                        else if ($workshop['status'] === 'completed') $icon = 'fa-check-circle';
                        else if ($workshop['status'] === 'upcoming') $icon = 'fa-clock';
                        else if ($workshop['status'] === 'cancelled') $icon = 'fa-times-circle';
                        ?>
                        <span class="badge <?= $badgeClass ?> mt-1">
                            <i class="fas <?= $icon ?>"></i>
                            <?= htmlspecialchars(ucfirst($workshop['status'])) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="card animate__animated animate__fadeInUp" style="animation-delay: 0.3s">
            <div class="tab-container">
                <div class="tab active" data-tab="details">Workshop Details</div>
                <div class="tab" data-tab="registrations">Registrations (<?= count($registrations) ?>)</div>
                <div class="tab" data-tab="materials">Materials (<?= count($materials) ?>)</div>
            </div>
            
            <!-- Workshop Details Tab -->
            <div id="details-tab" class="tab-content active">
                <form id="workshopForm" method="post" class="space-y-4" enctype="multipart/form-data">
                    <input type="hidden" name="update_workshop" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Workshop ID -->
                        <div class="form-group">
                            <label for="workshop_id" class="form-label">Workshop ID</label>
                            <input type="text" id="workshop_id" 
                                   class="form-control bg-gray-100"
                                   value="<?= htmlspecialchars($workshop['workshop_id']) ?>" readonly>
                        </div>
                        
                        <!-- Title -->
                        <div class="form-group">
                            <label for="title" class="form-label">Title*</label>
                            <input type="text" id="title" name="title" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($workshop['title']) ?>" required>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="form-group">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" rows="3"
                                  class="form-control"><?= htmlspecialchars($workshop['description']) ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Start Date/Time -->
                        <div class="form-group">
                            <label for="start_datetime" class="form-label">Start Date & Time*</label>
                            <input type="datetime-local" id="start_datetime" name="start_datetime" 
                                   class="form-control"
                                   value="<?= date('Y-m-d\TH:i', strtotime($workshop['start_datetime'])) ?>" required>
                        </div>
                        
                        <!-- End Date/Time -->
                        <div class="form-group">
                            <label for="end_datetime" class="form-label">End Date & Time*</label>
                            <input type="datetime-local" id="end_datetime" name="end_datetime" 
                                   class="form-control"
                                   value="<?= date('Y-m-d\TH:i', strtotime($workshop['end_datetime'])) ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Location -->
                        <div class="form-group">
                            <label for="location" class="form-label">Location*</label>
                            <input type="text" id="location" name="location" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($workshop['location']) ?>" required>
                        </div>
                        
                        <!-- Max Participants -->
                        <div class="form-group">
                            <label for="max_participants" class="form-label">Max Participants*</label>
                            <input type="number" id="max_participants" name="max_participants" min="1" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($workshop['max_participants']) ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Current Registrations -->
                        <div class="form-group">
                            <label for="current_registrations" class="form-label">Current Registrations</label>
                            <input type="number" id="current_registrations" name="current_registrations" min="0" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($workshop['current_registrations']) ?>">
                        </div>
                        
                        <!-- Trainer -->
                        <div class="form-group">
                            <label for="trainer_id" class="form-label">Trainer</label>
                            <select id="trainer_id" name="trainer_id" 
                                    class="form-control">
                                <option value="">Select Trainer</option>
                                <?php foreach ($trainers as $trainer): ?>
                                    <option value="<?= $trainer['id'] ?>" <?= $workshop['trainer_id'] == $trainer['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($trainer['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Fee -->
                        <div class="form-group">
                            <label for="fee" class="form-label">Fee (₹)*</label>
                            <input type="number" id="fee" name="fee" min="0" step="0.01" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($workshop['fee']) ?>" required>
                        </div>
                        
                        <!-- Cover Image -->
                        <div class="form-group">
                            <label for="cover_image" class="form-label">Cover Image</label>
                            <?php if ($workshop['cover_image']): ?>
                                <img src="<?= htmlspecialchars($workshop['cover_image']) ?>" 
                                     class="thumbnail-preview mb-2" 
                                     id="thumbnailPreview"
                                     alt="Workshop thumbnail">
                            <?php else: ?>
                                <div class="thumbnail-preview mb-2 bg-gray-100 flex items-center justify-center" id="thumbnailPreview">
                                    <i class="fas fa-image text-gray-400 text-4xl"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="file-input-container">
                                <div class="file-input-button">
                                    <i class="fas fa-upload mr-2"></i>
                                    <span id="fileLabel">Choose new thumbnail</span>
                                </div>
                                <input type="file" id="cover_image_file" name="cover_image_file" 
                                       class="file-input" accept="image/*">
                            </div>
                            <small class="text-gray-500">Recommended size: 1200x630 pixels</small>
                        </div>
                    </div>

                    <!-- Requirements -->
                    <div class="form-group">
                        <label for="requirements" class="form-label">Requirements</label>
                        <textarea id="requirements" name="requirements" rows="2"
                                  class="form-control"><?= htmlspecialchars($workshop['requirements']) ?></textarea>
                    </div>

                    <!-- Certificate Available -->
                    <div class="form-group flex items-center">
                        <input type="checkbox" id="certificate_available" name="certificate_available" 
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                               <?= $workshop['certificate_available'] ? 'checked' : '' ?>>
                        <label for="certificate_available" class="ml-2 block text-sm text-gray-700">
                            Certificate Available
                        </label>
                    </div>

                    <!-- Status -->
                    <div class="form-group">
                        <label for="status" class="form-label">Status*</label>
                        <select id="status" name="status" 
                                class="form-control"
                                required>
                            <option value="upcoming" <?= $workshop['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="ongoing" <?= $workshop['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="completed" <?= $workshop['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $workshop['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-4 pt-4 border-t border-gray-200">
                        <button type="button" onclick="if(confirm('Discard all changes?')) window.location.href='workshop_list.php'" 
                                class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Registrations Tab -->
            <div id="registrations-tab" class="tab-content">
                <?php if (empty($registrations)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-users-slash text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">No registrations for this workshop yet</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Registration Date</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($registrations as $reg): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($reg['first_name'] . ' ' . $reg['last_name']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= htmlspecialchars($reg['email']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('d M Y', strtotime($reg['registration_date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?= $reg['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 
                                               ($reg['payment_status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                               ($reg['payment_status'] === 'failed' ? 'bg-red-100 text-red-800' : 'bg-blue-100 text-blue-800')) ?>">
                                            <?= ucfirst($reg['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?= $reg['attendance_status'] === 'attended' ? 'bg-green-100 text-green-800' : 
                                               ($reg['attendance_status'] === 'registered' ? 'bg-blue-100 text-blue-800' : 
                                               ($reg['attendance_status'] === 'absent' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800')) ?>">
                                            <?= ucfirst($reg['attendance_status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="#" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                        <a href="#" class="text-red-600 hover:text-red-900">Remove</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Materials Tab -->
            <div id="materials-tab" class="tab-content">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-medium text-gray-900">Workshop Materials</h3>
                    <button id="addMaterialBtn" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Material
                    </button>
                </div>
                
                <?php if (empty($materials)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-folder-open text-4xl text-gray-300 mb-4"></i>
                        <p class="text-gray-500">No materials uploaded for this workshop yet</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($materials as $material): ?>
                            <div class="material-item animate__animated animate__fadeIn">
                                <div class="material-icon">
                                    <?php 
                                    $icon = 'fa-file-alt';
                                    if (strpos($material['file_type'], 'slide') !== false) $icon = 'fa-file-powerpoint';
                                    elseif (strpos($material['file_type'], 'handout') !== false) $icon = 'fa-file-pdf';
                                    elseif (strpos($material['file_type'], 'exercise') !== false) $icon = 'fa-file-code';
                                    elseif (strpos($material['file_type'], 'recording') !== false) $icon = 'fa-file-video';
                                    ?>
                                    <i class="fas <?= $icon ?>"></i>
                                </div>
                                <div class="material-details">
                                    <div class="font-medium"><?= htmlspecialchars($material['title']) ?></div>
                                    <div class="text-sm text-gray-500">
                                        <?= date('d M Y, H:i', strtotime($material['uploaded_at'])) ?> • 
                                        <?= strtoupper(str_replace('_', ' ', $material['file_type'])) ?>
                                        <?= $material['is_public'] ? '• Public' : '• Private' ?>
                                    </div>
                                </div>
                                <div class="material-actions">
                                    <a href="<?= htmlspecialchars($material['file_path']) ?>" 
                                       class="action-link" 
                                       title="Download"
                                       download>
                                        <i class="fas fa-download"></i>
                                    </a>
                                    <a href="#" 
                                       class="action-link" 
                                       title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="delete_material.php?id=<?= $material['id'] ?>&workshop_id=<?= $workshop_id ?>" 
                                       class="action-link delete" 
                                       title="Delete"
                                       onclick="return confirm('Delete this material?')">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Add Material Modal -->
    <div id="addMaterialModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-xl font-semibold text-gray-800">Add Workshop Material</h3>
                <span class="close-modal">&times;</span>
            </div>
            <form id="materialForm" method="post" class="space-y-4" enctype="multipart/form-data">
                <input type="hidden" name="workshop_id" value="<?= $workshop_id ?>">
                
                <div class="form-group">
                    <label for="material_title" class="form-label">Title*</label>
                    <input type="text" id="material_title" name="material_title" 
                           class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label for="material_description" class="form-label">Description</label>
                    <textarea id="material_description" name="material_description" rows="3"
                              class="form-control"></textarea>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="form-group">
                        <label for="material_file_type" class="form-label">File Type*</label>
                        <select id="material_file_type" name="material_file_type" 
                                class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="slides">Slides</option>
                            <option value="handout">Handout</option>
                            <option value="exercise">Exercise</option>
                            <option value="recording">Recording</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="material_is_public" class="form-label">Visibility</label>
                        <select id="material_is_public" name="material_is_public" 
                                class="form-control">
                            <option value="0">Private (Only for this workshop)</option>
                            <option value="1">Public (All students can access)</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="material_file" class="form-label">File*</label>
                    <div class="file-input-container">
                        <div class="file-input-button">
                            <i class="fas fa-upload mr-2"></i>
                            <span id="materialFileLabel">Choose file</span>
                        </div>
                        <input type="file" id="material_file" name="material_file" 
                               class="file-input" required>
                    </div>
                    <small class="text-gray-500">Max file size: 20MB</small>
                </div>
                
                <div class="flex justify-end space-x-4 pt-4 border-t border-gray-200">
                    <button type="button" class="close-modal-btn px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Upload Material
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Include JS libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        $(document).ready(function() {
            // Initialize DataTable for registrations
            $('table').DataTable({
                responsive: true,
                pageLength: 10,
                lengthMenu: [5, 10, 25, 50, 100],
                language: {
                    search: "_INPUT_",
                    searchPlaceholder: "Search...",
                }
            });

            // Initialize date/time pickers
            flatpickr("#start_datetime", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                minDate: "today"
            });

            flatpickr("#end_datetime", {
                enableTime: true,
                dateFormat: "Y-m-d H:i",
                minDate: "today"
            });

            // Tab functionality
            $('.tab').click(function() {
                const tabId = $(this).data('tab');
                
                // Update active tab
                $('.tab').removeClass('active');
                $(this).addClass('active');
                
                // Show corresponding content
                $('.tab-content').removeClass('active');
                $(`#${tabId}-tab`).addClass('active');
            });

            // Material modal functionality
            $('#addMaterialBtn').click(function() {
                $('#addMaterialModal').fadeIn();
            });

            $('.close-modal, .close-modal-btn').click(function() {
                $('#addMaterialModal').fadeOut();
            });

            // Close modal when clicking outside
            $(window).click(function(event) {
                if ($(event.target).is('.modal')) {
                    $('.modal').fadeOut();
                }
            });

            // Form validation
            $('#workshopForm').submit(function(e) {
                const start = new Date($('#start_datetime').val());
                const end = new Date($('#end_datetime').val());
                
                if (start >= end) {
                    alert('End date/time must be after start date/time');
                    e.preventDefault();
                    return false;
                }
                
                if ($('#max_participants').val() < $('#current_registrations').val()) {
                    alert('Current registrations cannot exceed max participants');
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });

            // Material form validation
            $('#materialForm').submit(function(e) {
                const fileInput = $('#material_file')[0];
                if (fileInput.files.length > 0) {
                    const fileSize = fileInput.files[0].size / 1024 / 1024; // in MB
                    if (fileSize > 20) {
                        alert('File size must be less than 20MB');
                        e.preventDefault();
                        return false;
                    }
                }
                return true;
            });

            // Thumbnail preview
            $('#cover_image_file').change(function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        $('#thumbnailPreview').attr('src', e.target.result);
                        $('#fileLabel').text(file.name);
                    }
                    reader.readAsDataURL(file);
                }
            });

            // Material file label update
            $('#material_file').change(function(e) {
                const file = e.target.files[0];
                if (file) {
                    $('#materialFileLabel').text(file.name);
                }
            });

            // Success message fade out
            setTimeout(function() {
                $('.success-message').fadeOut();
            }, 3000);
        });
    </script>
</body>
</html>