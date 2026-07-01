<?php
session_start();
require_once '../db_connection.php';
require_once '../header.php';
require_once '../sidebar.php';

// Check admin permissions
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get active report type
$report_type = $_GET['report'] ?? 'student_performance';
$batch_filter = $_GET['batch_id'] ?? '';
$student_filter = $_GET['student_id'] ?? '';
$exam_type_filter = $_GET['exam_type'] ?? '';
$subject_filter = $_GET['subject'] ?? '';

// Get available batches, students, exam types, and subjects for filters
$batches = $db->query("SELECT batch_id, batch_name FROM batches ORDER BY batch_name")->fetchAll(PDO::FETCH_ASSOC);
$students = $db->query("SELECT student_id, CONCAT(first_name, ' ', last_name) as student_name FROM students WHERE current_status = 'active' ORDER BY student_name")->fetchAll(PDO::FETCH_ASSOC);
$exam_types = $db->query("SELECT DISTINCT exam_type FROM exams ORDER BY exam_type")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $db->query("SELECT DISTINCT subject FROM exams ORDER BY subject")->fetchAll(PDO::FETCH_ASSOC);

// Initialize report data
$report_data = [];

// Student Performance Report
if ($report_type === 'student_performance') {
    $query = "
        SELECT 
            s.student_id,
            CONCAT(s.first_name, ' ', s.last_name) as student_name,
            b.batch_name,
            e.exam_name,
            e.subject,
            e.exam_type,
            er.obtained_marks,
            e.total_marks,
            er.grade,
            (er.obtained_marks / e.total_marks) * 100 as percentage,
            e.exam_date
        FROM exam_results er
        JOIN students s ON er.student_id = s.student_id
        JOIN exams e ON er.exam_id = e.exam_id
        JOIN batches b ON e.batch_id = b.batch_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($student_filter)) {
        $query .= " AND s.student_id = ?";
        $params[] = $student_filter;
    }
    
    if (!empty($batch_filter)) {
        $query .= " AND b.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " ORDER BY s.student_id, e.exam_date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Batch Performance Analysis
elseif ($report_type === 'batch_performance') {
    $query = "
        SELECT 
            b.batch_id,
            b.batch_name,
            e.subject,
            COUNT(DISTINCT er.student_id) as total_students,
            AVG(er.obtained_marks) as avg_marks,
            AVG((er.obtained_marks / e.total_marks) * 100) as avg_percentage,
            SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN er.obtained_marks < e.passing_marks THEN 1 ELSE 0 END) as failed,
            MAX(er.obtained_marks) as highest_marks,
            MIN(er.obtained_marks) as lowest_marks
        FROM batches b
        JOIN exams e ON b.batch_id = e.batch_id
        JOIN exam_results er ON e.exam_id = er.exam_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND b.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY b.batch_id, b.batch_name, e.subject ORDER BY b.batch_name, e.subject";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Exam Type Comparison
elseif ($report_type === 'exam_type_comparison') {
    $query = "
        SELECT 
            e.exam_type,
            e.subject,
            COUNT(DISTINCT e.exam_id) as total_exams,
            COUNT(DISTINCT er.student_id) as total_students,
            AVG(er.obtained_marks) as avg_marks,
            AVG((er.obtained_marks / e.total_marks) * 100) as avg_percentage,
            SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN er.obtained_marks < e.passing_marks THEN 1 ELSE 0 END) as failed
        FROM exams e
        JOIN exam_results er ON e.exam_id = er.exam_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY e.exam_type, e.subject ORDER BY e.exam_type, e.subject";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Exam Results Summary
elseif ($report_type === 'exam_results_summary') {
    $query = "
        SELECT 
            e.subject,
            COUNT(DISTINCT e.exam_id) as total_exams,
            COUNT(DISTINCT er.student_id) as total_students,
            AVG(er.obtained_marks) as avg_marks,
            AVG((er.obtained_marks / e.total_marks) * 100) as avg_percentage,
            SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN er.obtained_marks < e.passing_marks THEN 1 ELSE 0 END) as failed,
            (SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) / COUNT(*)) * 100 as pass_percentage
        FROM exams e
        JOIN exam_results er ON e.exam_id = er.exam_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY e.subject ORDER BY e.subject";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Component-wise Analysis
