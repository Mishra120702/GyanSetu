<?php
// file: daily_attendance_export.php
// Daily Attendance Export - Export attendance for specific date across all batches or single batch

// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Set default timezone
date_default_timezone_set('Asia/Kolkata');

// Get filter parameters
$export_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$export_format = isset($_GET['format']) ? $_GET['format'] : 'excel'; // excel or csv

// Get all batches for dropdown
try {
    $stmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching batches: " . $e->getMessage());
    $batches = [];
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'true') {
    exportAttendance($db, $export_date, $batch_id, $export_format);
    exit;
}

function cleanPhoneNumber($phone) {
    if (empty($phone)) return 'N/A';
    
    // Remove any non-printable characters and trim
    $clean = preg_replace('/[^\x20-\x7E0-9]/', '', $phone);
    // Remove any remaining special characters except + and numbers
    $clean = preg_replace('/[^0-9+]/', '', $clean);
    // Trim and return, default to N/A if empty after cleaning
    return !empty($clean) ? $clean : 'N/A';
}

function cleanString($str) {
    if (empty($str)) return 'N/A';
    // Remove non-printable characters and trim
    $clean = preg_replace('/[^\x20-\x7E]/', '', $str);
    return !empty(trim($clean)) ? trim($clean) : 'N/A';
}

