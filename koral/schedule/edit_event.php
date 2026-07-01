<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$event_id = isset($_GET['id']) ? $_GET['id'] : null;
$event_type = isset($_GET['type']) ? $_GET['type'] : 'class'; // class, exam, workshop

if (!$event_id) {
    header("Location: ../batch_list.php");
    exit();
}

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $event = null;
    $batch_id = null;
    
    if ($event_type === 'class') {
        // Get class details
        $stmt = $conn->prepare("SELECT s.*, b.batch_id, b.batch_name FROM schedule s 
                               JOIN batches b ON s.batch_id = b.batch_id 
                               WHERE s.id = ?");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        $batch_id = $event['batch_id'] ?? null;
        
    } elseif ($event_type === 'exam') {
        // Get exam details
        $stmt = $conn->prepare("SELECT e.*, b.batch_id, b.batch_name FROM exams e 
                               JOIN batches b ON e.batch_id = b.batch_id 
                               WHERE e.exam_id = ?");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        $batch_id = $event['batch_id'] ?? null;
        
    } elseif ($event_type === 'workshop') {
        // Get workshop details
        $stmt = $conn->prepare("SELECT w.* FROM workshops w WHERE w.workshop_id = ?");
        $stmt->execute([$event_id]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        // Note: Workshops might not be linked to specific batches
    }
    
    if (!$event) {
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
        if ($event_type === 'class') {
            $schedule_date = $_POST['schedule_date'];
            $start_time = $_POST['start_time'];
            $end_time = $_POST['end_time'];
            $topic = $_POST['topic'];
            $description = $_POST['description'];
            $is_cancelled = isset($_POST['is_cancelled']) ? 1 : 0;
            $cancellation_reason = $_POST['cancellation_reason'];
            $is_back_schedule = isset($_POST['is_back_schedule']) ? 1 : ($event['is_back_schedule'] ?? 0);
            
            $stmt = $conn->prepare("UPDATE schedule 
                                   SET schedule_date = ?, start_time = ?, end_time = ?, topic = ?, 
                                       description = ?, is_cancelled = ?, cancellation_reason = ?, is_back_schedule = ?
                                   WHERE id = ?");
            $stmt->execute([$schedule_date, $start_time, $end_time, $topic, $description, 
                           $is_cancelled, $cancellation_reason, $is_back_schedule, $event_id]);
            
        } elseif ($event_type === 'exam') {
            $exam_name = $_POST['exam_name'];
            $subject = $_POST['subject'];
            $exam_date = $_POST['exam_date'];
            $total_marks = $_POST['total_marks'];
            $passing_marks = $_POST['passing_marks'];
            $exam_type = $_POST['exam_type'];
            $description = $_POST['description'];
            $exam_components = implode(',', $_POST['exam_components'] ?? ['mcq']);
            $is_back_schedule = isset($_POST['is_back_schedule']) ? 1 : ($event['is_back_schedule'] ?? 0);
            
            $stmt = $conn->prepare("UPDATE exams 
                                   SET exam_name = ?, subject = ?, exam_date = ?, total_marks = ?, 
                                       passing_marks = ?, exam_type = ?, description = ?, exam_components = ?, is_back_schedule = ?
                                   WHERE exam_id = ?");
            $stmt->execute([$exam_name, $subject, $exam_date, $total_marks, $passing_marks, 
                           $exam_type, $description, $exam_components, $is_back_schedule, $event_id]);
            
        } elseif ($event_type === 'workshop') {
            $title = $_POST['title'];
            $description = $_POST['description'];
            $start_datetime = $_POST['start_datetime'];
            $end_datetime = $_POST['end_datetime'];
            $location = $_POST['location'];
            $max_participants = $_POST['max_participants'];
            $trainer_id = $_POST['trainer_id'];
            $fee = $_POST['fee'];
            $difficulty_level = $_POST['difficulty_level'];
            $requirements = $_POST['requirements'];
            $certificate_available = isset($_POST['certificate_available']) ? 1 : 0;
            $status = $_POST['status'];
            $is_back_schedule = isset($_POST['is_back_schedule']) ? 1 : ($event['is_back_schedule'] ?? 0);
            
            $stmt = $conn->prepare("UPDATE workshops 
                                   SET title = ?, description = ?, start_datetime = ?, end_datetime = ?, 
                                       location = ?, max_participants = ?, trainer_id = ?, fee = ?, 
                                       difficulty_level = ?, requirements = ?, certificate_available = ?, status = ?, is_back_schedule = ?
                                   WHERE workshop_id = ?");
            $stmt->execute([$title, $description, $start_datetime, $end_datetime, $location, 
                           $max_participants, $trainer_id, $fee, $difficulty_level, $requirements, 
                           $certificate_available, $status, $is_back_schedule, $event_id]);
        }
        
        if ($batch_id) {
            header("Location: schedule.php?batch_id=" . $batch_id);
        } else {
            header("Location: ../workshop/workshop_list.php");
        }
        exit();
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$event_titles = [
    'class' => 'Edit Class',
    'exam' => 'Edit Exam',
    'workshop' => 'Edit Workshop'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $event_titles[$event_type] ?> | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Poppins', sans-serif;
        }
        
        .input-group {
            transition: all 0.3s ease;
        }
        
        .input-group:focus-within {
            transform: translateY(-2px);
        }
        
        .delete-btn {
            transition: all 0.3s ease;
        }
        
        .delete-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        
        .back-schedule-indicator {
            animation: pulseIndicator 2s infinite;
        }
        
        @keyframes pulseIndicator {
            0% { background-color: #fef3c7; }
            50% { background-color: #fde68a; }
            100% { background-color: #fef3c7; }
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
            <?php if ($batch_id): ?>
                <a href="schedule.php?batch_id=<?= $batch_id ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-6 transition-all duration-300">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Schedule
                </a>
            <?php elseif ($event_type === 'workshop'): ?>
                <a href="../workshop/workshop_list.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 mb-6 transition-all duration-300">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Workshops
                </a>
            <?php endif; ?>
            
            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-gray-800"><?= $event_titles[$event_type] ?></h1>
                <?php if ($batch_id): ?>
                    <p class="text-gray-600 mt-1">
                        <?= $event_type === 'class' ? 'Class' : ($event_type === 'exam' ? 'Exam' : 'Workshop') ?> ID: 
                        <span class="font-semibold"><?= htmlspecialchars($event_id) ?></span>
                        <?php if (isset($event['batch_name'])): ?>
                            | Batch: <?= htmlspecialchars($event['batch_name']) ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Back Schedule Indicator -->
            <?php if (isset($event['is_back_schedule']) && $event['is_back_schedule']): ?>
            <div class="mb-6 p-4 bg-yellow-100 border border-yellow-300 rounded-lg back-schedule-indicator">
                <div class="flex items-center">
                    <i class="fas fa-history text-yellow-600 mr-3"></i>
                    <div>
                        <h3 class="font-medium text-yellow-800">Back Schedule Event</h3>
                        <p class="text-sm text-yellow-700">This event was added as a back schedule (past date).</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Form -->
            <div class="bg-white shadow-lg rounded-xl p-6">
                <form action="edit_event.php?id=<?= $event_id ?>&type=<?= $event_type ?>" method="POST">
                    
                    <!-- Class Form -->
                    <?php if ($event_type === 'class'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="input-group">
                            <label for="schedule_date" class="block text-sm font-medium text-gray-700 mb-1">
                                Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" id="schedule_date" name="schedule_date" required
                                   value="<?= htmlspecialchars($event['schedule_date']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="start_time" class="block text-sm font-medium text-gray-700 mb-1">
                                Start Time <span class="text-red-500">*</span>
                            </label>
                            <input type="time" id="start_time" name="start_time" required
                                   value="<?= htmlspecialchars(substr($event['start_time'], 0, 5)) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="end_time" class="block text-sm font-medium text-gray-700 mb-1">
                                End Time <span class="text-red-500">*</span>
                            </label>
                            <input type="time" id="end_time" name="end_time" required
                                   value="<?= htmlspecialchars(substr($event['end_time'], 0, 5)) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group md:col-span-2">
                            <label for="topic" class="block text-sm font-medium text-gray-700 mb-1">
                                Topic <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="topic" name="topic" required
                                   value="<?= htmlspecialchars($event['topic']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                    </div>
                    
                    <!-- Exam Form -->
                    <?php elseif ($event_type === 'exam'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="input-group">
                            <label for="exam_name" class="block text-sm font-medium text-gray-700 mb-1">
                                Exam Name <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="exam_name" name="exam_name" required
                                   value="<?= htmlspecialchars($event['exam_name']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="subject" class="block text-sm font-medium text-gray-700 mb-1">
                                Subject <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="subject" name="subject" required
                                   value="<?= htmlspecialchars($event['subject']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="exam_date" class="block text-sm font-medium text-gray-700 mb-1">
                                Exam Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" id="exam_date" name="exam_date" required
                                   value="<?= htmlspecialchars($event['exam_date']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="exam_type" class="block text-sm font-medium text-gray-700 mb-1">
                                Exam Type <span class="text-red-500">*</span>
                            </label>
                            <select id="exam_type" name="exam_type" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                                <option value="quarterly" <?= $event['exam_type'] === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="half-yearly" <?= $event['exam_type'] === 'half-yearly' ? 'selected' : '' ?>>Half Yearly</option>
                                <option value="final" <?= $event['exam_type'] === 'final' ? 'selected' : '' ?>>Final</option>
                                <option value="unit_test" <?= $event['exam_type'] === 'unit_test' ? 'selected' : '' ?>>Unit Test</option>
                                <option value="practice" <?= $event['exam_type'] === 'practice' ? 'selected' : '' ?>>Practice</option>
                            </select>
                        </div>
                        
                        <div class="input-group">
                            <label for="total_marks" class="block text-sm font-medium text-gray-700 mb-1">
                                Total Marks <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="total_marks" name="total_marks" required min="0" step="0.01"
                                   value="<?= htmlspecialchars($event['total_marks']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="passing_marks" class="block text-sm font-medium text-gray-700 mb-1">
                                Passing Marks <span class="text-red-500">*</span>
                            </label>
                            <input type="number" id="passing_marks" name="passing_marks" required min="0" step="0.01"
                                   value="<?= htmlspecialchars($event['passing_marks']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Exam Components</label>
                            <div class="flex flex-wrap gap-4">
                                <?php
                                $components = explode(',', $event['exam_components'] ?? 'mcq');
                                ?>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="exam_components[]" value="mcq" 
                                           <?= in_array('mcq', $components) ? 'checked' : '' ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <span class="ml-2 text-sm text-gray-700">MCQ</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="exam_components[]" value="project"
                                           <?= in_array('project', $components) ? 'checked' : '' ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <span class="ml-2 text-sm text-gray-700">Project</span>
                                </label>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="exam_components[]" value="viva"
                                           <?= in_array('viva', $components) ? 'checked' : '' ?>
                                           class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                    <span class="ml-2 text-sm text-gray-700">Viva</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Workshop Form -->
                    <?php elseif ($event_type === 'workshop'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="input-group md:col-span-2">
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">
                                Workshop Title <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="title" name="title" required
                                   value="<?= htmlspecialchars($event['title']) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="start_datetime" class="block text-sm font-medium text-gray-700 mb-1">
                                Start Date & Time <span class="text-red-500">*</span>
                            </label>
                            <input type="datetime-local" id="start_datetime" name="start_datetime" required
                                   value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($event['start_datetime']))) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="end_datetime" class="block text-sm font-medium text-gray-700 mb-1">
                                End Date & Time <span class="text-red-500">*</span>
                            </label>
                            <input type="datetime-local" id="end_datetime" name="end_datetime" required
                                   value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($event['end_datetime']))) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="location" class="block text-sm font-medium text-gray-700 mb-1">
                                Location
                            </label>
                            <input type="text" id="location" name="location"
                                   value="<?= htmlspecialchars($event['location'] ?? '') ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="difficulty_level" class="block text-sm font-medium text-gray-700 mb-1">
                                Difficulty Level <span class="text-red-500">*</span>
                            </label>
                            <select id="difficulty_level" name="difficulty_level" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                                <option value="beginner" <?= $event['difficulty_level'] === 'beginner' ? 'selected' : '' ?>>Beginner</option>
                                <option value="intermediate" <?= $event['difficulty_level'] === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
                                <option value="advanced" <?= $event['difficulty_level'] === 'advanced' ? 'selected' : '' ?>>Advanced</option>
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
                                    <option value="<?= $trainer['id'] ?>" 
                                        <?= $event['trainer_id'] == $trainer['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($trainer['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="input-group">
                            <label for="max_participants" class="block text-sm font-medium text-gray-700 mb-1">
                                Max Participants
                            </label>
                            <input type="number" id="max_participants" name="max_participants" min="1"
                                   value="<?= htmlspecialchars($event['max_participants'] ?? '') ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="fee" class="block text-sm font-medium text-gray-700 mb-1">
                                Fee ($)
                            </label>
                            <input type="number" id="fee" name="fee" min="0" step="0.01"
                                   value="<?= htmlspecialchars($event['fee'] ?? 0) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                        </div>
                        
                        <div class="input-group">
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">
                                Status <span class="text-red-500">*</span>
                            </label>
                            <select id="status" name="status" required
                                    class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200">
                                <option value="upcoming" <?= $event['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                                <option value="ongoing" <?= $event['status'] === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                                <option value="completed" <?= $event['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $event['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="input-group md:col-span-2">
                            <label for="requirements" class="block text-sm font-medium text-gray-700 mb-1">
                                Requirements
                            </label>
                            <textarea id="requirements" name="requirements" rows="2"
                                      class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"><?= htmlspecialchars($event['requirements'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="input-group">
                            <div class="flex items-center">
                                <input type="checkbox" id="certificate_available" name="certificate_available"
                                       <?= $event['certificate_available'] ? 'checked' : '' ?>
                                       class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded">
                                <label for="certificate_available" class="ml-2 block text-sm text-gray-700">
                                    Certificate Available
                                </label>
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
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
                    </div>
                    
                    <!-- Class Specific: Cancellation -->
                    <?php if ($event_type === 'class'): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="input-group">
                            <div class="flex items-center">
                                <input type="checkbox" id="is_cancelled" name="is_cancelled" 
                                       <?= $event['is_cancelled'] ? 'checked' : '' ?>
                                       class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                <label for="is_cancelled" class="ml-2 block text-sm text-gray-700">
                                    Cancel this class
                                </label>
                            </div>
                        </div>
                        
                        <div class="input-group">
                            <label for="cancellation_reason" class="block text-sm font-medium text-gray-700 mb-1">
                                Cancellation Reason (if applicable)
                            </label>
                            <textarea id="cancellation_reason" name="cancellation_reason" rows="2"
                                      class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200"><?= htmlspecialchars($event['cancellation_reason'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Back Schedule Checkbox -->
                    <div class="mb-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                        <div class="flex items-center">
                            <input type="checkbox" id="is_back_schedule" name="is_back_schedule" 
                                   <?= (isset($event['is_back_schedule']) && $event['is_back_schedule']) ? 'checked' : '' ?>
                                   class="h-4 w-4 text-gray-600 focus:ring-gray-500 border-gray-300 rounded">
                            <label for="is_back_schedule" class="ml-2 block text-sm text-gray-700">
                                Mark as back schedule (for events with past dates)
                            </label>
                        </div>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="flex justify-between items-center pt-6 border-t border-gray-200">
                        <div>
                            <button type="submit" class="px-6 py-3 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 transition-all duration-300 shadow-md hover:shadow-lg flex items-center">
                                <i class="fas fa-save mr-2"></i> Save Changes
                            </button>
                        </div>
                        
                        <?php if ($event_type === 'class'): ?>
                        <div>
                            <a href="schedule.php?batch_id=<?= $batch_id ?>&delete_id=<?= $event_id ?>" 
                               class="delete-btn px-6 py-3 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition-all duration-300 shadow-md hover:shadow-lg flex items-center"
                               onclick="return confirm('Are you sure you want to delete this class? This action cannot be undone.')">
                                <i class="fas fa-trash mr-2"></i> Delete Class
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // For exams, set passing marks placeholder based on total marks
            const totalMarksInput = document.getElementById('total_marks');
            const passingMarksInput = document.getElementById('passing_marks');
            if (totalMarksInput && passingMarksInput) {
                totalMarksInput.addEventListener('input', function() {
                    const total = parseFloat(this.value) || 0;
                    const passing = Math.ceil(total * 0.33); // 33% passing
                    passingMarksInput.placeholder = `Suggested: ${passing}`;
                });
            }
            
            // Add validation for end time after start time
            const startTimeInput = document.getElementById('start_time');
            const endTimeInput = document.getElementById('end_time');
            if (startTimeInput && endTimeInput) {
                endTimeInput.addEventListener('change', function() {
                    if (startTimeInput.value && this.value && this.value <= startTimeInput.value) {
                        alert('End time must be after start time');
                        this.value = '';
                        this.focus();
                    }
                });
            }
            
            const startDatetimeInput = document.getElementById('start_datetime');
            const endDatetimeInput = document.getElementById('end_datetime');
            if (startDatetimeInput && endDatetimeInput) {
                endDatetimeInput.addEventListener('change', function() {
                    if (startDatetimeInput.value && this.value && this.value <= startDatetimeInput.value) {
                        alert('End datetime must be after start datetime');
                        this.value = '';
                        this.focus();
                    }
                });
            }
            
            // Show/hide cancellation reason based on checkbox
            const isCancelledCheckbox = document.getElementById('is_cancelled');
            const cancellationReasonTextarea = document.getElementById('cancellation_reason');
            if (isCancelledCheckbox && cancellationReasonTextarea) {
                function toggleCancellationReason() {
                    cancellationReasonTextarea.disabled = !isCancelledCheckbox.checked;
                    if (!isCancelledCheckbox.checked) {
                        cancellationReasonTextarea.value = '';
                    }
                }
                
                isCancelledCheckbox.addEventListener('change', toggleCancellationReason);
                toggleCancellationReason(); // Initial state
            }
        });
    </script>
</body>
</html>