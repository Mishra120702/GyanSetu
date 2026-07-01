<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../logout_a.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_batch'])) {
    // Prepare batch data
    $batchData = [
        'batch_id' => $_POST['batch_id'],
        'course_name' => $_POST['course_name'],
        'start_date' => $_POST['start_date'],
        'end_date' => $_POST['end_date'],
        'time_slot' => $_POST['time_slot'] ?? null,
        'platform' => $_POST['platform'] ?? null,
        'meeting_link' => $_POST['meeting_link'] ?? null,
        'max_students' => $_POST['max_students'],
        'current_enrollment' => $_POST['current_enrollment'] ?? 0,
        'academic_year' => $_POST['academic_year'] ?? null,
        'batch_mentor_id' => $_POST['batch_mentor_id'] ?? null,
        'num_students' => $_POST['num_students'] ?? 0,
        'mode' => $_POST['mode'],
        'status' => $_POST['status'],
        'created_by' => $_SESSION['user_id']
    ];

    // Validate data
    $errors = validateBatchData($batchData);
    
    if (empty($errors)) {
        if (createBatch($db, $batchData)) {
            $_SESSION['success_message'] = 'Batch created successfully!';
            header("Location: ../dashboard/dashboard.php");
            exit();
        } else {
            $errors[] = 'Failed to create batch. Please try again.';
        }
    }
}

