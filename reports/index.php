<?php
require_once '../db_connection.php';
require_once '../header.php';
require_once '../sidebar.php';

// Get filter parameters
$batch_id = $_GET['batch_id'] ?? '';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-t');
$student_id = $_GET['student_id'] ?? '';
$report_view = $_GET['view'] ?? 'overview';
$include_history = $_GET['include_history'] ?? 'yes';

// Get all batches for filter dropdowns
$batches_query = $db->query("SELECT batch_id, batch_name FROM batches WHERE status IN ('ongoing', 'completed') ORDER BY start_date DESC");
$batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);

// Enhanced student query with all batch fields - simplified for better search
$students_query = $db->query("
    SELECT 
        s.student_id, 
        s.first_name, 
        s.last_name,
        CONCAT(s.first_name, ' ', s.last_name) as full_name
    FROM students s
    WHERE s.current_status IN ('active', 'completed', 'on hold')
    ORDER BY s.first_name, s.last_name
");
$students = $students_query->fetchAll(PDO::FETCH_ASSOC);

// Initialize performance data
$performance_data = [];
$student_name = '';
$report_title = 'Student Performance Report';
$batch_name = '';
$feedback_data = [];
$attendance_data = [];
$exam_data = [];
$detailed_feedback_data = [];
$batch_history = [];
$student_batches = [];

// Get student performance data if student selected
if (!empty($student_id)) {
    // Get comprehensive student information including all batch fields
    $student_info = $db->prepare("
        SELECT 
            s.student_id, 
            CONCAT(s.first_name, ' ', s.last_name) as name,
            s.batch_name,
            s.batch_name_2,
            s.batch_name_3,
            s.batch_name_4,
            s.enrollment_date,
            s.current_status,
            s.email,
            s.phone_number
        FROM students s
        WHERE s.student_id = ?
    ");
    $student_info->execute([$student_id]);
    $student_data = $student_info->fetch(PDO::FETCH_ASSOC);
    
    if ($student_data) {
        $student_name = $student_data['name'];
        $report_title = "Performance Report: " . $student_name;
        
        // Collect all batch IDs the student belongs to
        $student_batches = array_filter([
            $student_data['batch_name'],
            $student_data['batch_name_2'],
            $student_data['batch_name_3'],
            $student_data['batch_name_4']
        ]);
        
        // Get batch transfer history
        $history_query = $db->prepare("
            SELECT 
                sbh.*,
                b1.batch_name as from_batch_name,
                b2.batch_name as to_batch_name,
                u.name as transferred_by_name
            FROM student_batch_history sbh
            LEFT JOIN batches b1 ON sbh.from_batch_id = b1.batch_id
            LEFT JOIN batches b2 ON sbh.to_batch_id = b2.batch_id
            LEFT JOIN users u ON sbh.transferred_by = u.id
            WHERE sbh.student_id = ?
            ORDER BY sbh.transfer_date DESC
        ");
        $history_query->execute([$student_id]);
        $batch_history = $history_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Add historical batches to the list
        foreach ($batch_history as $history) {
            if (!empty($history['from_batch_id']) && !in_array($history['from_batch_id'], $student_batches)) {
                $student_batches[] = $history['from_batch_id'];
            }
            if (!empty($history['to_batch_id']) && !in_array($history['to_batch_id'], $student_batches)) {
                $student_batches[] = $history['to_batch_id'];
            }
        }
        
        // Build the batch filter condition
        $batch_filter = "";
        $params = [$student_id, $start_date, $end_date];
        
        if (!empty($student_batches) && $include_history === 'yes') {
            $placeholders = implode(',', array_fill(0, count($student_batches), '?'));
            $batch_filter = " AND a.batch_id IN ($placeholders)";
            $params = array_merge($params, $student_batches);
        } elseif (!empty($batch_id)) {
            $batch_filter = " AND a.batch_id = ?";
            $params[] = $batch_id;
        }
        
        // 1. ATTENDANCE PERFORMANCE - Get all attendance across all batches
        $attendance_perf = $db->prepare("
            SELECT 
                a.date as date, 
                'Attendance' as type,
                NULL as score,
                a.status as status,
                a.remarks as remarks,
                a.batch_id,
                a.camera_status,
                b.batch_name as batch_name,
                b.batch_name as batch_display
            FROM attendance a
            LEFT JOIN batches b ON a.batch_id = b.batch_id
            WHERE (a.student_id = ? OR a.student_name = ?)
            AND a.date BETWEEN ? AND ?
            $batch_filter
            ORDER BY a.date DESC
        ");
        
        $execute_params = [$student_id, $student_name, $start_date, $end_date];
        if (!empty($student_batches) && $include_history === 'yes') {
            $execute_params = array_merge($execute_params, $student_batches);
        } elseif (!empty($batch_id)) {
            $execute_params[] = $batch_id;
        }
        
        $attendance_perf->execute($execute_params);
        $attendance_data = $attendance_perf->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. EXAM PERFORMANCE - Get all exams across all batches
        $exam_perf = $db->prepare("
            SELECT 
                e.exam_date as date,
                'Exam' as type,
                er.obtained_marks as score,
                'Completed' as status,
                er.remarks as remarks,
                e.batch_id as batch_id,
                e.exam_name,
                e.total_marks,
                e.passing_marks,
                e.subject,
                e.exam_type,
                e.exam_components,
                e.mcq_marks,
                e.project_marks,
                e.viva_marks,
                er.grade,
                er.mcq_marks as student_mcq_marks,
                er.project_marks as student_project_marks,
                er.viva_marks as student_viva_marks,
                b.batch_name,
                b.batch_name as batch_display
            FROM exam_results er
            JOIN exams e ON er.exam_id = e.exam_id
            LEFT JOIN batches b ON e.batch_id = b.batch_id
            WHERE er.student_id = ?
            AND e.exam_date BETWEEN ? AND ?
            " . (!empty($student_batches) && $include_history === 'yes' ? " AND e.batch_id IN (" . implode(',', array_fill(0, count($student_batches), '?')) . ")" : (!empty($batch_id) ? " AND e.batch_id = ?" : "")) . "
            ORDER BY e.exam_date DESC
        ");
        
        $exam_params = [$student_id, $start_date, $end_date];
        if (!empty($student_batches) && $include_history === 'yes') {
            $exam_params = array_merge($exam_params, $student_batches);
        } elseif (!empty($batch_id)) {
            $exam_params[] = $batch_id;
        }
        $exam_perf->execute($exam_params);
        $exam_data = $exam_perf->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. FEEDBACK PERFORMANCE - Get all feedback across all batches
        $feedback_perf = $db->prepare("
            SELECT 
                f.date as date,
                'Feedback' as type,
                ((f.class_rating + f.assignment_understanding + f.practical_understanding) / 3) as score,
                'Submitted' as status,
                f.suggestions as remarks,
                f.batch_id,
                f.class_rating,
                f.assignment_understanding,
                f.practical_understanding,
                f.satisfied,
                f.feedback_text,
                f.is_regular,
                b.batch_name,
                b.batch_name as batch_display
            FROM feedback f
            LEFT JOIN batches b ON f.batch_id = b.batch_id
            WHERE f.student_name = ?
            AND f.date BETWEEN ? AND ?
            " . (!empty($student_batches) && $include_history === 'yes' ? " AND f.batch_id IN (" . implode(',', array_fill(0, count($student_batches), '?')) . ")" : (!empty($batch_id) ? " AND f.batch_id = ?" : "")) . "
            ORDER BY f.date DESC
        ");
        
        $feedback_params = [$student_name, $start_date, $end_date];
        if (!empty($student_batches) && $include_history === 'yes') {
            $feedback_params = array_merge($feedback_params, $student_batches);
        } elseif (!empty($batch_id)) {
            $feedback_params[] = $batch_id;
        }
        $feedback_perf->execute($feedback_params);
        $feedback_data = $feedback_perf->fetchAll(PDO::FETCH_ASSOC);
        
        // Get detailed feedback data
        $detailed_feedback_query = $db->prepare("
            SELECT 
                f.date, 
                f.class_rating, 
                f.assignment_understanding, 
                f.practical_understanding, 
                f.satisfied,
                f.suggestions,
                f.feedback_text,
                f.is_regular,
                b.batch_name
            FROM feedback f
            LEFT JOIN batches b ON f.batch_id = b.batch_id
            WHERE f.student_name = ?
            AND f.date BETWEEN ? AND ?
            ORDER BY f.date DESC
        ");
        $detailed_feedback_query->execute([$student_name, $start_date, $end_date]);
        $detailed_feedback_data = $detailed_feedback_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine all performance data for overview
        $performance_data = array_merge($attendance_data, $exam_data, $feedback_data);
        
        // Sort by date
        usort($performance_data, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
    }
}

// Prepare chart data
$chart_data = [];
$summary_data = [
    'total_attendance' => 0,
    'present_percentage' => 0,
    'average_exam_score' => 'N/A',
    'total_exams' => 0,
    'average_feedback_rating' => 'N/A',
    'total_feedback' => 0,
    'batches_attended' => 0
];

if (!empty($performance_data)) {
    $attendance_counts = ['Present' => 0, 'Absent' => 0];
    $exam_scores = [];
    $exam_names = [];
    $feedback_scores = [];
    $attendance_by_month = [];
    $batch_performance = [];
    
    foreach ($performance_data as $record) {
        $month = date('Y-m', strtotime($record['date']));
        $batch_display = $record['batch_display'] ?? $record['batch_name'] ?? 'Unknown Batch';
        
        // Track batch-wise performance
        if (!isset($batch_performance[$batch_display])) {
            $batch_performance[$batch_display] = [
                'attendance' => ['Present' => 0, 'Absent' => 0],
                'exams' => [],
                'feedback' => []
            ];
        }
        
        if ($record['type'] === 'Attendance') {
            $status = $record['status'] ?? 'Absent';
            $attendance_counts[$status] = ($attendance_counts[$status] ?? 0) + 1;
            $batch_performance[$batch_display]['attendance'][$status] = ($batch_performance[$batch_display]['attendance'][$status] ?? 0) + 1;
            
            if (!isset($attendance_by_month[$month])) {
                $attendance_by_month[$month] = ['Present' => 0, 'Absent' => 0];
            }
            $attendance_by_month[$month][$status] = ($attendance_by_month[$month][$status] ?? 0) + 1;
        } 
        elseif ($record['type'] === 'Exam' && $record['score'] !== null) {
            $exam_scores[] = floatval($record['score']);
            $exam_names[] = $record['exam_name'] ?? 'Exam ' . (count($exam_names) + 1);
            $batch_performance[$batch_display]['exams'][] = floatval($record['score']);
        }
        elseif ($record['type'] === 'Feedback' && $record['score'] !== null) {
            $feedback_scores[] = floatval($record['score']);
            $batch_performance[$batch_display]['feedback'][] = floatval($record['score']);
        }
    }
    
    // Prepare monthly attendance data (keep for overview)
    $monthly_attendance_labels = [];
    $monthly_attendance_present = [];
    $monthly_attendance_absent = [];
    
    ksort($attendance_by_month);
    foreach ($attendance_by_month as $month => $counts) {
        $monthly_attendance_labels[] = date('M Y', strtotime($month . '-01'));
        $monthly_attendance_present[] = $counts['Present'] ?? 0;
        $monthly_attendance_absent[] = $counts['Absent'] ?? 0;
    }
    
    // Prepare batch comparison data
    $batch_labels = [];
    $batch_attendance_rates = [];
    $batch_exam_avgs = [];
    $batch_feedback_avgs = [];
    
    foreach ($batch_performance as $batch => $data) {
        $batch_labels[] = $batch;
        
        // Attendance rate for this batch
        $total_att = $data['attendance']['Present'] + $data['attendance']['Absent'];
        $batch_attendance_rates[] = $total_att > 0 ? round(($data['attendance']['Present'] / $total_att) * 100) : 0;
        
        // Exam average for this batch
        $batch_exam_avgs[] = !empty($data['exams']) ? round(array_sum($data['exams']) / count($data['exams']), 1) : 0;
        
        // Feedback average for this batch
        $batch_feedback_avgs[] = !empty($data['feedback']) ? round(array_sum($data['feedback']) / count($data['feedback']), 1) : 0;
    }
    
    // Chart data configuration - only create if data exists
    $has_attendance_data = !empty($attendance_counts) && ($attendance_counts['Present'] > 0 || $attendance_counts['Absent'] > 0);
    if ($has_attendance_data) {
        $chart_data['attendance'] = [
            'labels' => array_keys($attendance_counts),
            'data' => array_values($attendance_counts),
            'colors' => ['#10b981', '#ef4444'],
            'title' => 'Attendance Distribution',
            'type' => 'pie'
        ];
    }
    
    $has_monthly_attendance = !empty($monthly_attendance_labels);
    if ($has_monthly_attendance) {
        $chart_data['monthly_attendance'] = [
            'labels' => $monthly_attendance_labels,
            'datasets' => [
                ['label' => 'Present', 'data' => $monthly_attendance_present, 'backgroundColor' => '#10b981', 'borderColor' => '#059669', 'borderWidth' => 1],
                ['label' => 'Absent', 'data' => $monthly_attendance_absent, 'backgroundColor' => '#ef4444', 'borderColor' => '#dc2626', 'borderWidth' => 1]
            ],
            'title' => 'Monthly Attendance Breakdown',
            'type' => 'bar'
        ];
    }
    
    // Individual exam scores - BAR CHART (only if exams exist)
    $has_exam_data = !empty($exam_scores);
    if ($has_exam_data) {
        $chart_data['exams'] = [
            'labels' => $exam_names,
            'data' => $exam_scores,
            'colors' => ['#3b82f6'],
            'title' => 'Individual Exam Scores',
            'type' => 'bar'
        ];
    }
    
    // Batch comparison (only if multiple batches)
    $has_batch_comparison = !empty($batch_labels);
    if ($has_batch_comparison) {
        $chart_data['batch_comparison'] = [
            'labels' => $batch_labels,
            'datasets' => [
                ['label' => 'Attendance Rate %', 'data' => $batch_attendance_rates, 'backgroundColor' => '#10b981', 'borderColor' => '#059669'],
                ['label' => 'Exam Avg %', 'data' => $batch_exam_avgs, 'backgroundColor' => '#3b82f6', 'borderColor' => '#2563eb'],
                ['label' => 'Feedback Avg /5', 'data' => $batch_feedback_avgs, 'backgroundColor' => '#ec4899', 'borderColor' => '#db2777']
            ],
            'title' => 'Performance by Batch',
            'type' => 'bar'
        ];
    }
    
    // Feedback details chart (only if detailed feedback exists)
    $has_feedback_details = !empty($detailed_feedback_data);
    if ($has_feedback_details) {
        $feedback_labels = array_map(function($item) { 
            return date('M d, Y', strtotime($item['date'])); 
        }, $detailed_feedback_data);
        
        $feedback_class = array_column($detailed_feedback_data, 'class_rating');
        $feedback_assignments = array_column($detailed_feedback_data, 'assignment_understanding');
        $feedback_practical = array_column($detailed_feedback_data, 'practical_understanding');
        $feedback_satisfaction = array_column($detailed_feedback_data, 'satisfied');
        
        $chart_data['feedback_details'] = [
            'labels' => $feedback_labels,
            'datasets' => [
                ['label' => 'Class Rating', 'data' => $feedback_class, 'borderColor' => '#3b82f6', 'backgroundColor' => '#3b82f620', 'tension' => 0.4],
                ['label' => 'Assignments', 'data' => $feedback_assignments, 'borderColor' => '#10b981', 'backgroundColor' => '#10b98120', 'tension' => 0.4],
                ['label' => 'Practical', 'data' => $feedback_practical, 'borderColor' => '#f59e0b', 'backgroundColor' => '#f59e0b20', 'tension' => 0.4],
                ['label' => 'Satisfaction', 'data' => $feedback_satisfaction, 'borderColor' => '#8b5cf6', 'backgroundColor' => '#8b5cf620', 'tension' => 0.4]
            ],
            'title' => 'Feedback Ratings Over Time',
            'type' => 'line'
        ];
    }
    
    // Calculate summary data
    $total_attendance = 0;
    $present_count = 0;
    $total_exams = 0;
    $total_exam_score = 0;
    $total_feedback = 0;
    $total_feedback_score = 0;
    $unique_batches = [];
    
    foreach ($performance_data as $record) {
        if (!empty($record['batch_display']) && !in_array($record['batch_display'], $unique_batches)) {
            $unique_batches[] = $record['batch_display'];
        }
        
        if ($record['type'] === 'Attendance') {
            $total_attendance++;
            if ($record['status'] === 'Present') $present_count++;
        } 
        elseif ($record['type'] === 'Exam' && $record['score'] !== null) {
            $total_exams++;
            $total_exam_score += $record['score'];
        }
        elseif ($record['type'] === 'Feedback' && $record['score'] !== null) {
            $total_feedback++;
            $total_feedback_score += $record['score'];
        }
    }
    
    // Calculate averages
    $summary_data = [
        'total_attendance' => $total_attendance,
        'present_percentage' => $total_attendance > 0 ? round(($present_count / $total_attendance) * 100) : 0,
        'average_exam_score' => $total_exams > 0 ? round($total_exam_score / $total_exams, 1) : 'N/A',
        'total_exams' => $total_exams,
        'average_feedback_rating' => $total_feedback > 0 ? round($total_feedback_score / $total_feedback, 1) : 'N/A',
        'total_feedback' => $total_feedback,
        'batches_attended' => count($unique_batches)
    ];
}
?>

<!-- Main Content with Sidebar Offset -->
<div class="ml-64 p-8 transition-all duration-300 min-h-screen" style="background: linear-gradient(145deg,#eef2ff 0%,#e0e7ff 30%,#f0f4ff 60%,#ede9fe 100%); position:relative; overflow-x:hidden;">

<style>
/* ===== REPORTS PAGE UPGRADE ===== */
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

/* Gradient summary cards */
.stat-card-gradient {
    border-radius: 20px;
    padding: 24px;
    color: white;
    position: relative;
    overflow: hidden;
    transition: all .35s cubic-bezier(.4,0,.2,1);
    cursor: default;
    border: none;
}
.stat-card-gradient::before {
    content:'';
    position:absolute; top:0; left:-75%;
    width:60%; height:100%;
    background:linear-gradient(120deg,transparent,rgba(255,255,255,.18),transparent);
    transform:skewX(-20deg);
    transition:left .55s ease;
    pointer-events:none;
}
.stat-card-gradient:hover::before { left:140%; }
.stat-card-gradient::after {
    content:''; position:absolute; inset:0;
    border-radius:20px;
    border:1.5px solid rgba(255,255,255,.3);
    pointer-events:none;
}
.stat-card-gradient:hover { transform:translateY(-7px) scale(1.02); }

.scg-blue   { background:linear-gradient(135deg,#3b82f6 0%,#4f46e5 100%); box-shadow:0 8px 24px rgba(59,130,246,.4); }
.scg-violet { background:linear-gradient(135deg,#6366f1 0%,#8b5cf6 100%); box-shadow:0 8px 24px rgba(99,102,241,.4); }
.scg-pink   { background:linear-gradient(135deg,#ec4899 0%,#a855f7 100%); box-shadow:0 8px 24px rgba(236,72,153,.4); }
.scg-teal   { background:linear-gradient(135deg,#14b8a6 0%,#3b82f6 100%); box-shadow:0 8px 24px rgba(20,184,166,.4); }
.scg-blue:hover   { box-shadow:0 20px 40px rgba(59,130,246,.55); }
.scg-violet:hover { box-shadow:0 20px 40px rgba(99,102,241,.55); }
.scg-pink:hover   { box-shadow:0 20px 40px rgba(236,72,153,.55); }
.scg-teal:hover   { box-shadow:0 20px 40px rgba(20,184,166,.55); }

.scg-number { font-size:2.4rem; font-weight:900; line-height:1; color:white; text-shadow:0 2px 8px rgba(0,0,0,.2); }
.scg-label  { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,.82); }
.scg-sub    { font-size:.85rem; color:rgba(255,255,255,.9); margin-top:4px; }
.scg-icon   { background:rgba(255,255,255,.2); border-radius:14px; padding:12px; }
.scg-bar    { height:5px; background:rgba(255,255,255,.25); border-radius:4px; overflow:hidden; margin-top:14px; }
.scg-bar-fill { height:100%; border-radius:4px; background:rgba(255,255,255,.75); transition:width 1s ease; }

/* Glass panels */
.glass-panel {
    background:rgba(255,255,255,.85);
    backdrop-filter:blur(12px);
    -webkit-backdrop-filter:blur(12px);
    border:1px solid rgba(99,102,241,.12);
    box-shadow:0 8px 24px rgba(99,102,241,.1);
    border-radius:20px;
}

/* Page header */
.rpt-header-badge {
    background:linear-gradient(135deg,#6366f1,#8b5cf6);
    border-radius:16px; padding:14px 18px;
    box-shadow:0 8px 20px rgba(99,102,241,.35);
}
</style>
<div class="rpt-orb1"></div>
<div class="rpt-orb2"></div>
<div style="position:relative;z-index:1;">

    <!-- Top Navigation -->
    <div class="mb-8">
        <?php include 'navbar.php'; ?>
    </div>

    <!-- Page Header with Actions -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
        <div class="flex items-center space-x-4">
            <div class="bg-gradient-to-r from-blue-600 to-indigo-600 p-3 rounded-xl shadow-lg">
                <i class="fas fa-chart-line text-white text-2xl"></i>
            </div>
            <div>
                <h1 class="text-3xl font-bold text-gray-800"><?= htmlspecialchars($report_title) ?></h1>
                <p class="text-sm text-gray-500 mt-1 flex items-center">
                    <i class="fas fa-calendar-alt mr-2"></i>
                    <?= date('M d, Y', strtotime($start_date)) ?> - <?= date('M d, Y', strtotime($end_date)) ?>
                </p>
            </div>
        </div>
        <div class="flex space-x-3">
            <button onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-5 py-2.5 rounded-xl hover:bg-gray-50 transition-all transform hover:scale-105 hover:shadow-md flex items-center">
                <i class="fas fa-print mr-2"></i> Print
            </button>
            <button onclick="exportToPDF()" class="bg-white border border-gray-300 text-gray-700 px-5 py-2.5 rounded-xl hover:bg-gray-50 transition-all transform hover:scale-105 hover:shadow-md flex items-center">
                <i class="fas fa-file-pdf mr-2 text-red-500"></i> Export PDF
            </button>
            <button onclick="exportToExcel()" class="bg-white border border-gray-300 text-gray-700 px-5 py-2.5 rounded-xl hover:bg-gray-50 transition-all transform hover:scale-105 hover:shadow-md flex items-center">
                <i class="fas fa-file-excel mr-2 text-green-600"></i> Excel
            </button>
        </div>
    </div>

    <!-- Enhanced Student Search Filter Card -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 transition-all hover:shadow-2xl border border-gray-100">
        <div class="flex items-center mb-6">
            <div class="bg-blue-100 p-2 rounded-lg mr-3">
                <i class="fas fa-search text-blue-600"></i>
            </div>
            <h2 class="text-xl font-semibold text-gray-800">Find Student</h2>
            <span class="ml-3 px-3 py-1 bg-blue-100 text-blue-600 rounded-full text-xs font-medium">Type to search</span>
        </div>
        
        <form method="get" class="grid grid-cols-1 gap-6" id="reportForm">
            <!-- Student Search with Live Filter -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                    <i class="fas fa-user-graduate text-blue-500 mr-2"></i>
                    Select Student
                </label>
                <div class="relative">
                    <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" 
                           id="studentSearchInput" 
                           class="w-full pl-11 pr-12 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all bg-white" 
                           placeholder="Type student name to search..."
                           autocomplete="off">
                    <i id="searchClearIcon" class="fas fa-times-circle absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 cursor-pointer hidden"></i>
                </div>
                <div id="studentDropdown" class="hidden absolute z-50 mt-1 w-full bg-white border border-gray-200 rounded-xl shadow-lg max-h-80 overflow-y-auto" style="width: calc(100% - 2rem);">
                    <div id="studentList" class="py-2">
                        <?php foreach ($students as $student): ?>
                            <div class="student-option px-4 py-3 hover:bg-blue-50 cursor-pointer transition-colors border-b border-gray-100 last:border-0" 
                                 data-student-id="<?= htmlspecialchars($student['student_id']) ?>"
                                 data-student-name="<?= htmlspecialchars(strtolower($student['full_name'])) ?>">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <span class="font-medium text-gray-800"><?= htmlspecialchars($student['full_name']) ?></span>
                                        <span class="text-xs text-gray-500 ml-2">(<?= htmlspecialchars($student['student_id']) ?>)</span>
                                    </div>
                                    <i class="fas fa-chevron-right text-gray-400 text-sm"></i>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <input type="hidden" name="student_id" id="selectedStudentId" value="<?= htmlspecialchars($student_id) ?>">
                <p class="text-xs text-gray-500 mt-2 flex items-center">
                    <i class="fas fa-info-circle mr-1 text-blue-400"></i>
                    Start typing student name to search from the list
                </p>
                <?php if (!empty($student_id)): ?>
                    <div class="mt-3 inline-flex items-center px-4 py-2 bg-blue-50 rounded-xl text-blue-700 text-sm">
                        <i class="fas fa-check-circle mr-2"></i>
                        Selected: <strong class="ml-1"><?= htmlspecialchars($student_name) ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Additional Filters (Collapsible) -->
            <div class="border-t border-gray-100 pt-4 mt-2">
                <div class="flex items-center justify-between cursor-pointer" onclick="toggleFilters()">
                    <div class="flex items-center">
                        <i class="fas fa-sliders-h text-gray-500 mr-2"></i>
                        <span class="text-sm font-medium text-gray-600">Advanced Filters</span>
                    </div>
                    <i id="filterToggleIcon" class="fas fa-chevron-down text-gray-400 transition-transform"></i>
                </div>
                <div id="advancedFilters" class="hidden mt-4 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5">
                    <!-- Batch Filter -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-layer-group text-purple-500 mr-2"></i>
                            Batch
                        </label>
                        <div class="relative">
                            <i class="fas fa-filter absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            <select name="batch_id" class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all appearance-none bg-white">
                                <option value="">All Batches</option>
                                <?php foreach ($batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_id']) ?>" <?= $batch_id === $batch['batch_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Include History Toggle -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-history text-green-500 mr-2"></i>
                            Data Scope
                        </label>
                        <div class="relative">
                            <select name="include_history" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all appearance-none bg-white">
                                <option value="yes" <?= $include_history === 'yes' ? 'selected' : '' ?>>All Batches (Incl. History)</option>
                                <option value="no" <?= $include_history === 'no' ? 'selected' : '' ?>>Current Batch Only</option>
                            </select>
                        </div>
                    </div>

                    <!-- Date Range -->
                    <div class="md:col-span-2 grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-calendar-plus text-orange-500 mr-2"></i>
                                Start Date
                            </label>
                            <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                <i class="fas fa-calendar-check text-green-500 mr-2"></i>
                                End Date
                            </label>
                            <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
                        </div>
                    </div>

                    <!-- Report View -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                            <i class="fas fa-chart-pie text-indigo-500 mr-2"></i>
                            View
                        </label>
                        <div class="relative">
                            <select name="view" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all appearance-none bg-white">
                                <option value="overview" <?= $report_view === 'overview' ? 'selected' : '' ?>>Overview Dashboard</option>
                                <option value="attendance" <?= $report_view === 'attendance' ? 'selected' : '' ?>>Attendance Analysis</option>
                                <option value="exams" <?= $report_view === 'exams' ? 'selected' : '' ?>>Exam Performance</option>
                                <option value="feedback" <?= $report_view === 'feedback' ? 'selected' : '' ?>>Feedback Analysis</option>
                                <option value="batch_comparison" <?= $report_view === 'batch_comparison' ? 'selected' : '' ?>>Batch Comparison</option>
                            </select>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="md:col-span-2 flex justify-end space-x-4 items-end">
                        <a href="?" class="bg-gray-100 text-gray-700 px-6 py-3 rounded-xl hover:bg-gray-200 transition-all transform hover:scale-105 flex items-center">
                            <i class="fas fa-redo mr-2"></i> Reset
                        </a>
                        <button type="submit" class="bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-8 py-3 rounded-xl hover:from-blue-700 hover:to-indigo-700 transition-all transform hover:scale-105 shadow-md hover:shadow-lg flex items-center">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php if (!empty($student_id) && !empty($student_data)): ?>
        <!-- Student Info Banner -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl p-6 mb-8 border border-blue-100 animate-slide-up">
            <div class="flex flex-wrap items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="bg-gradient-to-br from-blue-500 to-indigo-600 p-4 rounded-2xl shadow-lg">
                        <i class="fas fa-user-graduate text-white text-3xl"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($student_name) ?></h3>
                        <div class="flex flex-wrap gap-3 mt-2">
                            <span class="px-3 py-1 bg-blue-100 text-blue-600 rounded-full text-sm flex items-center">
                                <i class="fas fa-id-card mr-1"></i> ID: <?= htmlspecialchars($student_id) ?>
                            </span>
                            <?php if (!empty($student_data['email'])): ?>
                                <span class="px-3 py-1 bg-green-100 text-green-600 rounded-full text-sm flex items-center">
                                    <i class="fas fa-envelope mr-1"></i> <?= htmlspecialchars($student_data['email']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($student_data['phone_number'])): ?>
                                <span class="px-3 py-1 bg-purple-100 text-purple-600 rounded-full text-sm flex items-center">
                                    <i class="fas fa-phone mr-1"></i> <?= htmlspecialchars($student_data['phone_number']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="px-3 py-1 bg-yellow-100 text-yellow-600 rounded-full text-sm flex items-center">
                                <i class="fas fa-calendar-alt mr-1"></i> Enrolled: <?= date('M d, Y', strtotime($student_data['enrollment_date'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="mt-4 md:mt-0">
                    <div class="bg-white rounded-xl px-6 py-3 shadow-sm">
                        <span class="text-sm text-gray-500 block">Current Status</span>
                        <span class="font-semibold text-lg <?= $student_data['current_status'] === 'active' ? 'text-green-600' : 'text-orange-600' ?>">
                            <?= ucfirst($student_data['current_status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Batch History Timeline (if available) -->
        <?php if (!empty($batch_history)): ?>
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-8 animate-fade-in">
            <div class="flex items-center mb-4">
                <div class="bg-purple-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-timeline text-purple-600"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-800">Batch Transfer History</h3>
            </div>
            <div class="relative">
                <div class="absolute left-4 top-0 bottom-0 w-0.5 bg-purple-200"></div>
                <div class="space-y-4">
                    <?php foreach ($batch_history as $history): ?>
                    <div class="relative pl-10">
                        <div class="absolute left-2 top-2 w-4 h-4 bg-purple-500 rounded-full border-4 border-purple-100"></div>
                        <div class="bg-purple-50 rounded-xl p-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-medium text-gray-800">
                                        <span class="text-purple-600"><?= htmlspecialchars($history['from_batch_name'] ?? $history['from_batch_id']) ?></span>
                                        <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                                        <span class="text-indigo-600"><?= htmlspecialchars($history['to_batch_name'] ?? $history['to_batch_id']) ?></span>
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1">
                                        <i class="far fa-clock mr-1"></i> <?= date('M d, Y H:i', strtotime($history['transfer_date'])) ?>
                                    </p>
                                </div>
                                <?php if (!empty($history['transferred_by_name'])): ?>
                                <span class="text-xs text-gray-500 bg-white px-3 py-1 rounded-full">
                                    By: <?= htmlspecialchars($history['transferred_by_name']) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- View Tabs with Modern Design -->
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'overview'])) ?>" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $report_view === 'overview' ? 'bg-blue-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-chart-pie mr-2"></i> Overview
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'attendance'])) ?>" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $report_view === 'attendance' ? 'bg-green-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-calendar-check mr-2"></i> Attendance
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'exams'])) ?>" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $report_view === 'exams' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-graduation-cap mr-2"></i> Exams
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'feedback'])) ?>" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $report_view === 'feedback' ? 'bg-purple-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-comment-alt mr-2"></i> Feedback
            </a>
            <a href="?<?= http_build_query(array_merge($_GET, ['view' => 'batch_comparison'])) ?>" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $report_view === 'batch_comparison' ? 'bg-orange-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-layer-group mr-2"></i> Batch Comparison
            </a>
        </div>

        <!-- Performance Summary Cards (shown in all views) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Attendance Card -->
            <div class="stat-card-gradient scg-blue animate-fade-in" onclick="openRptModal('rptAttendanceModal')" style="cursor:pointer;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="scg-label"><i class="fas fa-calendar-check mr-2"></i>Total Attendance</p>
                        <h3 class="scg-number mt-2"><?= $summary_data['total_attendance'] ?></h3>
                        <p class="scg-sub"><strong><?= $summary_data['present_percentage'] ?>%</strong> present</p>
                    </div>
                    <div class="scg-icon"><i class="fas fa-user-check text-white text-2xl"></i></div>
                </div>
                <div class="scg-bar"><div class="scg-bar-fill" style="width:<?= $summary_data['present_percentage'] ?>%"></div></div>
            </div>

        <!-- Exams Card -->
            <div class="stat-card-gradient scg-violet animate-fade-in" onclick="openRptModal('rptExamsModal')" style="animation-delay:.1s;cursor:pointer;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="scg-label"><i class="fas fa-graduation-cap mr-2"></i>Total Exams</p>
                        <h3 class="scg-number mt-2"><?= $summary_data['total_exams'] ?></h3>
                        <p class="scg-sub"><strong><?= $summary_data['average_exam_score'] ?></strong> avg score</p>
                    </div>
                    <div class="scg-icon"><i class="fas fa-pen-alt text-white text-2xl"></i></div>
                </div>
                <?php if (is_numeric($summary_data['average_exam_score'])): ?>
                <div class="scg-bar"><div class="scg-bar-fill" style="width:<?= $summary_data['average_exam_score'] ?>%"></div></div>
                <?php endif; ?>
            </div>

            <!-- Feedback Card -->
            <div class="stat-card-gradient scg-pink animate-fade-in" onclick="openRptModal('rptFeedbackModal')" style="animation-delay:.2s;cursor:pointer;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="scg-label"><i class="fas fa-comment-alt mr-2"></i>Feedback Received</p>
                        <h3 class="scg-number mt-2"><?= $summary_data['total_feedback'] ?></h3>
                        <p class="scg-sub"><strong><?= $summary_data['average_feedback_rating'] ?></strong> avg rating</p>
                    </div>
                    <div class="scg-icon"><i class="fas fa-star text-white text-2xl"></i></div>
                </div>
                <?php if (is_numeric($summary_data['average_feedback_rating'])): ?>
                <div class="scg-bar"><div class="scg-bar-fill" style="width:<?= ($summary_data['average_feedback_rating'] / 5) * 100 ?>%"></div></div>
                <?php endif; ?>
            </div>

            <!-- Batches Card -->
            <div class="stat-card-gradient scg-teal animate-fade-in" onclick="openRptModal('rptBatchesModal')" style="animation-delay:.3s;cursor:pointer;">
                <div class="flex justify-between items-start">
                    <div>
                        <p class="scg-label"><i class="fas fa-layer-group mr-2"></i>Batches Attended</p>
                        <h3 class="scg-number mt-2"><?= $summary_data['batches_attended'] ?></h3>
                        <p class="scg-sub">including history</p>
                    </div>
                    <div class="scg-icon"><i class="fas fa-history text-white text-2xl"></i></div>
                </div>
                <div class="mt-3 flex flex-wrap gap-1">
                    <?php foreach ($student_batches as $index => $batch): ?>
                        <?php if ($index < 3): ?>
                            <span style="font-size:.7rem;background:rgba(255,255,255,.22);padding:2px 8px;border-radius:20px;color:white;"><?= htmlspecialchars($batch) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (count($student_batches) > 3): ?>
                        <span style="font-size:.7rem;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:20px;color:white;">+<?= count($student_batches) - 3 ?> more</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Content Area based on view -->
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden animate-slide-up">
            <!-- View Title -->
            <div class="bg-gradient-to-r from-gray-50 to-white p-6 border-b border-gray-200">
                <div class="flex items-center">
                    <?php
                    $view_icons = [
                        'overview' => ['icon' => 'chart-pie', 'color' => 'blue'],
                        'attendance' => ['icon' => 'calendar-check', 'color' => 'green'],
                        'exams' => ['icon' => 'graduation-cap', 'color' => 'indigo'],
                        'feedback' => ['icon' => 'comment-alt', 'color' => 'purple'],
                        'batch_comparison' => ['icon' => 'layer-group', 'color' => 'orange']
                    ];
                    $current_icon = $view_icons[$report_view] ?? $view_icons['overview'];
                    ?>
                    <div class="bg-<?= $current_icon['color'] ?>-100 p-3 rounded-xl mr-4">
                        <i class="fas fa-<?= $current_icon['icon'] ?> text-<?= $current_icon['color'] ?>-600 text-xl"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800 capitalize"><?= $report_view ?> Analysis</h2>
                        <p class="text-sm text-gray-500 mt-1">
                            <?php if ($report_view === 'overview'): ?>
                                Comprehensive performance overview across all metrics
                            <?php elseif ($report_view === 'attendance'): ?>
                                Detailed attendance records and patterns
                            <?php elseif ($report_view === 'exams'): ?>
                                Exam scores and component-wise performance
                            <?php elseif ($report_view === 'feedback'): ?>
                                Student feedback ratings and comments
                            <?php elseif ($report_view === 'batch_comparison'): ?>
                                Performance comparison across different batches
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Charts Section for Overview -->
            <?php if ($report_view === 'overview' && !empty($chart_data)): ?>
            <div class="p-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Attendance Pie Chart - Only if data exists -->
                    <?php if (!empty($chart_data['attendance'])): ?>
                    <div class="bg-gray-50 rounded-xl p-4 transition-all hover:shadow-md">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-chart-pie text-blue-500 mr-2"></i>
                            <?= $chart_data['attendance']['title'] ?>
                        </h3>
                        <div class="h-64">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Monthly Attendance Bar Chart - Only if data exists -->
                    <?php if (!empty($chart_data['monthly_attendance'])): ?>
                    <div class="bg-gray-50 rounded-xl p-4 transition-all hover:shadow-md">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-chart-bar text-green-500 mr-2"></i>
                            <?= $chart_data['monthly_attendance']['title'] ?>
                        </h3>
                        <div class="h-64">
                            <canvas id="monthlyAttendanceChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Individual Exam Scores Bar Chart - Only if exams exist -->
                    <?php if (!empty($chart_data['exams'])): ?>
                    <div class="bg-gray-50 rounded-xl p-4 transition-all hover:shadow-md">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-chart-bar text-indigo-500 mr-2"></i>
                            <?= $chart_data['exams']['title'] ?>
                        </h3>
                        <div class="h-64">
                            <canvas id="examsChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Batch Comparison Chart - Only if multiple batches exist -->
                    <?php if (!empty($chart_data['batch_comparison'])): ?>
                    <div class="bg-gray-50 rounded-xl p-4 transition-all hover:shadow-md">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-chart-bar text-orange-500 mr-2"></i>
                            <?= $chart_data['batch_comparison']['title'] ?>
                        </h3>
                        <div class="h-80">
                            <canvas id="batchComparisonChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Feedback Details Chart - Only if detailed feedback exists -->
                    <?php if (!empty($chart_data['feedback_details'])): ?>
                    <div class="bg-gray-50 rounded-xl p-4 transition-all hover:shadow-md lg:col-span-2">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-chart-line text-purple-500 mr-2"></i>
                            <?= $chart_data['feedback_details']['title'] ?>
                        </h3>
                        <div class="h-80">
                            <canvas id="feedbackDetailsChart"></canvas>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Batch Comparison View -->
            <?php if ($report_view === 'batch_comparison'): ?>
                <div class="p-6">
                    <?php if (!empty($chart_data['batch_comparison'])): ?>
                    <div class="bg-gray-50 rounded-xl p-6 mb-8">
                        <h3 class="text-lg font-semibold text-gray-700 mb-6 flex items-center">
                            <i class="fas fa-chart-bar text-orange-500 mr-2"></i>
                            Performance Metrics by Batch
                        </h3>
                        <div class="h-96">
                            <canvas id="batchComparisonChart"></canvas>
                        </div>
                    </div>

                    <!-- Batch-wise Detailed Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Present %</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exams</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exam Avg</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Feedback</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Feedback Avg</th>
                                 </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php
                                $batch_stats = [];
                                foreach ($performance_data as $record) {
                                    $batch = $record['batch_display'] ?? $record['batch_name'] ?? 'Unknown';
                                    if (!isset($batch_stats[$batch])) {
                                        $batch_stats[$batch] = [
                                            'attendance' => ['Present' => 0, 'Absent' => 0],
                                            'exams' => [],
                                            'feedback' => []
                                        ];
                                    }
                                    
                                    if ($record['type'] === 'Attendance') {
                                        $batch_stats[$batch]['attendance'][$record['status']] = ($batch_stats[$batch]['attendance'][$record['status']] ?? 0) + 1;
                                    } elseif ($record['type'] === 'Exam' && $record['score'] !== null) {
                                        $batch_stats[$batch]['exams'][] = $record['score'];
                                    } elseif ($record['type'] === 'Feedback' && $record['score'] !== null) {
                                        $batch_stats[$batch]['feedback'][] = $record['score'];
                                    }
                                }
                                
                                foreach ($batch_stats as $batch => $stats): 
                                    $total_att = $stats['attendance']['Present'] + $stats['attendance']['Absent'];
                                    $att_pct = $total_att > 0 ? round(($stats['attendance']['Present'] / $total_att) * 100) : 0;
                                    $exam_avg = !empty($stats['exams']) ? round(array_sum($stats['exams']) / count($stats['exams']), 1) : 'N/A';
                                    $feedback_avg = !empty($stats['feedback']) ? round(array_sum($stats['feedback']) / count($stats['feedback']), 1) : 'N/A';
                                ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="font-medium text-gray-900"><?= htmlspecialchars($batch) ?></span>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= $total_att ?>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <span class="text-sm font-medium <?= $att_pct >= 75 ? 'text-green-600' : ($att_pct >= 50 ? 'text-yellow-600' : 'text-red-600') ?> mr-2">
                                                <?= $att_pct ?>%
                                            </span>
                                            <div class="w-16 h-1.5 bg-gray-200 rounded-full">
                                                <div class="h-1.5 bg-<?= $att_pct >= 75 ? 'green' : ($att_pct >= 50 ? 'yellow' : 'red') ?>-500 rounded-full" style="width: <?= $att_pct ?>%"></div>
                                            </div>
                                        </div>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= count($stats['exams']) ?>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?= is_numeric($exam_avg) ? ($exam_avg >= 75 ? 'text-green-600' : ($exam_avg >= 50 ? 'text-yellow-600' : 'text-red-600')) : 'text-gray-400' ?>">
                                        <?= $exam_avg ?>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                        <?= count($stats['feedback']) ?>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?= is_numeric($feedback_avg) ? ($feedback_avg >= 4 ? 'text-green-600' : ($feedback_avg >= 3 ? 'text-yellow-600' : 'text-red-600')) : 'text-gray-400' ?>">
                                        <?= $feedback_avg ?>
                                     </td>
                                 </tr>
                                <?php endforeach; ?>
                            </tbody>
                         </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-16">
                        <div class="bg-orange-100 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-layer-group text-orange-500 text-3xl"></i>
                        </div>
                        <p class="text-gray-500 text-lg">No batch comparison data available</p>
                        <p class="text-sm text-gray-400 mt-2">Try selecting a different date range or student</p>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Attendance View -->
            <?php if ($report_view === 'attendance'): ?>
                <div class="p-6">
                    <?php if (empty($attendance_data)): ?>
                    <div class="text-center py-16">
                        <div class="bg-green-100 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calendar-times text-green-500 text-3xl"></i>
                        </div>
                        <p class="text-gray-500 text-lg">No attendance records found</p>
                        <p class="text-sm text-gray-400 mt-2">Try adjusting your filters</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                 <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Camera</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                                 </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($attendance_data as $index => $row): ?>
                                <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?> hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <i class="far fa-calendar-alt text-gray-400 mr-2"></i>
                                        <?= date('M d, Y', strtotime($row['date'])) ?>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-3 py-1 bg-blue-100 text-blue-600 rounded-full text-xs font-medium">
                                            <?= htmlspecialchars($row['batch_display'] ?? $row['batch_name'] ?? $row['batch_id'] ?? 'N/A') ?>
                                        </span>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $row['status'] === 'Present' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <i class="fas fa-<?= $row['status'] === 'Present' ? 'check-circle' : 'times-circle' ?> mr-1"></i>
                                            <?= $row['status'] ?>
                                        </span>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= ($row['camera_status'] ?? 'Off') === 'On' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                            <i class="fas fa-video mr-1"></i>
                                            <?= $row['camera_status'] ?? 'Off' ?>
                                        </span>
                                     </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate">
                                        <?= htmlspecialchars($row['remarks'] ?? 'No remarks') ?>
                                     </td>
                                 </tr>
                                <?php endforeach; ?>
                            </tbody>
                         </table>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Exams View -->
            <?php if ($report_view === 'exams'): ?>
                <div class="p-6">
                    <?php if (empty($exam_data)): ?>
                    <div class="text-center py-16">
                        <div class="bg-indigo-100 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-graduation-cap text-indigo-500 text-3xl"></i>
                        </div>
                        <p class="text-gray-500 text-lg">No exam records found</p>
                        <p class="text-sm text-gray-400 mt-2">Try adjusting your filters</p>
                    </div>
                    <?php else: ?>
                        <!-- Component Performance Summary -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <?php
                            // Calculate component averages
                            $component_totals = [
                                'mcq' => ['total' => 0, 'obtained' => 0, 'count' => 0],
                                'project' => ['total' => 0, 'obtained' => 0, 'count' => 0],
                                'viva' => ['total' => 0, 'obtained' => 0, 'count' => 0]
                            ];

                            foreach ($exam_data as $exam) {
                                if (!empty($exam['exam_components'])) {
                                    $components = explode(',', $exam['exam_components']);
                                    
                                    if (in_array('mcq', $components) && !is_null($exam['student_mcq_marks'])) {
                                        $component_totals['mcq']['total'] += $exam['mcq_marks'];
                                        $component_totals['mcq']['obtained'] += $exam['student_mcq_marks'];
                                        $component_totals['mcq']['count']++;
                                    }
                                    
                                    if (in_array('project', $components) && !is_null($exam['student_project_marks'])) {
                                        $component_totals['project']['total'] += $exam['project_marks'];
                                        $component_totals['project']['obtained'] += $exam['student_project_marks'];
                                        $component_totals['project']['count']++;
                                    }
                                    
                                    if (in_array('viva', $components) && !is_null($exam['student_viva_marks'])) {
                                        $component_totals['viva']['total'] += $exam['viva_marks'];
                                        $component_totals['viva']['obtained'] += $exam['student_viva_marks'];
                                        $component_totals['viva']['count']++;
                                    }
                                }
                            }
                            ?>

                            <!-- MCQ Card -->
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-6 shadow-lg">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="text-lg font-bold text-blue-800">MCQ Performance</h4>
                                    <div class="bg-blue-500 p-3 rounded-xl">
                                        <i class="fas fa-list-ol text-white"></i>
                                    </div>
                                </div>
                                <?php if ($component_totals['mcq']['count'] > 0): ?>
                                    <?php $mcq_pct = ($component_totals['mcq']['obtained'] / $component_totals['mcq']['total']) * 100; ?>
                                    <div class="text-3xl font-bold text-blue-600 mb-2"><?= number_format($mcq_pct, 1) ?>%</div>
                                    <p class="text-sm text-blue-600 mb-3"><?= number_format($component_totals['mcq']['obtained'], 1) ?> / <?= number_format($component_totals['mcq']['total'], 1) ?> marks</p>
                                    <div class="w-full bg-white rounded-full h-2.5">
                                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?= $mcq_pct ?>%"></div>
                                    </div>
                                    <p class="text-xs text-blue-500 mt-2">From <?= $component_totals['mcq']['count'] ?> exams</p>
                                <?php else: ?>
                                    <p class="text-blue-500">No MCQ data</p>
                                <?php endif; ?>
                            </div>

                            <!-- Project Card -->
                            <div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-6 shadow-lg">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="text-lg font-bold text-green-800">Project Work</h4>
                                    <div class="bg-green-500 p-3 rounded-xl">
                                        <i class="fas fa-project-diagram text-white"></i>
                                    </div>
                                </div>
                                <?php if ($component_totals['project']['count'] > 0): ?>
                                    <?php $project_pct = ($component_totals['project']['obtained'] / $component_totals['project']['total']) * 100; ?>
                                    <div class="text-3xl font-bold text-green-600 mb-2"><?= number_format($project_pct, 1) ?>%</div>
                                    <p class="text-sm text-green-600 mb-3"><?= number_format($component_totals['project']['obtained'], 1) ?> / <?= number_format($component_totals['project']['total'], 1) ?> marks</p>
                                    <div class="w-full bg-white rounded-full h-2.5">
                                        <div class="bg-green-600 h-2.5 rounded-full" style="width: <?= $project_pct ?>%"></div>
                                    </div>
                                    <p class="text-xs text-green-500 mt-2">From <?= $component_totals['project']['count'] ?> exams</p>
                                <?php else: ?>
                                    <p class="text-green-500">No project data</p>
                                <?php endif; ?>
                            </div>

                            <!-- Viva Card -->
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-6 shadow-lg">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="text-lg font-bold text-purple-800">Viva Performance</h4>
                                    <div class="bg-purple-500 p-3 rounded-xl">
                                        <i class="fas fa-microphone text-white"></i>
                                    </div>
                                </div>
                                <?php if ($component_totals['viva']['count'] > 0): ?>
                                    <?php $viva_pct = ($component_totals['viva']['obtained'] / $component_totals['viva']['total']) * 100; ?>
                                    <div class="text-3xl font-bold text-purple-600 mb-2"><?= number_format($viva_pct, 1) ?>%</div>
                                    <p class="text-sm text-purple-600 mb-3"><?= number_format($component_totals['viva']['obtained'], 1) ?> / <?= number_format($component_totals['viva']['total'], 1) ?> marks</p>
                                    <div class="w-full bg-white rounded-full h-2.5">
                                        <div class="bg-purple-600 h-2.5 rounded-full" style="width: <?= $viva_pct ?>%"></div>
                                    </div>
                                    <p class="text-xs text-purple-500 mt-2">From <?= $component_totals['viva']['count'] ?> exams</p>
                                <?php else: ?>
                                    <p class="text-purple-500">No viva data</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Detailed Exam Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                     <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exam</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Components</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                                      </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($exam_data as $index => $row): ?>
                                        <?php 
                                        $percentage = ($row['score'] / $row['total_marks']) * 100;
                                        $grade_color = $percentage >= 80 ? 'green' : ($percentage >= 60 ? 'yellow' : 'red');
                                        $passed = $row['score'] >= $row['passing_marks'];
                                        ?>
                                    <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?> hover:bg-blue-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?= date('M d, Y', strtotime($row['date'])) ?>
                                         </tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-3 py-1 bg-indigo-100 text-indigo-600 rounded-full text-xs font-medium">
                                                <?= htmlspecialchars($row['batch_display'] ?? $row['batch_name'] ?? $row['batch_id'] ?? 'N/A') ?>
                                            </span>
                                         </tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($row['exam_name']) ?>
                                         </tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                            <?= htmlspecialchars($row['subject'] ?? 'N/A') ?>
                                         </tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-center">
                                                <span class="font-bold text-<?= $grade_color ?>-600 text-lg">
                                                    <?= $row['score'] ?>
                                                </span>
                                                <span class="text-gray-500 text-xs block">
                                                    /<?= $row['total_marks'] ?> (<?= number_format($percentage, 1) ?>%)
                                                </span>
                                            </div>
                                         </tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $passed ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                                <?= $row['grade'] ?? ($passed ? 'PASS' : 'FAIL') ?>
                                            </span>
                                         </tr>
                                        <td class="px-6 py-4 text-sm">
                                            <?php if (!empty($row['exam_components'])): ?>
                                                <div class="space-y-1">
                                                    <?php 
                                                    $components = explode(',', $row['exam_components']);
                                                    foreach ($components as $component): 
                                                        $marks_field = "student_{$component}_marks";
                                                        $total_field = "{$component}_marks";
                                                        if (!empty($row[$marks_field])): 
                                                    ?>
                                                        <div class="flex items-center text-xs">
                                                            <span class="capitalize w-16"><?= $component ?>:</span>
                                                            <span class="font-medium"><?= $row[$marks_field] ?>/<?= $row[$total_field] ?></span>
                                                        </div>
                                                    <?php endif; endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                         </tr>
                                        <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate">
                                            <?= htmlspecialchars($row['remarks'] ?? 'No remarks') ?>
                                         </tr>
                                      </tr>
                                    <?php endforeach; ?>
                                </tbody>
                              </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Feedback View -->
            <?php if ($report_view === 'feedback'): ?>
                <div class="p-6">
                    <?php if (empty($feedback_data)): ?>
                    <div class="text-center py-16">
                        <div class="bg-purple-100 w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-comment-alt text-purple-500 text-3xl"></i>
                        </div>
                        <p class="text-gray-500 text-lg">No feedback records found</p>
                        <p class="text-sm text-gray-400 mt-2">Try adjusting your filters</p>
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <?php foreach ($feedback_data as $feedback): ?>
                        <div class="bg-gradient-to-br from-purple-50 to-pink-50 rounded-xl p-6 shadow-lg hover:shadow-xl transition-all">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <div class="flex items-center mb-2">
                                        <span class="px-3 py-1 bg-purple-200 text-purple-700 rounded-full text-xs font-medium mr-2">
                                            <?= htmlspecialchars($feedback['batch_display'] ?? $feedback['batch_name'] ?? $feedback['batch_id'] ?? 'N/A') ?>
                                        </span>
                                        <span class="text-sm text-gray-500">
                                            <i class="far fa-calendar-alt mr-1"></i> <?= date('M d, Y', strtotime($feedback['date'])) ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-2xl font-bold text-purple-700 mr-2"><?= number_format($feedback['score'], 1) ?></span>
                                        <span class="text-gray-500">/5</span>
                                    </div>
                                </div>
                                <div class="bg-white p-3 rounded-xl shadow-sm">
                                    <i class="fas fa-star text-yellow-400 text-xl"></i>
                                </div>
                            </div>

                            <!-- Ratings Grid -->
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Class Rating</p>
                                    <div class="flex items-center">
                                        <div class="w-full bg-white rounded-full h-2 mr-2">
                                            <div class="bg-blue-500 h-2 rounded-full" style="width: <?= ($feedback['class_rating'] / 5) * 100 ?>%"></div>
                                        </div>
                                        <span class="text-sm font-medium"><?= $feedback['class_rating'] ?></span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Assignments</p>
                                    <div class="flex items-center">
                                        <div class="w-full bg-white rounded-full h-2 mr-2">
                                            <div class="bg-green-500 h-2 rounded-full" style="width: <?= ($feedback['assignment_understanding'] / 5) * 100 ?>%"></div>
                                        </div>
                                        <span class="text-sm font-medium"><?= $feedback['assignment_understanding'] ?></span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Practical</p>
                                    <div class="flex items-center">
                                        <div class="w-full bg-white rounded-full h-2 mr-2">
                                            <div class="bg-yellow-500 h-2 rounded-full" style="width: <?= ($feedback['practical_understanding'] / 5) * 100 ?>%"></div>
                                        </div>
                                        <span class="text-sm font-medium"><?= $feedback['practical_understanding'] ?></span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs text-gray-500 mb-1">Satisfaction</p>
                                    <div class="flex items-center">
                                        <div class="w-full bg-white rounded-full h-2 mr-2">
                                            <div class="bg-purple-500 h-2 rounded-full" style="width: <?= ($feedback['satisfied'] / 5) * 100 ?>%"></div>
                                        </div>
                                        <span class="text-sm font-medium"><?= $feedback['satisfied'] ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Feedback Text -->
                            <?php if (!empty($feedback['suggestions']) || !empty($feedback['feedback_text'])): ?>
                            <div class="bg-white bg-opacity-50 rounded-lg p-4 mt-2">
                                <?php if (!empty($feedback['suggestions'])): ?>
                                <p class="text-sm text-gray-700 mb-2">
                                    <span class="font-medium">Suggestions:</span> <?= htmlspecialchars($feedback['suggestions']) ?>
                                </p>
                                <?php endif; ?>
                                <?php if (!empty($feedback['feedback_text'])): ?>
                                <p class="text-sm text-gray-700">
                                    <span class="font-medium">Comments:</span> <?= htmlspecialchars($feedback['feedback_text']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <div class="mt-4 flex items-center">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?= $feedback['is_regular'] === 'Yes' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                    <i class="fas fa-<?= $feedback['is_regular'] === 'Yes' ? 'check' : 'times' ?>-circle mr-1"></i>
                                    Regular: <?= $feedback['is_regular'] ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Overview Data Table (shown in overview view) -->
            <?php if ($report_view === 'overview'): ?>
                <div class="overflow-x-auto border-t border-gray-200">
                    <div class="p-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-700 flex items-center">
                            <i class="fas fa-table text-blue-500 mr-2"></i>
                            Detailed Records
                        </h3>
                        <span class="text-sm text-gray-500"><?= count($performance_data) ?> entries found</span>
                    </div>
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                             <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                             </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($performance_data)): ?>
                                 <tr>
                                    <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                                        <div class="flex flex-col items-center">
                                            <i class="fas fa-database text-4xl text-gray-300 mb-3"></i>
                                            <p class="text-lg">No performance records found</p>
                                            <p class="text-sm text-gray-400 mt-1">Try selecting a different student or adjusting the filters</p>
                                        </div>
                                     </td>
                                 </tr>
                            <?php else: ?>
                                <?php foreach ($performance_data as $index => $row): ?>
                                <tr class="<?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?> hover:bg-blue-50 transition-colors">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= date('M d, Y', strtotime($row['date'])) ?>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-3 py-1 bg-blue-100 text-blue-600 rounded-full text-xs font-medium">
                                            <?= htmlspecialchars($row['batch_display'] ?? $row['batch_name'] ?? $row['batch_id'] ?? 'N/A') ?>
                                        </span>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium 
                                            <?= $row['type'] === 'Attendance' ? 'bg-green-100 text-green-800' : 
                                               ($row['type'] === 'Exam' ? 'bg-indigo-100 text-indigo-800' : 
                                               'bg-purple-100 text-purple-800') ?>">
                                            <i class="fas fa-<?= $row['type'] === 'Attendance' ? 'calendar-check' : 
                                               ($row['type'] === 'Exam' ? 'pen' : 'comment') ?> mr-1"></i>
                                            <?= $row['type'] ?>
                                        </span>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if ($row['type'] === 'Attendance'): ?>
                                            <span class="text-gray-400">-</span>
                                        <?php elseif ($row['type'] === 'Exam'): ?>
                                            <span class="font-medium <?= $row['score'] >= 80 ? 'text-green-600' : ($row['score'] >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                <?= $row['score'] ?>%
                                            </span>
                                        <?php else: ?>
                                            <span class="font-medium text-purple-600">
                                                <?= number_format($row['score'], 1) ?>/5
                                            </span>
                                        <?php endif; ?>
                                     </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium 
                                            <?= in_array($row['status'], ['Present', 'Completed', 'Submitted']) ? 'bg-green-100 text-green-800' : 
                                               ($row['status'] === 'Absent' ? 'bg-red-100 text-red-800' : 
                                               'bg-yellow-100 text-yellow-800') ?>">
                                            <?= $row['status'] ?>
                                        </span>
                                     </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 max-w-xs truncate">
                                        <?= htmlspecialchars($row['remarks'] ?? 'No remarks') ?>
                                     </td>
                                 </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- No Student Selected State -->
        <div class="bg-white rounded-2xl shadow-xl p-12 text-center animate-fade-in">
            <div class="bg-gradient-to-r from-blue-100 to-indigo-100 w-32 h-32 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-chart-line text-blue-600 text-5xl"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 mb-3">Select a Student to View Performance</h2>
            <p class="text-gray-500 max-w-md mx-auto mb-8">
                Start typing a student name in the search box above to find and select a student for comprehensive performance analysis.
            </p>
            <div class="flex justify-center space-x-4">
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                    Multi-batch support
                </div>
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                    Historical data
                </div>
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                    Interactive charts
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Student Search Functionality
const searchInput = document.getElementById('studentSearchInput');
const studentDropdown = document.getElementById('studentDropdown');
const studentList = document.getElementById('studentList');
const selectedStudentId = document.getElementById('selectedStudentId');
const searchClearIcon = document.getElementById('searchClearIcon');
const reportForm = document.getElementById('reportForm');

// All student options data
const studentOptions = Array.from(document.querySelectorAll('.student-option'));

// Function to filter students based on search term
function filterStudents(searchTerm) {
    const term = searchTerm.toLowerCase().trim();
    let hasVisible = false;
    
    studentOptions.forEach(option => {
        const studentName = option.dataset.studentName;
        if (studentName.includes(term) || term === '') {
            option.style.display = 'flex';
            hasVisible = true;
        } else {
            option.style.display = 'none';
        }
    });
    
    // Show "No results" message if needed
    let noResultsMsg = document.getElementById('noResultsMsg');
    if (!hasVisible && term !== '') {
        if (!noResultsMsg) {
            noResultsMsg = document.createElement('div');
            noResultsMsg.id = 'noResultsMsg';
            noResultsMsg.className = 'px-4 py-6 text-center text-gray-500';
            noResultsMsg.innerHTML = '<i class="fas fa-user-slash mr-2"></i>No students found matching "' + searchTerm + '"';
            studentList.appendChild(noResultsMsg);
        }
    } else if (noResultsMsg) {
        noResultsMsg.remove();
    }
}

// Show dropdown when input is focused or has content
searchInput.addEventListener('focus', () => {
    filterStudents(searchInput.value);
    studentDropdown.classList.remove('hidden');
});

// Filter as user types
searchInput.addEventListener('input', () => {
    const value = searchInput.value;
    filterStudents(value);
    studentDropdown.classList.remove('hidden');
    
    // Show/hide clear icon
    if (value.length > 0) {
        searchClearIcon.classList.remove('hidden');
    } else {
        searchClearIcon.classList.add('hidden');
    }
});

// Clear search input
searchClearIcon.addEventListener('click', () => {
    searchInput.value = '';
    filterStudents('');
    searchClearIcon.classList.add('hidden');
    searchInput.focus();
});

// Handle student selection
studentOptions.forEach(option => {
    option.addEventListener('click', () => {
        const studentId = option.dataset.studentId;
        const studentName = option.dataset.studentName;
        
        // Set the hidden input value
        selectedStudentId.value = studentId;
        
        // Update search input with selected student name (capitalized)
        const capitalizedName = studentName.split(' ').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
        searchInput.value = capitalizedName;
        
        // Hide dropdown
        studentDropdown.classList.add('hidden');
        
        // Auto-submit the form to load report
        reportForm.submit();
    });
});

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    if (!searchInput.contains(event.target) && !studentDropdown.contains(event.target)) {
        studentDropdown.classList.add('hidden');
    }
});

// Prevent dropdown close when clicking inside dropdown
studentDropdown.addEventListener('click', function(event) {
    event.stopPropagation();
});

// Toggle advanced filters
function toggleFilters() {
    const filters = document.getElementById('advancedFilters');
    const icon = document.getElementById('filterToggleIcon');
    if (filters.classList.contains('hidden')) {
        filters.classList.remove('hidden');
        icon.classList.add('rotate-180');
    } else {
        filters.classList.add('hidden');
        icon.classList.remove('rotate-180');
    }
}

// Initialize charts when the page loads
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($chart_data)): ?>
        <?php if (!empty($chart_data['attendance'])): ?>
        // Attendance Pie Chart
        const attendanceCtx = document.getElementById('attendanceChart');
        if (attendanceCtx) {
            new Chart(attendanceCtx, {
                type: 'doughnut',
                data: {
                    labels: <?= json_encode($chart_data['attendance']['labels']) ?>,
                    datasets: [{
                        data: <?= json_encode($chart_data['attendance']['data']) ?>,
                        backgroundColor: <?= json_encode($chart_data['attendance']['colors']) ?>,
                        borderWidth: 0,
                        hoverOffset: 10
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { usePointStyle: true, padding: 20 }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        <?php if (!empty($chart_data['monthly_attendance'])): ?>
        // Monthly Attendance Bar Chart
        const monthlyAttendanceCtx = document.getElementById('monthlyAttendanceChart');
        if (monthlyAttendanceCtx) {
            new Chart(monthlyAttendanceCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_data['monthly_attendance']['labels']) ?>,
                    datasets: <?= json_encode($chart_data['monthly_attendance']['datasets']) ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { display: false } },
                        x: { 
                            grid: { display: false },
                            ticks: {
                                // Horizontal labels - no rotation
                                maxRotation: 0,
                                minRotation: 0
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        <?php if (!empty($chart_data['exams'])): ?>
        // Individual Exam Scores Bar Chart - HORIZONTAL LABELS
        const examsCtx = document.getElementById('examsChart');
        if (examsCtx) {
            new Chart(examsCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_data['exams']['labels']) ?>,
                    datasets: [{
                        label: 'Exam Score (%)',
                        data: <?= json_encode($chart_data['exams']['data']) ?>,
                        backgroundColor: <?= json_encode($chart_data['exams']['colors']) ?> + '80',
                        borderColor: <?= json_encode($chart_data['exams']['colors'][0]) ?>,
                        borderWidth: 2,
                        borderRadius: 8,
                        barPercentage: 0.7,
                        categoryPercentage: 0.8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.raw + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            max: 100,
                            title: {
                                display: true,
                                text: 'Score (%)',
                                color: '#6b7280'
                            },
                            grid: { display: false }
                        },
                        x: { 
                            grid: { display: false },
                            ticks: {
                                // HORIZONTAL LABELS - no rotation, auto-skip if needed
                                maxRotation: 0,
                                minRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 10
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        <?php if (!empty($chart_data['batch_comparison'])): ?>
        // Batch Comparison Chart
        const batchComparisonCtx = document.getElementById('batchComparisonChart');
        if (batchComparisonCtx) {
            new Chart(batchComparisonCtx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($chart_data['batch_comparison']['labels']) ?>,
                    datasets: <?= json_encode($chart_data['batch_comparison']['datasets']) ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true },
                        x: {
                            ticks: {
                                maxRotation: 0,
                                minRotation: 0,
                                autoSkip: true
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>

        <?php if (!empty($chart_data['feedback_details'])): ?>
        // Feedback Details Chart
        const feedbackDetailsCtx = document.getElementById('feedbackDetailsChart');
        if (feedbackDetailsCtx) {
            new Chart(feedbackDetailsCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($chart_data['feedback_details']['labels']) ?>,
                    datasets: <?= json_encode($chart_data['feedback_details']['datasets']) ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true, max: 5 },
                        x: {
                            ticks: {
                                maxRotation: 0,
                                minRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 8
                            }
                        }
                    }
                }
            });
        }
        <?php endif; ?>
    <?php endif; ?>
});

// Export functions
function exportToPDF() {
    alert('PDF export would generate a comprehensive report including all charts and tables.');
    // In production, this would call a backend endpoint or use a library like jsPDF
}

function exportToExcel() {
    alert('Excel export would download all performance data in spreadsheet format.');
    // In production, this would generate an Excel file with multiple sheets
}

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(el => el.remove());
}, 5000);
</script>

<style>
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.animate-fade-in {
    animation: fadeIn 0.5s ease-out forwards;
}

.animate-slide-up {
    animation: slideUp 0.6s ease-out forwards;
}

.rotate-180 {
    transform: rotate(180deg);
}

/* Custom scrollbar */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 10px;
}

::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Print styles */
@media print {
    .ml-64 { margin-left: 0 !important; }
    .p-8 { padding: 1rem !important; }
    button, .no-print { display: none !important; }
    .shadow-xl { box-shadow: none !important; }
    .bg-gradient-to-r { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<?php if (!empty($student_id) && !empty($student_data)): ?>
<!-- ===== DETAIL MODALS ===== -->
<style>
.rpt-modal-backdrop {
    position:fixed; inset:0;
    background:rgba(0,0,0,.5);
    backdrop-filter:blur(4px);
    z-index:1050;
    display:none;
    align-items:center;
    justify-content:center;
    animation:rptFadeIn .2s ease;
}
.rpt-modal-backdrop.active { display:flex; }
@keyframes rptFadeIn { from{opacity:0} to{opacity:1} }
.rpt-modal-box {
    background:white;
    border-radius:24px;
    overflow:hidden;
    width:90%; max-width:880px;
    max-height:85vh;
    display:flex; flex-direction:column;
    box-shadow:0 30px 60px rgba(0,0,0,.25);
    animation:rptSlideUp .3s cubic-bezier(.4,0,.2,1);
}
@keyframes rptSlideUp { from{transform:translateY(40px);opacity:0} to{transform:translateY(0);opacity:1} }
.rpt-modal-header {
    padding:22px 28px;
    color:white;
    display:flex; align-items:center; justify-content:space-between;
    flex-shrink:0;
}
.rpt-modal-hero { font-size:3rem; font-weight:900; line-height:1; text-shadow:0 2px 8px rgba(0,0,0,.2); }
.rpt-modal-body {
    padding:22px 28px;
    overflow-y:auto;
    background:#f8fafc;
    flex:1;
}
.rpt-modal-body::-webkit-scrollbar { width:6px; }
.rpt-modal-body::-webkit-scrollbar-thumb { background:#cbd5e0; border-radius:3px; }
.rpt-tbl { width:100%; border-collapse:collapse; font-size:.87rem; }
.rpt-tbl thead th {
    background:rgba(99,102,241,.08);
    font-weight:700; font-size:.75rem;
    text-transform:uppercase; letter-spacing:.8px;
    color:#4a5568; padding:11px 14px; border:none;
}
.rpt-tbl tbody tr { border-bottom:1px solid #edf2f7; transition:background .2s; }
.rpt-tbl tbody tr:hover { background:#eef2ff; }
.rpt-tbl td { padding:10px 14px; vertical-align:middle; border:none; }
.rpt-search {
    width:100%; border:2px solid #e2e8f0;
    border-radius:12px; padding:8px 14px;
    font-size:.9rem; margin-bottom:14px;
    transition:border-color .2s; outline:none;
}
.rpt-search:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
.rpt-close-btn {
    background:rgba(255,255,255,.2);
    border:none; color:white;
    width:36px; height:36px;
    border-radius:50%; font-size:1.2rem;
    cursor:pointer; display:flex;
    align-items:center; justify-content:center;
    transition:background .2s;
}
.rpt-close-btn:hover { background:rgba(255,255,255,.35); }
</style>

<!-- Attendance Detail Modal -->
<div class="rpt-modal-backdrop" id="rptAttendanceModal" onclick="closeRptModal('rptAttendanceModal',event)">
  <div class="rpt-modal-box">
    <div class="rpt-modal-header" style="background:linear-gradient(135deg,#3b82f6,#4f46e5)">
      <div class="d-flex align-items-center gap-3">
        <div class="rpt-modal-hero"><?= $summary_data['total_attendance'] ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-calendar-check me-2"></i>Attendance Records</h4>
          <small style="opacity:.8">Full attendance history &bull; <?= $summary_data['present_percentage'] ?>% present rate</small>
        </div>
      </div>
      <button class="rpt-close-btn" onclick="closeRptModal('rptAttendanceModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="rpt-modal-body">
      <input class="rpt-search" placeholder="🔍 Search by date, status or batch..." onkeyup="rptFilter(this,'rptAttTbl')">
      <?php if(empty($attendance_data)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-calendar-times fa-3x mb-3"></i><p>No attendance records found.</p></div>
      <?php else: ?>
      <table class="rpt-tbl" id="rptAttTbl">
        <thead><tr><th>#</th><th>Date</th><th>Status</th><th>Batch</th><th>Remarks</th></tr></thead>
        <tbody>
        <?php foreach($attendance_data as $i=>$a): ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= date('d M Y', strtotime($a['date'])) ?></td>
          <td>
            <?php if($a['status']==='Present'): ?>
              <span style="background:#dcfce7;color:#16a34a;padding:2px 10px;border-radius:20px;font-weight:600;font-size:.8rem;"><i class="fas fa-check me-1"></i>Present</span>
            <?php else: ?>
              <span style="background:#fee2e2;color:#dc2626;padding:2px 10px;border-radius:20px;font-weight:600;font-size:.8rem;"><i class="fas fa-times me-1"></i>Absent</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($a['batch_name'] ?? '-') ?></td>
          <td style="color:#64748b"><?= htmlspecialchars($a['remarks'] ?? '-') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Exams Detail Modal -->
<div class="rpt-modal-backdrop" id="rptExamsModal" onclick="closeRptModal('rptExamsModal',event)">
  <div class="rpt-modal-box">
    <div class="rpt-modal-header" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
      <div class="d-flex align-items-center gap-3">
        <div class="rpt-modal-hero"><?= $summary_data['total_exams'] ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-graduation-cap me-2"></i>Exam Performance</h4>
          <small style="opacity:.8">All exams &bull; Avg score: <?= $summary_data['average_exam_score'] ?></small>
        </div>
      </div>
      <button class="rpt-close-btn" onclick="closeRptModal('rptExamsModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="rpt-modal-body">
      <input class="rpt-search" placeholder="🔍 Search by exam name, subject or batch..." onkeyup="rptFilter(this,'rptExamTbl')">
      <?php if(empty($exam_data)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-file-alt fa-3x mb-3"></i><p>No exam records found.</p></div>
      <?php else: ?>
      <table class="rpt-tbl" id="rptExamTbl">
        <thead><tr><th>#</th><th>Date</th><th>Exam</th><th>Subject</th><th>Score</th><th>Total</th><th>Grade</th><th>Batch</th></tr></thead>
        <tbody>
        <?php foreach($exam_data as $i=>$e):
            $pct = $e['total_marks']>0 ? round(($e['score']/$e['total_marks'])*100) : 0;
            $passed = $e['score'] >= ($e['passing_marks']??0);
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= date('d M Y', strtotime($e['date'])) ?></td>
          <td><strong><?= htmlspecialchars($e['exam_name']??'Exam') ?></strong></td>
          <td style="color:#64748b"><?= htmlspecialchars($e['subject']??'-') ?></td>
          <td>
            <span style="font-weight:700;color:<?= $passed?'#16a34a':'#dc2626' ?>"><?= $e['score'] ?></span>
            <span style="font-size:.75rem;color:#94a3b8;"> (<?= $pct ?>%)</span>
          </td>
          <td><?= $e['total_marks'] ?></td>
          <td>
            <?php if($e['grade']): ?>
              <span style="background:#eef2ff;color:#4f46e5;padding:2px 8px;border-radius:20px;font-weight:600;font-size:.8rem;"><?= htmlspecialchars($e['grade']) ?></span>
            <?php else: ?>-<?php endif; ?>
          </td>
          <td style="color:#64748b"><?= htmlspecialchars($e['batch_name']??'-') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Feedback Detail Modal -->
<div class="rpt-modal-backdrop" id="rptFeedbackModal" onclick="closeRptModal('rptFeedbackModal',event)">
  <div class="rpt-modal-box">
    <div class="rpt-modal-header" style="background:linear-gradient(135deg,#ec4899,#a855f7)">
      <div class="d-flex align-items-center gap-3">
        <div class="rpt-modal-hero"><?= $summary_data['total_feedback'] ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-star me-2"></i>Feedback Records</h4>
          <small style="opacity:.8">All submissions &bull; Avg rating: <?= $summary_data['average_feedback_rating'] ?>/5</small>
        </div>
      </div>
      <button class="rpt-close-btn" onclick="closeRptModal('rptFeedbackModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="rpt-modal-body">
      <input class="rpt-search" placeholder="🔍 Search by date or batch..." onkeyup="rptFilter(this,'rptFbTbl')">
      <?php if(empty($feedback_data)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-comment-slash fa-3x mb-3"></i><p>No feedback records found.</p></div>
      <?php else: ?>
      <table class="rpt-tbl" id="rptFbTbl">
        <thead><tr><th>#</th><th>Date</th><th>Class</th><th>Assignment</th><th>Practical</th><th>Avg</th><th>Batch</th></tr></thead>
        <tbody>
        <?php foreach($feedback_data as $i=>$f):
            $avg = round(($f['class_rating']+$f['assignment_understanding']+$f['practical_understanding'])/3,1);
            $stars = str_repeat('★',(int)round($avg)).str_repeat('☆',5-(int)round($avg));
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= date('d M Y', strtotime($f['date'])) ?></td>
          <td style="text-align:center"><?= $f['class_rating'] ?>/5</td>
          <td style="text-align:center"><?= $f['assignment_understanding'] ?>/5</td>
          <td style="text-align:center"><?= $f['practical_understanding'] ?>/5</td>
          <td><strong style="color:#a855f7"><?= $avg ?></strong> <span style="color:#f59e0b;font-size:.8rem"><?= $stars ?></span></td>
          <td style="color:#64748b"><?= htmlspecialchars($f['batch_name']??'-') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Batches Detail Modal -->
<div class="rpt-modal-backdrop" id="rptBatchesModal" onclick="closeRptModal('rptBatchesModal',event)">
  <div class="rpt-modal-box">
    <div class="rpt-modal-header" style="background:linear-gradient(135deg,#14b8a6,#3b82f6)">
      <div class="d-flex align-items-center gap-3">
        <div class="rpt-modal-hero"><?= $summary_data['batches_attended'] ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-layer-group me-2"></i>Batches Attended</h4>
          <small style="opacity:.8">All batches including transfer history</small>
        </div>
      </div>
      <button class="rpt-close-btn" onclick="closeRptModal('rptBatchesModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="rpt-modal-body">
      <?php
        $batch_ids_all = array_unique(array_filter([
            $student_data['batch_name'],$student_data['batch_name_2'],
            $student_data['batch_name_3'],$student_data['batch_name_4']
        ]));
        // Get batch details
        $batch_detail_list = [];
        if(!empty($batch_ids_all)){
            $ph = implode(',',array_fill(0,count($batch_ids_all),'?'));
            $bq = $db->prepare("SELECT b.batch_id,b.batch_name,b.status,b.start_date,b.end_date,b.mode,b.current_enrollment,b.max_students,u.name as mentor FROM batches b LEFT JOIN trainers u ON b.batch_mentor_id=u.id WHERE b.batch_id IN ($ph)");
            $bq->execute(array_values($batch_ids_all));
            $batch_detail_list = $bq->fetchAll(PDO::FETCH_ASSOC);
        }
      ?>
      <?php if(empty($batch_ids_all)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-layer-group fa-3x mb-3"></i><p>No batch records found.</p></div>
      <?php else: ?>
      <table class="rpt-tbl">
        <thead><tr><th>#</th><th>Batch ID</th><th>Batch Name</th><th>Status</th><th>Mode</th><th>Mentor</th><th>Start</th><th>End</th></tr></thead>
        <tbody>
        <?php foreach($batch_detail_list as $i=>$bd):
            $sc = $bd['status']==='ongoing'?'#16a34a':($bd['status']==='upcoming'?'#d97706':'#64748b');
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= htmlspecialchars($bd['batch_id']) ?></strong></td>
          <td><?= htmlspecialchars($bd['batch_name']) ?></td>
          <td><span style="background:<?= $sc ?>22;color:<?= $sc ?>;padding:2px 10px;border-radius:20px;font-weight:600;font-size:.8rem;"><?= ucfirst($bd['status']) ?></span></td>
          <td><?= ucfirst($bd['mode']??'-') ?></td>
          <td style="color:#64748b"><?= htmlspecialchars($bd['mentor']??'Unassigned') ?></td>
          <td><?= $bd['start_date'] ? date('d M Y',strtotime($bd['start_date'])) : '-' ?></td>
          <td><?= $bd['end_date']   ? date('d M Y',strtotime($bd['end_date']))   : '-' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(!empty($batch_history)): ?>
        <tr><td colspan="8" style="background:#f1f5f9;font-weight:700;color:#475569;padding:10px 14px;"><i class="fas fa-exchange-alt me-2"></i>Transfer History</td></tr>
        <?php foreach($batch_history as $h): ?>
        <tr style="background:#fffbeb;">
          <td>—</td>
          <td colspan="2" style="color:#92400e;"><i class="fas fa-arrow-right me-1"></i><?= htmlspecialchars($h['from_batch_name']??$h['from_batch_id']) ?> → <?= htmlspecialchars($h['to_batch_name']??$h['to_batch_id']) ?></td>
          <td colspan="2"><span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:20px;font-size:.8rem;">Transferred</span></td>
          <td colspan="2" style="color:#64748b"><?= date('d M Y',strtotime($h['transfer_date'])) ?></td>
          <td style="color:#64748b"><?= htmlspecialchars($h['transferred_by_name']??'System') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function openRptModal(id){
    document.getElementById(id).classList.add('active');
    document.body.style.overflow='hidden';
}
function closeRptModal(id,e){
    if(e && e.target!==document.getElementById(id)) return;
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow='';
}
function rptFilter(inp,tblId){
    const q=inp.value.toLowerCase();
    document.querySelectorAll('#'+tblId+' tbody tr').forEach(tr=>{
        tr.style.display=tr.textContent.toLowerCase().includes(q)?'':'none';
    });
}
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
        document.querySelectorAll('.rpt-modal-backdrop.active').forEach(m=>{
            m.classList.remove('active');
            document.body.style.overflow='';
        });
    }
});
</script>
<?php endif; ?>