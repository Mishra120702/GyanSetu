<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : null;
$event_type = isset($_GET['type']) ? $_GET['type'] : 'class'; // class, exam, workshop

if (!$batch_id) {
    header("Location: ../batch_list.php");
    exit();
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get batch details
    $stmt = $conn->prepare("SELECT * FROM batches WHERE batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$batch) {
        header("Location: ../batch_list.php");
        exit();
    }
    
    // Get trainers for workshops
    $trainers = [];
    if ($event_type === 'workshop') {
        $stmt = $conn->prepare("SELECT id, name FROM trainers WHERE is_active = 1 ORDER BY name");
        $stmt->execute();
        $trainers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = $_POST['title'];
        $date = $_POST['date'];
        $description = $_POST['description'] ?? '';
        
        // Check if user wants to bypass date validation for back schedules
        $ignore_date_restriction = isset($_POST['ignore_date_restriction']) ? 1 : 0;
        
        if ($event_type === 'class') {
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            
            $stmt = $conn->prepare("INSERT INTO schedule (batch_id, schedule_date, start_time, end_time, topic, description, created_by, is_back_schedule) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$batch_id, $date, $start_time, $end_time, $title, $description, $_SESSION['user_id'], $ignore_date_restriction]);
            
        } elseif ($event_type === 'exam') {
            $subject = $_POST['subject'];
            $total_marks = $_POST['total_marks'];
            $passing_marks = $_POST['passing_marks'];
            $exam_type = $_POST['exam_type'];
            $exam_components = implode(',', $_POST['exam_components'] ?? ['mcq']);
            
            // Generate exam ID
            $exam_id = 'EXAM' . date('ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            $stmt = $conn->prepare("INSERT INTO exams (exam_id, exam_name, batch_id, subject, exam_date, total_marks, passing_marks, exam_type, description, created_by, exam_components, is_back_schedule) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$exam_id, $title, $batch_id, $subject, $date, $total_marks, $passing_marks, $exam_type, $description, $_SESSION['user_id'], $exam_components, $ignore_date_restriction]);
            
        } elseif ($event_type === 'workshop') {
            $start_datetime = $_POST['start_datetime'];
            $end_datetime = $_POST['end_datetime'];
            $location = $_POST['location'] ?? '';
            $max_participants = $_POST['max_participants'] ?? null;
            $trainer_id = $_POST['trainer_id'] ?? null;
            $fee = $_POST['fee'] ?? 0;
            $difficulty_level = $_POST['difficulty_level'];
            $requirements = $_POST['requirements'] ?? '';
            $certificate_available = isset($_POST['certificate_available']) ? 1 : 0;
            
            // Generate workshop ID
            $workshop_id = 'WS' . date('ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            $stmt = $conn->prepare("INSERT INTO workshops (workshop_id, title, description, start_datetime, end_datetime, location, max_participants, trainer_id, fee, difficulty_level, requirements, certificate_available, created_by, is_back_schedule) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$workshop_id, $title, $description, $start_datetime, $end_datetime, $location, $max_participants, $trainer_id, $fee, $difficulty_level, $requirements, $certificate_available, $_SESSION['user_id'], $ignore_date_restriction]);
        }
        
        header("Location: schedule.php?batch_id=" . $batch_id);
        exit();
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$event_titles = [
    'class' => 'Add New Class',
    'exam' => 'Add New Exam',
    'workshop' => 'Add New Workshop'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $event_titles[$event_type] ?> | <?= htmlspecialchars($batch['batch_id']) ?> | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Poppins', sans-serif;
        }
        
        .tab-button {
            transition: all 0.3s ease;
        }
        
        .tab-button.active {
            background-color: #3b82f6;
            color: white;
        }
        
        .form-section {
            transition: all 0.3s ease;
            display: none;
        }
        
        .form-section.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .input-group {
            transition: all 0.3s ease;
        }
        
        .input-group:focus-within {
            transform: translateY(-2px);
        }
        
        .back-schedule-warning {
            animation: pulseWarning 2s infinite;
        }
        
        @keyframes pulseWarning {
            0% { border-color: #f59e0b; }
            50% { border-color: #fbbf24; }
            100% { border-color: #f59e0b; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php
    include '../header.php';
    include '../sidebar.php';   
    ?>
    
    <div class="ml-64 p-6">
        <div class="max-w-4xl mx-auto">
            <!-- Back button -->
            <a href="schedule.php?batch_id=<?= $batch_id ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-6 transition-all duration-300">
                <i class="fas fa-arrow-left mr-2"></i> Back to Schedule
            </a>
            
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-800"><?= $event_titles[$event_type] ?></h1>
                <p class="text-gray-600 mt-1">Batch: <?= htmlspecialchars($batch['batch_id']) ?> - <?= htmlspecialchars($batch['batch_name']) ?></p>
            </div>
            
            <!-- Event Type Tabs -->
            <div class="bg-white rounded-xl shadow-md p-4 mb-6">
                <div class="flex space-x-2">
                    <a href="add_event.php?batch_id=<?= $batch_id ?>&type=class" 
                       class="tab-button px-4 py-2 rounded-lg text-sm font-medium <?= $event_type === 'class' ? 'active bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <i class="fas fa-chalkboard-teacher mr-2"></i>Add Class
                    </a>
                    <a href="add_event.php?batch_id=<?= $batch_id ?>&type=exam" 
                       class="tab-button px-4 py-2 rounded-lg text-sm font-medium <?= $event_type === 'exam' ? 'active bg-purple-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <i class="fas fa-file-alt mr-2"></i>Add Exam
                    </a>
                    <a href="add_event.php?batch_id=<?= $batch_id ?>&type=workshop" 
                       class="tab-button px-4 py-2 rounded-lg text-sm font-medium <?= $event_type === 'workshop' ? 'active bg-green-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <i class="fas fa-wrench mr-2"></i>Add Workshop
                    </a>
                </div>
            </div>
            
            <!-- Form -->
            <div class="bg-white shadow-lg rounded-xl p-6">
                <form action="add_event.php?batch_id=<?= $batch_id ?>&type=<?= $event_type ?>" method="POST">
                    
                    <!-- Common Fields -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="input-group">
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">
                                <?= $event_type === 'class' ? 'Topic' : ($event_type === 'exam' ? 'Exam Name' : 'Workshop Title') ?>
                                <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="title" name="title" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="date" class="block text-sm font-medium text-gray-700 mb-1">
                                Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" id="date" name="date" required
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                    </div>
                    
                    <!-- Class Specific Fields -->
                    <?php if ($event_type === 'class'): ?>
                    <div class="form-section active" id="class-fields">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="input-group">
                                <label for="start_time" class="block text-sm font-medium text-gray-700 mb-1">
                                    Start Time <span class="text-red-500">*</span>
                                </label>
                                <input type="time" id="start_time" name="start_time" required
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group">
                                <label for="end_time" class="block text-sm font-medium text-gray-700 mb-1">
                                    End Time <span class="text-red-500">*</span>
                                </label>
                                <input type="time" id="end_time" name="end_time" required
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Exam Specific Fields -->
                    <?php elseif ($event_type === 'exam'): ?>
                    <div class="form-section active" id="exam-fields">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="input-group">
                                <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">
                                    Subject <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="subject" name="subject" required
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group">
                                <label for="exam_type" class="block text-sm font-medium text-gray-700 mb-1">
                                    Exam Type <span class="text-red-500">*</span>
                                </label>
                                <select id="exam_type" name="exam_type" required
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                                    <option value="quarterly">Quarterly</option>
                                    <option value="half-yearly">Half Yearly</option>
                                    <option value="final">Final</option>
                                    <option value="unit_test">Unit Test</option>
                                    <option value="practice">Practice</option>
                                </select>
                            </div>
                            
                            <div class="input-group">
                                <label for="total_marks" class="block text-sm font-medium text-gray-700 mb-1">
                                    Total Marks <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="total_marks" name="total_marks" required min="0" step="0.01"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group">
                                <label for="passing_marks" class="block text-sm font-medium text-gray-700 mb-1">
                                    Passing Marks <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="passing_marks" name="passing_marks" required min="0" step="0.01"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Exam Components</label>
                                <div class="flex flex-wrap gap-4">
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="exam_components[]" value="mcq" checked
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <span class="ml-2 text-sm text-gray-700">MCQ</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="exam_components[]" value="project"
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <span class="ml-2 text-sm text-gray-700">Project</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" name="exam_components[]" value="viva"
                                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                        <span class="ml-2 text-sm text-gray-700">Viva</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Workshop Specific Fields -->
                    <?php elseif ($event_type === 'workshop'): ?>
                    <div class="form-section active" id="workshop-fields">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="input-group">
                                <label for="start_datetime" class="block text-sm font-medium text-gray-700 mb-1">
                                    Start Date & Time <span class="text-red-500">*</span>
                                </label>
                                <input type="datetime-local" id="start_datetime" name="start_datetime" required
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group">
                                <label for="end_datetime" class="block text-sm font-medium text-gray-700 mb-1">
                                    End Date & Time <span class="text-red-500">*</span>
                                </label>
                                <input type="datetime-local" id="end_datetime" name="end_datetime" required
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group">
                                <label for="location" class="block text-sm font-medium text-gray-700 mb-1">
                                    Location
                                </label>
                                <input type="text" id="location" name="location"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group">
                                <label for="difficulty_level" class="block text-sm font-medium text-gray-700 mb-1">
                                    Difficulty Level <span class="text-red-500">*</span>
                                </label>
                                <select id="difficulty_level" name="difficulty_level" required
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                                    <option value="beginner">Beginner</option>
                                    <option value="intermediate">Intermediate</option>
                                    <option value="advanced">Advanced</option>
                                </select>
                            </div>
                            
                            <div class="input-group">
                                <label for="trainer_id" class="block text-sm font-medium text-gray-700 mb-1">
                                    Trainer
                                </label>
                                <select id="trainer_id" name="trainer_id"
                                        class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                                    <option value="">Select Trainer</option>
                                    <?php foreach ($trainers as $trainer): ?>
                                        <option value="<?= $trainer['id'] ?>"><?= htmlspecialchars($trainer['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="input-group">
                                <label for="max_participants" class="block text-sm font-medium text-gray-700 mb-1">
                                    Max Participants
                                </label>
                                <input type="number" id="max_participants" name="max_participants" min="1"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group">
                                <label for="fee" class="block text-sm font-medium text-gray-700 mb-1">
                                    Fee ($)
                                </label>
                                <input type="number" id="fee" name="fee" min="0" step="0.01" value="0"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                            </div>
                            
                            <div class="input-group md:col-span-2">
                                <label for="requirements" class="block text-sm font-medium text-gray-700 mb-1">
                                    Requirements (optional)
                                </label>
                                <textarea id="requirements" name="requirements" rows="2"
                                          class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"></textarea>
                            </div>
                            
                            <div class="input-group">
                                <div class="flex items-center">
                                    <input type="checkbox" id="certificate_available" name="certificate_available"
                                           class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded">
                                    <label for="certificate_available" class="ml-2 block text-sm text-gray-700">
                                        Certificate Available
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Common Description Field -->
                    <div class="input-group mb-6">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                            Description
                        </label>
                        <textarea id="description" name="description" rows="4"
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"></textarea>
                    </div>
                    
                    <!-- Back Schedule Warning -->
                    <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg back-schedule-warning">
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-500 text-lg mt-0.5"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">Back Schedule Notice</h3>
                                <div class="mt-1">
                                    <p class="text-sm text-yellow-700">
                                        You are adding an event with a past date. This is considered a back schedule.
                                    </p>
                                    <div class="mt-2 flex items-center">
                                        <input type="checkbox" id="ignore_date_restriction" name="ignore_date_restriction" 
                                               class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-yellow-300 rounded">
                                        <label for="ignore_date_restriction" class="ml-2 text-sm font-medium text-yellow-800">
                                            I understand this is a back schedule and want to proceed
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="flex justify-end">
                        <button type="submit" id="submitBtn" disabled
                                class="px-6 py-3 bg-gray-400 text-white font-medium rounded-lg cursor-not-allowed flex items-center">
                            <i class="fas fa-save mr-2"></i> 
                            <?= $event_type === 'class' ? 'Save Class' : ($event_type === 'exam' ? 'Create Exam' : 'Create Workshop') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Set default date to today
            const today = new Date().toISOString().split('T')[0];
            const dateInput = document.getElementById('date');
            if (dateInput) {
                dateInput.value = today;
            }
            
            // Set default times for class
            const startTimeInput = document.getElementById('start_time');
            const endTimeInput = document.getElementById('end_time');
            if (startTimeInput && endTimeInput) {
                // Set default class times (e.g., 10:00 AM to 12:00 PM)
                startTimeInput.value = '10:00';
                endTimeInput.value = '12:00';
            }
            
            // Set default datetime for workshop
            const startDatetimeInput = document.getElementById('start_datetime');
            const endDatetimeInput = document.getElementById('end_datetime');
            if (startDatetimeInput && endDatetimeInput) {
                const now = new Date();
                const tomorrow = new Date(now);
                tomorrow.setDate(tomorrow.getDate() + 1);
                
                // Format for datetime-local input
                const formatDateTime = (date) => {
                    return date.toISOString().slice(0, 16);
                };
                
                startDatetimeInput.value = formatDateTime(tomorrow);
                endDatetimeInput.value = formatDateTime(new Date(tomorrow.getTime() + 2 * 60 * 60 * 1000)); // +2 hours
            }
            
            // For exams, set default passing marks based on total marks
            const totalMarksInput = document.getElementById('total_marks');
            const passingMarksInput = document.getElementById('passing_marks');
            if (totalMarksInput && passingMarksInput) {
                totalMarksInput.addEventListener('input', function() {
                    const total = parseFloat(this.value) || 0;
                    const passing = Math.ceil(total * 0.33); // 33% passing
                    passingMarksInput.placeholder = `Suggested: ${passing}`;
                    passingMarksInput.value = passing;
                });
            }
            
            // Add validation for end time after start time
            if (startTimeInput && endTimeInput) {
                endTimeInput.addEventListener('change', function() {
                    if (startTimeInput.value && this.value && this.value <= startTimeInput.value) {
                        alert('End time must be after start time');
                        this.value = '';
                        this.focus();
                    }
                });
            }
            
            if (startDatetimeInput && endDatetimeInput) {
                endDatetimeInput.addEventListener('change', function() {
                    if (startDatetimeInput.value && this.value && this.value <= startDatetimeInput.value) {
                        alert('End datetime must be after start datetime');
                        this.value = '';
                        this.focus();
                    }
                });
            }
            
            // Handle back schedule validation
            const dateInputField = document.getElementById('date');
            const startDatetimeField = document.getElementById('start_datetime');
            const ignoreCheckbox = document.getElementById('ignore_date_restriction');
            const submitBtn = document.getElementById('submitBtn');
            const warningDiv = document.querySelector('.back-schedule-warning');
            
            function checkDateAndEnableSubmit() {
                let selectedDate = null;
                
                if (dateInputField) {
                    selectedDate = new Date(dateInputField.value);
                } else if (startDatetimeField) {
                    selectedDate = new Date(startDatetimeField.value);
                }
                
                const today = new Date();
                today.setHours(0, 0, 0, 0); // Reset time to compare only dates
                
                if (selectedDate && selectedDate < today) {
                    // Show warning for past dates
                    warningDiv.classList.remove('hidden');
                    submitBtn.disabled = !ignoreCheckbox.checked;
                    submitBtn.className = ignoreCheckbox.checked ? 
                        'px-6 py-3 bg-yellow-600 text-white font-medium rounded-lg hover:bg-yellow-700 transition-all duration-300 shadow-md hover:shadow-lg flex items-center' :
                        'px-6 py-3 bg-gray-400 text-white font-medium rounded-lg cursor-not-allowed flex items-center';
                } else {
                    // Hide warning for future dates
                    warningDiv.classList.add('hidden');
                    submitBtn.disabled = false;
                    submitBtn.className = 'px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-all duration-300 shadow-md hover:shadow-lg flex items-center';
                }
            }
            
            // Event listeners for date changes
            if (dateInputField) {
                dateInputField.addEventListener('change', checkDateAndEnableSubmit);
            }
            
            if (startDatetimeField) {
                startDatetimeField.addEventListener('change', checkDateAndEnableSubmit);
            }
            
            if (ignoreCheckbox) {
                ignoreCheckbox.addEventListener('change', checkDateAndEnableSubmit);
            }
            
            // Initial check
            checkDateAndEnableSubmit();
        });
    </script>
</body>
</html>