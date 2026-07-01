<?php
// Database connection
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Set default filters with proper validation
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_batch = isset($_GET['batch_id']) ? $_GET['batch_id'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly';

// Validate and sanitize inputs
$selected_month = preg_match('/^\d{4}-\d{2}$/', $selected_month) ? $selected_month : date('Y-m');
$selected_batch = htmlspecialchars(trim($selected_batch));
$start_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) ? $start_date : '';
$end_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date) ? $end_date : '';

// Validate custom date range
$date_range_error = '';
if ($report_type === 'custom' && $start_date && $end_date) {
    if (strtotime($start_date) > strtotime($end_date)) {
        $date_range_error = "Start date cannot be after end date";
        $start_date = '';
        $end_date = '';
    } elseif (strtotime($end_date) - strtotime($start_date) > 365 * 86400) {
        $date_range_error = "Date range cannot exceed 1 year";
        $start_date = '';
        $end_date = '';
    }
}

// Get all batches for the filter dropdown
try {
    $stmt = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_id");
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching batches: " . $e->getMessage());
    $batches = [];
}

// Get available months with attendance data
try {
    $stmt = $db->query("SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month FROM attendance ORDER BY month DESC");
    $available_months = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching months: " . $e->getMessage());
    $available_months = [];
}

// Get attendance statistics based on report type
$attendance_stats = [];
$attendance_data = [];
$total_days = 0;
$date_range_label = '';

if ($report_type === 'monthly' && $selected_month) {
    try {
        // Calculate first and last day of the month
        $first_day = date('Y-m-01', strtotime($selected_month));
        $last_day = date('Y-m-t', strtotime($selected_month));
        $total_days = date('t', strtotime($selected_month));
        $date_range_label = date('F Y', strtotime($selected_month));
        
        list($attendance_stats, $attendance_data) = fetchAttendanceData($db, $first_day, $last_day, $selected_batch);
        
    } catch (PDOException $e) {
        error_log("Database error fetching attendance stats: " . $e->getMessage());
        $attendance_stats = [];
        $attendance_data = [];
    }
} elseif ($report_type === 'custom' && $start_date && $end_date && !$date_range_error) {
    try {
        $total_days = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24) + 1;
        $date_range_label = date('M j, Y', strtotime($start_date)) . ' to ' . date('M j, Y', strtotime($end_date));
        
        list($attendance_stats, $attendance_data) = fetchAttendanceData($db, $start_date, $end_date, $selected_batch);
        
    } catch (PDOException $e) {
        error_log("Database error fetching custom range attendance stats: " . $e->getMessage());
        $attendance_stats = [];
        $attendance_data = [];
    }
}

/**
 * Fetch attendance data for a given date range
 */
function fetchAttendanceData($db, $start_date, $end_date, $selected_batch) {
    // Build query for attendance statistics
    $query = "
        SELECT 
            a.student_id,
            s.first_name,
            s.last_name,
            a.batch_id as attendance_batch,
            b.batch_name as attendance_batch_name,
            s.batch_name as current_batch,
            (SELECT batch_name FROM batches WHERE batch_id = s.batch_name) as current_batch_name,
            COUNT(*) as total_days,
            SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_days,
            ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as attendance_percentage,
            CASE 
                WHEN COUNT(DISTINCT a.batch_id) > 1 THEN 1
                ELSE 0
            END as has_multiple_batches,
            GROUP_CONCAT(DISTINCT a.batch_id ORDER BY a.batch_id SEPARATOR ', ') as all_batches_attended
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN batches b ON a.batch_id = b.batch_id
        WHERE DATE(a.date) BETWEEN ? AND ?
    ";
    
    $params = [$start_date, $end_date];
    
    if ($selected_batch) {
        $query .= " AND a.batch_id = ?";
        $params[] = $selected_batch;
    }
    
    $query .= " GROUP BY a.student_id, s.first_name, s.last_name, s.batch_name 
               ORDER BY s.first_name, s.last_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $attendance_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get detailed attendance data for the calendar view
    $detail_query = "
        SELECT 
            a.student_id,
            a.date,
            a.status,
            a.camera_status,
            a.remarks,
            a.batch_id
        FROM attendance a
        WHERE DATE(a.date) BETWEEN ? AND ?
    ";
    
    $detail_params = [$start_date, $end_date];
    
    if ($selected_batch) {
        $detail_query .= " AND a.batch_id = ?";
        $detail_params[] = $selected_batch;
    }
    
    $detail_query .= " ORDER BY a.student_id, a.date";
    
    $stmt = $db->prepare($detail_query);
    $stmt->execute($detail_params);
    $attendance_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize details by student and date
    $attendance_data = [];
    foreach ($attendance_details as $detail) {
        $attendance_data[$detail['student_id']][$detail['date']] = [
            'status' => $detail['status'],
            'camera_status' => $detail['camera_status'],
            'remarks' => $detail['remarks'],
            'batch_id' => $detail['batch_id']
        ];
    }
    
    return [$attendance_stats, $attendance_data];
}

