<?php
session_start();
require_once '../../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

$student_user_id = $_SESSION['user_id'];

// Get student information
$student_query = $db->prepare("
    SELECT s.*, u.email 
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get student's active batches
$batches = [];
$batch_ids = [];

if (!empty($student['batch_name'])) {
    $batch_ids[] = $student['batch_name'];
}
if (!empty($student['batch_name_2'])) {
    $batch_ids[] = $student['batch_name_2'];
}
if (!empty($student['batch_name_3'])) {
    $batch_ids[] = $student['batch_name_3'];
}

if (!empty($batch_ids)) {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $batches_query = $db->prepare("
        SELECT * FROM batches 
        WHERE batch_id IN ($placeholders)
        AND status IN ('upcoming', 'ongoing')
        ORDER BY batch_name
    ");
    $batches_query->execute($batch_ids);
    $active_batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);
} else {
    $active_batches = [];
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_leave'])) {
    
    // Validate required fields (removed counselling_request, acceptable_situation, support_needed)
    $required_fields = [
        'batch_id', 'start_date', 'end_date', 'reason_category', 
        'reason_detail', 'absence_type', 'informed_academy',
        'course_importance', 'content_value', 'topic_understanding',
        'practical_ability', 'unique_learning', 'loss_reflection',
        'future_commitment'
    ];
    
    $valid = true;
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field]) && $_POST[$field] !== '0') {
            $valid = false;
            $error_message = "Please fill in all required fields.";
            break;
        }
    }
    
    // Check responsibility acceptance
    if (!isset($_POST['responsibility_acceptance'])) {
        $valid = false;
        $error_message = "Please accept the responsibility statement.";
    }
    
    // Calculate total days
    $start_date = new DateTime($_POST['start_date']);
    $end_date = new DateTime($_POST['end_date']);
    $interval = $start_date->diff($end_date);
    $total_days = $interval->days + 1;
    
    // Handle file upload
    $prescription_path = null;
    if (isset($_FILES['medical_prescription']) && $_FILES['medical_prescription']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/leave_prescriptions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $file_type = $_FILES['medical_prescription']['type'];
        
        if (in_array($file_type, $allowed_types)) {
            $file_extension = pathinfo($_FILES['medical_prescription']['name'], PATHINFO_EXTENSION);
            $file_name = 'prescription_' . $student['student_id'] . '_' . time() . '.' . $file_extension;
            $target_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['medical_prescription']['tmp_name'], $target_path)) {
                $prescription_path = 'uploads/leave_prescriptions/' . $file_name;
            }
        }
    }
    
    if ($valid) {
        // Generate application number
        $year = date('Y');
        $month = date('m');
        $app_query = $db->prepare("SELECT COUNT(*) as count FROM leave_applications WHERE application_no LIKE 'LEAVE-$year$month%'");
        $app_query->execute();
        $count = $app_query->fetch(PDO::FETCH_ASSOC)['count'] + 1;
        $application_no = 'LEAVE-' . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
        
        // Insert leave application (removed counselling_request, acceptable_situation, support_needed)
        $stmt = $db->prepare("
            INSERT INTO leave_applications (
                application_no, student_id, student_name, batch_id, email,
                start_date, end_date, total_days, reason_category, reason_detail,
                absence_type, informed_academy, medical_prescription,
                course_importance, content_value, topic_understanding,
                practical_ability, unique_learning, loss_reflection,
                future_commitment, responsibility_acceptance, status
            ) VALUES (
                :application_no, :student_id, :student_name, :batch_id, :email,
                :start_date, :end_date, :total_days, :reason_category, :reason_detail,
                :absence_type, :informed_academy, :medical_prescription,
                :course_importance, :content_value, :topic_understanding,
                :practical_ability, :unique_learning, :loss_reflection,
                :future_commitment, :responsibility_acceptance, 'pending'
            )
        ");
        
        $result = $stmt->execute([
            ':application_no' => $application_no,
            ':student_id' => $student['student_id'],
            ':student_name' => $student['first_name'] . ' ' . $student['last_name'],
            ':batch_id' => $_POST['batch_id'],
            ':email' => $student['email'],
            ':start_date' => $_POST['start_date'],
            ':end_date' => $_POST['end_date'],
            ':total_days' => $total_days,
            ':reason_category' => $_POST['reason_category'],
            ':reason_detail' => $_POST['reason_detail'],
            ':absence_type' => $_POST['absence_type'],
            ':informed_academy' => $_POST['informed_academy'],
            ':medical_prescription' => $prescription_path,
            ':course_importance' => $_POST['course_importance'],
            ':content_value' => $_POST['content_value'],
            ':topic_understanding' => $_POST['topic_understanding'],
            ':practical_ability' => $_POST['practical_ability'],
            ':unique_learning' => $_POST['unique_learning'],
            ':loss_reflection' => $_POST['loss_reflection'],
            ':future_commitment' => $_POST['future_commitment'],
            ':responsibility_acceptance' => isset($_POST['responsibility_acceptance']) ? 1 : 0
        ]);
        
        if ($result) {
            $application_id = $db->lastInsertId();
            
            // Add to history
            $history_stmt = $db->prepare("
                INSERT INTO leave_application_history (application_id, action, action_by)
                VALUES (:application_id, 'submitted', :action_by)
            ");
            $history_stmt->execute([
                ':application_id' => $application_id,
                ':action_by' => $_SESSION['user_id']
            ]);
            
            $success_message = "Leave application submitted successfully! Application Number: " . $application_no;
            
            // Redirect to success page
            header("Location: ../my_leaves.php?success=1&app_no=" . urlencode($application_no));
            exit();
        } else {
            $error_message = "Failed to submit leave application. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply for Leave - ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out forwards;
        }
        
        .animate-slide-left {
            animation: slideInLeft 0.5s ease-out forwards;
        }
        
        .animate-slide-right {
            animation: slideInRight 0.5s ease-out forwards;
        }
        
        .progress-step {
            transition: all 0.3s ease;
            position: relative;
        }
        
        .progress-step.completed {
            background-color: #10b981 !important;
            color: white !important;
        }
        
        .progress-step.active {
            background-color: #3b82f6 !important;
            color: white !important;
            transform: scale(1.1);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.3);
        }
        
        .progress-bar-fill {
            transition: width 0.3s ease;
        }
        
        .radio-card {
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .radio-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.2);
        }
        
        .radio-card.selected {
            border-color: #3b82f6 !important;
            background-color: #eff6ff !important;
        }
        
        .form-section {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        
        .form-section.hidden {
            display: none !important;
        }
        
        .input-error {
            border-color: #ef4444 !important;
            background-color: #fef2f2 !important;
        }
        
        .error-message {
            color: #ef4444;
            font-size: 0.75rem;
            margin-top: 0.25rem;
        }
        
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        @media (max-width: 768px) {
            .container-custom {
                padding-left: 1rem;
                padding-right: 1rem;
            }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50">
    <?php include '../../header.php'; ?>
    <?php include '../side.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <div class="container-custom max-w-5xl mx-auto px-4 py-6 md:py-8">
            <!-- Page Header -->
            <div class="mb-8 animate-fade-in">
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-calendar-alt text-blue-600 mr-3"></i>
                    Apply for Leave
                </h1>
                <p class="text-gray-600 mt-2">Please fill out the application form carefully. All fields marked with * are required.</p>
            </div>
            
            <!-- Error Message -->
            <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-700 rounded-lg animate-fade-in">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span><?= htmlspecialchars($error_message) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- No Batches Message -->
            <?php if (empty($active_batches)): ?>
                <div class="bg-white rounded-2xl shadow-xl p-8 text-center animate-fade-in">
                    <div class="bg-yellow-100 inline-block p-6 rounded-full mb-4">
                        <i class="fas fa-exclamation-triangle text-yellow-600 text-4xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-800 mb-2">No Active Batches</h3>
                    <p class="text-gray-600 mb-4">You don't have any active batches to apply for leave.</p>
                    <a href="my_leaves.php" class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i> Back to My Leaves
                    </a>
                </div>
            <?php else: ?>
                <!-- Main Form -->
                <form method="POST" enctype="multipart/form-data" id="leaveForm" class="bg-white rounded-2xl shadow-xl overflow-hidden animate-fade-in">
                    <!-- Progress Bar -->
                    <div class="bg-gray-50 border-b border-gray-200 px-6 py-4">
                        <div class="flex items-center justify-between max-w-2xl mx-auto">
                            <div class="flex items-center flex-1">
                                <div class="progress-step w-10 h-10 rounded-full bg-green-500 text-white flex items-center justify-center font-bold text-sm" id="step1">1</div>
                                <div class="flex-1 h-2 bg-gray-200 mx-2">
                                    <div class="h-full bg-green-500 progress-bar-fill" style="width: 0%" id="progress1"></div>
                                </div>
                                <div class="progress-step w-10 h-10 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center font-bold text-sm" id="step2">2</div>
                                <div class="flex-1 h-2 bg-gray-200 mx-2">
                                    <div class="h-full bg-blue-500 progress-bar-fill" style="width: 0%" id="progress2"></div>
                                </div>
                                <div class="progress-step w-10 h-10 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center font-bold text-sm" id="step3">3</div>
                                <div class="flex-1 h-2 bg-gray-200 mx-2">
                                    <div class="h-full bg-blue-500 progress-bar-fill" style="width: 0%" id="progress3"></div>
                                </div>
                                <div class="progress-step w-10 h-10 rounded-full bg-gray-200 text-gray-600 flex items-center justify-center font-bold text-sm" id="step4">4</div>
                            </div>
                        </div>
                        <div class="flex justify-between max-w-2xl mx-auto mt-2 text-xs text-gray-500">
                            <span>Basic Info</span>
                            <span>Reason</span>
                            <span>Feedback</span>
                            <span>Commitment</span>
                        </div>
                    </div>
                    
                    <!-- Form Sections -->
                    <div class="p-6 md:p-8">
                        <!-- Section 1: Basic Information -->
                        <div id="section1" class="form-section">
                            <div class="mb-6">
                                <div class="flex items-center mb-4">
                                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-info-circle text-blue-600"></i>
                                    </div>
                                    <h3 class="text-lg font-bold text-gray-800">Basic Information</h3>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Batch *</label>
                                        <select name="batch_id" id="batch_id" required 
                                                class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                            <option value="">-- Select Batch --</option>
                                            <?php foreach ($active_batches as $batch): ?>
                                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                                                    <?= htmlspecialchars($batch['batch_name']) ?> (<?= htmlspecialchars($batch['batch_id']) ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Student Name</label>
                                        <input type="text" value="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>" 
                                               class="w-full px-4 py-3 bg-gray-100 border border-gray-300 rounded-xl text-gray-600" readonly>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Student ID</label>
                                        <input type="text" value="<?= htmlspecialchars($student['student_id']) ?>" 
                                               class="w-full px-4 py-3 bg-gray-100 border border-gray-300 rounded-xl text-gray-600" readonly>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                                        <input type="email" value="<?= htmlspecialchars($student['email']) ?>" 
                                               class="w-full px-4 py-3 bg-gray-100 border border-gray-300 rounded-xl text-gray-600" readonly>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Leave Start Date *</label>
                                        <input type="date" name="start_date" id="start_date" required 
                                               min="<?= date('Y-m-d') ?>" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Leave End Date *</label>
                                        <input type="date" name="end_date" id="end_date" required 
                                               min="<?= date('Y-m-d') ?>" 
                                               class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Total Days</label>
                                        <input type="text" id="total_days_display" readonly 
                                               class="w-full px-4 py-3 bg-gray-100 border border-gray-300 rounded-xl text-gray-600">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Section 2: Reason for Absence -->
                        <div id="section2" class="form-section hidden">
                            <div class="mb-6">
                                <div class="flex items-center mb-4">
                                    <div class="bg-yellow-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-question-circle text-yellow-600"></i>
                                    </div>
                                    <h3 class="text-lg font-bold text-gray-800">Reason for Absence</h3>
                                </div>
                                
                                <div class="space-y-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Main Reason for Missing Class *</label>
                                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                                            <?php 
                                            $reasons = ['Health Issue', 'Family Emergency', 'Personal Work', 'College Work & Exam', 'Other'];
                                            foreach ($reasons as $reason): 
                                            ?>
                                            <label class="radio-card relative border-2 rounded-xl p-4 cursor-pointer transition-all border-gray-200 hover:border-blue-300">
                                                <input type="radio" name="reason_category" value="<?= $reason ?>" class="absolute opacity-0" required>
                                                <div class="flex flex-col items-center text-center">
                                                    <?php
                                                    $icon = match($reason) {
                                                        'Health Issue' => 'fa-heartbeat',
                                                        'Family Emergency' => 'fa-users',
                                                        'Personal Work' => 'fa-user-cog',
                                                        'College Work & Exam' => 'fa-graduation-cap',
                                                        'Other' => 'fa-ellipsis-h'
                                                    };
                                                    ?>
                                                    <i class="fas <?= $icon ?> text-2xl mb-2 text-gray-500"></i>
                                                    <span class="text-sm text-gray-600"><?= $reason ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Detailed Reason *</label>
                                        <textarea name="reason_detail" id="reason_detail" required rows="4" 
                                                  class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                                  placeholder="Please provide detailed reason for your absence..."></textarea>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Medical Prescription (if applicable)</label>
                                        <div class="relative border-2 border-dashed border-gray-300 rounded-xl p-6 text-center hover:border-blue-500 transition-all group">
                                            <input type="file" name="medical_prescription" id="medical_prescription" accept="image/*,.pdf" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 group-hover:text-blue-500 transition-colors"></i>
                                            <p class="mt-2 text-sm text-gray-600">Click or drag to upload prescription</p>
                                            <p class="text-xs text-gray-500 mt-1">PDF, JPG, PNG (Max 5MB)</p>
                                            <div id="file_name_display" class="mt-2 text-sm text-green-600 hidden"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-3">Was your absence planned? *</label>
                                            <div class="flex space-x-6">
                                                <label class="flex items-center cursor-pointer">
                                                    <input type="radio" name="absence_type" value="Planned" class="mr-2" required>
                                                    <span>Planned</span>
                                                </label>
                                                <label class="flex items-center cursor-pointer">
                                                    <input type="radio" name="absence_type" value="Sudden" class="mr-2">
                                                    <span>Sudden</span>
                                                </label>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-3">Did you inform the academy before? *</label>
                                            <div class="flex space-x-6">
                                                <label class="flex items-center cursor-pointer">
                                                    <input type="radio" name="informed_academy" value="Yes" class="mr-2" required>
                                                    <span>Yes</span>
                                                </label>
                                                <label class="flex items-center cursor-pointer">
                                                    <input type="radio" name="informed_academy" value="No" class="mr-2">
                                                    <span>No</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Section 3: Course Value & Learning Feedback -->
                        <div id="section3" class="form-section hidden">
                            <div class="mb-6">
                                <div class="flex items-center mb-4">
                                    <div class="bg-purple-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-graduation-cap text-purple-600"></i>
                                    </div>
                                    <h3 class="text-lg font-bold text-gray-800">Course Value & Learning Feedback</h3>
                                </div>
                                
                                <div class="space-y-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Do you understand how important this course is for your career? *</label>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <?php 
                                            $importance_options = ['Yes, very important', 'Somewhat important', 'Not sure'];
                                            foreach ($importance_options as $option): 
                                            ?>
                                            <label class="radio-card relative border-2 rounded-xl p-4 cursor-pointer transition-all border-gray-200 hover:border-blue-300">
                                                <input type="radio" name="course_importance" value="<?= $option ?>" class="absolute opacity-0" required>
                                                <div class="flex flex-col items-center text-center">
                                                    <i class="fas <?= $option == 'Yes, very important' ? 'fa-check-circle' : ($option == 'Somewhat important' ? 'fa-question-circle' : 'fa-times-circle') ?> text-2xl mb-2 text-gray-500"></i>
                                                    <span class="text-sm text-gray-600"><?= $option ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Do you feel the content taught in class is valuable? *</label>
                                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                            <?php 
                                            $content_options = ['Very valuable', 'Good', 'Average', 'Not useful'];
                                            foreach ($content_options as $option): 
                                            ?>
                                            <label class="radio-card relative border-2 rounded-xl p-4 cursor-pointer transition-all border-gray-200 hover:border-blue-300">
                                                <input type="radio" name="content_value" value="<?= $option ?>" class="absolute opacity-0" required>
                                                <div class="flex flex-col items-center text-center">
                                                    <i class="fas <?= $option == 'Very valuable' ? 'fa-star' : ($option == 'Good' ? 'fa-thumbs-up' : ($option == 'Average' ? 'fa-meh' : 'fa-thumbs-down')) ?> text-2xl mb-2 text-gray-500"></i>
                                                    <span class="text-sm text-gray-600"><?= $option ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Are you able to understand the topics being taught? *</label>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <?php 
                                            $understanding_options = ['Yes, clearly', 'Sometimes', 'No, I struggle'];
                                            foreach ($understanding_options as $option): 
                                            ?>
                                            <label class="radio-card relative border-2 rounded-xl p-4 cursor-pointer transition-all border-gray-200 hover:border-blue-300">
                                                <input type="radio" name="topic_understanding" value="<?= $option ?>" class="absolute opacity-0" required>
                                                <div class="flex flex-col items-center text-center">
                                                    <i class="fas <?= $option == 'Yes, clearly' ? 'fa-smile' : ($option == 'Sometimes' ? 'fa-meh' : 'fa-frown') ?> text-2xl mb-2 text-gray-500"></i>
                                                    <span class="text-sm text-gray-600"><?= $option ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Are you able to perform practical tasks properly? *</label>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <?php 
                                            $practical_options = ['Yes', 'With some difficulty', 'No'];
                                            foreach ($practical_options as $option): 
                                            ?>
                                            <label class="radio-card relative border-2 rounded-xl p-4 cursor-pointer transition-all border-gray-200 hover:border-blue-300">
                                                <input type="radio" name="practical_ability" value="<?= $option ?>" class="absolute opacity-0" required>
                                                <div class="flex flex-col items-center text-center">
                                                    <i class="fas <?= $option == 'Yes' ? 'fa-check-circle' : ($option == 'With some difficulty' ? 'fa-exclamation-circle' : 'fa-times-circle') ?> text-2xl mb-2 text-gray-500"></i>
                                                    <span class="text-sm text-gray-600"><?= $option ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Do you think this type of practical learning is difficult to find elsewhere? *</label>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <?php 
                                            $unique_options = ['Yes', 'Maybe', 'No'];
                                            foreach ($unique_options as $option): 
                                            ?>
                                            <label class="radio-card relative border-2 rounded-xl p-4 cursor-pointer transition-all border-gray-200 hover:border-blue-300">
                                                <input type="radio" name="unique_learning" value="<?= $option ?>" class="absolute opacity-0" required>
                                                <div class="flex flex-col items-center text-center">
                                                    <i class="fas <?= $option == 'Yes' ? 'fa-check-circle' : ($option == 'Maybe' ? 'fa-question-circle' : 'fa-times-circle') ?> text-2xl mb-2 text-gray-500"></i>
                                                    <span class="text-sm text-gray-600"><?= $option ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Section 4: Self-Reflection & Commitment -->
                        <div id="section4" class="form-section hidden">
                            <div class="mb-6">
                                <div class="flex items-center mb-4">
                                    <div class="bg-green-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-brain text-green-600"></i>
                                    </div>
                                    <h3 class="text-lg font-bold text-gray-800">Self-Reflection & Commitment</h3>
                                </div>
                                
                                <div class="space-y-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">If you continue missing classes, what do you think you will lose? *</label>
                                        <textarea name="loss_reflection" id="loss_reflection" required rows="3" 
                                                  class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all"
                                                  placeholder="Share your thoughts..."></textarea>
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-3">Will you ensure regular attendance from now? *</label>
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                            <?php 
                                            $commitment_options = ['Yes', 'I will try', 'Not sure'];
                                            foreach ($commitment_options as $option): 
                                            ?>
                                            <label class="radio-card relative border-2 rounded-xl p-4 cursor-pointer transition-all border-gray-200 hover:border-blue-300">
                                                <input type="radio" name="future_commitment" value="<?= $option ?>" class="absolute opacity-0" required>
                                                <div class="flex flex-col items-center text-center">
                                                    <i class="fas <?= $option == 'Yes' ? 'fa-check-circle' : ($option == 'I will try' ? 'fa-hand-peace' : 'fa-question-circle') ?> text-2xl mb-2 text-gray-500"></i>
                                                    <span class="text-sm text-gray-600"><?= $option ?></span>
                                                </div>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-yellow-50 p-4 rounded-xl border border-yellow-200">
                                        <label class="flex items-start cursor-pointer">
                                            <input type="checkbox" name="responsibility_acceptance" id="responsibility_acceptance" value="1" class="mt-1 mr-3 w-5 h-5">
                                            <span class="text-sm text-gray-700">
                                                <strong class="text-yellow-700">Yes, I accept full responsibility</strong> for any negative impact (exam performance, internship delay, skill gap, or placement delay) caused due to missing classes. *
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Navigation Buttons -->
                        <div class="flex justify-between items-center pt-6 mt-6 border-t">
                            <button type="button" id="prevBtn" class="px-6 py-3 bg-gray-100 text-gray-700 rounded-xl hover:bg-gray-200 transition-all font-medium hidden">
                                <i class="fas fa-arrow-left mr-2"></i> Previous
                            </button>
                            <div id="placeholderDiv"></div>
                            <button type="button" id="nextBtn" class="px-6 py-3 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-all font-medium">
                                Next <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                            <button type="submit" name="submit_leave" id="submitBtn" class="px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all font-medium hidden">
                                <i class="fas fa-paper-plane mr-2"></i> Submit Application
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Global variables
        let currentSection = 1;
        const totalSections = 4;
        
        // DOM Elements
        const section1 = document.getElementById('section1');
        const section2 = document.getElementById('section2');
        const section3 = document.getElementById('section3');
        const section4 = document.getElementById('section4');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        const step4 = document.getElementById('step4');
        const progress1 = document.getElementById('progress1');
        const progress2 = document.getElementById('progress2');
        const progress3 = document.getElementById('progress3');
        
        // Calculate total days
        function calculateTotalDays() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                if (end >= start) {
                    const diffTime = Math.abs(end - start);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                    document.getElementById('total_days_display').value = diffDays + ' day(s)';
                    return true;
                } else {
                    document.getElementById('total_days_display').value = 'Invalid dates';
                    return false;
                }
            }
            return false;
        }
        
        // Add event listeners for date inputs
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');
        
        if (startDateInput && endDateInput) {
            startDateInput.addEventListener('change', calculateTotalDays);
            endDateInput.addEventListener('change', calculateTotalDays);
        }
        
        // File upload display
        const fileInput = document.getElementById('medical_prescription');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                const fileNameDisplay = document.getElementById('file_name_display');
                if (this.files && this.files[0]) {
                    fileNameDisplay.textContent = 'Selected: ' + this.files[0].name;
                    fileNameDisplay.classList.remove('hidden');
                } else {
                    fileNameDisplay.classList.add('hidden');
                }
            });
        }
        
        // Radio card selection styling
        function setupRadioCards() {
            document.querySelectorAll('.radio-card').forEach(card => {
                card.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        
                        // Remove selected class from siblings in same group
                        const name = radio.getAttribute('name');
                        document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                            const parentCard = r.closest('.radio-card');
                            if (parentCard) {
                                parentCard.classList.remove('selected', 'border-blue-500', 'bg-blue-50');
                            }
                        });
                        
                        // Add selected class to current card
                        this.classList.add('selected', 'border-blue-500', 'bg-blue-50');
                        
                        // Update icon colors
                        const icons = this.querySelectorAll('i');
                        icons.forEach(icon => {
                            icon.classList.add('text-blue-600');
                        });
                        
                        // Reset other cards' icons
                        document.querySelectorAll(`input[name="${name}"]`).forEach(r => {
                            const parentCard = r.closest('.radio-card');
                            if (parentCard && parentCard !== this) {
                                const iconsInCard = parentCard.querySelectorAll('i');
                                iconsInCard.forEach(icon => {
                                    icon.classList.remove('text-blue-600');
                                });
                            }
                        });
                    }
                });
            });
        }
        
        // Validate current section
        function validateSection(section) {
            let sectionElement;
            switch(section) {
                case 1: sectionElement = section1; break;
                case 2: sectionElement = section2; break;
                case 3: sectionElement = section3; break;
                case 4: sectionElement = section4; break;
                default: return true;
            }
            
            const requiredInputs = sectionElement.querySelectorAll('[required]');
            let isValid = true;
            
            requiredInputs.forEach(input => {
                if (input.type === 'radio') {
                    const name = input.getAttribute('name');
                    const radioGroup = sectionElement.querySelectorAll(`input[name="${name}"]`);
                    let isChecked = false;
                    radioGroup.forEach(radio => {
                        if (radio.checked) isChecked = true;
                    });
                    if (!isChecked) {
                        isValid = false;
                        input.closest('.radio-card')?.classList.add('border-red-500');
                    } else {
                        const radioCards = sectionElement.querySelectorAll(`input[name="${name}"]`);
                        radioCards.forEach(radio => {
                            radio.closest('.radio-card')?.classList.remove('border-red-500');
                        });
                    }
                } else if (input.type === 'checkbox') {
                    if (!input.checked && input.getAttribute('name') === 'responsibility_acceptance') {
                        isValid = false;
                        input.classList.add('border-red-500');
                        input.closest('.bg-yellow-50')?.classList.add('border-red-500');
                    } else {
                        input.classList.remove('border-red-500');
                        input.closest('.bg-yellow-50')?.classList.remove('border-red-500');
                    }
                } else if (!input.value.trim()) {
                    isValid = false;
                    input.classList.add('input-error');
                } else {
                    input.classList.remove('input-error');
                }
            });
            
            // Special validation for section 1: check if end date >= start date
            if (section === 1) {
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                if (startDate && endDate) {
                    const start = new Date(startDate);
                    const end = new Date(endDate);
                    if (end < start) {
                        isValid = false;
                        document.getElementById('end_date').classList.add('input-error');
                        showToast('End date must be after start date', 'error');
                    }
                }
            }
            
            return isValid;
        }
        
        // Update progress indicators
        function updateProgress(newSection) {
            // Update step circles
            const steps = [step1, step2, step3, step4];
            steps.forEach((step, index) => {
                const stepNumber = index + 1;
                if (stepNumber < newSection) {
                    step.classList.add('completed');
                    step.classList.remove('active');
                    step.style.backgroundColor = '#10b981';
                } else if (stepNumber === newSection) {
                    step.classList.add('active');
                    step.classList.remove('completed');
                    step.style.backgroundColor = '#3b82f6';
                } else {
                    step.classList.remove('completed', 'active');
                    step.style.backgroundColor = '#e5e7eb';
                    step.style.color = '#4b5563';
                }
            });
            
            // Update progress bars
            const progressBars = [progress1, progress2, progress3];
            for (let i = 0; i < progressBars.length; i++) {
                if (i + 2 <= newSection) {
                    progressBars[i].style.width = '100%';
                } else {
                    progressBars[i].style.width = '0%';
                }
            }
        }
        
        // Navigate between sections
        function navigateToSection(section) {
            // Validate current section before leaving
            if (section > currentSection) {
                if (!validateSection(currentSection)) {
                    showToast('Please fill in all required fields in this section', 'error');
                    return false;
                }
            }
            
            // Hide all sections
            section1.classList.add('hidden');
            section2.classList.add('hidden');
            section3.classList.add('hidden');
            section4.classList.add('hidden');
            
            // Show selected section
            switch(section) {
                case 1:
                    section1.classList.remove('hidden');
                    break;
                case 2:
                    section2.classList.remove('hidden');
                    break;
                case 3:
                    section3.classList.remove('hidden');
                    break;
                case 4:
                    section4.classList.remove('hidden');
                    break;
            }
            
            // Update buttons
            if (section === 1) {
                prevBtn.classList.add('hidden');
            } else {
                prevBtn.classList.remove('hidden');
            }
            
            if (section === totalSections) {
                nextBtn.classList.add('hidden');
                submitBtn.classList.remove('hidden');
            } else {
                nextBtn.classList.remove('hidden');
                submitBtn.classList.add('hidden');
            }
            
            // Update progress indicators
            updateProgress(section);
            
            // Add animation class
            const currentSectionDiv = document.getElementById(`section${section}`);
            currentSectionDiv.classList.add('animate-slide-right');
            setTimeout(() => {
                currentSectionDiv.classList.remove('animate-slide-right');
            }, 500);
            
            currentSection = section;
            return true;
        }
        
        // Next button handler
        function nextSection() {
            navigateToSection(currentSection + 1);
        }
        
        // Previous button handler
        function prevSection() {
            navigateToSection(currentSection - 1);
        }
        
        // Toast notification
        function showToast(message, type = 'info') {
            // Remove existing toasts
            const existingToasts = document.querySelectorAll('.custom-toast');
            existingToasts.forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `custom-toast fixed top-4 right-4 p-4 rounded-lg shadow-lg text-white z-50 transform transition-all duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500'}`;
            toast.style.minWidth = '300px';
            toast.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.add('translate-x-0', 'opacity-100');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => {
                    if (toast.parentNode) toast.parentNode.removeChild(toast);
                }, 300);
            }, 3000);
        }
        
        // Form submission validation
        function validateForm() {
            if (!validateSection(4)) {
                showToast('Please fill in all required fields in the final section', 'error');
                return false;
            }
            
            const responsibilityCheck = document.getElementById('responsibility_acceptance');
            if (!responsibilityCheck.checked) {
                showToast('Please accept the responsibility statement', 'error');
                return false;
            }
            
            return true;
        }
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Setup radio cards
            setupRadioCards();
            
            // Set up navigation
            nextBtn.addEventListener('click', nextSection);
            prevBtn.addEventListener('click', prevSection);
            
            // Form submission
            const form = document.getElementById('leaveForm');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!validateForm()) {
                        e.preventDefault();
                    }
                });
            }
            
            // Initialize first section
            navigateToSection(1);
            
            // Add floating label effects
            const inputs = document.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement?.classList.add('focused');
                });
                input.addEventListener('blur', function() {
                    if (!this.value) {
                        this.parentElement?.classList.remove('focused');
                    }
                });
            });
        });
        
        // Mobile menu toggle
        function toggleMobileMenu() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (sidebar && overlay) {
                sidebar.classList.toggle('-translate-x-full');
                overlay.classList.toggle('hidden');
            }
        }
        
        // Expose functions globally
        window.nextSection = nextSection;
        window.prevSection = prevSection;
        window.toggleMobileMenu = toggleMobileMenu;
        window.calculateTotalDays = calculateTotalDays;
    </script>
</body>
</html>