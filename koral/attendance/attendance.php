<?php
// file: attendance.php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Check if batch_id is provided in URL
$preselected_batch = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$preselected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Get all batches for the filter dropdown
try {
    $stmt = $db->query("SELECT batch_id, batch_name FROM batches");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching batches: " . $e->getMessage());
    $batches = [];
}

// Handle file upload if submitted
if (isset($_POST['import'])) {
    if (isset($_FILES['excel_file'])) {
        require_once 'attendance_upload.php'; // Include the processing script
        header("Location: attendance.php"); // Redirect back to prevent form resubmission
        exit();
    }
}

// Handle new attendance creation
if (isset($_POST['create_attendance'])) {
    $batch_id = $_POST['batch_id'];
    $date = $_POST['date'];
    
    try {
        // Check if attendance already exists for this batch and date
        $stmt = $db->prepare("SELECT COUNT(*) FROM attendance WHERE batch_id = ? AND date = ?");
        $stmt->execute([$batch_id, $date]);
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $_SESSION['error_message'] = "Attendance already exists for batch $batch_id on $date";
        } else {
            // Get all ACTIVE students in this batch with their student_id
            // Updated to include batch_name_2, batch_name_3, and batch_name_4
            $stmt = $db->prepare("SELECT student_id, CONCAT(first_name, ' ', last_name) as student_name 
                                 FROM students 
                                 WHERE (batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?) 
                                 AND current_status = 'active'");
            $stmt->execute([$batch_id, $batch_id, $batch_id, $batch_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($students)) {
                $_SESSION['error_message'] = "No active students found in batch $batch_id";
            } else {
                // Insert attendance records for each student with student_id
                $insertCount = 0;
                foreach ($students as $student) {
                    $stmt = $db->prepare("INSERT INTO attendance (date, batch_id, student_id, student_name, status, camera_status) 
                                         VALUES (?, ?, ?, ?, 'Absent', 'Off')");
                    if ($stmt->execute([$date, $batch_id, $student['student_id'], $student['student_name']])) {
                        $insertCount++;
                    }
                }
                
                $_SESSION['success_message'] = "New attendance created for batch $batch_id on $date with $insertCount active students marked as Absent";
            }
        }
    } catch (PDOException $e) {
        error_log("Database error creating attendance: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error occurred while creating attendance: " . $e->getMessage();
    }
    
    // Redirect to prevent form resubmission
    header("Location: attendance.php?batch_id=" . urlencode($batch_id) . "&date=" . urlencode($date));
    exit();
}

// Handle attendance deletion
if (isset($_POST['delete_attendance'])) {
    $batch_id = $_POST['delete_batch_id'];
    $date = $_POST['delete_date'];
    
    if (empty($batch_id) || empty($date)) {
        $_SESSION['error_message'] = "Batch ID and Date are required for deletion";
    } else {
        try {
            // Start transaction
            $db->beginTransaction();
            
            // Delete attendance records
            $stmt = $db->prepare("DELETE FROM attendance WHERE batch_id = ? AND date = ?");
            $stmt->execute([$batch_id, $date]);
            $deletedCount = $stmt->rowCount();
            
            $db->commit();
            
            $_SESSION['success_message'] = "Successfully deleted $deletedCount attendance records for batch $batch_id on $date";
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("Database error deleting attendance: " . $e->getMessage());
            $_SESSION['error_message'] = "Database error occurred while deleting attendance: " . $e->getMessage();
        }
    }
    
    header("Location: attendance.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Tracking - ASD Academy</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        /* Your existing CSS styles remain the same */
        :root {
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-700: #374151;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8fafc;
            transition: all 0.3s ease;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        .card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            transform: translateY(-2px);
        }
        
        header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            min-width: 70px;
            justify-content: center;
            transition: all 0.2s ease;
            transform-origin: center;
        }
        
        .status-present {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-absent {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-late {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 44px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #e2e8f0;
            transition: .4s;
            border-radius: 24px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        }
        
        input:checked + .slider {
            background-color: var(--success);
        }
        
        input:checked + .slider:before {
            transform: translateX(20px);
        }
        
        .camera-slider input:checked + .slider {
            background-color: var(--primary);
        }

        /* New styles for status slider */
        .status-slider input:checked + .slider {
            background-color: var(--success);
        }
        
        .status-slider input:not(:checked) + .slider {
            background-color: var(--danger);
        }

        .status-label {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            font-weight: 600;
            color: white;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .status-present-label {
            left: 6px;
            opacity: 0;
        }

        .status-absent-label {
            right: 6px;
            opacity: 1;
        }

        .status-slider input:checked + .slider .status-present-label {
            opacity: 1;
        }

        .status-slider input:checked + .slider .status-absent-label {
            opacity: 0;
        }

        .status-slider input:not(:checked) + .slider .status-present-label {
            opacity: 0;
        }

        .status-slider input:not(:checked) + .slider .status-absent-label {
            opacity: 1;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 2px 5px rgba(59, 130, 246, 0.3);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.3);
        }
        
        .btn-success {
            background-color: var(--success);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 2px 5px rgba(16, 185, 129, 0.3);
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-success:hover {
            background-color: #0d9488;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(16, 185, 129, 0.3);
        }
        
        .btn-danger {
            background-color: var(--danger);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 2px 5px rgba(239, 68, 68, 0.3);
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(239, 68, 68, 0.3);
        }
        
        .btn-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
            border: none;
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-secondary:hover {
            background-color: var(--gray-300);
        }
        
        .toggle-buttons {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .toggle-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: none;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        
        .toggle-btn.active {
            background-color: var(--primary);
            color: white;
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.3);
        }
        
        .toggle-btn:not(.active) {
            background-color: var(--gray-100);
            color: var(--gray-700);
        }
        
        .minimal-input {
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 0.6rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            background-color: white;
            width: 100%;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        
        .minimal-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
            outline: none;
        }
        
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            animation: fadeIn 0.5s ease-out;
            border-left: 4px solid transparent;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border-color: #10b981;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #b91c1b;
            border-color: #ef4444;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(59, 130, 246, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            padding: 2rem;
            transform: translateY(-20px);
            transition: all 0.3s ease;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        
        #successToast {
            position: fixed;
            bottom: 1rem;
            right: 1rem;
            background-color: #10b981;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        
        #successToast.show {
            transform: translateY(0);
            opacity: 1;
        }

        .toggle-status-btn {
            display: flex;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--gray-300);
            width: fit-content;
        }

        .toggle-status-btn button {
            padding: 0.5rem 1rem;
            border: none;
            background-color: var(--gray-100);
            color: var(--gray-700);
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .toggle-status-btn button.active {
            background-color: var(--primary);
            color: white;
        }

        .toggle-status-btn button:first-child {
            border-right: 1px solid var(--gray-300);
        }

        .template-info {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .template-info h4 {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }

        .template-info ul {
            list-style-type: disc;
            margin-left: 1.5rem;
            color: #6b7280;
        }

        .template-info li {
            margin-bottom: 0.25rem;
        }

        /* New styles for disabled camera slider */
        .camera-slider.disabled {
            opacity: 0.6;
            pointer-events: none;
        }

        .camera-slider.disabled .slider {
            background-color: #9ca3af !important;
        }

        /* New styles for batch transfer indicator */
        .batch-history {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            color: #92400e;
            margin-top: 0.25rem;
        }

        /* Batch info badge */
        .batch-info-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }

        .primary-batch {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .secondary-batch {
            background-color: #f0f9ff;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }

        .tertiary-batch {
            background-color: #fefce8;
            color: #854d0e;
            border: 1px solid #fde047;
        }

        .quaternary-batch {
            background-color: #f3e8ff;
            color: #6b21a8;
            border: 1px solid #d8b4fe;
        }

        /* Delete confirmation modal styles */
        .delete-confirmation {
            text-align: center;
        }

        .delete-confirmation i {
            font-size: 3rem;
            color: #ef4444;
            margin-bottom: 1rem;
        }

        .delete-confirmation h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #374151;
        }

        .delete-confirmation p {
            color: #6b7280;
            margin-bottom: 1.5rem;
        }

        .modal-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
        }

        /* Export dropdown styles */
        .relative {
            position: relative;
        }
        
        .inline-block {
            display: inline-block;
        }
        
        .hidden {
            display: none;
        }
        
        .export-dropdown {
            position: absolute;
            right: 0;
            margin-top: 0.5rem;
            width: 16rem;
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            z-index: 50;
            border: 1px solid var(--gray-200);
        }
        
        .export-dropdown a, .export-dropdown button {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.875rem;
            color: var(--gray-700);
            transition: all 0.2s ease;
            border: none;
            background: none;
            cursor: pointer;
        }
        
        .export-dropdown a:hover, .export-dropdown button:hover {
            background-color: var(--gray-100);
        }
        
        .export-dropdown i {
            margin-right: 0.5rem;
            width: 1.25rem;
        }
        
        .export-dropdown hr {
            margin: 0.5rem 0;
            border: 0;
            border-top: 1px solid var(--gray-200);
        }
        
        /* DataTable export button */
        #dtExportBtn {
            margin-left: 0.5rem;
            padding: 0.4rem 1rem;
            font-size: 0.875rem;
        }
        
        /* Grid layout for filters */
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1rem;
        }
        
        @media (max-width: 768px) {
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .export-dropdown {
                width: 100%;
                position: static;
                margin-top: 0.5rem;
                box-shadow: none;
                border: 1px solid var(--gray-200);
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600 hover:text-blue-500 transition-colors" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-clipboard-check text-blue-500"></i>
                <span>Attendance Tracking</span>
            </h1>
        </header>

        <div class="p-4 md:p-6">
            <!-- Display error/success messages -->
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error mb-4">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['error_message']); ?>
                    <?php unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success mb-4">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['success_message']); ?>
                    <?php unset($_SESSION['success_message']); ?>
                </div>
            <?php endif; ?>

            <!-- Toggle buttons -->
            <div class="toggle-buttons">
                <button id="showManualBtn" class="toggle-btn active">
                    <i class="fas fa-edit mr-2"></i> Manual Attendance
                </button>
                <button id="showUploadBtn" class="toggle-btn">
                    <i class="fas fa-file-upload mr-2"></i> Upload Excel
                </button>
                <a href="monthly_attendance.php" class="toggle-btn">
                    <i class="fas fa-calendar-alt mr-2"></i> Monthly Report
                </a>
                <button id="showCreateBtn" class="toggle-btn">
                    <i class="fas fa-plus-circle mr-2"></i> New Attendance
                </button>
                <button id="showDeleteBtn" class="toggle-btn">
                    <i class="fas fa-trash-alt mr-2"></i> Delete Attendance
                </button>
            </div>
            
            <!-- Manual Attendance Section -->
            <div id="manualAttendanceSection">
                <!-- Filters Card -->
                <div class="card">
                    <div class="filters-grid">
                        <select id="batchFilter" class="minimal-input">
                            <option value="">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                            <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                <?= ($preselected_batch === $batch['batch_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <input type="text" id="dateFilter" class="minimal-input date-picker" placeholder="Select date" value="<?= $preselected_date ?>">
                        
                        <button id="markAllPresent" class="btn-primary">
                            <i class="fas fa-check-circle mr-2"></i> Mark All Present
                        </button>
                        
                        <button id="loadAttendance" class="btn-primary">
                            <i class="fas fa-sync-alt mr-2"></i> Load Attendance
                        </button>
                        
                        <!-- Export Dropdown Button -->
                        <div class="relative inline-block">
                            <button id="exportDropdownBtn" class="btn-success w-full" onclick="toggleExportDropdown()">
                                <i class="fas fa-download mr-2"></i> Export <i class="fas fa-caret-down ml-1"></i>
                            </button>
                            <div id="exportDropdown" class="export-dropdown hidden">
                                <a href="daily_attendance_export.php">
                                    <i class="fas fa-calendar-day text-blue-500"></i> Daily Export Page
                                </a>
                                <button onclick="quickExportCurrent()">
                                    <i class="fas fa-file-excel text-green-500"></i> Export Current View (Excel)
                                </button>
                                <button onclick="exportToCSV()">
                                    <i class="fas fa-file-csv text-blue-500"></i> Export Current View (CSV)
                                </button>
                                <hr>
                                <button onclick="exportAllBatchesToday()">
                                    <i class="fas fa-calendar-check text-purple-500"></i> All Batches - Today
                                </button>
                                <button onclick="exportWithDetails()">
                                    <i class="fas fa-info-circle text-orange-500"></i> Export with Student Details
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Attendance Table Card -->
                <div class="card">
                    <div id="attendanceError" class="alert alert-error mb-4" style="display: none;">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <span id="errorMessage">Error loading attendance data</span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table id="attendanceTable" class="display" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Batch ID</th>
                                    <th>Primary Batch</th>
                                    <th>Secondary Batch</th>
                                    <th>Tertiary Batch</th>
                                    <th>Quaternary Batch</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Camera</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Data will be loaded via AJAX -->
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="flex justify-end mt-4">
                        <button id="saveAttendance" class="btn-primary">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Upload Excel Section (initially hidden) -->
            <div id="uploadExcelSection" style="display: none;">
                <div class="card">
                    <h2 class="text-xl font-bold mb-4">Upload Excel File</h2>
                    <form action="attendance.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label for="excel_file" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Excel File
                            </label>
                            <input type="file" id="excel_file" name="excel_file" accept=".xlsx,.xls" class="minimal-input" required>
                        </div>
                        
                        <!-- Template Information -->
                        <div class="template-info">
                            <h4><i class="fas fa-info-circle mr-2 text-blue-500"></i>Excel Template Requirements</h4>
                            <ul>
                                <li>File format must be .xlsx or .xls</li>
                                <li>Required columns: student_id, date, status</li>
                                <li>Optional columns: batch_id, student_name, camera_status, remarks</li>
                                <li>Date format: YYYY-MM-DD (e.g., 2024-01-15)</li>
                                <li>Status values: 'Present' or 'Absent' only</li>
                                <li>Camera status values: 'On' or 'Off'</li>
                                <li>First row should contain column headers</li>
                                <li><strong>Note:</strong> Camera status will be automatically set to 'Off' if status is not 'Present'</li>
                            </ul>
                            <div class="mt-3">
                                <a href="javascript:void(0)" onclick="downloadTemplate()" class="text-blue-500 hover:text-blue-700 text-sm font-medium">
                                    <i class="fas fa-download mr-1"></i> Download Excel Template
                                </a>
                            </div>
                        </div>
                        
                        <button type="submit" name="import" class="btn-primary mt-4">
                            <i class="fas fa-upload mr-2"></i> Upload Attendance
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Create New Attendance Section (initially hidden) -->
            <div id="createAttendanceSection" style="display: none;">
                <div class="card">
                    <h2 class="text-xl font-bold mb-4">Create New Attendance</h2>
                    <form action="attendance.php" method="POST">
                        <div class="mb-4">
                            <label for="createBatch" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Batch
                            </label>
                            <select id="createBatch" name="batch_id" class="minimal-input" required>
                                <option value="">-- Select Batch --</option>
                                <?php foreach ($batches as $batch): ?>
                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                                    <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="createDate" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Date
                            </label>
                            <input type="text" id="createDate" name="date" class="minimal-input create-date-picker" required>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="submit" name="create_attendance" class="btn-primary">
                                <i class="fas fa-plus-circle mr-2"></i> Create Attendance
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Delete Attendance Section (initially hidden) -->
            <div id="deleteAttendanceSection" style="display: none;">
                <div class="card">
                    <h2 class="text-xl font-bold mb-4">Delete Attendance</h2>
                    <form id="deleteAttendanceForm" action="attendance.php" method="POST">
                        <div class="mb-4">
                            <label for="deleteBatch" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Batch <span class="text-red-500">*</span>
                            </label>
                            <select id="deleteBatch" name="delete_batch_id" class="minimal-input" required>
                                <option value="">-- Select Batch --</option>
                                <?php foreach ($batches as $batch): ?>
                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>">
                                    <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-4">
                            <label for="deleteDate" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Date <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="deleteDate" name="delete_date" class="minimal-input delete-date-picker" required>
                        </div>
                        
                        <div class="mb-4">
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle text-red-400"></i>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-red-800">Warning</h3>
                                        <div class="mt-2 text-sm text-red-700">
                                            <p>This action will permanently delete all attendance records for the selected batch and date. This action cannot be undone.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end space-x-3">
                            <button type="button" id="deleteConfirmBtn" class="btn-danger">
                                <i class="fas fa-trash-alt mr-2"></i> Delete Attendance
                            </button>
                        </div>
                        
                        <!-- Hidden submit button -->
                        <input type="submit" name="delete_attendance" style="display: none;">
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div id="loadingModal" class="modal-overlay">
        <div class="modal-content flex flex-col items-center justify-center p-8">
            <div class="spinner mb-4"></div>
            <h3 class="text-lg font-medium text-gray-800">Loading...</h3>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmationModal" class="modal-overlay">
        <div class="modal-content">
            <div class="delete-confirmation">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>Confirm Deletion</h3>
                <p>Are you sure you want to delete attendance records for batch <span id="confirmBatch"></span> on <span id="confirmDate"></span>?</p>
                <p class="text-red-600 font-medium">This action cannot be undone!</p>
                <div class="modal-buttons">
                    <button type="button" id="cancelDeleteBtn" class="btn-primary">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </button>
                    <button type="button" id="confirmDeleteBtn" class="btn-danger">
                        <i class="fas fa-trash-alt mr-2"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Toast -->
    <div id="successToast" class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg">
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-2"></i>
            <span id="toastMessage">Operation completed successfully!</span>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    $(document).ready(function() {
        // Initialize date pickers
        flatpickr("#dateFilter", {
            dateFormat: "Y-m-d",
            defaultDate: "<?= $preselected_date ?>",
            maxDate: "today"
        });
        
        flatpickr("#createDate", {
            dateFormat: "Y-m-d",
            defaultDate: "today",
            maxDate: "today"
        });

        flatpickr("#deleteDate", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Function to update camera slider state based on status
        function updateCameraState(statusToggle, cameraToggle) {
            const isPresent = statusToggle.is(':checked');
            const cameraSlider = cameraToggle.closest('.camera-slider');
            
            if (!isPresent) {
                // If status is not present, force camera to off and disable it
                cameraToggle.prop('checked', false);
                cameraToggle.prop('disabled', true);
                cameraSlider.addClass('disabled');
            } else {
                // If status is present, enable camera slider
                cameraToggle.prop('disabled', false);
                cameraSlider.removeClass('disabled');
            }
        }

        // Show/hide loading modal
        function showLoading() {
            $('#loadingModal').addClass('active');
        }
        
        function hideLoading() {
            $('#loadingModal').removeClass('active');
        }

        // Show/hide delete confirmation modal
        function showDeleteConfirmation(batchId, date) {
            $('#confirmBatch').text(batchId);
            $('#confirmDate').text(date);
            $('#deleteConfirmationModal').addClass('active');
        }
        
        function hideDeleteConfirmation() {
            $('#deleteConfirmationModal').removeClass('active');
        }

        // Show toast message
        function showToast(message, isSuccess = true) {
            const toast = $('#successToast');
            const icon = toast.find('i');
            const messageSpan = $('#toastMessage');
            
            if (isSuccess) {
                toast.removeClass('bg-red-500').addClass('bg-green-500');
                icon.removeClass('fa-exclamation-circle').addClass('fa-check-circle');
            } else {
                toast.removeClass('bg-green-500').addClass('bg-red-500');
                icon.removeClass('fa-check-circle').addClass('fa-exclamation-circle');
            }
            
            messageSpan.text(message);
            toast.addClass('show');
            
            setTimeout(() => {
                toast.removeClass('show');
            }, 3000);
        }

        // Show error message
        function showError(message) {
            $('#errorMessage').text(message);
            $('#attendanceError').show();
        }

        function hideError() {
            $('#attendanceError').hide();
        }

        // Section toggle functionality
        $('#showManualBtn').click(function() {
            $('.toggle-btn').removeClass('active');
            $(this).addClass('active');
            $('#uploadExcelSection').hide();
            $('#createAttendanceSection').hide();
            $('#deleteAttendanceSection').hide();
            $('#manualAttendanceSection').show();
        });
        
        $('#showUploadBtn').click(function() {
            $('.toggle-btn').removeClass('active');
            $(this).addClass('active');
            $('#manualAttendanceSection').hide();
            $('#createAttendanceSection').hide();
            $('#deleteAttendanceSection').hide();
            $('#uploadExcelSection').show();
        });
        
        $('#showCreateBtn').click(function() {
            $('.toggle-btn').removeClass('active');
            $(this).addClass('active');
            $('#manualAttendanceSection').hide();
            $('#uploadExcelSection').hide();
            $('#deleteAttendanceSection').hide();
            $('#createAttendanceSection').show();
        });

        $('#showDeleteBtn').click(function() {
            $('.toggle-btn').removeClass('active');
            $(this).addClass('active');
            $('#manualAttendanceSection').hide();
            $('#uploadExcelSection').hide();
            $('#createAttendanceSection').hide();
            $('#deleteAttendanceSection').show();
        });

        // Initialize DataTable for attendance
        const attendanceTable = $('#attendanceTable').DataTable({
            responsive: true,
            searching: true,
            ordering: true,
            lengthChange: true,
            pageLength: 10,
            columns: [
                { data: 'student_id' },
                { data: 'student_name' },
                { data: 'batch_id' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        if (!row.batch_name) return '-';
                        let html = `<span class="batch-info-badge primary-batch">${row.batch_name}</span>`;
                        if (row.batch_id !== row.batch_name && row.batch_name) {
                            html += `<div class="batch-history">Attendance for ${row.batch_id}</div>`;
                        }
                        return html;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        if (!row.batch_name_2 || row.batch_name_2 === '') return '-';
                        return `<span class="batch-info-badge secondary-batch">${row.batch_name_2}</span>`;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        if (!row.batch_name_3 || row.batch_name_3 === '') return '-';
                        return `<span class="batch-info-badge tertiary-batch">${row.batch_name_3}</span>`;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        if (!row.batch_name_4 || row.batch_name_4 === '') return '-';
                        return `<span class="batch-info-badge quaternary-batch">${row.batch_name_4}</span>`;
                    }
                },
                { data: 'date' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return `
                            <label class="switch status-slider">
                                <input type="checkbox" class="status-toggle" data-id="${row.id}" ${row.status === 'Present' ? 'checked' : ''}>
                                <span class="slider">
                                    <span class="status-label status-present-label">P</span>
                                    <span class="status-label status-absent-label">A</span>
                                </span>
                            </label>
                        `;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        const isPresent = row.status === 'Present';
                        const isCameraOn = row.camera_status === 'On';
                        const disabledClass = !isPresent ? 'disabled' : '';
                        
                        return `
                            <label class="switch camera-slider ${disabledClass}">
                                <input type="checkbox" class="camera-toggle" data-id="${row.id}" 
                                    ${isCameraOn ? 'checked' : ''} 
                                    ${!isPresent ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                        `;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return `<input type="text" class="remarks-input minimal-input" data-id="${row.id}" value="${row.remarks || ''}" placeholder="Add remarks">`;
                    }
                }
            ],
            drawCallback: function() {
                // Add export button to DataTable toolbar
                if ($('#dtExportBtn').length === 0) {
                    $('.dataTables_filter').append(
                        '<button id="dtExportBtn" class="btn-success ml-2" onclick="exportCurrentDataTable()">' +
                        '<i class="fas fa-download mr-2"></i> Export Table</button>'
                    );
                }
            }
        });

        // Event delegation for status toggle changes
        $(document).on('change', '.status-toggle', function() {
            const statusToggle = $(this);
            const row = statusToggle.closest('tr');
            const cameraToggle = row.find('.camera-toggle');
            
            // Update camera state based on status
            updateCameraState(statusToggle, cameraToggle);
        });

        // Initialize camera states when table is loaded
        $(document).on('draw.dt', function() {
            $('.status-toggle').each(function() {
                const statusToggle = $(this);
                const row = statusToggle.closest('tr');
                const cameraToggle = row.find('.camera-toggle');
                updateCameraState(statusToggle, cameraToggle);
            });
        });

        // Load attendance data
        function loadAttendanceData() {
            const batchId = $('#batchFilter').val();
            const date = $('#dateFilter').val();
            
            if (!date) {
                showError('Please select a date');
                return;
            }
            
            showLoading();
            hideError();
            
            $.ajax({
                url: 'attendance_api.php',
                type: 'GET',
                data: { 
                    action: 'fetch',
                    batch_id: batchId,
                    date: date
                },
                success: function(response) {
                    hideLoading();
                    
                    try {
                        if (response.success) {
                            attendanceTable.clear().rows.add(response.data).draw();
                            showToast('Attendance data loaded successfully');
                        } else {
                            showError(response.message || 'Failed to load attendance data');
                        }
                    } catch (e) {
                        console.error('Error processing response:', e);
                        showError('Error processing attendance data');
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    console.error('AJAX Error:', error);
                    showError('Network error occurred while loading attendance data');
                }
            });
        }

        // Load attendance on page load if date is preselected
        if ($('#dateFilter').val()) {
            loadAttendanceData();
        }

        // Load attendance button click
        $('#loadAttendance').click(loadAttendanceData);

        // Mark all present button
        $('#markAllPresent').click(function() {
            // Mark all status toggles as present
            $('.status-toggle').prop('checked', true);
            
            // Enable and turn on all camera toggles
            $('.camera-toggle').each(function() {
                const cameraToggle = $(this);
                const cameraSlider = cameraToggle.closest('.camera-slider');
                
                // Enable the camera toggle
                cameraToggle.prop('disabled', false);
                cameraToggle.prop('checked', true);
                
                // Remove disabled class from camera slider
                cameraSlider.removeClass('disabled');
            });
            
            showToast('All students marked as present with camera on');
        });

        // Save attendance changes
        $('#saveAttendance').click(function() {
            const changes = [];
            
            $('#attendanceTable tbody tr').each(function() {
                const row = $(this);
                const id = row.find('.status-toggle').data('id');
                const status = row.find('.status-toggle').is(':checked') ? 'Present' : 'Absent';
                const cameraStatus = row.find('.camera-toggle').is(':checked') ? 'On' : 'Off';
                const remarks = row.find('.remarks-input').val();
                
                changes.push({
                    id: id,
                    status: status,
                    camera_status: cameraStatus,
                    remarks: remarks
                });
            });
            
            if (changes.length === 0) {
                showToast('No changes to save', false);
                return;
            }
            
            showLoading();
            
            $.ajax({
                url: 'attendance_api.php',
                type: 'POST',
                data: {
                    action: 'update',
                    changes: JSON.stringify(changes)
                },
                success: function(response) {
                    hideLoading();
                    
                    if (response.success) {
                        showToast('Attendance updated successfully');
                    } else {
                        showToast(response.message || 'Failed to update attendance', false);
                    }
                },
                error: function(xhr, status, error) {
                    hideLoading();
                    console.error('AJAX Error:', error);
                    showToast('Network error occurred while saving attendance', false);
                }
            });
        });

        // Delete attendance confirmation
        $('#deleteConfirmBtn').click(function() {
            const batchId = $('#deleteBatch').val();
            const date = $('#deleteDate').val();
            
            if (!batchId || !date) {
                showToast('Please select both batch and date', false);
                return;
            }
            
            showDeleteConfirmation(batchId, date);
        });

        // Cancel delete
        $('#cancelDeleteBtn').click(function() {
            hideDeleteConfirmation();
        });

        // Confirm delete
        $('#confirmDeleteBtn').click(function() {
            hideDeleteConfirmation();
            showLoading();
            
            // Submit the form
            $('#deleteAttendanceForm').find('input[type="submit"]').click();
        });

        // Close export dropdown when clicking outside
        $(document).click(function(event) {
            if (!$(event.target).closest('#exportDropdownBtn, #exportDropdown').length) {
                $('#exportDropdown').addClass('hidden');
            }
        });
    });

    // Export dropdown toggle
    function toggleExportDropdown() {
        $('#exportDropdown').toggleClass('hidden');
    }

    // Quick export current view to Excel
    function quickExportCurrent() {
        const batchId = $('#batchFilter').val();
        const date = $('#dateFilter').val();
        
        if (!date) {
            showToast('Please select a date first', false);
            return;
        }
        
        window.location.href = 'daily_attendance_export.php?date=' + encodeURIComponent(date) + 
                              '&batch_id=' + encodeURIComponent(batchId) + 
                              '&format=excel&export=true';
    }

    // Export current view as CSV
    function exportToCSV() {
        const batchId = $('#batchFilter').val();
        const date = $('#dateFilter').val();
        
        if (!date) {
            showToast('Please select a date first', false);
            return;
        }
        
        window.location.href = 'daily_attendance_export.php?date=' + encodeURIComponent(date) + 
                              '&batch_id=' + encodeURIComponent(batchId) + 
                              '&format=csv&export=true';
    }

    // Export all batches for today
    function exportAllBatchesToday() {
        const today = new Date().toISOString().split('T')[0];
        window.location.href = 'daily_attendance_export.php?date=' + today + 
                              '&batch_id=&format=excel&export=true';
    }

    // Export with student details (Excel format)
    function exportWithDetails() {
        const batchId = $('#batchFilter').val();
        const date = $('#dateFilter').val();
        
        if (!date) {
            showToast('Please select a date first', false);
            return;
        }
        
        window.location.href = 'daily_attendance_export.php?date=' + encodeURIComponent(date) + 
                              '&batch_id=' + encodeURIComponent(batchId) + 
                              '&format=excel&export=true';
    }

    // Export current DataTable data as CSV
    function exportCurrentDataTable() {
        const table = $('#attendanceTable').DataTable();
        const data = table.rows().data().toArray();
        
        if (data.length === 0) {
            showToast('No data to export', false);
            return;
        }
        
        // Create CSV from DataTable data
        let csv = 'Student ID,Student Name,Batch ID,Date,Status,Camera Status,Remarks\n';
        
        data.forEach(row => {
            // Escape commas in text fields
            const studentName = row.student_name ? row.student_name.replace(/,/g, ' ') : '';
            const remarks = row.remarks ? row.remarks.replace(/,/g, ' ') : '';
            
            csv += `${row.student_id},${studentName},${row.batch_id},${row.date},${row.status},${row.camera_status},${remarks}\n`;
        });
        
        // Download CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `attendance_${$('#dateFilter').val()}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        showToast('Table exported successfully');
    }

    // Download Excel template
    function downloadTemplate() {
        // Create a simple Excel template download
        const templateData = [
            ['student_id', 'date', 'status', 'batch_id', 'student_name', 'camera_status', 'remarks'],
            ['STU001', '2024-01-15', 'Present', 'BATCH001', 'John Doe', 'On', 'On time'],
            ['STU002', '2024-01-15', 'Absent', 'BATCH001', 'Jane Smith', 'Off', 'Sick leave'],
            ['STU003', '2024-01-15', 'Present', 'BATCH001', 'Bob Johnson', 'On', '']
        ];
        
        let csvContent = "data:text/csv;charset=utf-8,";
        templateData.forEach(row => {
            csvContent += row.join(",") + "\r\n";
        });
        
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "attendance_template.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Show toast message (define if not already defined)
    window.showToast = function(message, isSuccess = true) {
        const toast = $('#successToast');
        const icon = toast.find('i');
        const messageSpan = $('#toastMessage');
        
        if (isSuccess) {
            toast.removeClass('bg-red-500').addClass('bg-green-500');
            icon.removeClass('fa-exclamation-circle').addClass('fa-check-circle');
        } else {
            toast.removeClass('bg-green-500').addClass('bg-red-500');
            icon.removeClass('fa-check-circle').addClass('fa-exclamation-circle');
        }
        
        messageSpan.text(message);
        toast.addClass('show');
        
        setTimeout(() => {
            toast.removeClass('show');
        }, 3000);
    };
    </script>
</body>
</html>