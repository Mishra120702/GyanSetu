<?php
require_once '../db_connection.php';
session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : null;

if (!$batch_id) {
    header("Location: ../batch_list.php");
    exit();
}

$message = '';
$message_type = '';
$uploaded_data = [];
$preview_mode = false;
$errors = [];
$success_count = 0;
$error_count = 0;

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
    
    // Get existing schedule dates for validation
    $stmt = $conn->prepare("SELECT schedule_date, start_time, end_time FROM schedule WHERE batch_id = ? ORDER BY schedule_date DESC");
    $stmt->execute([$batch_id]);
    $existing_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle CSV upload and preview
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        
        if ($_POST['action'] === 'preview' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            
            if ($handle !== FALSE) {
                $row_count = 0;
                $headers = [];
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    if ($row_count == 0) {
                        // Validate headers
                        $headers = array_map('trim', $data);
                        $expected_headers = ['date', 'start_time', 'end_time', 'topic', 'description'];
                        
                        if (count(array_intersect($expected_headers, $headers)) < 4) {
                            $message = "Invalid CSV format. Required columns: date, start_time, end_time, topic (description is optional)";
                            $message_type = "error";
                            break;
                        }
                    } else {
                        $row_data = [];
                        foreach ($headers as $index => $header) {
                            $row_data[$header] = isset($data[$index]) ? trim($data[$index]) : '';
                        }
                        
                        // Validate row data
                        $row_errors = [];
                        
                        // Check required fields
                        if (empty($row_data['date'])) {
                            $row_errors[] = "Date is required";
                        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row_data['date'])) {
                            $row_errors[] = "Invalid date format (use YYYY-MM-DD)";
                        }
                        
                        if (empty($row_data['start_time'])) {
                            $row_errors[] = "Start time is required";
                        } elseif (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $row_data['start_time'])) {
                            $row_errors[] = "Invalid start time format (use HH:MM)";
                        }
                        
                        if (empty($row_data['end_time'])) {
                            $row_errors[] = "End time is required";
                        } elseif (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $row_data['end_time'])) {
                            $row_errors[] = "Invalid end time format (use HH:MM)";
                        }
                        
                        if (empty($row_data['topic'])) {
                            $row_errors[] = "Topic is required";
                        }
                        
                        // Validate time logic
                        if (!empty($row_data['start_time']) && !empty($row_data['end_time'])) {
                            if ($row_data['end_time'] <= $row_data['start_time']) {
                                $row_errors[] = "End time must be after start time";
                            }
                        }
                        
                        // Check for conflicts with existing schedule
                        if (empty($row_errors)) {
                            foreach ($existing_schedule as $existing) {
                                if ($existing['schedule_date'] == $row_data['date'] && 
                                    $existing['start_time'] == $row_data['start_time']) {
                                    $row_errors[] = "Conflict: Schedule already exists at this time";
                                    break;
                                }
                            }
                        }
                        
                        $row_data['row_number'] = $row_count + 1;
                        $row_data['errors'] = $row_errors;
                        $row_data['is_valid'] = empty($row_errors);
                        $uploaded_data[] = $row_data;
                    }
                    
                    $row_count++;
                }
                
                fclose($handle);
                
                if (!empty($uploaded_data)) {
                    $preview_mode = true;
                    $message = "File loaded successfully. Please review the data before uploading.";
                    $message_type = "info";
                }
            }
            
        } elseif ($_POST['action'] === 'confirm_upload' && isset($_POST['confirmed_data'])) {
            
            $confirmed_data = json_decode($_POST['confirmed_data'], true);
            $ignore_date_restriction = isset($_POST['ignore_date_restriction']) ? 1 : 0;
            
            $conn->beginTransaction();
            
            try {
                foreach ($confirmed_data as $row) {
                    if ($row['is_valid']) {
                        // Check for duplicate in this batch
                        $check_stmt = $conn->prepare("SELECT id FROM schedule WHERE batch_id = ? AND schedule_date = ? AND start_time = ?");
                        $check_stmt->execute([$batch_id, $row['date'], $row['start_time']]);
                        
                        if (!$check_stmt->fetch()) {
                            $insert_stmt = $conn->prepare("INSERT INTO schedule (batch_id, schedule_date, start_time, end_time, topic, description, created_by, is_back_schedule) 
                                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            
                            $description = $row['description'] ?? '';
                            $insert_stmt->execute([
                                $batch_id, 
                                $row['date'], 
                                $row['start_time'], 
                                $row['end_time'], 
                                $row['topic'], 
                                $description, 
                                $_SESSION['user_id'],
                                $ignore_date_restriction
                            ]);
                            
                            $success_count++;
                        } else {
                            $error_count++;
                        }
                    } else {
                        $error_count++;
                    }
                }
                
                $conn->commit();
                
                if ($success_count > 0) {
                    $message = "Successfully uploaded $success_count schedule entries.";
                    $message_type = "success";
                }
                
                if ($error_count > 0) {
                    $message .= " $error_count entries were skipped due to errors.";
                    $message_type = $success_count > 0 ? "warning" : "error";
                }
                
                $preview_mode = false;
                $uploaded_data = [];
                
            } catch (Exception $e) {
                $conn->rollBack();
                $message = "Error uploading data: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Generate sample CSV content
$sample_csv = "date,start_time,end_time,topic,description\n";
$sample_csv .= "2026-02-21,10:00,12:00,Introduction to PHP,Basic PHP concepts and syntax\n";
$sample_csv .= "2026-02-22,14:00,16:00,Database Design,MySQL fundamentals\n";
$sample_csv .= "2026-02-23,09:00,11:00,JavaScript Basics,Variables and functions\n";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Schedule | <?= htmlspecialchars($batch['batch_id']) ?> | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        
        .glass-morphism {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .upload-area {
            border: 2px dashed #cbd5e0;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        
        .upload-area:hover {
            border-color: #667eea;
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.3);
        }
        
        .upload-area.dragover {
            border-color: #4CAF50;
            background: linear-gradient(135deg, #c8e6c9 0%, #a5d6a7 100%);
            transform: scale(1.02);
        }
        
        .table-row-animation {
            transition: all 0.3s ease;
        }
        
        .table-row-animation:hover {
            background-color: #f7fafc;
            transform: translateX(5px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .valid-row {
            border-left: 4px solid #10B981;
        }
        
        .invalid-row {
            border-left: 4px solid #EF4444;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .slide-in {
            animation: slideIn 0.5s ease forwards;
        }
        
        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
            }
            50% {
                transform: scale(1.05);
            }
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        .floating-shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }
        
        .shape-1 {
            width: 300px;
            height: 300px;
            top: -150px;
            right: -150px;
            background: linear-gradient(135deg, #ff6b6b 0%, #ff8e8e 100%);
        }
        
        .shape-2 {
            width: 200px;
            height: 200px;
            bottom: -100px;
            left: -100px;
            background: linear-gradient(135deg, #4ecdc4 0%, #45b7d1 100%);
        }
        
        .progress-bar {
            transition: width 0.5s ease;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(102, 126, 234, 0.4);
        }
        
        .btn-primary:active {
            transform: translateY(0);
        }
        
        .btn-secondary {
            background: white;
            border: 2px solid #667eea;
            color: #667eea;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #667eea;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -5px rgba(102, 126, 234, 0.4);
        }
        
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .badge-success {
            background: linear-gradient(135deg, #10B981 0%, #059669 100%);
            color: white;
        }
        
        .badge-error {
            background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
            color: white;
        }
        
        .badge-warning {
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            color: white;
        }
        
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="relative overflow-x-hidden">
    <!-- Floating Shapes -->
    <div class="floating-shape shape-1"></div>
    <div class="floating-shape shape-2"></div>
    
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="ml-64 p-6 relative z-10">
        <div class="max-w-7xl mx-auto">
            <!-- Breadcrumb -->
            <nav class="flex mb-6" aria-label="Breadcrumb">
                <ol class="inline-flex items-center space-x-1 md:space-x-3">
                    <li class="inline-flex items-center">
                        <a href="../dashboard.php" class="text-white hover:text-white/80 inline-flex items-center">
                            <i class="fas fa-home mr-2"></i>
                            Dashboard
                        </a>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-white/50 text-sm mx-2"></i>
                            <a href="../batch_list.php" class="text-white hover:text-white/80">Batches</a>
                        </div>
                    </li>
                    <li>
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-white/50 text-sm mx-2"></i>
                            <a href="schedule.php?batch_id=<?= $batch_id ?>" class="text-white hover:text-white/80">Schedule</a>
                        </div>
                    </li>
                    <li aria-current="page">
                        <div class="flex items-center">
                            <i class="fas fa-chevron-right text-white/50 text-sm mx-2"></i>
                            <span class="text-white font-semibold">Bulk Upload</span>
                        </div>
                    </li>
                </ol>
            </nav>
            
            <!-- Header Card -->
            <div class="glass-morphism rounded-2xl shadow-xl p-8 mb-6 slide-in">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-cloud-upload-alt text-4xl mr-4 text-blue-600"></i>
                            Bulk Upload Schedule
                        </h1>
                        <p class="text-gray-600 mt-2">
                            Batch: <span class="font-semibold text-blue-600"><?= htmlspecialchars($batch['batch_id']) ?></span> - 
                            <span class="font-semibold"><?= htmlspecialchars($batch['batch_name']) ?></span>
                        </p>
                        <div class="flex items-center mt-4 space-x-4">
                            <div class="flex items-center text-sm text-gray-500">
                                <i class="fas fa-calendar-alt mr-2 text-blue-500"></i>
                                <span>Start: <?= date('d M Y', strtotime($batch['start_date'])) ?></span>
                            </div>
                            <div class="flex items-center text-sm text-gray-500">
                                <i class="fas fa-clock mr-2 text-green-500"></i>
                                <span><?= $batch['time_slot'] ?? 'Time not set' ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="bg-blue-100 text-blue-800 px-4 py-2 rounded-lg">
                            <i class="fas fa-layer-group mr-2"></i>
                            <span class="font-semibold">Total Existing Classes:</span>
                            <span class="ml-2 text-xl font-bold"><?= count($existing_schedule) ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="mb-6 transform transition-all duration-500 slide-in">
                    <div class="rounded-lg p-4 <?= 
                        $message_type === 'success' ? 'bg-green-100 border-l-4 border-green-500 text-green-700' : 
                        ($message_type === 'error' ? 'bg-red-100 border-l-4 border-red-500 text-red-700' : 
                        ($message_type === 'warning' ? 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700' : 
                        'bg-blue-100 border-l-4 border-blue-500 text-blue-700')) ?>">
                        <div class="flex items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-<?= 
                                    $message_type === 'success' ? 'check-circle' : 
                                    ($message_type === 'error' ? 'exclamation-circle' : 
                                    ($message_type === 'warning' ? 'exclamation-triangle' : 'info-circle')) ?> text-xl"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm font-medium"><?= htmlspecialchars($message) ?></p>
                            </div>
                            <button onclick="this.parentElement.parentElement.remove()" class="ml-auto">
                                <i class="fas fa-times text-gray-400 hover:text-gray-600"></i>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!$preview_mode): ?>
                <!-- Upload Section -->
                <div class="glass-morphism rounded-2xl shadow-xl p-8 mb-6 slide-in">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Left Column - Upload Form -->
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                                <i class="fas fa-upload text-blue-600 mr-3"></i>
                                Upload CSV File
                            </h2>
                            
                            <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                                <input type="hidden" name="action" value="preview">
                                
                                <div class="upload-area rounded-xl p-8 text-center cursor-pointer mb-6" id="dropZone">
                                    <i class="fas fa-file-csv text-5xl text-gray-400 mb-4"></i>
                                    <p class="text-lg font-medium text-gray-700 mb-2">
                                        Drag & drop your CSV file here
                                    </p>
                                    <p class="text-sm text-gray-500 mb-4">
                                        or click to browse
                                    </p>
                                    <input type="file" name="csv_file" id="csvFile" accept=".csv" class="hidden" required>
                                    <button type="button" onclick="document.getElementById('csvFile').click()" 
                                            class="btn-secondary px-6 py-2 rounded-lg text-sm font-semibold">
                                        <i class="fas fa-folder-open mr-2"></i>
                                        Choose File
                                    </button>
                                    <div id="selectedFile" class="mt-4 text-sm text-gray-600 hidden">
                                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                        <span id="fileName"></span>
                                    </div>
                                </div>
                                
                                <!-- Back Schedule Checkbox -->
                                <div class="mb-6 p-4 bg-yellow-50 rounded-lg border-2 border-yellow-200">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-exclamation-triangle text-yellow-500 text-xl"></i>
                                        </div>
                                        <div class="ml-3">
                                            <h3 class="text-sm font-medium text-yellow-800">Back Schedule Notice</h3>
                                            <div class="mt-2 flex items-center">
                                                <input type="checkbox" id="ignore_date_restriction" name="ignore_date_restriction" 
                                                       class="h-4 w-4 text-yellow-600 focus:ring-yellow-500 border-yellow-300 rounded">
                                                <label for="ignore_date_restriction" class="ml-2 text-sm text-yellow-700">
                                                    I understand some dates may be in the past and want to proceed
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <button type="submit" id="previewBtn" disabled
                                        class="w-full btn-primary text-white font-semibold py-3 px-6 rounded-lg flex items-center justify-center opacity-50 cursor-not-allowed">
                                    <i class="fas fa-eye mr-2"></i>
                                    Preview Upload
                                </button>
                            </form>
                        </div>
                        
                        <!-- Right Column - Instructions & Sample -->
                        <div class="border-l border-gray-200 pl-8">
                            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                                <i class="fas fa-info-circle text-blue-600 mr-3"></i>
                                Instructions
                            </h2>
                            
                            <div class="space-y-4">
                                <div class="flex items-start p-4 bg-blue-50 rounded-lg">
                                    <i class="fas fa-file-csv text-blue-600 mt-1 mr-3"></i>
                                    <div>
                                        <h3 class="font-semibold text-gray-800">File Format</h3>
                                        <p class="text-sm text-gray-600">Upload CSV files with the following columns:</p>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="p-3 bg-gray-50 rounded-lg">
                                        <span class="font-mono text-sm font-semibold text-blue-600">date</span>
                                        <p class="text-xs text-gray-500 mt-1">YYYY-MM-DD</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-lg">
                                        <span class="font-mono text-sm font-semibold text-green-600">start_time</span>
                                        <p class="text-xs text-gray-500 mt-1">HH:MM (24hr)</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-lg">
                                        <span class="font-mono text-sm font-semibold text-purple-600">end_time</span>
                                        <p class="text-xs text-gray-500 mt-1">HH:MM (24hr)</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-lg">
                                        <span class="font-mono text-sm font-semibold text-orange-600">topic</span>
                                        <p class="text-xs text-gray-500 mt-1">Class topic/title</p>
                                    </div>
                                    <div class="p-3 bg-gray-50 rounded-lg col-span-2">
                                        <span class="font-mono text-sm font-semibold text-gray-600">description</span>
                                        <p class="text-xs text-gray-500 mt-1">Optional description</p>
                                    </div>
                                </div>
                                
                                <!-- Sample CSV -->
                                <div class="mt-6">
                                    <h3 class="font-semibold text-gray-800 mb-3 flex items-center">
                                        <i class="fas fa-download text-green-600 mr-2"></i>
                                        Sample CSV Template
                                    </h3>
                                    <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
                                        <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap"><?= htmlspecialchars($sample_csv) ?></pre>
                                    </div>
                                    <button onclick="downloadSample()" 
                                            class="mt-3 btn-secondary px-4 py-2 rounded-lg text-sm font-semibold inline-flex items-center">
                                        <i class="fas fa-download mr-2"></i>
                                        Download Sample CSV
                                    </button>
                                </div>
                                
                                <!-- Tips -->
                                <div class="mt-6 p-4 bg-gray-50 rounded-lg">
                                    <h3 class="font-semibold text-gray-800 mb-2 flex items-center">
                                        <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
                                        Pro Tips
                                    </h3>
                                    <ul class="space-y-2 text-sm text-gray-600">
                                        <li class="flex items-center">
                                            <i class="fas fa-check-circle text-green-500 mr-2 text-xs"></i>
                                            Maximum 100 rows per upload
                                        </li>
                                        <li class="flex items-center">
                                            <i class="fas fa-check-circle text-green-500 mr-2 text-xs"></i>
                                            Avoid duplicate date & time combinations
                                        </li>
                                        <li class="flex items-center">
                                            <i class="fas fa-check-circle text-green-500 mr-2 text-xs"></i>
                                            End time must be after start time
                                        </li>
                                        <li class="flex items-center">
                                            <i class="fas fa-check-circle text-green-500 mr-2 text-xs"></i>
                                            Use 24-hour format for times (e.g., 14:30)
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Preview Section -->
                <div class="glass-morphism rounded-2xl shadow-xl p-8 mb-6 slide-in">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-eye text-blue-600 mr-3"></i>
                            Preview Upload Data
                        </h2>
                        <div class="flex space-x-3">
                            <span class="badge badge-success">
                                <i class="fas fa-check-circle mr-1"></i>
                                Valid: <?= count(array_filter($uploaded_data, function($row) { return $row['is_valid']; })) ?>
                            </span>
                            <span class="badge badge-error">
                                <i class="fas fa-exclamation-circle mr-1"></i>
                                Invalid: <?= count(array_filter($uploaded_data, function($row) { return !$row['is_valid']; })) ?>
                            </span>
                        </div>
                    </div>
                    
                    <!-- Data Table -->
                    <div class="overflow-x-auto rounded-lg border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Start Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">End Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Topic</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($uploaded_data as $index => $row): ?>
                                    <tr class="table-row-animation <?= $row['is_valid'] ? 'valid-row' : 'invalid-row' ?>">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= $row['row_number'] ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($row['date']) ?>
                                            <?php 
                                            $today = date('Y-m-d');
                                            if ($row['date'] < $today): ?>
                                                <span class="ml-2 badge badge-warning text-xs">Past</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= htmlspecialchars($row['start_time']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= htmlspecialchars($row['end_time']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?= htmlspecialchars($row['topic']) ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                                            <?= htmlspecialchars($row['description'] ?? '') ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($row['is_valid']): ?>
                                                <span class="badge badge-success">
                                                    <i class="fas fa-check-circle mr-1"></i>
                                                    Valid
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-error">
                                                    <i class="fas fa-times-circle mr-1"></i>
                                                    Invalid
                                                </span>
                                                <?php if (!empty($row['errors'])): ?>
                                                    <div class="text-xs text-red-600 mt-1">
                                                        <?= implode(', ', $row['errors']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Action Buttons -->
                    <div class="mt-6 flex justify-end space-x-4">
                        <a href="upload_schedule.php?batch_id=<?= $batch_id ?>" 
                           class="btn-secondary px-6 py-3 rounded-lg font-semibold inline-flex items-center">
                            <i class="fas fa-times mr-2"></i>
                            Cancel
                        </a>
                        
                        <form action="" method="POST" id="confirmForm">
                            <input type="hidden" name="action" value="confirm_upload">
                            <input type="hidden" name="confirmed_data" value='<?= htmlspecialchars(json_encode($uploaded_data)) ?>'>
                            <input type="hidden" name="ignore_date_restriction" value="<?= isset($_POST['ignore_date_restriction']) ? '1' : '0' ?>">
                            
                            <?php 
                            $valid_count = count(array_filter($uploaded_data, function($row) { return $row['is_valid']; }));
                            $has_past_dates = count(array_filter($uploaded_data, function($row) { 
                                return $row['date'] < date('Y-m-d'); 
                            })) > 0;
                            ?>
                            
                            <button type="submit" 
                                    <?= $valid_count === 0 ? 'disabled' : '' ?>
                                    class="btn-primary text-white font-semibold py-3 px-6 rounded-lg inline-flex items-center <?= $valid_count === 0 ? 'opacity-50 cursor-not-allowed' : '' ?>">
                                <i class="fas fa-check-circle mr-2"></i>
                                Confirm Upload (<?= $valid_count ?> entries)
                            </button>
                        </form>
                    </div>
                    
                    <?php if ($has_past_dates && !isset($_POST['ignore_date_restriction'])): ?>
                        <div class="mt-4 p-4 bg-yellow-50 rounded-lg">
                            <div class="flex">
                                <i class="fas fa-exclamation-triangle text-yellow-500 mr-3 mt-1"></i>
                                <div>
                                    <p class="text-sm text-yellow-700">
                                        <strong>Note:</strong> Some dates are in the past. These will be marked as back schedules.
                                        If you want to proceed, please go back and check the "I understand" box.
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <!-- Quick Stats Card -->
            <div class="glass-morphism rounded-2xl shadow-xl p-6 slide-in">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-chart-line text-blue-600 mr-2"></i>
                    Quick Statistics
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-blue-600 font-medium">Total Classes</p>
                                <p class="text-2xl font-bold text-gray-800"><?= count($existing_schedule) ?></p>
                            </div>
                            <i class="fas fa-calendar-check text-3xl text-blue-400"></i>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-green-600 font-medium">This Month</p>
                                <p class="text-2xl font-bold text-gray-800">
                                    <?= count(array_filter($existing_schedule, function($row) { 
                                        return date('Y-m', strtotime($row['schedule_date'])) === date('Y-m'); 
                                    })) ?>
                                </p>
                            </div>
                            <i class="fas fa-chart-line text-3xl text-green-400"></i>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-purple-600 font-medium">Next Class</p>
                                <p class="text-sm font-semibold text-gray-800">
                                    <?php 
                                    $next = array_filter($existing_schedule, function($row) { 
                                        return $row['schedule_date'] >= date('Y-m-d'); 
                                    });
                                    $next = array_values($next);
                                    echo !empty($next) ? date('d M', strtotime($next[0]['schedule_date'])) : 'No upcoming';
                                    ?>
                                </p>
                            </div>
                            <i class="fas fa-clock text-3xl text-purple-400"></i>
                        </div>
                    </div>
                    
                    <div class="bg-gradient-to-br from-orange-50 to-orange-100 rounded-lg p-4">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-orange-600 font-medium">Completion</p>
                                <p class="text-2xl font-bold text-gray-800">
                                    <?php 
                                    $total_days = (strtotime($batch['end_date']) - strtotime($batch['start_date'])) / (60*60*24);
                                    $elapsed_days = (time() - strtotime($batch['start_date'])) / (60*60*24);
                                    $progress = min(100, max(0, round(($elapsed_days / $total_days) * 100)));
                                    echo $progress . '%';
                                    ?>
                                </p>
                            </div>
                            <div class="relative">
                                <svg class="w-12 h-12">
                                    <circle class="text-gray-300" stroke-width="4" stroke="currentColor" fill="transparent" r="20" cx="24" cy="24"/>
                                    <circle class="text-orange-500" stroke-width="4" stroke="currentColor" fill="transparent" r="20" cx="24" cy="24"
                                            stroke-dasharray="<?= 2 * 3.14 * 20 ?>"
                                            stroke-dashoffset="<?= 2 * 3.14 * 20 * (1 - $progress/100) ?>"
                                            stroke-linecap="round"/>
                                </svg>
                                <span class="absolute inset-0 flex items-center justify-center text-xs font-bold"><?= $progress ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dropZone = document.getElementById('dropZone');
            const fileInput = document.getElementById('csvFile');
            const selectedFile = document.getElementById('selectedFile');
            const fileName = document.getElementById('fileName');
            const previewBtn = document.getElementById('previewBtn');
            const ignoreCheckbox = document.getElementById('ignore_date_restriction');
            
            // Drag and drop functionality
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, preventDefaults, false);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            ['dragenter', 'dragover'].forEach(eventName => {
                dropZone.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropZone.addEventListener(eventName, unhighlight, false);
            });
            
            function highlight() {
                dropZone.classList.add('dragover');
            }
            
            function unhighlight() {
                dropZone.classList.remove('dragover');
            }
            
            dropZone.addEventListener('drop', handleDrop, false);
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                fileInput.files = files;
                updateFileSelection();
            }
            
            fileInput.addEventListener('change', updateFileSelection);
            
            function updateFileSelection() {
                if (fileInput.files.length > 0) {
                    fileName.textContent = fileInput.files[0].name;
                    selectedFile.classList.remove('hidden');
                    previewBtn.disabled = false;
                    previewBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                    previewBtn.classList.add('hover:shadow-lg');
                } else {
                    selectedFile.classList.add('hidden');
                    previewBtn.disabled = true;
                    previewBtn.classList.add('opacity-50', 'cursor-not-allowed');
                    previewBtn.classList.remove('hover:shadow-lg');
                }
            }
            
            // Form validation
            document.getElementById('uploadForm').addEventListener('submit', function(e) {
                if (!fileInput.files.length) {
                    e.preventDefault();
                    alert('Please select a CSV file to upload.');
                }
            });
            
            // Loading state for confirm button
            document.getElementById('confirmForm')?.addEventListener('submit', function(e) {
                const btn = this.querySelector('button[type="submit"]');
                if (btn && !btn.disabled) {
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Uploading...';
                    btn.disabled = true;
                }
            });
            
            // Preview animation
            const rows = document.querySelectorAll('.table-row-animation');
            rows.forEach((row, index) => {
                row.style.animation = `slideIn 0.3s ease forwards ${index * 0.05}s`;
                row.style.opacity = '0';
            });
        });
        
        function downloadSample() {
            const content = `<?= addslashes($sample_csv) ?>`;
            const blob = new Blob([content], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sample_schedule.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
        }
        
        // Add tooltip functionality
        const tooltips = document.querySelectorAll('[data-tooltip]');
        tooltips.forEach(element => {
            element.addEventListener('mouseenter', e => {
                const tooltip = document.createElement('div');
                tooltip.className = 'absolute bg-gray-900 text-white text-xs rounded py-1 px-2 z-50';
                tooltip.textContent = element.dataset.tooltip;
                document.body.appendChild(tooltip);
                
                const rect = element.getBoundingClientRect();
                tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
                tooltip.style.left = rect.left + (rect.width - tooltip.offsetWidth) / 2 + 'px';
                
                element.addEventListener('mouseleave', () => {
                    tooltip.remove();
                });
            });
        });
    </script>
</body>
</html>