function generateNextBatchId(PDO $db): string {
    $lastBatch = $db->query("SELECT batch_id FROM batches ORDER BY batch_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $nextBatchId = 'B001'; // Default if no batches exist

    if ($lastBatch) {
        // Extract the numeric part and increment
        $lastNumber = (int) substr($lastBatch['batch_id'], 1);
        $nextNumber = $lastNumber + 1;
        $nextBatchId = 'B' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
    }

    return $nextBatchId;
}

function createBatch(PDO $db, array $batchData): bool {
    try {
        $stmt = $db->prepare("INSERT INTO batches (
    batch_id, course_name, start_date, end_date, time_slot, platform, 
    meeting_link, max_students, current_enrollment, academic_year,
    batch_mentor_id, mode, status, created_by
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        return $stmt->execute([
            $batchData['batch_id'],
            $batchData['course_name'],
            $batchData['start_date'],
            $batchData['end_date'],
            $batchData['time_slot'],
            $batchData['platform'],
            $batchData['meeting_link'],
            $batchData['max_students'],
            $batchData['current_enrollment'],
            $batchData['academic_year'],
            $batchData['batch_mentor_id'],
            $batchData['mode'],
            $batchData['status'],
            $batchData['created_by']
        ]);
    } catch (PDOException $e) {
        error_log("Error creating batch: " . $e->getMessage());
        return false;
    }
}

function validateBatchData(array $data): array {
    $errors = [];
    
    if (empty($data['course_name'])) {
        $errors[] = 'Course name is required';
    }
    
    if (empty($data['start_date'])) {
        $errors[] = 'Start date is required';
    }
    
    if (empty($data['end_date'])) {
        $errors[] = 'End date is required';
    } elseif (!empty($data['start_date']) && $data['end_date'] < $data['start_date']) {
        $errors[] = 'End date must be after start date';
    }
    
    if (empty($data['max_students']) || $data['max_students'] <= 0) {
        $errors[] = 'Max students must be a positive number';
    }
    
    if (!empty($data['current_enrollment']) && $data['current_enrollment'] > $data['max_students']) {
        $errors[] = 'Current enrollment cannot exceed max students';
    }
    
    return $errors;
}

$nextBatchId = generateNextBatchId($db);

// Fetch available courses from database
$courses = $db->query("SELECT id, name FROM courses")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Batch | ASD Academy</title>
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .form-group {
            transition: all 0.3s ease;
        }
        .form-group:hover {
            transform: translateY(-2px);
        }
        .input-focus:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
        }
        .error-shake {
            animation: shake 0.5s;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        .floating-label {
            position: relative;
        }
        .floating-label label {
            position: absolute;
            top: 0.75rem;
            left: 1rem;
            color: #6b7280;
            transition: all 0.2s ease;
            pointer-events: none;
        }
        .floating-label input:focus + label,
        .floating-label input:not(:placeholder-shown) + label,
        .floating-label select:focus + label,
        .floating-label select:not([value=""]) + label {
            transform: translateY(-1.5rem) scale(0.85);
            background-color: white;
            padding: 0 0.25rem;
            color: #3b82f6;
        }
        .success-message {
            animation: fadeInDown 0.5s;
        }
        .card-hover {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-blue-50 min-h-screen">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <!-- Success Message (if any) -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="success-message bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 animate__animated animate__fadeInDown">
                <span class="block sm:inline"><?= htmlspecialchars($_SESSION['success_message']) ?></span>
                <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i class="fas fa-check-circle"></i>
                </span>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6 error-shake animate__animated animate__shakeX" role="alert">
                <strong class="font-bold">Error!</strong>
                <ul class="mt-1 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?= htmlspecialchars($error) ?></li>
                    <?php endforeach; ?>
                </ul>
                <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                    <i class="fas fa-exclamation-circle"></i>
                </span>
            </div>
        <?php endif; ?>
        
        <div class="bg-white rounded-xl shadow-lg overflow-hidden card-hover animate__animated animate__fadeIn">
            <div class="bg-gradient-to-r from-blue-600 to-blue-800 p-6 text-white">
                <h2 class="text-2xl font-bold flex items-center">
                    <i class="fas fa-plus-circle mr-3"></i>
                    Add New Batch
                </h2>
                <p class="opacity-90 mt-1">Fill in the details below to create a new batch</p>
            </div>
            
            <form id="batchForm" method="post" class="p-6 space-y-6">
                <input type="hidden" name="add_batch" value="1">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Batch ID -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="text" id="batch_id" name="batch_id" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-gray-100 input-focus"
                                   value="<?= htmlspecialchars($nextBatchId) ?>" readonly required>
                            <label for="batch_id">Batch ID*</label>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Automatically generated</p>
                    </div>
                    
                    <!-- Course Name -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="text" id="course_name" name="course_name" 
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                required placeholder=" ">
                            <label for="course_name">Course Name*</label>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Start Date -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="date" id="start_date" name="start_date" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                   required>
                            <label for="start_date">Start Date*</label>
                        </div>
                    </div>
                    
                    <!-- End Date -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="date" id="end_date" name="end_date" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                   required>
                            <label for="end_date">End Date*</label>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Time Slot -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="text" id="time_slot" name="time_slot" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                   placeholder=" ">
                            <label for="time_slot">Time Slot (e.g. 18:00-20:00)</label>
                        </div>
                    </div>
                    
                    <!-- Max Students -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="number" id="max_students" name="max_students" min="1" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                   required placeholder=" ">
                            <label for="max_students">Max Students*</label>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Current Enrollment -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="number" id="current_enrollment" name="current_enrollment" min="0" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                   placeholder=" " value="0">
                            <label for="current_enrollment">Current Enrollment</label>
                        </div>
                    </div>
                    
                    <!-- Academic Year -->
                    <div class="form-group">
                        <div class="floating-label">
                            <input type="text" id="academic_year" name="academic_year" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                   placeholder=" " value="<?= date('Y') ?>-<?= date('Y')+1 ?>">
                            <label for="academic_year">Academic Year (e.g. 2024-25)</label>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Batch Mentor -->
                    <div class="form-group">
                        <div class="floating-label">
                            <select id="batch_mentor_id" name="batch_mentor_id" 
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus appearance-none">
                                <option value="">Select Mentor</option>
                                <?php 
                                $mentors = $db->query("SELECT t.id, t.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE u.status = 'active'")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($mentors as $mentor): ?>
                                    <option value="<?= $mentor['id'] ?>"><?= htmlspecialchars($mentor['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="batch_mentor_id">Batch Mentor</label>
                        </div>
                    </div>
                </div>

                <!-- Mode -->
                <div class="form-group">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Mode*</label>
                    <div class="flex space-x-4">
                        <label class="inline-flex items-center px-4 py-3 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50 transition-colors duration-200">
                            <input type="radio" name="mode" value="online" 
                                   class="h-5 w-5 text-blue-600 focus:ring-blue-500" checked>
                            <span class="ml-3 text-gray-700">
                                <i class="fas fa-globe mr-2 text-blue-600"></i>Online
                            </span>
                        </label>
                        <label class="inline-flex items-center px-4 py-3 bg-white border border-gray-300 rounded-lg cursor-pointer hover:bg-blue-50 transition-colors duration-200">
                            <input type="radio" name="mode" value="offline" 
                                   class="h-5 w-5 text-blue-600 focus:ring-blue-500">
                            <span class="ml-3 text-gray-700">
                                <i class="fas fa-building mr-2 text-blue-600"></i>Offline
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Online Fields -->
                <div id="onlineFields" class="space-y-4 transition-all duration-300 ease-in-out">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Platform -->
                        <div class="form-group">
                            <div class="floating-label">
                                <select id="platform" name="platform" 
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus appearance-none">
                                    <option value="">Select Platform</option>
                                    <option value="Google Meet">Google Meet</option>
                                    <option value="Zoom">Zoom</option>
                                    <option value="Microsoft Teams">Microsoft Teams</option>
                                </select>
                                <label for="platform">Platform</label>
                            </div>
                        </div>
                        
                        <!-- Meeting Link -->
                        <div class="form-group">
                            <div class="floating-label">
                                <input type="url" id="meeting_link" name="meeting_link" 
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus"
                                       placeholder=" ">
                                <label for="meeting_link">Meeting Link (https://...)</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="form-group">
                    <div class="floating-label">
                        <select id="status" name="status" 
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 input-focus appearance-none"
                                required>
                            <option value="upcoming">Upcoming</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <label for="status">Status*</label>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="flex flex-col sm:flex-row justify-end space-y-4 sm:space-y-0 sm:space-x-4 pt-6">
                    <a href="../dashboard/dashboard.php" class="px-6 py-3 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 flex items-center justify-center">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </a>
                    <button type="submit" 
                            class="px-6 py-3 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-gradient-to-r from-blue-600 to-blue-800 hover:from-blue-700 hover:to-blue-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200 flex items-center justify-center">
                        <i class="fas fa-save mr-2"></i> Create Batch
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            // Show/hide online fields based on mode selection with animation
            function toggleOnlineFields() {
                const onlineFields = $('#onlineFields');
                const mode = $('input[name="mode"]:checked').val();
                
                if (mode === 'online') {
                    onlineFields.slideDown(300);
                } else {
                    onlineFields.slideUp(300);
                }
            }
            
            // Set up event listeners for mode toggle
            $('input[name="mode"]').on('change', toggleOnlineFields);
            
            // Initial toggle when page loads
            toggleOnlineFields();
            
            // Date validation
            $('#start_date, #end_date').on('change', function() {
                const startDate = new Date($('#start_date').val());
                const endDate = new Date($('#end_date').val());
                
                if (startDate && endDate && endDate < startDate) {
                    $('#end_date').addClass('border-red-500');
                    $('<p class="text-red-500 text-xs mt-1">End date must be after start date</p>').insertAfter('#end_date');
                } else {
                    $('#end_date').removeClass('border-red-500');
                    $('#end_date').nextAll('p.text-red-500').remove();
                }
            });
            
            // Max students validation
            $('#max_students, #current_enrollment').on('input', function() {
                const maxStudents = parseInt($('#max_students').val()) || 0;
                const currentEnrollment = parseInt($('#current_enrollment').val()) || 0;
                
                if (currentEnrollment > maxStudents) {
                    $('#current_enrollment').addClass('border-red-500');
                    $('<p class="text-red-500 text-xs mt-1">Current enrollment cannot exceed max students</p>').insertAfter('#current_enrollment');
                } else {
                    $('#current_enrollment').removeClass('border-red-500');
                    $('#current_enrollment').nextAll('p.text-red-500').remove();
                }
            });
            
            // Form submission animation
            $('#batchForm').on('submit', function() {
                $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i> Creating...');
            });
            
            // Floating label initialization
            $('.floating-label input, .floating-label select').each(function() {
                if ($(this).val() !== '') {
                    $(this).siblings('label').addClass('floating');
                }
            });
        });
    </script>
</body>
</html>