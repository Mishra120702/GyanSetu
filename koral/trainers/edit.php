<?php
require_once '../db_connection.php';
require_once 'functions.php';

// Check admin permissions
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

if ($_SESSION['user_role'] !== 'admin') {
    header("Location: ../unauthorized.php");
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$trainerId = (int)$_GET['id'];
$errors = [];
$success = false;
$passwordChangeSuccess = false;
$profilePictureSuccess = false;

// Fetch trainer data
$stmt = $db->prepare("SELECT t.*, u.email, u.id as user_id 
                       FROM trainers t 
                       JOIN users u ON t.user_id = u.id 
                       WHERE t.id = ?");
$stmt->execute([$trainerId]);
$trainer = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainer) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if this is a profile picture change request
    if (isset($_POST['change_profile_picture']) && isset($_FILES['profile_picture'])) {
        $uploadDir = '../uploads/trainer_profile_pictures/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $file = $_FILES['profile_picture'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            $errors[] = 'Only JPG, PNG, and GIF files are allowed.';
        } elseif ($file['size'] > 2097152) { // 2MB
            $errors[] = 'File size must be less than 2MB.';
        } else {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'trainer_' . $trainerId . '_' . time() . '.' . $extension;
            $destination = $uploadDir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $destination)) {
                // Delete old profile picture if it exists
                if (!empty($trainer['profile_picture']) && file_exists($trainer['profile_picture'])) {
                    unlink($trainer['profile_picture']);
                }
                
                // Update database
                $stmt = $db->prepare("UPDATE trainers SET profile_picture = ? WHERE id = ?");
                if ($stmt->execute([$destination, $trainerId])) {
                    $profilePictureSuccess = true;
                    $_SESSION['success_message'] = 'Profile picture updated successfully';
                    // Refresh trainer data
                    $stmt = $db->prepare("SELECT t.*, u.email, u.id as user_id 
                                          FROM trainers t 
                                          JOIN users u ON t.user_id = u.id 
                                          WHERE t.id = ?");
                    $stmt->execute([$trainerId]);
                    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $errors[] = 'Failed to update profile picture in database';
                }
            } else {
                $errors[] = 'Failed to upload file';
            }
        }
    }
    // Check if this is a password change request
    elseif (isset($_POST['change_password'])) {
        $newPassword = trim($_POST['new_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');
        
        // Validate password
        if (empty($newPassword)) {
            $errors[] = 'New password is required';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'Password must be at least 8 characters long';
        } elseif ($newPassword !== $confirmPassword) {
            $errors[] = 'Passwords do not match';
        }
        
        if (empty($errors)) {
            try {
                // Start transaction
                $db->beginTransaction();
                
                // Update password in users table
                $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $result = $stmt->execute([$passwordHash, $trainer['user_id']]);
                
                if ($result && $stmt->rowCount() > 0) {
                    // Update trainer's updated_at timestamp
                    $stmt2 = $db->prepare("UPDATE trainers SET updated_at = NOW() WHERE id = ?");
                    $stmt2->execute([$trainerId]);
                    
                    $db->commit();
                    $passwordChangeSuccess = true;
                    $_SESSION['success_message'] = 'Password changed successfully';
                    
                    // Clear any existing password-related session data
                    unset($_SESSION['password_reset_required']);
                } else {
                    $db->rollBack();
                    $errors[] = 'Failed to update password. User record not found or no changes made.';
                }
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Failed to update password: ' . $e->getMessage();
                error_log("Password update error for trainer ID {$trainerId}, user ID {$trainer['user_id']}: " . $e->getMessage());
            }
        }
    } else {
        // Original form processing for trainer data
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $specialization = trim($_POST['specialization'] ?? '');
        $experience = (int)($_POST['experience'] ?? 0);
        $bio = trim($_POST['bio'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Basic validation
        if (empty($name)) $errors[] = 'Name is required';
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required';
        if ($experience < 0) $errors[] = 'Experience cannot be negative';

        if (empty($errors)) {
            // Check if email already exists for another user
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $trainer['user_id']]);
            
            if ($stmt->rowCount() > 0) {
                $errors[] = 'Email already exists for another user';
            } else {
                // Update records in a transaction
                $db->beginTransaction();
                
                try {
                    // Update user email
                    $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$email, $trainer['user_id']]);
                    
                    // Update trainer
                    $stmt = $db->prepare("UPDATE trainers 
                                          SET name = ?, specialization = ?, years_of_experience = ?, 
                                              bio = ?, is_active = ?, updated_at = NOW() 
                                          WHERE id = ?");
                    $stmt->execute([$name, $specialization, $experience, $bio, $isActive, $trainerId]);
                    
                    $db->commit();
                    $success = true;
                    
                    // Refresh trainer data
                    $stmt = $db->prepare("SELECT t.*, u.email 
                                          FROM trainers t 
                                          JOIN users u ON t.user_id = u.id 
                                          WHERE t.id = ?");
                    $stmt->execute([$trainerId]);
                    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Show success message
                    $_SESSION['success_message'] = 'Trainer updated successfully';
                } catch (Exception $e) {
                    $db->rollBack();
                    $errors[] = 'Failed to update trainer: ' . $e->getMessage();
                    error_log("Trainer update error for ID {$trainerId}: " . $e->getMessage());
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Trainer | ASD Academy</title>
    
    <!-- CSS Libraries -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="../css/admin.css">
    
    <style>
        :root {
            --primary: #3b82f6;
            --primary-light: #93c5fd;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #6366f1;
            --dark: #1f2937;
            --light: #f3f4f6;
        }
        
        .animate__animated {
            animation-duration: 0.5s;
        }
        
        .card {
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--dark);
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            background-color: white;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-check-input {
            width: 1rem;
            height: 1rem;
            margin-top: 0.15rem;
            margin-right: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 0.25rem;
            transition: all 0.2s;
        }
        
        .form-check-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
            border: 1px solid var(--primary);
        }
        
        .btn-primary:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            color: white;
        }
        
        .btn-outline-secondary {
            background-color: white;
            color: var(--dark);
            border: 1px solid #d1d5db;
        }
        
        .btn-outline-secondary:hover {
            background-color: #f3f4f6;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            color: var(--dark);
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
            border: 1px solid var(--danger);
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            color: white;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border-left: 4px solid var(--success);
        }
        
        .alert i {
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        .trainer-avatar {
            width: 100px;
            height: 100px;
            border-radius: 0.5rem;
            object-fit: cover;
            border: 3px solid white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            margin-bottom: 1rem;
        }
        
        .trainer-avatar:hover {
            transform: scale(1.05);
            box-shadow: 0 10px 15px rgba(0,0,0,0.1);
        }
        
        .loading-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.9);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        
        .loading-spinner.active {
            display: flex;
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .badge {
            padding: 0.35rem 0.65rem;
            border-radius: 50rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .badge-success {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .badge-danger {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .floating-label-group {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .floating-label {
            position: absolute;
            top: 0.5rem;
            left: 0.75rem;
            font-size: 0.75rem;
            color: #6b7280;
            background: white;
            padding: 0 0.25rem;
            transition: all 0.2s;
            pointer-events: none;
            transform-origin: left center;
        }
        
        .form-control:focus ~ .floating-label,
        .form-control:not(:placeholder-shown) ~ .floating-label {
            transform: translateY(-1rem) scale(0.85);
            color: var(--primary);
        }
        
        .form-control:focus ~ .floating-label {
            color: var(--primary);
        }
        
        .animate-bounce {
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 100% {
                transform: translateY(-5px);
            }
            50% {
                transform: translateY(5px);
            }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.4);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(59, 130, 246, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(59, 130, 246, 0);
            }
        }
        
        /* Password strength meter */
        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 2.5px;
            transition: all 0.3s;
        }
        
        .strength-0 {
            width: 0%;
            background-color: #ef4444;
        }
        
        .strength-1 {
            width: 25%;
            background-color: #ef4444;
        }
        
        .strength-2 {
            width: 50%;
            background-color: #f59e0b;
        }
        
        .strength-3 {
            width: 75%;
            background-color: #3b82f6;
        }
        
        .strength-4 {
            width: 100%;
            background-color: #10b981;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 0.5rem;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            animation: modalFadeIn 0.3s ease;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6b7280;
        }
        
        .close-modal:hover {
            color: #1f2937;
        }
        
        /* Profile picture modal styles */
        #profileModal .modal-content {
            max-width: 400px;
        }
        
        .preview-container {
            display: flex;
            justify-content: center;
            margin-bottom: 1.5rem;
        }
        
        .preview-image {
            max-width: 200px;
            max-height: 200px;
            border-radius: 0.5rem;
            object-fit: cover;
            border: 3px solid #e5e7eb;
        }
        
        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
            width: 100%;
        }
        
        .file-input-button {
            border: 1px dashed #d1d5db;
            border-radius: 0.375rem;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }
        
        .file-input-button:hover {
            border-color: var(--primary);
            background-color: #f8fafc;
        }
        
        .file-input-button i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .file-input-button span {
            display: block;
            font-size: 0.875rem;
            color: #6b7280;
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
        
        .password-requirements {
            font-size: 0.75rem;
            margin-top: 0.5rem;
            color: #6b7280;
        }
        
        .password-requirements ul {
            margin: 0.25rem 0 0 1rem;
            padding: 0;
        }
        
        .password-requirements li {
            list-style: none;
            margin-bottom: 0.25rem;
        }
        
        .password-requirements li.valid {
            color: #10b981;
        }
        
        .password-requirements li.invalid {
            color: #ef4444;
        }
        
        .password-requirements i {
            width: 1rem;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    <!-- Loading Spinner -->
    <div class="loading-spinner">
        <div class="spinner"></div>
        <div class="text-gray-600">Processing...</div>
    </div>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-user-edit text-blue-500"></i>
                <span>Edit Trainer</span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left mr-2"></i> Back to List
                </a>
            </div>
        </header>

        <div class="p-4 md:p-6">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger animate__animated animate__shakeX">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Error!</strong>
                        <ul class="mt-1 ml-4 list-disc">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            <?php elseif (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success animate__animated animate__fadeIn">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Success!</strong> <?= htmlspecialchars($_SESSION['success_message']) ?>
                    </div>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2">
                    <div class="card animate__animated animate__fadeIn">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-user-edit text-blue-500 mr-2"></i>
                                Trainer Information
                            </h2>
                            <span class="badge <?= $trainer['is_active'] ? 'badge-success' : 'badge-danger' ?>">
                                <?= $trainer['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <form method="POST" id="trainerForm">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="floating-label-group">
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?= htmlspecialchars($trainer['name']) ?>" 
                                               placeholder=" " required>
                                        <label class="floating-label">Full Name</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?= htmlspecialchars($trainer['email']) ?>" 
                                               placeholder=" " required>
                                        <label class="floating-label">Email Address</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="text" class="form-control" id="specialization" name="specialization" 
                                               value="<?= htmlspecialchars($trainer['specialization']) ?>" 
                                               placeholder=" ">
                                        <label class="floating-label">Specialization</label>
                                    </div>
                                    
                                    <div class="floating-label-group">
                                        <input type="number" class="form-control" id="experience" name="experience" 
                                               value="<?= htmlspecialchars($trainer['years_of_experience']) ?>" 
                                               min="0" placeholder=" ">
                                        <label class="floating-label">Years of Experience</label>
                                    </div>
                                    
                                    <div class="md:col-span-2 floating-label-group">
                                        <textarea class="form-control" id="bio" name="bio" rows="3" 
                                                  placeholder=" "><?= htmlspecialchars($trainer['bio']) ?></textarea>
                                        <label class="floating-label">Bio/Description</label>
                                    </div>
                                    
                                    <div class="md:col-span-2 flex items-center">
                                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                               <?= $trainer['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label ml-2" for="is_active">Mark as Active Trainer</label>
                                    </div>
                                </div>
                                
                                <div class="flex justify-end mt-6 space-x-3">
                                    <button type="button" onclick="openPasswordModal()" class="btn btn-danger">
                                        <i class="fas fa-key mr-2"></i> Change Password
                                    </button>
                                    <a href="view.php?id=<?= $trainerId ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-eye mr-2"></i> View Profile
                                    </a>
                                    <button type="submit" class="btn btn-primary pulse">
                                        <i class="fas fa-save mr-2"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div>
                    <div class="card animate__animated animate__fadeIn animate__delay-1s">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-id-card text-blue-500 mr-2"></i>
                                Trainer Photo
                            </h2>
                        </div>
                        <div class="card-body flex flex-col items-center">
                            <img src="<?= !empty($trainer['profile_picture']) ? $trainer['profile_picture'] : '../assets/images/default-avatar.svg' ?>" 
                                 class="trainer-avatar animate-bounce" 
                                 alt="<?= htmlspecialchars($trainer['name']) ?>"
                                 id="profileImagePreview">
                            <button type="button" onclick="openProfileModal()" class="btn btn-primary mt-2">
                                <i class="fas fa-camera mr-2"></i> Change Photo
                            </button>
                            <p class="text-sm text-gray-500 mt-3 text-center">
                                Recommended size: 500x500px<br>
                                Max file size: 2MB (JPG, PNG)
                            </p>
                        </div>
                    </div>
                    
                    <div class="card animate__animated animate__fadeIn animate__delay-2s mt-6">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="fas fa-chart-line text-blue-500 mr-2"></i>
                                Performance Stats
                            </h2>
                        </div>
                        <div class="card-body">
                            <div class="space-y-4">
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-sm font-medium text-gray-700">Rating</span>
                                        <span class="text-sm font-bold text-blue-600">4.8/5</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: 96%"></div>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-sm font-medium text-gray-700">Active Batches</span>
                                        <span class="text-sm font-bold text-blue-600">3</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-green-500 h-2.5 rounded-full" style="width: 60%"></div>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-sm font-medium text-gray-700">Completion Rate</span>
                                        <span class="text-sm font-bold text-blue-600">92%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-purple-500 h-2.5 rounded-full" style="width: 92%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Password Change Modal -->
    <div id="passwordModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-key mr-2 text-blue-500"></i>
                    Change Password
                </h3>
                <button class="close-modal" onclick="closePasswordModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="passwordForm" method="POST">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="mb-4">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                        <div class="password-strength strength-0" id="passwordStrength"></div>
                        <div class="password-requirements" id="passwordRequirements">
                            <ul>
                                <li id="req-length"><i class="fas fa-circle"></i> At least 8 characters</li>
                                <li id="req-lowercase"><i class="fas fa-circle"></i> At least one lowercase letter</li>
                                <li id="req-uppercase"><i class="fas fa-circle"></i> At least one uppercase letter</li>
                                <li id="req-number"><i class="fas fa-circle"></i> At least one number or special character</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        <small id="passwordMatch" class="text-gray-500"></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closePasswordModal()">
                    Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="submitPasswordForm()">
                    <i class="fas fa-save mr-2"></i> Update Password
                </button>
            </div>
        </div>
    </div>
    
    <!-- Profile Picture Change Modal -->
    <div id="profileModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="text-lg font-semibold text-gray-800">
                    <i class="fas fa-camera mr-2 text-blue-500"></i>
                    Change Profile Picture
                </h3>
                <button class="close-modal" onclick="closeProfileModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="profileForm" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="change_profile_picture" value="1">
                    
                    <div class="preview-container">
                        <img id="imagePreview" src="<?= !empty($trainer['profile_picture']) ? $trainer['profile_picture'] : '../assets/images/default-avatar.svg' ?>" 
                             class="preview-image" alt="Profile Preview">
                    </div>
                    
                    <div class="file-input-wrapper">
                        <label class="file-input-button">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span>Click to upload or drag and drop</span>
                            <span class="text-xs text-gray-400">JPG, PNG (Max 2MB)</span>
                            <input type="file" class="file-input" id="profile_picture" name="profile_picture" 
                                   accept="image/jpeg,image/png" onchange="previewImage(this)">
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeProfileModal()">
                    Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="submitProfileForm()">
                    <i class="fas fa-save mr-2"></i> Update Photo
                </button>
            </div>
        </div>
    </div>
    
    <!-- Include JS Libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize loading spinner
        const loadingSpinner = $('.loading-spinner');
        
        // Show loading spinner on form submission
        $('#trainerForm').on('submit', function() {
            loadingSpinner.addClass('active');
        });
        
        // Hide loading spinner when page is fully loaded
        $(window).on('load', function() {
            setTimeout(function() {
                loadingSpinner.removeClass('active');
            }, 500);
        });
        
        // Initialize tooltips
        $('[data-toggle="tooltip"]').tooltip();
        
        // Sidebar toggle for mobile
        window.toggleSidebar = function() {
            $('.sidebar').toggleClass('hidden');
        }
        
        // Add animation to form elements on focus
        $('.form-control').on('focus', function() {
            $(this).parent().addClass('animate__animated animate__pulse');
        }).on('blur', function() {
            $(this).parent().removeClass('animate__animated animate__pulse');
        });
        
        // Confirmation before leaving page with unsaved changes
        let formChanged = false;
        $('input, textarea, select').on('change keyup', function() {
            formChanged = true;
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Password strength checker with requirements
        function checkPasswordStrength(password) {
            let strength = 0;
            const requirements = {
                length: password.length >= 8,
                lowercase: /[a-z]/.test(password),
                uppercase: /[A-Z]/.test(password),
                number: /[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
            };
            
            // Update requirement indicators
            $('#req-length i').removeClass('fa-circle fa-check-circle').addClass(requirements.length ? 'fa-check-circle' : 'fa-circle');
            $('#req-length').removeClass('valid invalid').addClass(requirements.length ? 'valid' : 'invalid');
            
            $('#req-lowercase i').removeClass('fa-circle fa-check-circle').addClass(requirements.lowercase ? 'fa-check-circle' : 'fa-circle');
            $('#req-lowercase').removeClass('valid invalid').addClass(requirements.lowercase ? 'valid' : 'invalid');
            
            $('#req-uppercase i').removeClass('fa-circle fa-check-circle').addClass(requirements.uppercase ? 'fa-check-circle' : 'fa-circle');
            $('#req-uppercase').removeClass('valid invalid').addClass(requirements.uppercase ? 'valid' : 'invalid');
            
            $('#req-number i').removeClass('fa-circle fa-check-circle').addClass(requirements.number ? 'fa-check-circle' : 'fa-circle');
            $('#req-number').removeClass('valid invalid').addClass(requirements.number ? 'valid' : 'invalid');
            
            // Calculate strength
            if (requirements.length) strength++;
            if (requirements.lowercase) strength++;
            if (requirements.uppercase) strength++;
            if (requirements.number) strength++;
            
            return strength;
        }
        
        $('#new_password').on('keyup', function() {
            const password = $(this).val();
            const strength = checkPasswordStrength(password);
            $('#passwordStrength').removeClass().addClass('password-strength strength-' + strength);
        });
        
        // Password match checker
        $('#confirm_password').on('keyup', function() {
            const password = $('#new_password').val();
            const confirmPassword = $(this).val();
            
            if (confirmPassword.length === 0) {
                $('#passwordMatch').text('').removeClass('text-green-600 text-red-600');
            } else if (password === confirmPassword) {
                $('#passwordMatch').text('✓ Passwords match').removeClass('text-red-600').addClass('text-green-600');
            } else {
                $('#passwordMatch').text('✗ Passwords do not match').removeClass('text-green-600').addClass('text-red-600');
            }
        });
        
        // Success message animation
        <?php if ($success || $passwordChangeSuccess || $profilePictureSuccess): ?>
            Swal.fire({
                title: 'Success!',
                text: '<?= 
                    $profilePictureSuccess ? "Profile picture updated successfully" : 
                    ($passwordChangeSuccess ? "Password changed successfully" : "Trainer updated successfully") 
                ?>',
                icon: 'success',
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });
        <?php endif; ?>
    });
    
    // Modal functions
    window.openPasswordModal = function() {
        $('#passwordModal').css('display', 'flex');
        $('#new_password').focus();
        // Reset password requirements styling
        $('#passwordStrength').removeClass().addClass('password-strength strength-0');
        $('#passwordMatch').text('').removeClass('text-green-600 text-red-600');
        $('#req-length, #req-lowercase, #req-uppercase, #req-number').removeClass('valid invalid');
        $('#req-length i, #req-lowercase i, #req-uppercase i, #req-number i').removeClass('fa-check-circle').addClass('fa-circle');
    }
    
    window.closePasswordModal = function() {
        $('#passwordModal').hide();
        $('#passwordForm')[0].reset();
        $('#passwordStrength').removeClass().addClass('password-strength strength-0');
        $('#passwordMatch').text('').removeClass('text-green-600 text-red-600');
        $('#req-length, #req-lowercase, #req-uppercase, #req-number').removeClass('valid invalid');
        $('#req-length i, #req-lowercase i, #req-uppercase i, #req-number i').removeClass('fa-check-circle').addClass('fa-circle');
    }
    
    window.submitPasswordForm = function() {
        const newPassword = $('#new_password').val();
        const confirmPassword = $('#confirm_password').val();
        
        if (!newPassword || newPassword.length < 8) {
            Swal.fire({
                title: 'Error',
                text: 'Password must be at least 8 characters long',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        // Check all password requirements
        const hasLowercase = /[a-z]/.test(newPassword);
        const hasUppercase = /[A-Z]/.test(newPassword);
        const hasNumberOrSpecial = /[0-9!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(newPassword);
        
        if (!hasLowercase || !hasUppercase || !hasNumberOrSpecial) {
            Swal.fire({
                title: 'Error',
                text: 'Password must contain at least one uppercase letter, one lowercase letter, and one number or special character',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        if (newPassword !== confirmPassword) {
            Swal.fire({
                title: 'Error',
                text: 'Passwords do not match',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        // Confirm password change
        Swal.fire({
            title: 'Confirm Password Change',
            text: 'Are you sure you want to change the password for this trainer?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, change it!'
        }).then((result) => {
            if (result.isConfirmed) {
                $('.loading-spinner').addClass('active');
                $('#passwordForm').submit();
            }
        });
    }
    
    // Profile picture modal functions
    window.openProfileModal = function() {
        $('#profileModal').css('display', 'flex');
    }
    
    window.closeProfileModal = function() {
        $('#profileModal').hide();
        $('#profileForm')[0].reset();
        $('#imagePreview').attr('src', $('#profileImagePreview').attr('src'));
    }
    
    window.previewImage = function(input) {
        if (input.files && input.files[0]) {
            const file = input.files[0];
            const fileType = file.type;
            const fileSize = file.size;
            
            // Validate file type
            if (!['image/jpeg', 'image/png'].includes(fileType)) {
                Swal.fire({
                    title: 'Error',
                    text: 'Only JPG and PNG files are allowed',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                input.value = '';
                return;
            }
            
            // Validate file size (2MB)
            if (fileSize > 2097152) {
                Swal.fire({
                    title: 'Error',
                    text: 'File size must be less than 2MB',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
                input.value = '';
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#imagePreview').attr('src', e.target.result);
            }
            reader.readAsDataURL(file);
        }
    }
    
    window.submitProfileForm = function() {
        if (!$('#profile_picture').val()) {
            Swal.fire({
                title: 'Error',
                text: 'Please select an image file to upload',
                icon: 'error',
                confirmButtonText: 'OK'
            });
            return;
        }
        
        $('.loading-spinner').addClass('active');
        $('#profileForm').submit();
    }
    
    // Add styles for password requirements
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .password-requirements li.valid { color: #10b981; }
            .password-requirements li.invalid { color: #ef4444; }
            .password-requirements li i.fa-check-circle { color: #10b981; }
            .password-requirements li i.fa-circle { color: #6b7280; }
        `)
        .appendTo('head');
    </script>
</body>
</html>