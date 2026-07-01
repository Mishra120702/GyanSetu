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
                        background-color: #1B3C53; 
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
                        background-color: #f0ede9; 
                        padding: 10px; 
                        margin-bottom: 20px; 
                        border: 1px solid #234C6A; 
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
                        color: #234C6A; 
                    }
                    table.data-table { 
                        border-collapse: collapse; 
                        width: 100%; 
                    }
                    th { 
                        background-color: #1B3C53; 
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
            --primary: #234C6A;
            --primary-hover: #1B3C53;
            --primary-light: #456882;
            --navy-dark: #1B3C53;
            --navy-mid: #234C6A;
            --indigo: #456882;
            --accent: #D2C1B6;
            --accent-dark: #a8876f;
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
            background-color: #eef1f4;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            padding: 1.75rem;
            margin-bottom: 1.75rem;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }
        
        .export-header {
            background: linear-gradient(120deg, #1B3C53 0%, #234C6A 50%, #456882 100%);
            color: white;
            box-shadow: 0 6px 28px rgba(8,38,44,0.4);
            position: relative;
            overflow: hidden;
        }
        .export-header::after {
            content: '';
            position: absolute;
            inset: 0;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            pointer-events: none;
        }
        .export-header > * { position: relative; z-index: 1; }
        .export-header .icon-wrap {
            background: rgba(255,255,255,0.18);
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 0.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .export-header .menu-toggle { color: rgba(255,255,255,0.85); }
        .export-header .menu-toggle:hover { color: white; }
        .export-header .header-sub { color: rgba(255,255,255,0.65); font-size: 0.78rem; font-weight: 500; margin-top: 0.15rem; }
        .btn-back-dark {
            background: rgba(255,255,255,0.16);
            border: 1.5px solid rgba(255,255,255,0.35);
            color: white;
            padding: 0.5rem 1.1rem;
            border-radius: 2rem;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s;
            text-decoration: none;
            backdrop-filter: blur(8px);
        }
        .btn-back-dark:hover { background: rgba(255,255,255,0.28); transform: translateX(-2px); }
        
        .btn-primary {
            background: linear-gradient(135deg, #456882, #234C6A);
            color: white;
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px rgba(35,76,106,0.4);
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(35,76,106,0.5);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #34d399, #10b981);
            color: white;
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px rgba(16,185,129,0.4);
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(16,185,129,0.5);
        }
        
        .btn-secondary {
            background-color: var(--gray-200);
            color: var(--gray-700);
            border: none;
            border-radius: 0.75rem;
            padding: 0.6rem 1.2rem;
            font-weight: 700;
            font-size: 0.84rem;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
            cursor: pointer;
        }
        
        .btn-secondary:hover {
            background-color: var(--gray-300);
            transform: translateY(-1px);
        }

        /* quick buttons - each a different colour, matches attendance_reports.php */
        .btn-quick {
            font-size: 0.84rem;
            padding: 0.7rem 1.2rem;
            border-radius: 0.75rem;
            font-weight: 700;
            border: none;
            color: white;
            transition: all 0.18s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            cursor: pointer;
        }
        .btn-quick:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .btn-quick.q1 { background: linear-gradient(135deg, #c2a796, #a8876f); box-shadow: 0 4px 14px rgba(168,148,132,0.35); }
        .btn-quick.q2 { background: linear-gradient(135deg, #5b7e98, #456882); box-shadow: 0 4px 14px rgba(69,104,130,0.35); }
        .btn-quick.q3 { background: linear-gradient(135deg, #2f5d7d, #234C6A); box-shadow: 0 4px 14px rgba(35,76,106,0.35); }
        
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
            box-shadow: 0 0 0 3px rgba(35, 76, 106, 0.18);
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
            background-color: #f0ede9;
        }
        
        .format-card.selected {
            border-color: var(--primary);
            background-color: #f5f1ee;
            box-shadow: 0 4px 12px rgba(35, 76, 106, 0.18);
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

        /* ─── FILTER / TABLE CARD HEADERS (shared theme) ─── */
        .filter-card {
            background: white;
            border-radius: 1.25rem;
            margin-bottom: 1.75rem;
            box-shadow: 0 4px 24px rgba(35,76,106,0.12);
            border: 1px solid #dde6ec;
            overflow: hidden;
        }
        .filter-card-header {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            padding: 0.85rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .filter-card-body { padding: 1.5rem 1.75rem 1.75rem; }

        .field-label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #1B3C53;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
        }

        .table-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #dde6ec;
        }
        .table-card-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #1B3C53;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .title-pill {
            background: linear-gradient(135deg, #e8ded6, #D2C1B6);
            color: #1B3C53;
            width: 2rem; height: 2rem;
            border-radius: 0.6rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        /* ─── PREVIEW TABLE (mirrors DataTables theme on other pages) ─── */
        .preview-table { border-collapse: collapse; width: 100%; }
        .preview-table thead th {
            background: linear-gradient(135deg, #1B3C53, #102837);
            color: #ffffff;
            font-weight: 700;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            padding: 0.85rem 1rem;
            text-align: left;
            white-space: nowrap;
        }
        .preview-table thead th:first-child { border-radius: 0.6rem 0 0 0; }
        .preview-table thead th:last-child { border-radius: 0 0.6rem 0 0; }
        .preview-table tbody tr:nth-child(even) td { background: #f5f1ee; }
        .preview-table tbody td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #dde6ec;
            font-size: 0.87rem;
            vertical-align: middle;
        }
        .preview-table tbody tr:hover td {
            background: linear-gradient(135deg, #f0ede9, #f5f1ee);
        }
        .preview-badge {
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }
        .preview-badge.present { background-color: #dcfce7; color: #166534; }
        .preview-badge.absent  { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300">
        <!-- Header -->
        <header class="export-header px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center">
                <button class="menu-toggle md:hidden text-xl transition-colors mr-4" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="icon-wrap mr-3"><i class="fas fa-download"></i></span>
                <div>
                    <h1 class="text-xl font-bold text-white">Daily Attendance Export</h1>
                    <p class="header-sub">Download attendance records by date and batch</p>
                </div>
            </div>
            <div>
                <a href="attendance.php" class="btn-back-dark">
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
            <div class="filter-card">
                <div class="filter-card-header">
                    <i class="fas fa-sliders-h"></i> Export Settings
                </div>
                <div class="filter-card-body">
                
                <form method="GET" action="" id="exportForm">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="date" class="field-label">
                                Select Date <span class="text-red-500">*</span>
                            </label>
                            <input type="date" id="date" name="date" 
                                   value="<?= htmlspecialchars($export_date) ?>" 
                                   max="<?= date('Y-m-d') ?>"
                                   class="minimal-input" required>
                        </div>
                        
                        <div>
                            <label for="batch_id" class="field-label">
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
                        <label class="field-label">
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
                                <div class="format-icon" style="color:#234C6A;">
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
            </div>

            <!-- Preview Card (hidden by default) -->
            <div id="previewCard" class="card" style="display: none;">
                <div class="table-card-header">
                    <div class="table-card-title">
                        <span class="title-pill"><i class="fas fa-eye"></i></span> Preview Attendance
                    </div>
                </div>
                <div id="previewContent">
                    <!-- Preview will be loaded here -->

                </div>
            </div>

            <!-- Quick Export Buttons -->
            <div class="card">
                <div class="table-card-header">
                    <div class="table-card-title">
                        <span class="title-pill"><i class="fas fa-bolt"></i></span> Quick Export Options
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button onclick="quickExport('today')" class="btn-quick q3">
                        <i class="fas fa-calendar-day"></i> Today's Attendance
                    </button>
                    <button onclick="quickExport('yesterday')" class="btn-quick q1">
                        <i class="fas fa-calendar-day"></i> Yesterday's Attendance
                    </button>
                    <button onclick="quickExport('all')" class="btn-quick q2">
                        <i class="fas fa-calendar-week"></i> All Batches - Today
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
        $('#previewContent').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin text-3xl" style="color:#234C6A;"></i><p class="mt-2">Loading preview...</p></div>');
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
                    html += '<table class="preview-table">';
                    html += '<thead><tr>';
                    html += '<th>Student ID</th>';
                    html += '<th>Student Name</th>';
                    html += '<th>Batch</th>';
                    html += '<th>Status</th>';
                    html += '<th>Phone</th>';
                    html += '<th>Email</th>';
                    html += '</tr></thead><tbody>';
                    
                    response.data.forEach(function(row) {
                        html += '<tr>';
                        html += `<td>${row.student_id || 'N/A'}</td>`;
                        html += `<td>${row.student_name || 'N/A'}</td>`;
                        html += `<td>${row.batch_name || 'N/A'}</td>`;
                        html += `<td><span class="preview-badge ${row.status === 'Present' ? 'present' : 'absent'}">${row.status || 'N/A'}</span></td>`;
                        
                        // Clean phone number for display
                        let phone = row.phone_number || 'N/A';
                        phone = phone.replace(/[^\x20-\x7E0-9]/g, '').replace(/[^0-9+]/g, '');
                        
                        html += `<td>${phone}</td>`;
                        html += `<td>${row.email || 'N/A'}</td>`;
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