elseif ($report_type === 'component_analysis') {
    $query = "
        SELECT 
            e.exam_id,
            e.exam_name,
            e.subject,
            e.exam_type,
            e.mcq_marks,
            e.project_marks,
            e.viva_marks,
            AVG(er.mcq_marks) as avg_mcq_marks,
            AVG(er.project_marks) as avg_project_marks,
            AVG(er.viva_marks) as avg_viva_marks,
            COUNT(er.student_id) as total_students
        FROM exams e
        LEFT JOIN exam_results er ON e.exam_id = er.exam_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY e.exam_id, e.exam_name, e.subject, e.exam_type, e.mcq_marks, e.project_marks, e.viva_marks ORDER BY e.exam_date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Exam Enrollment Report
elseif ($report_type === 'enrollment_report') {
    $query = "
        SELECT 
            e.exam_id,
            e.exam_name,
            e.subject,
            e.exam_type,
            b.batch_name,
            COUNT(ee.student_id) as enrolled_students,
            COUNT(er.student_id) as appeared_students,
            COUNT(ee.student_id) - COUNT(er.student_id) as not_appeared,
            e.exam_date
        FROM exams e
        JOIN batches b ON e.batch_id = b.batch_id
        LEFT JOIN exam_enrollments ee ON e.exam_id = ee.exam_id
        LEFT JOIN exam_results er ON e.exam_id = er.exam_id AND ee.student_id = er.student_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY e.exam_id, e.exam_name, e.subject, e.exam_type, b.batch_name, e.exam_date ORDER BY e.exam_date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Malpractice & Monitoring
elseif ($report_type === 'malpractice_report') {
    $query = "
        SELECT 
            pe.exam_id,
            e.exam_name,
            b.batch_name,
            pe.exam_date,
            pe.mode,
            pe.proctor_name,
            pe.malpractice_cases,
            COUNT(es.student_id) as total_students,
            SUM(es.is_malpractice) as malpractice_students
        FROM proctored_exams pe
        JOIN exams e ON pe.exam_id = e.exam_id
        JOIN batches b ON pe.batch_id = b.batch_id
        LEFT JOIN exam_students es ON pe.exam_id = es.exam_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND pe.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    $query .= " GROUP BY pe.exam_id, e.exam_name, b.batch_name, pe.exam_date, pe.mode, pe.proctor_name, pe.malpractice_cases ORDER BY pe.exam_date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Exam Schedule & Planning
elseif ($report_type === 'exam_schedule') {
    $query = "
        SELECT 
            e.exam_id,
            e.exam_name,
            e.subject,
            e.exam_type,
            b.batch_name,
            e.exam_date,
            e.total_marks,
            e.passing_marks,
            u.name as created_by,
            COUNT(ee.student_id) as enrolled_students
        FROM exams e
        JOIN batches b ON e.batch_id = b.batch_id
        JOIN users u ON e.created_by = u.id
        LEFT JOIN exam_enrollments ee ON e.exam_id = ee.exam_id
        WHERE e.exam_date >= CURDATE()
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY e.exam_id, e.exam_name, e.subject, e.exam_type, b.batch_name, e.exam_date, e.total_marks, e.passing_marks, u.name ORDER BY e.exam_date ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Teacher/Evaluator Reports
elseif ($report_type === 'teacher_reports') {
    $query = "
        SELECT 
            u.id as teacher_id,
            u.name as teacher_name,
            COUNT(DISTINCT e.exam_id) as total_exams_created,
            COUNT(DISTINCT er.exam_id) as exams_with_results,
            COUNT(er.id) as total_results_uploaded,
            AVG(TIMESTAMPDIFF(HOUR, e.exam_date, er.uploaded_at)) as avg_grading_time_hours,
            MIN(e.exam_date) as first_exam_date,
            MAX(e.exam_date) as latest_exam_date
        FROM users u
        LEFT JOIN exams e ON u.id = e.created_by
        LEFT JOIN exam_results er ON e.exam_id = er.exam_id AND er.uploaded_by = u.id
        WHERE u.role = 'mentor'
        GROUP BY u.id, u.name
        ORDER BY total_exams_created DESC
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute();
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Trend Analysis
elseif ($report_type === 'trend_analysis') {
    $query = "
        SELECT 
            DATE_FORMAT(e.exam_date, '%Y-%m') as exam_month,
            e.subject,
            e.exam_type,
            COUNT(DISTINCT e.exam_id) as total_exams,
            COUNT(DISTINCT er.student_id) as total_students,
            AVG(er.obtained_marks) as avg_marks,
            AVG((er.obtained_marks / e.total_marks) * 100) as avg_percentage,
            (SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) / COUNT(*)) * 100 as pass_percentage
        FROM exams e
        JOIN exam_results er ON e.exam_id = er.exam_id
        WHERE e.exam_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY DATE_FORMAT(e.exam_date, '%Y-%m'), e.subject, e.exam_type ORDER BY exam_month DESC, e.subject";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Gap Analysis
elseif ($report_type === 'gap_analysis') {
    $query = "
        SELECT 
            e.subject,
            b.batch_name,
            AVG(er.obtained_marks) as batch_avg_marks,
            AVG((er.obtained_marks / e.total_marks) * 100) as batch_avg_percentage,
            (SELECT AVG(er2.obtained_marks) 
             FROM exam_results er2 
             JOIN exams e2 ON er2.exam_id = e2.exam_id 
             WHERE e2.subject = e.subject) as overall_avg_marks,
            COUNT(DISTINCT CASE WHEN er.obtained_marks < e.passing_marks THEN er.student_id END) as struggling_students,
            COUNT(DISTINCT er.student_id) as total_students
        FROM exams e
        JOIN batches b ON e.batch_id = b.batch_id
        JOIN exam_results er ON e.exam_id = er.exam_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY e.subject, b.batch_name ORDER BY e.subject, batch_avg_percentage ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Exam Audit Trail
elseif ($report_type === 'exam_audit') {
    $query = "
        SELECT 
            e.exam_id,
            e.exam_name,
            e.subject,
            e.exam_type,
            b.batch_name,
            e.exam_date,
            u_created.name as created_by,
            e.created_at,
            u_updated.name as last_updated_by,
            e.updated_at,
            COUNT(DISTINCT er.student_id) as students_with_results,
            COUNT(DISTINCT ee.student_id) as enrolled_students
        FROM exams e
        JOIN batches b ON e.batch_id = b.batch_id
        JOIN users u_created ON e.created_by = u_created.id
        LEFT JOIN users u_updated ON e.updated_by = u_updated.id
        LEFT JOIN exam_results er ON e.exam_id = er.exam_id
        LEFT JOIN exam_enrollments ee ON e.exam_id = ee.exam_id
        WHERE 1=1
    ";
    
    $params = [];
    
    if (!empty($batch_filter)) {
        $query .= " AND e.batch_id = ?";
        $params[] = $batch_filter;
    }
    
    if (!empty($exam_type_filter)) {
        $query .= " AND e.exam_type = ?";
        $params[] = $exam_type_filter;
    }
    
    if (!empty($subject_filter)) {
        $query .= " AND e.subject = ?";
        $params[] = $subject_filter;
    }
    
    $query .= " GROUP BY e.exam_id, e.exam_name, e.subject, e.exam_type, b.batch_name, e.exam_date, u_created.name, e.created_at, u_updated.name, e.updated_at ORDER BY e.created_at DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

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
</style>

<div class="ml-64 p-8 transition-all duration-300 min-h-screen" style="background: linear-gradient(145deg,#eef2ff 0%,#e0e7ff 30%,#f0f4ff 60%,#ede9fe 100%); position:relative; overflow-x:hidden;">
    <div class="rpt-orb1"></div>
    <div class="rpt-orb2"></div>

    <div class="relative z-10">
        <!-- Main Navigation Tabs -->
        <div class="mb-8">
            <?php include 'navbar.php'?>
        </div>

    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Exam Reports & Analytics</h1>
        <div class="flex space-x-4">
            <button onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-print mr-2"></i> Print Report
            </button>
            <button onclick="exportToPDF()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-file-pdf mr-2"></i> Export PDF
            </button>
            <button onclick="exportToExcel()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-file-excel mr-2"></i> Export Excel
            </button>
        </div>
    </div>

    <!-- Report Type Navigation -->
    <div class="glass-panel p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4 text-gray-700">Select Report Type</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
            <!-- Student Performance Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Student Performance</h3>
                <a href="?report=student_performance" class="block p-3 rounded-lg border border-blue-200 hover:border-blue-400 hover:bg-blue-50 transition-all <?= $report_type === 'student_performance' ? 'bg-blue-100 border-blue-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-user-graduate text-blue-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Student Performance</h4>
                            <p class="text-xs text-gray-600">Individual scores & analysis</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Batch Performance -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Batch Analysis</h3>
                <a href="?report=batch_performance" class="block p-3 rounded-lg border border-green-200 hover:border-green-400 hover:bg-green-50 transition-all <?= $report_type === 'batch_performance' ? 'bg-green-100 border-green-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-users text-green-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Batch Performance</h4>
                            <p class="text-xs text-gray-600">Batch-wise analysis</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Exam Type Comparison -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Exam Analysis</h3>
                <a href="?report=exam_type_comparison" class="block p-3 rounded-lg border border-purple-200 hover:border-purple-400 hover:bg-purple-50 transition-all <?= $report_type === 'exam_type_comparison' ? 'bg-purple-100 border-purple-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-chart-bar text-purple-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Exam Type Comparison</h4>
                            <p class="text-xs text-gray-600">Performance by exam type</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Statistical Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Statistics</h3>
                <a href="?report=exam_results_summary" class="block p-3 rounded-lg border border-orange-200 hover:border-orange-400 hover:bg-orange-50 transition-all <?= $report_type === 'exam_results_summary' ? 'bg-orange-100 border-orange-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-chart-pie text-orange-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Results Summary</h4>
                            <p class="text-xs text-gray-600">Overall statistics</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Component Analysis -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Component Analysis</h3>
                <a href="?report=component_analysis" class="block p-3 rounded-lg border border-red-200 hover:border-red-400 hover:bg-red-50 transition-all <?= $report_type === 'component_analysis' ? 'bg-red-100 border-red-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-puzzle-piece text-red-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Component Analysis</h4>
                            <p class="text-xs text-gray-600">MCQ vs Project vs Viva</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Administrative Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Administrative</h3>
                <a href="?report=enrollment_report" class="block p-3 rounded-lg border border-indigo-200 hover:border-indigo-400 hover:bg-indigo-50 transition-all <?= $report_type === 'enrollment_report' ? 'bg-indigo-100 border-indigo-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-clipboard-list text-indigo-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Enrollment Report</h4>
                            <p class="text-xs text-gray-600">Enrollment tracking</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Malpractice Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Monitoring</h3>
                <a href="?report=malpractice_report" class="block p-3 rounded-lg border border-pink-200 hover:border-pink-400 hover:bg-pink-50 transition-all <?= $report_type === 'malpractice_report' ? 'bg-pink-100 border-pink-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-eye text-pink-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Malpractice Report</h4>
                            <p class="text-xs text-gray-600">Monitoring & cases</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Operational Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Operational</h3>
                <a href="?report=exam_schedule" class="block p-3 rounded-lg border border-teal-200 hover:border-teal-400 hover:bg-teal-50 transition-all <?= $report_type === 'exam_schedule' ? 'bg-teal-100 border-teal-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-calendar-alt text-teal-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Exam Schedule</h4>
                            <p class="text-xs text-gray-600">Planning & scheduling</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Teacher Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Teacher Reports</h3>
                <a href="?report=teacher_reports" class="block p-3 rounded-lg border border-yellow-200 hover:border-yellow-400 hover:bg-yellow-50 transition-all <?= $report_type === 'teacher_reports' ? 'bg-yellow-100 border-yellow-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-chalkboard-teacher text-yellow-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Teacher Reports</h4>
                            <p class="text-xs text-gray-600">Evaluation workload</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Analytical Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Analytical</h3>
                <a href="?report=trend_analysis" class="block p-3 rounded-lg border border-gray-200 hover:border-gray-400 hover:bg-gray-50 transition-all <?= $report_type === 'trend_analysis' ? 'bg-gray-100 border-gray-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-chart-line text-gray-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Trend Analysis</h4>
                            <p class="text-xs text-gray-600">Performance trends</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Gap Analysis -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Gap Analysis</h3>
                <a href="?report=gap_analysis" class="block p-3 rounded-lg border border-rose-200 hover:border-rose-400 hover:bg-rose-50 transition-all <?= $report_type === 'gap_analysis' ? 'bg-rose-100 border-rose-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-search text-rose-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Gap Analysis</h4>
                            <p class="text-xs text-gray-600">Learning gaps</p>
                        </div>
                    </div>
                </a>
            </div>

            <!-- Audit Reports -->
            <div class="space-y-2">
                <h3 class="font-semibold text-gray-700 text-sm uppercase tracking-wide">Audit & Compliance</h3>
                <a href="?report=exam_audit" class="block p-3 rounded-lg border border-cyan-200 hover:border-cyan-400 hover:bg-cyan-50 transition-all <?= $report_type === 'exam_audit' ? 'bg-cyan-100 border-cyan-400' : 'bg-white' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-history text-cyan-600 mr-3"></i>
                        <div>
                            <h4 class="font-medium text-gray-800">Exam Audit Trail</h4>
                            <p class="text-xs text-gray-600">Complete history</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- Filters Section -->
    <div class="glass-panel p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4 text-gray-700">Report Filters</h2>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <input type="hidden" name="report" value="<?= $report_type ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Batch</label>
                <select name="batch_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $batch): ?>
                        <option value="<?= $batch['batch_id'] ?>" <?= $batch_filter === $batch['batch_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($batch['batch_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Student</label>
                <select name="student_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">All Students</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= $student['student_id'] ?>" <?= $student_filter === $student['student_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($student['student_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Exam Type</label>
                <select name="exam_type" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">All Types</option>
                    <?php foreach ($exam_types as $type): ?>
                        <option value="<?= $type['exam_type'] ?>" <?= $exam_type_filter === $type['exam_type'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type['exam_type']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                <select name="subject" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?= $subject['subject'] ?>" <?= $subject_filter === $subject['subject'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($subject['subject']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="md:col-span-2 lg:col-span-4 flex justify-end space-x-4">
                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors transform hover:scale-105">
                    <i class="fas fa-filter mr-2"></i> Apply Filters
                </button>
                <a href="?report=<?= $report_type ?>" class="bg-gray-300 text-gray-700 px-6 py-2 rounded-lg hover:bg-gray-400 transition-colors transform hover:scale-105">
                    <i class="fas fa-redo mr-2"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Report Content -->
    <div class="glass-panel p-6">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-800">
                <?= ucwords(str_replace('_', ' ', $report_type)) ?> Report
            </h2>
            <div class="text-sm text-gray-500">
                Generated on <?= date('F j, Y \a\t g:i A') ?>
            </div>
        </div>

        <?php if (empty($report_data)): ?>
            <div class="text-center py-12">
                <i class="fas fa-chart-bar text-gray-300 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">No Data Available</h3>
                <p class="text-gray-500">No records found for the selected criteria.</p>
            </div>
        <?php else: ?>
            <!-- Report-specific content -->
            <?php if ($report_type === 'student_performance'): ?>
                <!-- Student Performance Report -->
                <div class="overflow-x-auto">
                    <table class="w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exam</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Marks</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($report_data as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($row['student_name']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($row['batch_name']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($row['exam_name']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($row['subject']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($row['exam_type']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= $row['obtained_marks'] ?>/<?= $row['total_marks'] ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= number_format($row['percentage'], 2) ?>%</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($row['grade']) ?></td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500"><?= date('M j, Y', strtotime($row['exam_date'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php elseif ($report_type === 'batch_performance'): ?>
                <!-- Batch Performance Report -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 animate-fade-in">
                    <!-- Charts -->
                    <div class="glass-panel p-5 bg-white/60">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2"><i class="fas fa-chart-bar text-blue-500 mr-2"></i>Average Performance (%)</h3>
                        <div class="h-64">
                            <canvas id="batchAvgChart"></canvas>
                        </div>
                    </div>
                    <div class="glass-panel p-5 bg-white/60">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2"><i class="fas fa-chart-pie text-purple-500 mr-2"></i>Pass vs Fail</h3>
                        <div class="h-64 flex justify-center">
                            <canvas id="batchPassFailChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6 mb-6">
                    <?php foreach ($report_data as $i => $row): 
                        $delay = $i * 0.1;
                    ?>
                        <div class="stat-card-gradient scg-blue animate-fade-in" style="animation-delay: <?= $delay ?>s">
                            <div class="flex justify-between items-start mb-3 border-b border-white/20 pb-2">
                                <div>
                                    <h4 class="font-bold text-lg text-blue-900 leading-tight"><?= htmlspecialchars($row['batch_name']) ?></h4>
                                    <span class="text-xs bg-white/30 text-blue-900 px-2 py-0.5 rounded-full font-semibold mt-1 inline-block"><i class="fas fa-book mr-1"></i><?= htmlspecialchars($row['subject']) ?></span>
                                </div>
                                <div class="bg-white/40 rounded-full w-8 h-8 flex items-center justify-center text-blue-700 shadow-sm"><i class="fas fa-users"></i></div>
                            </div>
                            <div class="grid grid-cols-2 gap-y-3 gap-x-3 text-sm mt-3">
                                <div class="bg-white/50 p-3 rounded-lg shadow-inner">
                                    <span class="block text-[10px] uppercase tracking-wider text-blue-700 font-bold mb-0.5">Avg %</span>
                                    <span class="font-black text-gray-800 text-lg"><?= number_format($row['avg_percentage'], 1) ?>%</span>
                                </div>
                                <div class="bg-white/50 p-3 rounded-lg shadow-inner">
                                    <span class="block text-[10px] uppercase tracking-wider text-blue-700 font-bold mb-0.5">Avg Marks</span>
                                    <span class="font-black text-gray-800 text-lg"><?= number_format($row['avg_marks'], 1) ?></span>
                                </div>
                                <div class="bg-white/50 p-3 rounded-lg shadow-inner border border-green-300">
                                    <span class="block text-[10px] uppercase tracking-wider text-green-700 font-bold mb-0.5">Passed</span>
                                    <span class="font-black text-green-700 text-lg"><?= $row['passed'] ?></span>
                                </div>
                                <div class="bg-white/50 p-3 rounded-lg shadow-inner border border-red-300">
                                    <span class="block text-[10px] uppercase tracking-wider text-red-700 font-bold mb-0.5">Failed</span>
                                    <span class="font-black text-red-700 text-lg"><?= $row['failed'] ?></span>
                                </div>
                                <div class="col-span-2 flex justify-between bg-white/50 py-2.5 px-3 rounded-lg shadow-inner text-xs">
                                    <span class="font-bold text-gray-700"><span class="text-[10px] uppercase text-gray-500 mr-1">Max:</span><?= (float)$row['highest_marks'] ?></span>
                                    <span class="font-bold text-gray-700"><span class="text-[10px] uppercase text-gray-500 mr-1">Min:</span><?= (float)$row['lowest_marks'] ?></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const rawData = <?= json_encode($report_data) ?>;
                        if(rawData.length === 0) return;
                        
                        const labels = rawData.map(d => d.batch_name.substring(0, 15) + (d.batch_name.length > 15 ? '...' : '') + ' (' + d.subject + ')');
                        const avgData = rawData.map(d => parseFloat(d.avg_percentage).toFixed(2));
                        const passedData = rawData.map(d => parseInt(d.passed));
                        const failedData = rawData.map(d => parseInt(d.failed));

                        // Average Chart
                        const ctxAvg = document.getElementById('batchAvgChart').getContext('2d');
                        new Chart(ctxAvg, {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'Average Percentage (%)',
                                    data: avgData,
                                    backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                    borderColor: 'rgba(59, 130, 246, 1)',
                                    borderWidth: 1,
                                    borderRadius: 6,
                                    barPercentage: 0.5
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: { 
                                    y: { 
                                        beginAtZero: true, max: 100,
                                        grid: { color: 'rgba(0,0,0,0.05)' }
                                    },
                                    x: { grid: { display: false } }
                                },
                                plugins: { legend: { display: false } },
                                animation: { duration: 1500, easing: 'easeOutQuart' }
                            }
                        });

                        // Pass/Fail Doughnut Chart
                        const ctxPF = document.getElementById('batchPassFailChart').getContext('2d');
                        new Chart(ctxPF, {
                            type: 'doughnut',
                            data: {
                                labels: ['Total Passed', 'Total Failed'],
                                datasets: [{
                                    data: [
                                        passedData.reduce((a, b) => a + b, 0),
                                        failedData.reduce((a, b) => a + b, 0)
                                    ],
                                    backgroundColor: ['#22c55e', '#ef4444'],
                                    borderWidth: 2,
                                    borderColor: '#ffffff',
                                    hoverOffset: 4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                cutout: '70%',
                                plugins: {
                                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } }
                                },
                                animation: { duration: 2000, easing: 'easeOutBounce' }
                            }
                        });
                    });
                </script>

            <?php elseif ($report_type === 'exam_type_comparison'): ?>
                <!-- Exam Type Comparison Report -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6 animate-fade-in">
                    <!-- Charts -->
                    <div class="glass-panel p-5 bg-white/60">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2"><i class="fas fa-chart-line text-indigo-500 mr-2"></i>Average % by Exam Type</h3>
                        <div class="h-64">
                            <canvas id="examTypeRadarChart"></canvas>
                        </div>
                    </div>
                    <div class="glass-panel p-5 bg-white/60">
                        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b border-gray-200 pb-2"><i class="fas fa-balance-scale text-orange-500 mr-2"></i>Pass vs Fail Ratio</h3>
                        <div class="h-64 flex justify-center">
                            <canvas id="examTypePassFailChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto glass-panel">
                    <table class="w-full table-auto">
                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
                            <tr>
                                <th class="px-5 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Exam Type</th>
                                <th class="px-5 py-4 text-left text-xs font-bold text-gray-600 uppercase tracking-wider">Subject</th>
                                <th class="px-5 py-4 text-center text-xs font-bold text-gray-600 uppercase tracking-wider">Exams</th>
                                <th class="px-5 py-4 text-center text-xs font-bold text-gray-600 uppercase tracking-wider">Students</th>
                                <th class="px-5 py-4 text-center text-xs font-bold text-gray-600 uppercase tracking-wider">Avg Marks</th>
                                <th class="px-5 py-4 text-center text-xs font-bold text-gray-600 uppercase tracking-wider">Avg %</th>
                                <th class="px-5 py-4 text-center text-xs font-bold text-green-600 uppercase tracking-wider">Passed</th>
                                <th class="px-5 py-4 text-center text-xs font-bold text-red-600 uppercase tracking-wider">Failed</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php foreach ($report_data as $row): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-5 py-4 whitespace-nowrap text-sm font-bold text-gray-900 border-l-4 border-indigo-500"><?= htmlspecialchars($row['exam_type']) ?></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-600 font-medium"><?= htmlspecialchars($row['subject']) ?></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-500 text-center"><?= $row['total_exams'] ?></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-500 text-center"><?= $row['total_students'] ?></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm font-semibold text-gray-700 text-center"><?= number_format($row['avg_marks'], 1) ?></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm font-bold text-indigo-600 text-center"><?= number_format($row['avg_percentage'], 1) ?>%</td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-center"><span class="bg-green-100 text-green-800 px-3 py-1 rounded-full font-bold"><?= $row['passed'] ?></span></td>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-center"><span class="bg-red-100 text-red-800 px-3 py-1 rounded-full font-bold"><?= $row['failed'] ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const rawData = <?= json_encode($report_data) ?>;
                        if(rawData.length === 0) return;
                        
                        const labels = rawData.map(d => d.exam_type + ' (' + d.subject + ')');
                        const avgData = rawData.map(d => parseFloat(d.avg_percentage).toFixed(2));
                        const passedData = rawData.map(d => parseInt(d.passed));
                        const failedData = rawData.map(d => parseInt(d.failed));

                        // Bar Chart for Avg %
                        const ctxRadar = document.getElementById('examTypeRadarChart').getContext('2d');
                        new Chart(ctxRadar, {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [{
                                    label: 'Average %',
                                    data: avgData,
                                    backgroundColor: 'rgba(99, 102, 241, 0.7)',
                                    borderColor: 'rgba(99, 102, 241, 1)',
                                    borderWidth: 1,
                                    borderRadius: 6,
                                    barPercentage: 0.5
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: { 
                                    y: { beginAtZero: true, max: 100, grid: { color: 'rgba(0,0,0,0.05)' } },
                                    x: { grid: { display: false } }
                                },
                                plugins: { legend: { display: false } },
                                animation: { duration: 1500 }
                            }
                        });

                        // Pass/Fail Bar Chart
                        const ctxPF = document.getElementById('examTypePassFailChart').getContext('2d');
                        new Chart(ctxPF, {
                            type: 'bar',
                            data: {
                                labels: labels,
                                datasets: [
                                    {
                                        label: 'Passed',
                                        data: passedData,
                                        backgroundColor: '#22c55e',
                                        borderRadius: {topLeft: 6, topRight: 6, bottomLeft: 6, bottomRight: 6},
                                        barPercentage: 0.8,
                                        categoryPercentage: 0.4
                                    },
                                    {
                                        label: 'Failed',
                                        data: failedData,
                                        backgroundColor: '#ef4444',
                                        borderRadius: {topLeft: 6, topRight: 6, bottomLeft: 6, bottomRight: 6},
                                        barPercentage: 0.8,
                                        categoryPercentage: 0.4
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: { 
                                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
                                    x: { grid: { display: false } }
                                },
                                plugins: {
                                    legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } }
                                },
                                animation: { duration: 1500 }
                            }
                        });
                    });
                </script>

            <?php else: ?>
                <!-- Default table view for other reports -->
                <div class="overflow-x-auto">
                    <table class="w-full table-auto">
                        <thead class="bg-gray-50">
                            <tr>
                                <?php foreach (array_keys($report_data[0]) as $column): ?>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?= ucwords(str_replace('_', ' ', $column)) ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($report_data as $row): ?>
                                <tr class="hover:bg-gray-50">
                                    <?php foreach ($row as $value): ?>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                            <?= is_numeric($value) && strpos($value, '.') !== false ? number_format($value, 2) : htmlspecialchars($value ?? '') ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function exportToPDF() {
    // Implement PDF export functionality
    alert('PDF export functionality would be implemented here');
}

function exportToExcel() {
    // Implement Excel export functionality
    alert('Excel export functionality would be implemented here');
}
</script>

<?php require_once '../footer.php'; ?>