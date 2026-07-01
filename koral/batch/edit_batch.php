<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get batch ID from URL
$batch_id = $_GET['id'] ?? null;

// Fetch batch data if ID is provided
$batch = null;
if ($batch_id) {
    $stmt = $db->prepare("SELECT b.*, t.name as mentor_name 
                         FROM batches b
                         LEFT JOIN trainers t ON b.batch_mentor_id = t.id
                         WHERE b.batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle form submission for updating batch
if (isset($_POST['update_batch'])) {
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // Get current batch status before update
        $stmt = $db->prepare("SELECT status FROM batches WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $old_status = $stmt->fetchColumn();
        $new_status = $_POST['status'];
        
        // Handle thumbnail upload
        $thumbnail_path = $batch['thumbnail_path']; // Keep existing by default
        
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] == 0) {
            $upload_dir = '../uploads/batch_thumbnails/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['thumbnail']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $file_type = mime_content_type($_FILES['thumbnail']['tmp_name']);
            
            if (in_array($file_type, $allowed_types)) {
                if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $target_file)) {
                    // Delete old thumbnail if it exists and is not default
                    if ($thumbnail_path && file_exists('../' . $thumbnail_path) && 
                        !str_contains($thumbnail_path, 'default_thumbnail')) {
                        unlink('../' . $thumbnail_path);
                    }
                    $thumbnail_path = 'uploads/batch_thumbnails/' . $file_name;
                }
            }
        }
        
        // Prepare the update statement for batch
        $stmt = $db->prepare("UPDATE batches SET 
            batch_name = ?, 
            start_date = ?, 
            end_date = ?, 
            time_slot = ?, 
            platform = ?, 
            meeting_link = ?, 
            max_students = ?, 
            current_enrollment = ?, 
            academic_year = ?,
            batch_mentor_id = ?, 
            mode = ?, 
            status = ?,
            thumbnail_path = ?,
            course_description = ?
            WHERE batch_id = ?");
        
        // Execute batch update
        $success = $stmt->execute([
            $_POST['batch_name'],
            $_POST['start_date'],
            $_POST['end_date'],
            $_POST['time_slot'],
            $_POST['platform'],
            $_POST['meeting_link'],
            $_POST['max_students'],
            $_POST['current_enrollment'],
            $_POST['academic_year'],
            $_POST['batch_mentor_id'],
            $_POST['mode'],
            $new_status,
            $thumbnail_path,
            $_POST['course_description'],
            $batch_id
        ]);
        
        if (!$success) {
            throw new Exception("Error updating batch details.");
        }
        
        // Check if batch status changed to "completed"
        if ($old_status !== 'completed' && $new_status === 'completed') {
            // Get all active students in this batch (from all batch fields)
            $stmt = $db->prepare("SELECT student_id FROM students 
                                 WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) 
                                 AND current_status = 'active'");
            $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $active_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($active_students) > 0) {
                // Update all active students in this batch to "completed" status
                $placeholders = implode(',', array_fill(0, count($active_students), '?'));
                $stmt = $db->prepare("UPDATE students SET current_status = 'completed' WHERE student_id IN ($placeholders)");
                
                // Execute with student IDs as parameters
                $stmt->execute($active_students);
                
                // Log the status change for each student
                $logStmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, processed_at) VALUES (?, 'completed', 'Batch marked as completed', ?, NOW())");
                
                foreach ($active_students as $student_id) {
                    $logStmt->execute([$student_id, $_SESSION['user_id']]);
                }
                
                $_SESSION['success_message'] = "Batch updated successfully! " . count($active_students) . " students marked as completed.";
            } else {
                $_SESSION['success_message'] = "Batch updated successfully! No active students to mark as completed.";
            }
        } elseif ($old_status === 'completed' && $new_status !== 'completed') {
            // If batch status changed from completed to something else, revert student statuses
            $stmt = $db->prepare("SELECT student_id FROM students 
                                 WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) 
                                 AND current_status = 'completed'");
            $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $completed_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($completed_students) > 0) {
                // Update completed students back to active status
                $placeholders = implode(',', array_fill(0, count($completed_students), '?'));
                $stmt = $db->prepare("UPDATE students SET current_status = 'active' WHERE student_id IN ($placeholders)");
                
                // Execute with student IDs as parameters
                $stmt->execute($completed_students);
                
                // Log the status change for each student
                $logStmt = $db->prepare("INSERT INTO student_status_log (student_id, action, reason, processed_by, processed_at) VALUES (?, 'reactivated', 'Batch status changed from completed', ?, NOW())");
                
                foreach ($completed_students as $student_id) {
                    $logStmt->execute([$student_id, $_SESSION['user_id']]);
                }
                
                $_SESSION['success_message'] = "Batch updated successfully! " . count($completed_students) . " students reverted to active status.";
            } else {
                $_SESSION['success_message'] = "Batch updated successfully!";
            }
        } else {
            $_SESSION['success_message'] = "Batch updated successfully!";
        }
        
        // Commit transaction
        $db->commit();
        
    } catch (Exception $e) {
        // Roll back transaction if something failed
        $db->rollBack();
        $_SESSION['error_message'] = "Error updating batch: " . $e->getMessage();
    }
    
    // Redirect back to batch list
    header("Location: batch_list.php");
    exit();
}