// Handle export request
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    exportToCSV($attendance_stats, $report_type, $selected_month, $start_date, $end_date, $selected_batch, $date_range_label);
    exit;
}

/**
 * Export attendance data to CSV
 */
function exportToCSV($attendance_stats, $report_type, $selected_month, $start_date, $end_date, $selected_batch, $date_range_label) {
    header('Content-Type: text/csv; charset=utf-8');
    
    if ($report_type === 'monthly') {
        $filename = "monthly_attendance_" . $selected_month;
    } else {
        $filename = "custom_attendance_" . $start_date . "_to_" . $end_date;
    }
    
    if ($selected_batch) {
        $filename .= "_batch_" . $selected_batch;
    }
    
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Add headers
    fputcsv($output, ['Attendance Report - ' . $date_range_label]);
    fputcsv($output, []); // Empty row
    
    // Column headers
    $headers = ['Student ID', 'Student Name', 'Current Batch', 'Attendance Batch(s)', 'Total Days', 'Present Days', 'Absent Days', 'Attendance %', 'Status'];
    fputcsv($output, $headers);
    
    // Data rows
    foreach ($attendance_stats as $stat) {
        $status = '';
        if ($stat['attendance_percentage'] >= 90) {
            $status = 'Excellent';
        } elseif ($stat['attendance_percentage'] >= 75) {
            $status = 'Good';
        } else {
            $status = 'Needs Improvement';
        }
        
        $batch_info = $stat['has_multiple_batches'] ? $stat['all_batches_attended'] : $stat['attendance_batch'];
        $current_batch_display = $stat['current_batch_name'] ?: $stat['current_batch'];
        
        $row = [
            $stat['student_id'],
            $stat['first_name'] . ' ' . $stat['last_name'],
            $current_batch_display,
            $batch_info,
            $stat['total_days'],
            $stat['present_days'],
            $stat['absent_days'],
            $stat['attendance_percentage'] . '%',
            $status
        ];
        fputcsv($output, $row);
    }
    
    fclose($output);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report - ASD Academy</title>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.2.2/css/buttons.dataTables.min.css">
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
        
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            min-width: 70px;
            justify-content: center;
        }
        
        .status-present {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-absent {
            background-color: #fee2e2;
            color: #991b1b;
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
        
        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
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
            cursor: pointer;
        }
        
        .btn-secondary:hover {
            background-color: var(--gray-300);
        }
        
        .btn-secondary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
        
        .minimal-input.error {
            border-color: var(--danger);
        }
        
        .error-message {
            color: var(--danger);
            font-size: 0.75rem;
            margin-top: 0.25rem;
            display: block;
        }
        
        .summary-card {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        .summary-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .summary-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        
        .progress-bar {
            height: 8px;
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            background-color: white;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .attendance-high {
            color: var(--success);
            font-weight: bold;
        }
        
        .attendance-medium {
            color: var(--warning);
            font-weight: bold;
        }
        
        .attendance-low {
            color: var(--danger);
            font-weight: bold;
        }
        
        .student-row:hover {
            background-color: var(--gray-50);
        }
        
        .dataTables_wrapper {
            margin-top: 1rem;
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
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            max-width: 90%;
            max-height: 90%;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .batch-transfer-indicator {
            background-color: #fef3c7;
            border: 1px solid #f59e0b;
            border-radius: 4px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            color: #92400e;
            margin-top: 0.25rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }

        .batch-info {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .current-batch {
            font-weight: 600;
            color: var(--primary);
        }

        .attendance-batches {
            font-size: 0.8rem;
            color: var(--gray-600);
        }

        .multiple-batches {
            background-color: #fef3c7;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            border: 1px solid #f59e0b;
            font-size: 0.75rem;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(59, 130, 246, 0.2);
            border-radius: 50%;
            border-top-color: var(--primary);
            animation: spin 1s ease-in-out infinite;
            margin: 0 auto;
        }

        .report-type-tabs {
            display: flex;
            border-bottom: 1px solid var(--gray-200);
            margin-bottom: 1rem;
        }

        .report-type-tab {
            padding: 0.75rem 1.5rem;
            border: none;
            background: none;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .report-type-tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
        }

        .date-range-fields {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .date-range-fields.active {
            display: block;
        }

        .month-selector {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .month-selector.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        
        .alert-warning {
            background-color: #fef3c7;
            border: 1px solid #fde68a;
            color: #92400e;
        }
        
        @media (max-width: 768px) {
            .card {
                padding: 1rem;
            }
            
            .modal-content {
                max-width: 95%;
                padding: 1rem;
            }

            .report-type-tabs {
                flex-direction: column;
                border-bottom: none;
            }

            .report-type-tab {
                text-align: left;
                border-bottom: 1px solid var(--gray-200);
                border-left: 2px solid transparent;
            }

            .report-type-tab.active {
                border-left-color: var(--primary);
                border-bottom-color: var(--gray-200);
            }
            
            .summary-value {
                font-size: 1.5rem;
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
                <i class="fas fa-calendar-alt text-blue-500"></i>
                <span>Attendance Report</span>
            </h1>
            <a href="attendance.php" class="btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i> Back to Attendance
            </a>
        </header>

        <div class="p-4 md:p-6">
            <!-- Filters Card -->
            <div class="card">
                <h2 class="text-xl font-bold mb-4">Report Filters</h2>
                
                <!-- Report Type Tabs -->
                <div class="report-type-tabs">
                    <button type="button" class="report-type-tab <?= $report_type === 'monthly' ? 'active' : '' ?>" data-type="monthly">
                        <i class="fas fa-calendar-month mr-2"></i> Monthly Report
                    </button>
                    <button type="button" class="report-type-tab <?= $report_type === 'custom' ? 'active' : '' ?>" data-type="custom">
                        <i class="fas fa-calendar-day mr-2"></i> Custom Date Range
                    </button>
                </div>

                <?php if ($date_range_error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($date_range_error) ?>
                </div>
                <?php endif; ?>

                <form method="GET" action="" id="reportForm" novalidate>
                    <!-- Monthly Report Fields -->
                    <div class="month-selector <?= $report_type === 'monthly' ? 'active' : '' ?>">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="monthFilter" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-alt mr-1"></i> Select Month
                                </label>
                                <select id="monthFilter" name="month" class="minimal-input">
                                    <option value="">-- Select Month --</option>
                                    <?php foreach ($available_months as $month): ?>
                                    <option value="<?= htmlspecialchars($month['month']) ?>" 
                                        <?= ($selected_month === $month['month']) ? 'selected' : '' ?>>
                                        <?= date('F Y', strtotime($month['month'])) ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php if (empty($available_months)): ?>
                                    <option value="<?= date('Y-m') ?>" selected><?= date('F Y') ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="batchFilterMonthly" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-layer-group mr-1"></i> Select Batch (Optional)
                                </label>
                                <select id="batchFilterMonthly" name="batch_id" class="minimal-input">
                                    <option value="">All Batches</option>
                                    <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                        <?= ($selected_batch === $batch['batch_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Date Range Fields -->
                    <div class="date-range-fields <?= $report_type === 'custom' ? 'active' : '' ?>">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="startDate" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-day mr-1"></i> Start Date
                                </label>
                                <input type="date" id="startDate" name="start_date" 
                                       value="<?= htmlspecialchars($start_date) ?>" 
                                       class="minimal-input" 
                                       min="2000-01-01"
                                       max="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div>
                                <label for="endDate" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-calendar-check mr-1"></i> End Date
                                </label>
                                <input type="date" id="endDate" name="end_date" 
                                       value="<?= htmlspecialchars($end_date) ?>" 
                                       class="minimal-input"
                                       min="2000-01-01"
                                       max="<?= date('Y-m-d') ?>">
                            </div>
                            
                            <div>
                                <label for="batchFilterCustom" class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-layer-group mr-1"></i> Select Batch (Optional)
                                </label>
                                <select id="batchFilterCustom" name="batch_id" class="minimal-input">
                                    <option value="">All Batches</option>
                                    <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" 
                                        <?= ($selected_batch === $batch['batch_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_id'] . ' - ' . $batch['batch_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div id="dateRangeError" class="error-message" style="display: none;"></div>
                    </div>

                    <input type="hidden" name="report_type" id="reportType" value="<?= $report_type ?>">
                    
                    <div class="flex flex-wrap items-center gap-3 mt-4">
                        <button type="submit" id="applyFiltersBtn" class="btn-primary">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        <?php if ((($report_type === 'monthly' && $selected_month) || ($report_type === 'custom' && $start_date && $end_date && !$date_range_error)) && !empty($attendance_stats)): ?>
                        <button type="submit" name="export" value="csv" class="btn-secondary">
                            <i class="fas fa-download mr-2"></i> Export CSV
                        </button>
                        <?php endif; ?>
                        <button type="button" id="resetFiltersBtn" class="btn-secondary">
                            <i class="fas fa-undo-alt mr-2"></i> Reset
                        </button>
                    </div>
                </form>
            </div>

            <?php if ((($report_type === 'monthly' && $selected_month) || ($report_type === 'custom' && $start_date && $end_date && !$date_range_error)) && !empty($attendance_stats)): ?>
            <!-- Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="summary-card">
                    <div class="summary-value"><?= count($attendance_stats) ?></div>
                    <div class="summary-label">Total Students</div>
                </div>
                
                <div class="summary-card" style="background: linear-gradient(135deg, #10b981 0%, #047857 100%);">
                    <?php
                    $total_present = array_sum(array_column($attendance_stats, 'present_days'));
                    $total_absent = array_sum(array_column($attendance_stats, 'absent_days'));
                    $total_records = $total_present + $total_absent;
                    $overall_percentage = $total_records > 0 ? round(($total_present / $total_records) * 100, 2) : 0;
                    ?>
                    <div class="summary-value"><?= $overall_percentage ?>%</div>
                    <div class="summary-label">Overall Attendance</div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $overall_percentage ?>%"></div>
                    </div>
                </div>
                
                <div class="summary-card" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                    <div class="summary-value"><?= $total_days ?></div>
                    <div class="summary-label">Working Days</div>
                </div>
                
                <div class="summary-card" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);">
                    <?php
                    // Count students with multiple batches
                    $multiple_batch_students = array_filter($attendance_stats, function($stat) {
                        return $stat['has_multiple_batches'] == 1;
                    });
                    ?>
                    <div class="summary-value"><?= count($multiple_batch_students) ?></div>
                    <div class="summary-label">Batch Transfers</div>
                </div>
            </div>

            <!-- Detailed Report -->
            <div class="card">
                <h2 class="text-xl font-bold mb-4">
                    <i class="fas fa-chart-line text-blue-500 mr-2"></i>
                    Detailed Report - <?= htmlspecialchars($date_range_label) ?>
                    <?php if ($selected_batch): ?>
                    <span class="text-sm font-normal text-gray-500 ml-2">
                        (Batch: <?= htmlspecialchars($selected_batch) ?>)
                    </span>
                    <?php endif; ?>
                </h2>
                
                <div class="overflow-x-auto">
                    <table id="attendanceReportTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Student Name</th>
                                <th>Batch Information</th>
                                <th>Total Days</th>
                                <th>Present</th>
                                <th>Absent</th>
                                <th>Attendance %</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_stats as $stat): 
                                $attendance_class = '';
                                if ($stat['attendance_percentage'] >= 90) {
                                    $attendance_class = 'attendance-high';
                                } elseif ($stat['attendance_percentage'] >= 75) {
                                    $attendance_class = 'attendance-medium';
                                } else {
                                    $attendance_class = 'attendance-low';
                                }

                                // Determine batch information display
                                $current_batch_display = $stat['current_batch_name'] ?: $stat['current_batch'];
                                $attendance_batch_display = $stat['has_multiple_batches'] ? 
                                    '<span class="multiple-batches" title="Attended multiple batches: ' . htmlspecialchars($stat['all_batches_attended']) . '"><i class="fas fa-exchange-alt mr-1"></i>Multiple Batches</span>' : 
                                    htmlspecialchars($stat['attendance_batch']);
                            ?>
                            <tr class="student-row">
                                <td><?= htmlspecialchars($stat['student_id']) ?></td>
                                <td><?= htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']) ?></td>
                                <td>
                                    <div class="batch-info">
                                        <div class="current-batch">Current: <?= htmlspecialchars($current_batch_display) ?></div>
                                        <div class="attendance-batches">Attendance: <?= $attendance_batch_display ?></div>
                                        <?php if ($stat['has_multiple_batches']): ?>
                                        <div class="batch-transfer-indicator">
                                            <i class="fas fa-exchange-alt mr-1"></i>
                                            Transferred Student
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?= $stat['total_days'] ?></td>
                                <td>
                                    <span class="status-badge status-present"><?= $stat['present_days'] ?></span>
                                </td>
                                <td>
                                    <span class="status-badge status-absent"><?= $stat['absent_days'] ?></span>
                                </td>
                                <td class="<?= $attendance_class ?> font-semibold">
                                    <?= $stat['attendance_percentage'] ?>%
                                </td>
                                <td>
                                    <?php if ($stat['attendance_percentage'] >= 90): ?>
                                        <span class="status-badge status-present">Excellent</span>
                                    <?php elseif ($stat['attendance_percentage'] >= 75): ?>
                                        <span class="status-badge" style="background-color: #fef3c7; color: #92400e;">Good</span>
                                    <?php else: ?>
                                        <span class="status-badge status-absent">Needs Improvement</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="view-calendar-btn btn-secondary text-sm mb-1 w-full" 
                                            data-student-id="<?= htmlspecialchars($stat['student_id']) ?>"
                                            data-student-name="<?= htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']) ?>"
                                            data-month="<?= $report_type === 'monthly' ? $selected_month : '' ?>"
                                            data-start-date="<?= $report_type === 'custom' ? $start_date : '' ?>"
                                            data-end-date="<?= $report_type === 'custom' ? $end_date : '' ?>"
                                            data-report-type="<?= $report_type ?>">
                                        <i class="fas fa-calendar-day mr-1"></i> View Calendar
                                    </button>
                                    <button class="view-history-btn btn-secondary text-sm w-full mt-1" 
                                            data-student-id="<?= htmlspecialchars($stat['student_id']) ?>"
                                            data-student-name="<?= htmlspecialchars($stat['first_name'] . ' ' . $stat['last_name']) ?>">
                                        <i class="fas fa-history mr-1"></i> Full History
                                    </button>
                                 </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif (($report_type === 'monthly' && $selected_month) || ($report_type === 'custom' && $start_date && $end_date && !$date_range_error)): ?>
            <!-- No Data Message -->
            <div class="card text-center py-8">
                <i class="fas fa-calendar-times text-gray-400 text-5xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-700 mb-2">No Attendance Data Found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($report_type === 'monthly'): ?>
                        No attendance records found for <?= date('F Y', strtotime($selected_month)) ?> with the selected filters.
                    <?php else: ?>
                        No attendance records found for the date range <?= date('M j, Y', strtotime($start_date)) ?> to <?= date('M j, Y', strtotime($end_date)) ?> with the selected filters.
                    <?php endif; ?>
                </p>
                <a href="attendance.php" class="btn-primary inline-block">
                    <i class="fas fa-clipboard-check mr-2"></i> Go to Attendance Tracking
                </a>
            </div>
            <?php else: ?>
            <!-- Initial State -->
            <div class="card text-center py-8">
                <i class="fas fa-chart-bar text-gray-400 text-5xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-700 mb-2">Attendance Report</h3>
                <p class="text-gray-500">Select a report type and filters to view the attendance report.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Calendar Modal -->
    <div id="calendarModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="flex justify-between items-center mb-4">
                <h3 id="modalTitle" class="text-lg font-bold">Student Attendance Calendar</h3>
                <button id="closeModal" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="calendarContainer">
                <div class="text-center py-8">
                    <div class="spinner mb-4"></div>
                    <p>Loading calendar...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- History Modal -->
    <div id="historyModal" class="modal-overlay" style="display: none;">
        <div class="modal-content" style="max-width: 900px;">
            <div class="flex justify-between items-center mb-4">
                <h3 id="historyModalTitle" class="text-lg font-bold">Student Attendance History</h3>
                <button id="closeHistoryModal" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="historyContainer">
                <div class="text-center py-8">
                    <div class="spinner mb-4"></div>
                    <p>Loading history...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.2.2/js/buttons.html5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
    $(document).ready(function() {
        let dataTable = null;
        
        // Initialize DataTable if table exists
        if ($('#attendanceReportTable').length && $('#attendanceReportTable tbody tr').length) {
            dataTable = $('#attendanceReportTable').DataTable({
                responsive: true,
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-download mr-2"></i> Export CSV',
                        className: 'btn-secondary',
                        filename: function() {
                            const reportType = $('#reportType').val();
                            let filename = '';
                            if (reportType === 'monthly') {
                                filename = 'monthly_attendance_<?= $selected_month ?>';
                            } else {
                                filename = 'custom_attendance_<?= $start_date ?>_to_<?= $end_date ?>';
                            }
                            
                            if ('<?= $selected_batch ?>') {
                                filename += '_batch_<?= $selected_batch ?>';
                            }
                            return filename;
                        }
                    }
                ],
                pageLength: 25,
                order: [[6, 'desc']],
                columnDefs: [
                    { targets: [2, 8], orderable: false }
                ],
                language: {
                    emptyTable: "No attendance data available for the selected filters"
                }
            });
        }

        // Report type tab switching
        $('.report-type-tab').click(function() {
            const reportType = $(this).data('type');
            
            // Update active tab
            $('.report-type-tab').removeClass('active');
            $(this).addClass('active');
            
            // Update hidden field
            $('#reportType').val(reportType);
            
            // Show/hide appropriate fields
            if (reportType === 'monthly') {
                $('.month-selector').addClass('active');
                $('.date-range-fields').removeClass('active');
                $('#monthFilter').prop('required', true);
                $('#startDate').prop('required', false);
                $('#endDate').prop('required', false);
            } else {
                $('.month-selector').removeClass('active');
                $('.date-range-fields').addClass('active');
                $('#monthFilter').prop('required', false);
                $('#startDate').prop('required', true);
                $('#endDate').prop('required', true);
            }
        });

        // Synchronize batch selection between monthly and custom filters
        function syncBatchFilters() {
            $('#batchFilterMonthly').off('change').on('change', function() {
                $('#batchFilterCustom').val($(this).val());
            });
            
            $('#batchFilterCustom').off('change').on('change', function() {
                $('#batchFilterMonthly').val($(this).val());
            });
        }
        
        syncBatchFilters();

        // Custom date range validation
        function validateDateRange() {
            const startDate = $('#startDate').val();
            const endDate = $('#endDate').val();
            const errorDiv = $('#dateRangeError');
            
            errorDiv.hide().empty();
            
            if (!startDate || !endDate) {
                return true;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                errorDiv.text('Start date cannot be after end date').show();
                return false;
            }
            
            // Check if date range exceeds 1 year
            const diffTime = Math.abs(new Date(endDate) - new Date(startDate));
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            if (diffDays > 365) {
                errorDiv.text('Date range cannot exceed 1 year (365 days)').show();
                return false;
            }
            
            return true;
        }
        
        // Validate dates on change
        $('#startDate, #endDate').on('change', function() {
            validateDateRange();
            
            // Set min/max constraints
            if ($('#startDate').val()) {
                $('#endDate').attr('min', $('#startDate').val());
            }
            if ($('#endDate').val()) {
                $('#startDate').attr('max', $('#endDate').val());
            }
        });
        
        // Form validation before submit
        $('#reportForm').on('submit', function(e) {
            const reportType = $('#reportType').val();
            
            if (reportType === 'monthly') {
                const month = $('#monthFilter').val();
                if (!month) {
                    alert('Please select a month');
                    e.preventDefault();
                    return false;
                }
            } else {
                const startDate = $('#startDate').val();
                const endDate = $('#endDate').val();
                
                if (!startDate || !endDate) {
                    alert('Please select both start and end dates');
                    e.preventDefault();
                    return false;
                }
                
                if (!validateDateRange()) {
                    e.preventDefault();
                    return false;
                }
            }
            
            // Show loading state
            const submitBtn = $(this).find('button[type="submit"]');
            const originalText = submitBtn.html();
            submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i> Loading...').prop('disabled', true);
            
            // Re-enable after timeout (in case of slow response)
            setTimeout(() => {
                submitBtn.html(originalText).prop('disabled', false);
            }, 5000);
        });
        
        // Reset filters
        $('#resetFiltersBtn').on('click', function() {
            const reportType = $('#reportType').val();
            
            if (reportType === 'monthly') {
                $('#monthFilter').val('');
                $('#batchFilterMonthly').val('');
            } else {
                $('#startDate').val('');
                $('#endDate').val('');
                $('#batchFilterCustom').val('');
            }
            
            // Submit the form to reset
            $('#reportForm').submit();
        });

        // Set initial date constraints
        if ($('#startDate').val()) {
            $('#endDate').attr('min', $('#startDate').val());
        }
        if ($('#endDate').val()) {
            $('#startDate').attr('max', $('#endDate').val());
        }

        // View calendar functionality
        $('.view-calendar-btn').click(function() {
            const studentId = $(this).data('student-id');
            const studentName = $(this).data('student-name');
            const reportType = $(this).data('report-type');
            let month = $(this).data('month');
            let startDate = $(this).data('start-date');
            let endDate = $(this).data('end-date');
            
            // Show loading in modal
            $('#calendarContainer').html('<div class="text-center py-8"><div class="spinner mb-4"></div><p>Loading calendar...</p></div>');
            
            let modalTitle = 'Attendance Calendar - ' + studentName;
            if (reportType === 'monthly') {
                modalTitle += ' (' + month + ')';
            } else {
                modalTitle += ' (' + startDate + ' to ' + endDate + ')';
            }
            $('#modalTitle').text(modalTitle);
            $('#calendarModal').show();
            
            // Load calendar via AJAX
            $.ajax({
                url: 'attendance_api.php',
                type: 'GET',
                data: {
                    action: 'student_calendar',
                    student_id: studentId,
                    month: month,
                    start_date: startDate,
                    end_date: endDate,
                    report_type: reportType
                },
                success: function(response) {
                    if (response.success) {
                        $('#calendarContainer').html(response.html);
                    } else {
                        $('#calendarContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading calendar data: ' + (response.message || 'Unknown error') + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#calendarContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading calendar data. Please try again.</p></div>');
                }
            });
        });

        // View full history functionality
        $('.view-history-btn').click(function() {
            const studentId = $(this).data('student-id');
            const studentName = $(this).data('student-name');
            
            // Show loading in modal
            $('#historyContainer').html('<div class="text-center py-8"><div class="spinner mb-4"></div><p>Loading attendance history...</p></div>');
            $('#historyModalTitle').text('Complete Attendance History - ' + studentName);
            $('#historyModal').show();
            
            // Load history via AJAX
            $.ajax({
                url: 'attendance_api.php',
                type: 'GET',
                data: {
                    action: 'student_full_history',
                    student_id: studentId
                },
                success: function(response) {
                    if (response.success) {
                        $('#historyContainer').html(response.html);
                    } else {
                        $('#historyContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading attendance history: ' + (response.message || 'Unknown error') + '</p></div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#historyContainer').html('<div class="text-center py-8 text-red-500"><i class="fas fa-exclamation-circle text-3xl mb-2"></i><p>Error loading attendance history. Please try again.</p></div>');
                }
            });
        });

        // Close modals
        $('#closeModal, #closeHistoryModal').click(function() {
            $(this).closest('.modal-overlay').hide();
        });

        // Close modals when clicking outside
        $(window).click(function(event) {
            if ($(event.target).is('#calendarModal')) {
                $('#calendarModal').hide();
            }
            if ($(event.target).is('#historyModal')) {
                $('#historyModal').hide();
            }
        });

        // Mobile sidebar toggle
        window.toggleSidebar = function() {
            const sidebar = document.querySelector('.sidebar');
            const mainContent = document.querySelector('.flex-1');
            if (sidebar) {
                sidebar.classList.toggle('-translate-x-full');
            }
            if (mainContent) {
                mainContent.classList.toggle('ml-0');
                mainContent.classList.toggle('md:ml-64');
            }
        };
        
        // Prevent form double submission
        let isSubmitting = false;
        $('#applyFiltersBtn').on('click', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            isSubmitting = true;
            setTimeout(() => { isSubmitting = false; }, 3000);
        });
        
        // Initialize flatpickr for better date picker experience (optional)
        if (typeof flatpickr !== 'undefined') {
            flatpickr("#startDate, #endDate", {
                dateFormat: "Y-m-d",
                maxDate: "today",
                allowInput: true
            });
        }
    });
    </script>
</body>
</html>