function exportAttendance($db, $date, $batch_id, $format) {
    try {
        // Build query to fetch attendance data with only required fields
        $sql = "SELECT 
                    a.student_id,
                    a.student_name,
                    b.batch_name as attendance_batch_name,
                    a.status,
                    s.phone_number,
                    s.email
                FROM attendance a
                LEFT JOIN students s ON a.student_id = s.student_id
                LEFT JOIN batches b ON a.batch_id = b.batch_id
                WHERE a.date = ?";
        
        $params = [$date];
        
        if (!empty($batch_id)) {
            $sql .= " AND a.batch_id = ?";
            $params[] = $batch_id;
        }
        
        $sql .= " ORDER BY a.batch_id, a.student_name";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($attendance)) {
            $_SESSION['error_message'] = "No attendance records found for the selected date" . 
                (!empty($batch_id) ? " and batch" : "");
            header("Location: daily_attendance_export.php?date=" . urlencode($date) . 
                   "&batch_id=" . urlencode($batch_id));
            exit;
        }
        
        // Get summary statistics
        $total_students = count($attendance);
        $present_count = count(array_filter($attendance, function($row) { 
            return $row['status'] == 'Present'; 
        }));
        $absent_count = $total_students - $present_count;
        
        // Get batch info for filename
        $batch_info = '';
        if (!empty($batch_id)) {
            $batch_info = '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $batch_id);
        }
        
        // Generate filename
        $filename = "attendance_" . $date . $batch_info;
        
        if ($format == 'csv') {
            // CSV Export - Fix encoding for Excel
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            
            // Add BOM for UTF-8 to handle special characters properly in Excel
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            
            $output = fopen('php://output', 'w');
            
            // Add metadata headers
            fputcsv($output, ['Daily Attendance Report']);
            fputcsv($output, ['Date:', date('F j, Y', strtotime($date))]);
            if (!empty($batch_id)) {
                $batch_name = '';
                foreach ($GLOBALS['batches'] as $batch) {
                    if ($batch['batch_id'] == $batch_id) {
                        $batch_name = $batch['batch_name'];
                        break;
                    }
                }
                fputcsv($output, ['Batch:', $batch_id . ' - ' . cleanString($batch_name)]);
            } else {
                fputcsv($output, ['Batch:', 'All Batches']);
            }
            fputcsv($output, ['Generated:', date('Y-m-d H:i:s')]);
            fputcsv($output, []); // Empty row
            
            // Summary statistics
            fputcsv($output, ['SUMMARY STATISTICS']);
            fputcsv($output, ['Total Students:', $total_students]);
            fputcsv($output, ['Present:', $present_count, '(', round(($present_count/$total_students)*100, 2), '%)']);
            fputcsv($output, ['Absent:', $absent_count, '(', round(($absent_count/$total_students)*100, 2), '%)']);
            fputcsv($output, []); // Empty row
            
            // Column headers - Only required fields
            $headers = [
                'Student ID',
                'Student Name',
                'Batch Name',
                'Status',
                'Phone Number',
                'Email'
            ];
            fputcsv($output, $headers);
            
            // Data rows - Only required fields with cleaned data
            foreach ($attendance as $row) {
                $data = [
                    cleanString($row['student_id']),
                    cleanString($row['student_name']),
                    cleanString($row['attendance_batch_name'] ?: 'N/A'),
                    cleanString($row['status']),
                    cleanPhoneNumber($row['phone_number']),
                    cleanString($row['email'])
                ];
                fputcsv($output, $data);
            }
            
            fclose($output);
            
        } else {
            // Excel Export (HTML format with .xls extension)
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
            
            // Add BOM for UTF-8
            echo "\xEF\xBB\xBF";
            
            // Generate HTML table with styling
            ?>
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                <style>
                    body { font-family: Arial, sans-serif; }
                    .report-title { 
                        font-size: 20px; 
                        font-weight: bold; 
                        text-align: center; 
                        background-color: #4CAF50; 
                        color: white; 
                        padding: 10px; 
                    }
                    .info-table { 
                        width: 100%; 
                        margin-bottom: 20px; 
                        border-collapse: collapse; 
                    }
                    .info-table td { 
                        padding: 5px; 
                        border: 1px solid #ddd; 
                    }
                    .info-table .label { 
                        font-weight: bold; 
                        background-color: #f2f2f2; 
                        width: 150px; 
                    }
                    .summary-box { 
                        background-color: #e8f5e9; 
                        padding: 10px; 
                        margin-bottom: 20px; 
                        border: 1px solid #4CAF50; 
                        border-radius: 5px; 
                    }
                    .summary-item { 
                        display: inline-block; 
                        margin-right: 30px; 
                    }
                    .summary-label { 
                        font-weight: bold; 
                        color: #666; 
                    }
                    .summary-value { 
                        font-size: 18px; 
                        font-weight: bold; 
                        color: #4CAF50; 
                    }
                    table.data-table { 
                        border-collapse: collapse; 
                        width: 100%; 
                    }
                    th { 
                        background-color: #4CAF50; 
                        color: white; 
                        font-weight: bold; 
                        text-align: center; 
                        padding: 8px; 
                        border: 1px solid #ddd; 
                    }
                    td { 
                        padding: 8px; 
                        border: 1px solid #ddd; 
                        text-align: left; 
                    }
                    tr:nth-child(even) { 
                        background-color: #f2f2f2; 
                    }
                    .status-present { 
                        background-color: #dcfce7; 
                        color: #166534; 
                        padding: 3px 8px; 
                        border-radius: 4px; 
                        font-weight: bold; 
                    }
                    .status-absent { 
                        background-color: #fee2e2; 
                        color: #991b1b; 
                        padding: 3px 8px; 
                        border-radius: 4px; 
                        font-weight: bold; 
                    }
                </style>
            </head>
            <body>
                <table class="info-table">
                    <tr>
                        <td colspan="2" class="report-title">Daily Attendance Report</td>
                    </tr>
                    <tr>
                        <td class="label">Date:</td>
                        <td><?php echo date('F j, Y', strtotime($date)); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Batch:</td>
                        <td>
                            <?php 
                            if (!empty($batch_id)) {
                                $batch_name = '';
                                foreach ($GLOBALS['batches'] as $batch) {
                                    if ($batch['batch_id'] == $batch_id) {
                                        $batch_name = $batch['batch_name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars(cleanString($batch_id . ' - ' . $batch_name));
                            } else {
                                echo 'All Batches';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="label">Generated:</td>
                        <td><?php echo date('Y-m-d H:i:s'); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Generated By:</td>
                        <td><?php echo htmlspecialchars(cleanString($_SESSION['user_name'] ?? 'User')); ?></td>
                    </tr>
                </table>
                
                <div class="summary-box">
                    <h3>Summary Statistics</h3>
                    <div class="summary-item">
                        <span class="summary-label">Total Students:</span>
                        <span class="summary-value"><?php echo $total_students; ?></span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Present:</span>
                        <span class="summary-value" style="color: #10b981;"><?php echo $present_count; ?></span>
                        <span>(<?php echo round(($present_count/$total_students)*100, 2); ?>%)</span>
                    </div>
                    <div class="summary-item">
                        <span class="summary-label">Absent:</span>
                        <span class="summary-value" style="color: #ef4444;"><?php echo $absent_count; ?></span>
                        <span>(<?php echo round(($absent_count/$total_students)*100, 2); ?>%)</span>
                    </div>
                </div>
                
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Student Name</th>
                            <th>Batch Name</th>
                            <th>Status</th>
                            <th>Phone Number</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(cleanString($row['student_id'])); ?></td>
                            <td><?php echo htmlspecialchars(cleanString($row['student_name'])); ?></td>
                            <td><?php echo htmlspecialchars(cleanString($row['attendance_batch_name'] ?: 'N/A')); ?></td>
                            <td>
                                <span class="status-<?php echo strtolower($row['status']); ?>">
                                    <?php echo cleanString($row['status']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars(cleanPhoneNumber($row['phone_number'])); ?></td>
                            <td><?php echo htmlspecialchars(cleanString($row['email'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 20px; font-size: 12px; color: #666; text-align: center;">
                    <p>Generated by ASD Academy Attendance System on <?php echo date('Y-m-d H:i:s'); ?></p>
                </div>
            </body>
            </html>
            <?php
        }
        
    } catch (PDOException $e) {
        error_log("Database error in export: " . $e->getMessage());
        $_SESSION['error_message'] = "Database error occurred while exporting attendance";
        header("Location: daily_attendance_export.php?date=" . urlencode($date) . 
               "&batch_id=" . urlencode($batch_id));
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Attendance Export - ASD Academy</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="assets/css/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
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
            text-decoration: none;
            cursor: pointer;
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
        
        .export-options {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .format-card {
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            flex: 1;
        }
        
        .format-card:hover {
            border-color: var(--primary);
            background-color: #f0f9ff;
        }
        
        .format-card.selected {
            border-color: var(--primary);
            background-color: #eff6ff;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }
        
        .format-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .format-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .format-desc {
            font-size: 0.8rem;
            color: var(--gray-700);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
                <i class="fas fa-download text-green-500"></i>
                <span>Daily Attendance Export</span>
            </h1>
            <div class="flex space-x-2">
                <a href="attendance.php" class="btn-secondary">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Attendance
                </a>
            </div>
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

            <!-- Export Form Card -->
            <div class="card">
                <h2 class="text-xl font-bold mb-4">Export Daily Attendance</h2>
                
                <form method="GET" action="" id="exportForm">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="date" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" id="date" name="date" 
                                   value="<?= htmlspecialchars($export_date) ?>" 
                                   max="<?= date('Y-m-d') ?>"
                                   class="minimal-input" required>
                        </div>
                        
                        <div>
                            <label for="batch_id" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Batch (Optional)
                            </label>
                            <select id="batch_id" name="batch_id" class="minimal-input">
                                <option value="">All Batches</option>
                                <?php foreach ($batches as $batch): ?>
                                <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                    <?= ($batch_id == $batch['batch_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-3">
                            Export Format <span class="text-red-500">*</span>
                        </label>
                        <div class="export-options">
                            <div class="format-card <?= $export_format == 'excel' ? 'selected' : '' ?>" onclick="selectFormat('excel')">
                                <div class="format-icon text-green-600">
                                    <i class="fas fa-file-excel"></i>
                                </div>
                                <div class="format-title">Excel Format</div>
                                <div class="format-desc">.xls file with formatting</div>
                                <input type="radio" name="format" value="excel" 
                                       <?= $export_format == 'excel' ? 'checked' : '' ?> 
                                       style="display: none;">
                            </div>
                            
                            <div class="format-card <?= $export_format == 'csv' ? 'selected' : '' ?>" onclick="selectFormat('csv')">
                                <div class="format-icon text-blue-600">
                                    <i class="fas fa-file-csv"></i>
                                </div>
                                <div class="format-title">CSV Format</div>
                                <div class="format-desc">Comma separated values</div>
                                <input type="radio" name="format" value="csv" 
                                       <?= $export_format == 'csv' ? 'checked' : '' ?> 
                                       style="display: none;">
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="submit" name="export" value="true" class="btn-success">
                            <i class="fas fa-download mr-2"></i> Export Attendance
                        </button>
                        <button type="button" onclick="previewAttendance()" class="btn-primary">
                            <i class="fas fa-eye mr-2"></i> Preview
                        </button>
                    </div>
                </form>
            </div>

            <!-- Preview Card (hidden by default) -->
            <div id="previewCard" class="card" style="display: none;">
                <h2 class="text-xl font-bold mb-4">Preview Attendance</h2>
                <div id="previewContent">
                    <!-- Preview will be loaded here -->
                </div>
            </div>

            <!-- Quick Export Buttons -->
            <div class="card">
                <h2 class="text-xl font-bold mb-4">Quick Export Options</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button onclick="quickExport('today')" class="btn-primary w-full">
                        <i class="fas fa-calendar-day mr-2"></i> Today's Attendance
                    </button>
                    <button onclick="quickExport('yesterday')" class="btn-primary w-full">
                        <i class="fas fa-calendar-day mr-2"></i> Yesterday's Attendance
                    </button>
                    <button onclick="quickExport('all')" class="btn-primary w-full">
                        <i class="fas fa-calendar-week mr-2"></i> All Batches - Today
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    function selectFormat(format) {
        // Update visual selection
        $('.format-card').removeClass('selected');
        $(`.format-card:has(input[value="${format}"])`).addClass('selected');
        
        // Update radio button
        $(`input[name="format"][value="${format}"]`).prop('checked', true);
    }

    function previewAttendance() {
        const date = $('#date').val();
        const batchId = $('#batch_id').val();
        
        if (!date) {
            alert('Please select a date');
            return;
        }
        
        // Show loading in preview
        $('#previewContent').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin text-3xl text-blue-500"></i><p class="mt-2">Loading preview...</p></div>');
        $('#previewCard').show();
        
        // Load preview via AJAX
        $.ajax({
            url: 'attendance_api.php',
            type: 'GET',
            data: {
                action: 'fetch',
                date: date,
                batch_id: batchId,
                preview: true
            },
            success: function(response) {
                if (response.success && response.data.length > 0) {
                    let html = '<div class="overflow-x-auto">';
                    html += '<table class="min-w-full divide-y divide-gray-200">';
                    html += '<thead class="bg-gray-50"><tr>';
                    html += '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student ID</th>';
                    html += '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student Name</th>';
                    html += '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Batch</th>';
                    html += '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>';
                    html += '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>';
                    html += '<th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>';
                    html += '</tr></thead><tbody class="bg-white divide-y divide-gray-200">';
                    
                    response.data.forEach(function(row) {
                        html += '<tr>';
                        html += `<td class="px-6 py-4 whitespace-nowrap">${row.student_id || 'N/A'}</td>`;
                        html += `<td class="px-6 py-4 whitespace-nowrap">${row.student_name || 'N/A'}</td>`;
                        html += `<td class="px-6 py-4 whitespace-nowrap">${row.batch_name || 'N/A'}</td>`;
                        html += `<td class="px-6 py-4 whitespace-nowrap"><span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${row.status === 'Present' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">${row.status || 'N/A'}</span></td>`;
                        
                        // Clean phone number for display
                        let phone = row.phone_number || 'N/A';
                        phone = phone.replace(/[^\x20-\x7E0-9]/g, '').replace(/[^0-9+]/g, '');
                        
                        html += `<td class="px-6 py-4 whitespace-nowrap">${phone}</td>`;
                        html += `<td class="px-6 py-4 whitespace-nowrap">${row.email || 'N/A'}</td>`;
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table></div>';
                    html += `<div class="mt-4 text-sm text-gray-600">Total: ${response.data.length} records</div>`;
                    
                    $('#previewContent').html(html);
                } else {
                    $('#previewContent').html('<div class="text-center py-8 text-gray-500"><i class="fas fa-info-circle text-3xl mb-2"></i><p>No attendance records found for the selected date</p></div>');
                }
            },
            error: function() {
                $('#previewContent').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading preview</p></div>');
            }
        });
    }

    function quickExport(type) {
        let date = '';
        const today = new Date();
        
        if (type === 'today') {
            date = today.toISOString().split('T')[0];
        } else if (type === 'yesterday') {
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            date = yesterday.toISOString().split('T')[0];
        } else if (type === 'all') {
            date = today.toISOString().split('T')[0];
        }
        
        $('#date').val(date);
        
        if (type === 'all') {
            $('#batch_id').val('');
        }
        
        // Submit form with excel format
        $('input[name="format"][value="excel"]').prop('checked', true);
        $('.format-card').removeClass('selected');
        $('.format-card:has(input[value="excel"])').addClass('selected');
        
        // Add export parameter and submit
        const form = $('#exportForm');
        form.append('<input type="hidden" name="export" value="true">');
        form.submit();
    }

    // Set max date to today
    document.addEventListener('DOMContentLoaded', function() {
        const dateInput = document.getElementById('date');
        const today = new Date().toISOString().split('T')[0];
        dateInput.setAttribute('max', today);
    });
    </script>
</body>
</html>