// If batch not found, redirect to list
if (!$batch) {
    header("Location: batch_list.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Batch - ASD Academy</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --purple: #9c27b0;
            --light: #f8f9fa;
            --dark: #212529;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fb;
            color: #333;
            transition: all 0.3s ease;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.12);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }
        
        .minimal-input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .minimal-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        /* Status badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .status-ongoing {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-completed {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .status-upcoming {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-cancelled {
            background-color: #fee2e2;
            color: #b91c1c;
        }
        
        /* Thumbnail preview */
        .thumbnail-preview {
            width: 200px;
            height: 150px;
            border-radius: 8px;
            object-fit: cover;
            border: 2px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .thumbnail-preview:hover {
            border-color: var(--primary);
            transform: scale(1.05);
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, var(--secondary), var(--primary));
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-edit text-blue-500"></i>
                <span>Edit Batch: <?= htmlspecialchars($batch['batch_id']) ?></span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="batch_list.php" class="text-gray-600 hover:text-gray-800">
                    <i class="fas fa-arrow-left mr-1"></i> Back to List
                </a>
            </div>
        </header>

        <div class="p-4 md:p-6 animate-fade-in">
            <!-- Notification Area -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 animate__animated animate__slideInDown">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 animate__animated animate__slideInDown">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Main Form Card -->
            <div class="card p-6 mb-6">
                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="update_batch" value="1">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Batch ID (Read-only) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-hashtag mr-2 text-blue-500"></i>Batch ID
                            </label>
                            <input type="text" class="minimal-input bg-gray-100" 
                                   value="<?= htmlspecialchars($batch['batch_id']) ?>" readonly>
                            <small class="text-gray-500 text-xs">Batch ID cannot be changed</small>
                        </div>
                        
                        <!-- Batch Name -->
                        <div>
                            <label for="batch_name" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-book mr-2 text-blue-500"></i>Course Name*
                            </label>
                            <input type="text" id="batch_name" name="batch_name" 
                                   class="minimal-input" value="<?= htmlspecialchars($batch['batch_name']) ?>" required>
                        </div>
                    </div>

                    <!-- Course Description -->
                    <div class="mb-6">
                        <label for="course_description" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-align-left mr-2 text-blue-500"></i>Course Description
                        </label>
                        <textarea id="course_description" name="course_description" 
                                  class="minimal-input" rows="3"
                                  placeholder="Enter course description..."><?= htmlspecialchars($batch['course_description'] ?? '') ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Start Date -->
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-plus mr-2 text-blue-500"></i>Start Date*
                            </label>
                            <input type="date" id="start_date" name="start_date" 
                                   class="minimal-input" value="<?= htmlspecialchars($batch['start_date']) ?>" required>
                        </div>
                        
                        <!-- End Date -->
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-minus mr-2 text-blue-500"></i>End Date*
                            </label>
                            <input type="date" id="end_date" name="end_date" 
                                   class="minimal-input" value="<?= htmlspecialchars($batch['end_date']) ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Time Slot -->
                        <div>
                            <label for="time_slot" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="far fa-clock mr-2 text-blue-500"></i>Time Slot
                            </label>
                            <input type="text" id="time_slot" name="time_slot" 
                                   class="minimal-input" value="<?= htmlspecialchars($batch['time_slot']) ?>"
                                   placeholder="e.g., 10:00 AM - 12:00 PM">
                        </div>
                        
                        <!-- Max Students -->
                        <div>
                            <label for="max_students" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-users mr-2 text-blue-500"></i>Max Students*
                            </label>
                            <input type="number" id="max_students" name="max_students" min="1" 
                                   class="minimal-input" value="<?= htmlspecialchars($batch['max_students']) ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Current Enrollment -->
                        <div>
                            <label for="current_enrollment" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-user-check mr-2 text-blue-500"></i>Current Enrollment
                            </label>
                            <input type="number" id="current_enrollment" name="current_enrollment" min="0" 
                                   class="minimal-input" value="<?= htmlspecialchars($batch['current_enrollment']) ?>">
                        </div>
                        
                        <!-- Academic Year -->
                        <div>
                            <label for="academic_year" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-graduation-cap mr-2 text-blue-500"></i>Academic Year
                            </label>
                            <input type="text" id="academic_year" name="academic_year" 
                                   class="minimal-input" value="<?= htmlspecialchars($batch['academic_year']) ?>"
                                   placeholder="e.g., 2024-25">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Batch Mentor -->
                        <div>
                            <label for="batch_mentor_id" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-user-tie mr-2 text-blue-500"></i>Batch Mentor
                            </label>
                            <select id="batch_mentor_id" name="batch_mentor_id" class="minimal-input">
                                <option value="">Select Mentor</option>
                                <?php 
                                $mentors = $db->query("SELECT id, name FROM trainers WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($mentors as $mentor): ?>
                                    <option value="<?= $mentor['id'] ?>" <?= $batch['batch_mentor_id'] == $mentor['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mentor['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Thumbnail Upload -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-image mr-2 text-blue-500"></i>Batch Thumbnail
                            </label>
                            <div class="flex flex-col md:flex-row gap-4 items-center">
                                <div class="relative">
                                    <img id="thumbnailPreview" 
                                         src="<?= !empty($batch['thumbnail_path']) ? '../' . htmlspecialchars($batch['thumbnail_path']) : 'https://via.placeholder.com/200x150?text=No+Thumbnail' ?>" 
                                         alt="Thumbnail Preview" class="thumbnail-preview">
                                    <?php if (!empty($batch['thumbnail_path'])): ?>
                                        <a href="../<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                                           target="_blank" 
                                           class="absolute bottom-2 right-2 bg-blue-500 text-white p-1 rounded-full hover:bg-blue-600">
                                            <i class="fas fa-external-link-alt text-xs"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1">
                                    <input type="file" id="thumbnail" name="thumbnail" 
                                           class="minimal-input p-2" 
                                           accept="image/*"
                                           onchange="previewThumbnail(this)">
                                    <small class="text-gray-500 text-xs">Max size: 2MB. Supported: JPG, PNG, GIF, WebP</small>
                                    <?php if (!empty($batch['thumbnail_path'])): ?>
                                        <div class="mt-2">
                                            <label class="inline-flex items-center">
                                                <input type="checkbox" name="remove_thumbnail" value="1" 
                                                       class="h-4 w-4 text-blue-600">
                                                <span class="ml-2 text-sm text-gray-600">Remove current thumbnail</span>
                                            </label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Mode Selection -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-laptop-house mr-2 text-blue-500"></i>Mode*
                        </label>
                        <div class="flex space-x-4">
                            <label class="inline-flex items-center">
                                <input type="radio" name="mode" value="online" 
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500" 
                                       <?= $batch['mode'] === 'online' ? 'checked' : '' ?>>
                                <span class="ml-2 text-gray-700"><i class="fas fa-wifi mr-1"></i> Online</span>
                            </label>
                            <label class="inline-flex items-center">
                                <input type="radio" name="mode" value="offline" 
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500" 
                                       <?= $batch['mode'] === 'offline' ? 'checked' : '' ?>>
                                <span class="ml-2 text-gray-700"><i class="fas fa-building mr-1"></i> Offline</span>
                            </label>
                        </div>
                    </div>

                    <!-- Online Fields -->
                    <div id="onlineFields" class="mb-6" style="<?= $batch['mode'] === 'online' ? '' : 'display: none;' ?>">
                        <h3 class="text-lg font-semibold mb-4 text-gray-700">
                            <i class="fas fa-link mr-2"></i>Online Class Details
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Platform -->
                            <div>
                                <label for="platform" class="block text-sm font-medium text-gray-700 mb-2">
                                    Platform
                                </label>
                                <select id="platform" name="platform" class="minimal-input">
                                    <option value="">Select Platform</option>
                                    <option value="Google Meet" <?= $batch['platform'] === 'Google Meet' ? 'selected' : '' ?>>Google Meet</option>
                                    <option value="Zoom" <?= $batch['platform'] === 'Zoom' ? 'selected' : '' ?>>Zoom</option>
                                    <option value="Microsoft Teams" <?= $batch['platform'] === 'Microsoft Teams' ? 'selected' : '' ?>>Microsoft Teams</option>
                                    <option value="Other" <?= $batch['platform'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            
                            <!-- Meeting Link -->
                            <div>
                                <label for="meeting_link" class="block text-sm font-medium text-gray-700 mb-2">
                                    Meeting Link
                                </label>
                                <input type="url" id="meeting_link" name="meeting_link" 
                                       class="minimal-input" value="<?= htmlspecialchars($batch['meeting_link']) ?>" 
                                       placeholder="https://meet.google.com/abc-xyz">
                            </div>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="mb-8">
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-info-circle mr-2 text-blue-500"></i>Status*
                        </label>
                        <select id="status" name="status" class="minimal-input" required>
                            <option value="upcoming" <?= $batch['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                            <option value="ongoing" <?= $batch['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                            <option value="completed" <?= $batch['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="cancelled" <?= $batch['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                        <small class="text-gray-500 text-xs">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            Changing status to "Completed" will mark all active students in this batch as "Completed"
                        </small>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-4 pt-6 border-t border-gray-200">
                        <a href="batch_list.php" class="btn-secondary">
                            <i class="fas fa-times mr-2"></i> Cancel
                        </a>
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save mr-2"></i> Update Batch
                        </button>
                    </div>
                </form>
            </div>

            <!-- Current Batch Info Card -->
            <div class="card p-6">
                <h3 class="text-lg font-semibold mb-4 text-gray-700 flex items-center">
                    <i class="fas fa-info-circle mr-2 text-blue-500"></i>Current Batch Information
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <h4 class="font-medium text-blue-700 mb-2">
                            <i class="fas fa-users mr-2"></i>Student Distribution
                        </h4>
                        <?php
                        // Get actual student counts from all batch fields
                        $counts = $db->prepare("
                            SELECT 
                                (SELECT COUNT(*) FROM students WHERE batch_name = ? AND current_status = 'active') as batch_name,
                                (SELECT COUNT(*) FROM students WHERE batch_name_2 = ? AND current_status = 'active') as batch_name_2,
                                (SELECT COUNT(*) FROM students WHERE batch_name_3 = ? AND current_status = 'active') as batch_name_3,
                                (SELECT COUNT(*) FROM students WHERE batch_name_4 = ? AND current_status = 'active') as batch_name_4
                        ");
                        $counts->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
                        $student_counts = $counts->fetch(PDO::FETCH_ASSOC);
                        $total_active = array_sum($student_counts);
                        ?>
                        <p class="text-2xl font-bold text-blue-600"><?= $total_active ?></p>
                        <p class="text-sm text-gray-600">Active Students</p>
                    </div>
                    
                    <div class="bg-green-50 p-4 rounded-lg">
                        <h4 class="font-medium text-green-700 mb-2">
                            <i class="fas fa-calendar-alt mr-2"></i>Schedule
                        </h4>
                        <p class="text-lg font-semibold text-green-600">
                            <?= date('d M Y', strtotime($batch['start_date'])) ?> - <?= date('d M Y', strtotime($batch['end_date'])) ?>
                        </p>
                        <?php if ($batch['time_slot']): ?>
                            <p class="text-sm text-gray-600"><?= htmlspecialchars($batch['time_slot']) ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="bg-purple-50 p-4 rounded-lg">
                        <h4 class="font-medium text-purple-700 mb-2">
                            <i class="fas fa-chart-pie mr-2"></i>Enrollment Status
                        </h4>
                        <?php
                        $enrollmentPercent = $batch['max_students'] > 0 ? 
                            ($total_active / $batch['max_students']) * 100 : 0;
                        ?>
                        <p class="text-2xl font-bold text-purple-600"><?= round($enrollmentPercent) ?>%</p>
                        <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
                            <div class="bg-purple-600 h-2 rounded-full" style="width: <?= min($enrollmentPercent, 100) ?>%"></div>
                        </div>
                        <p class="text-sm text-gray-600 mt-1"><?= $total_active ?>/<?= $batch['max_students'] ?> seats</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Show/hide online fields based on mode selection
            $('input[name="mode"]').change(function() {
                if ($(this).val() === 'online') {
                    $('#onlineFields').slideDown();
                } else {
                    $('#onlineFields').slideUp();
                }
            });

            // Form validation
            $('form').on('submit', function(e) {
                let valid = true;
                
                // Check if end date is after start date
                const startDate = new Date($('#start_date').val());
                const endDate = new Date($('#end_date').val());
                
                if (endDate <= startDate) {
                    alert('⚠️ End date must be after start date');
                    valid = false;
                }

                // Check if current enrollment doesn't exceed max students
                const currentEnrollment = parseInt($('#current_enrollment').val()) || 0;
                const maxStudents = parseInt($('#max_students').val()) || 0;
                
                if (currentEnrollment > maxStudents) {
                    alert('⚠️ Current enrollment cannot exceed maximum students');
                    valid = false;
                }
                
                // Check if max students is positive
                if (maxStudents <= 0) {
                    alert('⚠️ Maximum students must be greater than 0');
                    valid = false;
                }
                
                if (!valid) {
                    e.preventDefault();
                } else {
                    // Show loading indicator
                    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i> Updating...');
                    $('button[type="submit"]').prop('disabled', true);
                }
            });
            
            // Check for remove thumbnail checkbox
            $('input[name="remove_thumbnail"]').change(function() {
                if ($(this).is(':checked')) {
                    $('#thumbnail').prop('disabled', true);
                    $('#thumbnailPreview').attr('src', 'https://via.placeholder.com/200x150?text=No+Thumbnail');
                } else {
                    $('#thumbnail').prop('disabled', false);
                    // Restore original thumbnail
                    $('#thumbnailPreview').attr('src', '<?= !empty($batch['thumbnail_path']) ? '../' . htmlspecialchars($batch['thumbnail_path']) : 'https://via.placeholder.com/200x150?text=No+Thumbnail' ?>');
                }
            });
        });
        
        function previewThumbnail(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Check file size (max 2MB)
                if (file.size > 2 * 1024 * 1024) {
                    alert('File size must be less than 2MB');
                    input.value = '';
                    return;
                }
                
                // Check file type
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    alert('Please select a valid image file (JPG, PNG, GIF, or WebP)');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    $('#thumbnailPreview').attr('src', e.target.result);
                    $('input[name="remove_thumbnail"]').prop('checked', false);
                };
                reader.readAsDataURL(file);
            }
        }
    </script>
</body>
</html>