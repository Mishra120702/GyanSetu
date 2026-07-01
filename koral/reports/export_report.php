<?php
// export_report.php
require_once '../db_connection.php';
require_once '../header.php';
require_once '../sidebar.php';

// Check if user is admin or mentor
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'mentor')) {
    header('Location: ../login.php');
    exit;
}

// Include PHPExcel library (using PHPOffice if available, otherwise simple CSV)
require_once '../vendor/autoload.php'; // If using PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Get filter parameters
$batch_id = $_GET['batch_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$student_id = $_GET['student_id'] ?? '';
$report_type = $_GET['report_type'] ?? 'all'; // all, attendance, exams, feedback
$format = $_GET['format'] ?? 'excel'; // excel, csv
$export_all = isset($_GET['export_all']) && $_GET['export_all'] == '1';

// Get all batches and students for filter dropdowns
$batches_query = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY start_date DESC");
$batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);

// Get students
$students_query = $db->query("
    SELECT student_id, first_name, last_name, batch_name, 
           CONCAT(first_name, ' ', last_name, ' (', student_id, ') - ', batch_name) as display_name 
    FROM students 
    WHERE current_status = 'active' 
    ORDER BY first_name, last_name
");
$students = $students_query->fetchAll(PDO::FETCH_ASSOC);

// Process export request
if (isset($_GET['export']) && $_GET['export'] == '1') {
    exportReport($db, $batch_id, $start_date, $end_date, $student_id, $report_type, $format, $export_all);
    exit;
}

function exportReport($db, $batch_id, $start_date, $end_date, $student_id, $report_type, $format, $export_all) {
    $data = [];
    $filename = '';
    $headers = [];
    
    // Build query based on report type
    switch($report_type) {
        case 'attendance':
            $data = getAttendanceData($db, $batch_id, $start_date, $end_date, $student_id, $export_all);
            $headers = ['Date', 'Student ID', 'Student Name', 'Batch ID', 'Status', 'Camera Status', 'Remarks'];
            $filename = "attendance_report_" . date('Y-m-d') . ".xlsx";
            break;
            
        case 'exams':
            $data = getExamData($db, $batch_id, $start_date, $end_date, $student_id, $export_all);
            $headers = ['Exam Date', 'Student ID', 'Student Name', 'Batch ID', 'Exam Name', 'Subject', 'Exam Type', 
                       'Total Marks', 'Passing Marks', 'Obtained Marks', 'Percentage', 'Grade', 'Components', 
                       'MCQ Marks', 'Project Marks', 'Viva Marks', 'Remarks'];
            $filename = "exam_report_" . date('Y-m-d') . ".xlsx";
            break;
            
        case 'feedback':
            $data = getFeedbackData($db, $batch_id, $start_date, $end_date, $student_id, $export_all);
            $headers = ['Date', 'Student Name', 'Batch ID', 'Is Regular', 'Class Rating', 'Assignment Understanding', 
                       'Practical Understanding', 'Satisfaction', 'Overall Rating', 'Suggestions', 'Feedback Text'];
            $filename = "feedback_report_" . date('Y-m-d') . ".xlsx";
            break;
            
        case 'all':
        default:
            $data = getAllPerformanceData($db, $batch_id, $start_date, $end_date, $student_id, $export_all);
            $headers = ['Date', 'Type', 'Student ID', 'Student Name', 'Batch ID', 'Score/Marks', 'Status', 
                       'Remarks', 'Details'];
            $filename = "complete_performance_report_" . date('Y-m-d') . ".xlsx";
            break;
    }
    
    // Export based on format
    if ($format == 'csv') {
        exportCSV($data, $headers, $filename);
    } else {
        exportExcel($data, $headers, $filename, $report_type);
    }
}

function getAttendanceData($db, $batch_id, $start_date, $end_date, $student_id, $export_all) {
    $query = "SELECT 
                a.date, 
                a.student_id,
                a.student_name,
                a.batch_id,
                a.status,
                a.camera_status,
                a.remarks,
                s.batch_name
              FROM attendance a
              LEFT JOIN students s ON a.student_id = s.student_id
              WHERE a.date BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    
    if (!empty($batch_id)) {
        $query .= " AND a.batch_id = ?";
        $params[] = $batch_id;
    }
    
    if (!empty($student_id) && !$export_all) {
        $query .= " AND a.student_id = ?";
        $params[] = $student_id;
    }
    
    $query .= " ORDER BY a.date DESC, a.student_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getExamData($db, $batch_id, $start_date, $end_date, $student_id, $export_all) {
    $query = "SELECT 
                e.exam_date as date,
                er.student_id,
                s.first_name,
                s.last_name,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                e.batch_id,
                b.batch_name,
                e.exam_name,
                e.subject,
                e.exam_type,
                e.total_marks,
                e.passing_marks,
                er.obtained_marks,
                ROUND((er.obtained_marks / e.total_marks) * 100, 2) as percentage,
                er.grade,
                e.exam_components as components,
                er.mcq_marks,
                er.project_marks,
                er.viva_marks,
                er.remarks
              FROM exam_results er
              JOIN exams e ON er.exam_id = e.exam_id
              JOIN students s ON er.student_id = s.student_id
              LEFT JOIN batches b ON e.batch_id = b.batch_id
              WHERE e.exam_date BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    
    if (!empty($batch_id)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_id;
    }
    
    if (!empty($student_id) && !$export_all) {
        $query .= " AND er.student_id = ?";
        $params[] = $student_id;
    }
    
    $query .= " ORDER BY e.exam_date DESC, s.first_name, s.last_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFeedbackData($db, $batch_id, $start_date, $end_date, $student_id, $export_all) {
    $query = "SELECT 
                f.date,
                f.student_name,
                f.batch_id,
                b.batch_name,
                f.is_regular,
                f.class_rating,
                f.assignment_understanding,
                f.practical_understanding,
                f.satisfied,
                ROUND((f.class_rating + f.assignment_understanding + f.practical_understanding) / 3, 2) as overall_rating,
                f.suggestions,
                f.feedback_text
              FROM feedback f
              LEFT JOIN batches b ON f.batch_id = b.batch_id
              WHERE f.date BETWEEN ? AND ?";
    
    $params = [$start_date, $end_date];
    
    if (!empty($batch_id)) {
        $query .= " AND f.batch_id = ?";
        $params[] = $batch_id;
    }
    
    if (!empty($student_id) && !$export_all) {
        // Get student name from student_id
        $student_stmt = $db->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM students WHERE student_id = ?");
        $student_stmt->execute([$student_id]);
        $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            $query .= " AND f.student_name = ?";
            $params[] = $student['name'];
        }
    }
    
    $query .= " ORDER BY f.date DESC, f.student_name";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAllPerformanceData($db, $batch_id, $start_date, $end_date, $student_id, $export_all) {
    $data = [];
    
    // Get attendance data
    $attendance = getAttendanceData($db, $batch_id, $start_date, $end_date, $student_id, $export_all);
    foreach ($attendance as $row) {
        $data[] = [
            'date' => $row['date'],
            'type' => 'Attendance',
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'batch_id' => $row['batch_id'],
            'score' => '',
            'status' => $row['status'],
            'remarks' => $row['remarks'],
            'details' => "Camera: {$row['camera_status']}"
        ];
    }
    
    // Get exam data
    $exams = getExamData($db, $batch_id, $start_date, $end_date, $student_id, $export_all);
    foreach ($exams as $row) {
        $data[] = [
            'date' => $row['date'],
            'type' => 'Exam',
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'batch_id' => $row['batch_id'],
            'score' => "{$row['obtained_marks']}/{$row['total_marks']} ({$row['percentage']}%)",
            'status' => $row['grade'],
            'remarks' => $row['remarks'],
            'details' => "{$row['exam_name']} - {$row['subject']} ({$row['exam_type']})"
        ];
    }
    
    // Get feedback data
    $feedback = getFeedbackData($db, $batch_id, $start_date, $end_date, $student_id, $export_all);
    foreach ($feedback as $row) {
        $data[] = [
            'date' => $row['date'],
            'type' => 'Feedback',
            'student_id' => '',
            'student_name' => $row['student_name'],
            'batch_id' => $row['batch_id'],
            'score' => "{$row['overall_rating']}/5",
            'status' => 'Submitted',
            'remarks' => $row['suggestions'],
            'details' => "Class: {$row['class_rating']}/5, Assignments: {$row['assignment_understanding']}/5, Practical: {$row['practical_understanding']}/5"
        ];
    }
    
    // Sort by date
    usort($data, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    return $data;
}

function exportCSV($data, $headers, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write headers
    fputcsv($output, $headers);
    
    // Write data
    foreach ($data as $row) {
        fputcsv($output, array_values($row));
    }
    
    fclose($output);
    exit;
}

function exportExcel($data, $headers, $filename, $report_type) {
    // Create new Spreadsheet object
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set document properties
    $spreadsheet->getProperties()
        ->setCreator("ASD Cybernatics")
        ->setLastModifiedBy("ASD Cybernatics")
        ->setTitle("Student Performance Report")
        ->setSubject($report_type . " Report")
        ->setDescription("Export of student performance data")
        ->setKeywords("student performance report")
        ->setCategory("Report");
    
    // Add title
    $sheet->setCellValue('A1', strtoupper($report_type) . ' REPORT - ASD CYBERNATICS');
    $sheet->mergeCells('A1:' . chr(65 + count($headers) - 1) . '1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
    
    // Add export date
    $sheet->setCellValue('A2', 'Generated on: ' . date('F d, Y H:i:s'));
    $sheet->mergeCells('A2:' . chr(65 + count($headers) - 1) . '2');
    $sheet->getStyle('A2')->getFont()->setItalic(true);
    $sheet->getStyle('A2')->getAlignment()->setHorizontal('center');
    
    // Add headers
    $row = 4;
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . $row, $header);
        $sheet->getStyle($col . $row)->getFont()->setBold(true);
        $sheet->getColumnDimension($col)->setAutoSize(true);
        $col++;
    }
    
    // Add data
    $row = 5;
    foreach ($data as $record) {
        $col = 'A';
        foreach ($record as $value) {
            $sheet->setCellValue($col . $row, $value);
            
            // Apply formatting based on column/type
            if ($col == 'A' && strtotime($value)) {
                $sheet->getStyle($col . $row)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            }
            
            $col++;
        }
        
        // Alternate row colors
        if ($row % 2 == 0) {
            $sheet->getStyle('A' . $row . ':' . chr(ord('A') + count($headers) - 1) . $row)
                  ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                  ->getStartColor()->setARGB('FFEFEFEF');
        }
        
        $row++;
    }
    
    // Apply borders
    $lastCol = chr(ord('A') + count($headers) - 1);
    $lastRow = $row - 1;
    $styleArray = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['argb' => 'FF000000'],
            ],
        ],
    ];
    $sheet->getStyle('A4:' . $lastCol . $lastRow)->applyFromArray($styleArray);
    
    // Add auto filters
    $sheet->setAutoFilter('A4:' . $lastCol . $lastRow);
    
    // Freeze header row
    $sheet->freezePane('A5');
    
    // Set protection (optional)
    // $sheet->getProtection()->setSheet(true);
    
    // Save file
    $writer = new Xlsx($spreadsheet);
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer->save('php://output');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Reports - ASD Cybernatics</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    /* ===== STANDARDIZED BACKGROUND ===== */
    .rpt-orb1 {
        position:fixed; top:-120px; left:-120px;
        width:400px; height:400px; border-radius:50%;
        background:radial-gradient(circle,rgba(99,102,241,.12) 0%,transparent 70%);
        animation:rptOrb1 20s ease-in-out infinite alternate;
        pointer-events:none; z-index:0;
    }
    .rpt-orb2 {
        position:fixed; bottom:-100px; right:-100px;
        width:360px; height:360px; border-radius:50%;
        background:radial-gradient(circle,rgba(139,92,246,.1) 0%,transparent 70%);
        animation:rptOrb2 25s ease-in-out infinite alternate;
        pointer-events:none; z-index:0;
    }
    @keyframes rptOrb1 { from{transform:translate(0,0) scale(1)} to{transform:translate(50px,40px) scale(1.1)} }
    @keyframes rptOrb2 { from{transform:translate(0,0) scale(1)} to{transform:translate(-40px,-50px) scale(1.12)} }

    /* Glass panels */
    .glass-panel {
        background:rgba(255,255,255,.85);
        backdrop-filter:blur(12px);
        -webkit-backdrop-filter:blur(12px);
        border:1px solid rgba(99,102,241,.12);
        box-shadow:0 8px 24px rgba(99,102,241,.1);
        border-radius:20px;
    }
    
    .animate-fade-in {
        animation: fadeIn 0.5s ease-in-out;
    }
    
    .animate-slide-up {
        animation: slideUp 0.3s ease-out;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideUp {
        from { 
            opacity: 0;
            transform: translateY(20px);
        }
        to { 
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .bg-gradient-custom {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    
    .card-hover {
        transition: all 0.3s ease;
    }
    
    .card-hover:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
    }
</style>
</head>
<body style="background: linear-gradient(145deg,#eef2ff 0%,#e0e7ff 30%,#f0f4ff 60%,#ede9fe 100%); min-height:100vh; overflow-x:hidden;">
    <div class="rpt-orb1"></div>
    <div class="rpt-orb2"></div>
    
    <div class="relative z-10">
        <?php include 'navbar.php'; ?>

        <div class="ml-64 p-8 transition-all duration-300">
        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">
                <i class="fas fa-file-export text-blue-600 mr-3"></i>Export Reports
            </h1>
            <p class="text-gray-600">Export student performance data in Excel or CSV format with advanced filtering options</p>
            <div class="mt-2 text-sm text-gray-500">
                <i class="fas fa-info-circle mr-1"></i>
                Select filters and report type to generate customized exports
            </div>
        </div>

        <!-- Main Content -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left Column: Filters -->
            <div class="lg:col-span-2">
                <div class="glass-panel p-6 mb-6 animate-fade-in card-hover">
                    <div class="flex items-center mb-4">
                        <div class="bg-blue-100 p-3 rounded-full mr-4">
                            <i class="fas fa-filter text-blue-600 text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-semibold text-gray-800">Export Filters</h2>
                            <p class="text-sm text-gray-600">Configure your export criteria</p>
                        </div>
                    </div>
                    
                    <form id="exportForm" method="get" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- Report Type -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-chart-bar mr-1"></i>Report Type
                                </label>
                                <select name="report_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                                    <option value="all" <?= $report_type === 'all' ? 'selected' : '' ?>>Complete Performance Report</option>
                                    <option value="attendance" <?= $report_type === 'attendance' ? 'selected' : '' ?>>Attendance Report</option>
                                    <option value="exams" <?= $report_type === 'exams' ? 'selected' : '' ?>>Exam Performance Report</option>
                                    <option value="feedback" <?= $report_type === 'feedback' ? 'selected' : '' ?>>Feedback Report</option>
                                </select>
                            </div>

                            <!-- Export Format -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-file-alt mr-1"></i>Export Format
                                </label>
                                <select name="format" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                                    <option value="excel" <?= $format === 'excel' ? 'selected' : '' ?>>Excel (.xlsx)</option>
                                    <option value="csv" <?= $format === 'csv' ? 'selected' : '' ?>>CSV (.csv)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Student Selection -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-user-graduate mr-1"></i>Student Selection
                            </label>
                            <select name="student_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                                <option value="">All Students</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?= $student['student_id'] ?>" <?= $student_id === $student['student_id'] ? 'selected' : '' ?>>
                                        <?= $student['display_name'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-1">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="export_all" value="1" <?= $export_all ? 'checked' : '' ?> 
                                           class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-600">Include all matching records (overrides student selection)</span>
                                </label>
                            </div>
                        </div>

                        <!-- Batch Filter -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                <i class="fas fa-users mr-1"></i>Batch Filter
                            </label>
                            <select name="batch_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                                <option value="">All Batches</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= $batch['batch_id'] ?>" <?= $batch_id === $batch['batch_id'] ? 'selected' : '' ?>>
                                        <?= $batch['batch_name'] ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Date Range -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-calendar-start mr-1"></i>Start Date
                                </label>
                                <input type="date" name="start_date" value="<?= $start_date ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-calendar-end mr-1"></i>End Date
                                </label>
                                <input type="date" name="end_date" value="<?= $end_date ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex justify-end space-x-4 pt-4 border-t border-gray-200">
                            <button type="reset" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors duration-300">
                                <i class="fas fa-redo mr-2"></i>Reset Filters
                            </button>
                            <button type="button" onclick="previewExport()" class="px-6 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-colors duration-300">
                                <i class="fas fa-eye mr-2"></i>Preview Export
                            </button>
                            <button type="submit" name="export" value="1" class="px-6 py-2 bg-gradient-custom text-white rounded-lg hover:opacity-90 transition-all duration-300 transform hover:scale-105">
                                <i class="fas fa-download mr-2"></i>Generate Export
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Quick Export Templates -->
                <div class="glass-panel p-6 animate-fade-in card-hover">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">
                        <i class="fas fa-bolt mr-2 text-yellow-500"></i>Quick Export Templates
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <button onclick="quickExport('monthly_attendance')" class="bg-blue-50 p-4 rounded-lg border border-blue-200 hover:bg-blue-100 transition-all duration-300 group">
                            <div class="flex items-center">
                                <div class="bg-blue-100 p-3 rounded-full mr-3 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-calendar-check text-blue-600"></i>
                                </div>
                                <div class="text-left">
                                    <h4 class="font-medium text-gray-800">Monthly Attendance</h4>
                                    <p class="text-xs text-gray-600">Current month's attendance</p>
                                </div>
                            </div>
                        </button>
                        
                        <button onclick="quickExport('recent_exams')" class="bg-green-50 p-4 rounded-lg border border-green-200 hover:bg-green-100 transition-all duration-300 group">
                            <div class="flex items-center">
                                <div class="bg-green-100 p-3 rounded-full mr-3 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-graduation-cap text-green-600"></i>
                                </div>
                                <div class="text-left">
                                    <h4 class="font-medium text-gray-800">Recent Exams</h4>
                                    <p class="text-xs text-gray-600">Last 30 days exams</p>
                                </div>
                            </div>
                        </button>
                        
                        <button onclick="quickExport('all_feedback')" class="bg-purple-50 p-4 rounded-lg border border-purple-200 hover:bg-purple-100 transition-all duration-300 group">
                            <div class="flex items-center">
                                <div class="bg-purple-100 p-3 rounded-full mr-3 group-hover:scale-110 transition-transform">
                                    <i class="fas fa-comment-alt text-purple-600"></i>
                                </div>
                                <div class="text-left">
                                    <h4 class="font-medium text-gray-800">All Feedback</h4>
                                    <p class="text-xs text-gray-600">Complete feedback history</p>
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Right Column: Instructions & Statistics -->
            <div class="space-y-6">
                <!-- Instructions Card -->
                <div class="glass-panel p-6 animate-slide-up card-hover">
                    <div class="flex items-center mb-4">
                        <div class="bg-green-100 p-3 rounded-full mr-4">
                            <i class="fas fa-info-circle text-green-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Export Instructions</h3>
                        </div>
                    </div>
                    
                    <div class="space-y-3">
                        <div class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                            <p class="text-sm text-gray-600">Select report type from dropdown</p>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                            <p class="text-sm text-gray-600">Choose export format (Excel or CSV)</p>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                            <p class="text-sm text-gray-600">Apply filters as needed</p>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-2"></i>
                            <p class="text-sm text-gray-600">Click "Generate Export" to download</p>
                        </div>
                        <div class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-2"></i>
                            <p class="text-sm text-gray-600">Large exports may take time to process</p>
                        </div>
                    </div>
                </div>

                <!-- Export Statistics -->
                <div class="glass-panel p-6 animate-slide-up card-hover">
                    <div class="flex items-center mb-4">
                        <div class="bg-indigo-100 p-3 rounded-full mr-4">
                            <i class="fas fa-chart-pie text-indigo-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Export Statistics</h3>
                            <p class="text-sm text-gray-600">Based on current filters</p>
                        </div>
                    </div>
                    
                    <div class="space-y-4">
                        <?php
                        // Get counts for statistics
                        $stats_query = "SELECT 
                            (SELECT COUNT(*) FROM attendance WHERE date BETWEEN ? AND ?" . (!empty($batch_id) ? " AND batch_id = ?" : "") . ") as attendance_count,
                            (SELECT COUNT(*) FROM exam_results er JOIN exams e ON er.exam_id = e.exam_id WHERE e.exam_date BETWEEN ? AND ?" . (!empty($batch_id) ? " AND e.batch_id = ?" : "") . ") as exam_count,
                            (SELECT COUNT(*) FROM feedback WHERE date BETWEEN ? AND ?" . (!empty($batch_id) ? " AND batch_id = ?" : "") . ") as feedback_count";
                        
                        $stats_params = [$start_date, $end_date];
                        if (!empty($batch_id)) {
                            $stats_params[] = $batch_id;
                        }
                        $stats_params = array_merge($stats_params, [$start_date, $end_date]);
                        if (!empty($batch_id)) {
                            $stats_params[] = $batch_id;
                        }
                        $stats_params = array_merge($stats_params, [$start_date, $end_date]);
                        if (!empty($batch_id)) {
                            $stats_params[] = $batch_id;
                        }
                        
                        $stats_stmt = $db->prepare($stats_query);
                        $stats_stmt->execute($stats_params);
                        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="bg-blue-100 p-2 rounded-full mr-3">
                                    <i class="fas fa-calendar-check text-blue-600"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-700">Attendance Records</p>
                                    <p class="text-xs text-gray-500">Date range selected</p>
                                </div>
                            </div>
                            <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full font-bold">
                                <?= $stats['attendance_count'] ?? 0 ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="bg-green-100 p-2 rounded-full mr-3">
                                    <i class="fas fa-graduation-cap text-green-600"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-700">Exam Records</p>
                                    <p class="text-xs text-gray-500">Date range selected</p>
                                </div>
                            </div>
                            <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full font-bold">
                                <?= $stats['exam_count'] ?? 0 ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="bg-purple-100 p-2 rounded-full mr-3">
                                    <i class="fas fa-comment-alt text-purple-600"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-700">Feedback Records</p>
                                    <p class="text-xs text-gray-500">Date range selected</p>
                                </div>
                            </div>
                            <span class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full font-bold">
                                <?= $stats['feedback_count'] ?? 0 ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Export History -->
                <div class="glass-panel p-6 animate-slide-up card-hover">
                    <div class="flex justify-between items-center mb-4">
                        <div class="flex items-center">
                            <div class="bg-yellow-100 p-3 rounded-full mr-4">
                                <i class="fas fa-history text-yellow-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Recent Exports</h3>
                            </div>
                        </div>
                        <a href="export_history.php" class="text-blue-600 hover:text-blue-800 text-sm">
                            <i class="fas fa-external-link-alt mr-1"></i> View All
                        </a>
                    </div>
                    
                    <div class="space-y-3">
                        <?php
                        // Get recent export history (you might want to log exports in a separate table)
                        // This is a placeholder for export history
                        ?>
                        <div class="text-center py-4">
                            <i class="fas fa-file-export text-gray-300 text-3xl mb-2"></i>
                            <p class="text-gray-500 text-sm">No recent exports found</p>
                            <p class="text-gray-400 text-xs">Export history will appear here</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview Modal -->
        <div id="previewModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[80vh] overflow-hidden animate-slide-up">
                <div class="flex justify-between items-center bg-gradient-custom text-white p-6">
                    <div>
                        <h3 class="text-xl font-semibold">Export Preview</h3>
                        <p class="text-blue-100 text-sm">Preview of data to be exported</p>
                    </div>
                    <button onclick="closePreview()" class="text-white hover:text-gray-200">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div class="p-6 overflow-y-auto max-h-[60vh]">
                    <div id="previewContent" class="text-gray-700">
                        <!-- Preview content will be loaded here via AJAX -->
                        <div class="text-center py-8">
                            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto"></div>
                            <p class="mt-4 text-gray-600">Loading preview...</p>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-4 p-6 border-t border-gray-200">
                    <button onclick="closePreview()" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors">
                        Cancel
                    </button>
                    <button onclick="confirmExport()" class="px-6 py-2 bg-gradient-custom text-white rounded-lg hover:opacity-90 transition-all">
                        <i class="fas fa-download mr-2"></i>Confirm Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Quick export templates
    function quickExport(template) {
        const form = document.getElementById('exportForm');
        
        switch(template) {
            case 'monthly_attendance':
                form.report_type.value = 'attendance';
                form.start_date.value = '<?= date("Y-m-01") ?>';
                form.end_date.value = '<?= date("Y-m-t") ?>';
                break;
            case 'recent_exams':
                form.report_type.value = 'exams';
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                form.start_date.value = thirtyDaysAgo.toISOString().split('T')[0];
                form.end_date.value = '<?= date("Y-m-d") ?>';
                break;
            case 'all_feedback':
                form.report_type.value = 'feedback';
                form.start_date.value = '<?= date("Y-m-d", strtotime("-1 year")) ?>';
                form.end_date.value = '<?= date("Y-m-d") ?>';
                break;
        }
        
        form.export.value = '1';
        form.submit();
    }
    
    // Preview export
    function previewExport() {
        const form = document.getElementById('exportForm');
        const formData = new FormData(form);
        
        // Remove export parameter for preview
        formData.delete('export');
        
        // Show modal
        document.getElementById('previewModal').classList.remove('hidden');
        
        // Load preview via AJAX
        fetch('preview_export.php?' + new URLSearchParams(formData))
            .then(response => response.text())
            .then(html => {
                document.getElementById('previewContent').innerHTML = html;
            })
            .catch(error => {
                document.getElementById('previewContent').innerHTML = `
                    <div class="text-center py-8 text-red-600">
                        <i class="fas fa-exclamation-triangle text-3xl mb-3"></i>
                        <p>Error loading preview: ${error.message}</p>
                    </div>
                `;
            });
    }
    
    function closePreview() {
        document.getElementById('previewModal').classList.add('hidden');
    }
    
    function confirmExport() {
        document.getElementById('exportForm').submit();
    }
    
    // Form validation
    document.getElementById('exportForm').addEventListener('submit', function(e) {
        const startDate = new Date(this.start_date.value);
        const endDate = new Date(this.end_date.value);
        
        if (startDate > endDate) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Invalid Date Range',
                text: 'Start date cannot be after end date',
                confirmButtonColor: '#667eea'
            });
        }
    });
    
    // Auto-update statistics on filter change
    const filterInputs = document.querySelectorAll('#exportForm select, #exportForm input[type="date"]');
    filterInputs.forEach(input => {
        input.addEventListener('change', function() {
            // Here you could implement live statistics update
            console.log('Filter changed - update statistics');
        });
    });
    
    // Format date inputs for better UX
    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        const firstDay = new Date(new Date().getFullYear(), new Date().getMonth(), 1)
            .toISOString().split('T')[0];
        const lastDay = new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0)
            .toISOString().split('T')[0];
        
        // Set default dates if empty
        const startDateInput = document.querySelector('input[name="start_date"]');
        const endDateInput = document.querySelector('input[name="end_date"]');
        
        if (!startDateInput.value) startDateInput.value = firstDay;
        if (!endDateInput.value) endDateInput.value = lastDay;
    });
    </script>
    </div>
</div>
</body>
</html>