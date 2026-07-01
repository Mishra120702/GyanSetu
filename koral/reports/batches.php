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

// Get filter parameters
$selected_batch_id = $_GET['batch_id'] ?? '';
$view_type = $_GET['view'] ?? 'overview';
$time_period = $_GET['period'] ?? 'current';
$course_filter = $_GET['course'] ?? '';

// Get all batches with detailed information including thumbnail
$batches_query = $db->query("
    SELECT b.*, 
           t.name as mentor_name,
           t.profile_picture as mentor_avatar,
           c.name as course_name,
           COUNT(DISTINCT s.student_id) as current_enrollment,
           COUNT(DISTINCT sch.id) as total_classes,
           COUNT(DISTINCT CASE WHEN s.current_status = 'dropped' THEN s.student_id END) as dropped_students,
           COUNT(DISTINCT CASE WHEN s.current_status = 'completed' THEN s.student_id END) as completed_students
    FROM batches b
    LEFT JOIN trainers t ON b.batch_mentor_id = t.id
    LEFT JOIN courses c ON b.batch_name = c.id
    LEFT JOIN students s ON s.batch_name = b.batch_id
    LEFT JOIN schedule sch ON sch.batch_id = b.batch_id
    GROUP BY b.batch_id, b.batch_name, b.start_date, b.end_date, b.status, 
             t.name, c.name, b.max_students, b.time_slot, b.platform, b.thumbnail_path
    ORDER BY 
        CASE 
            WHEN b.status = 'ongoing' THEN 1
            WHEN b.status = 'upcoming' THEN 2
            WHEN b.status = 'completed' THEN 3
            ELSE 4
        END,
        b.start_date DESC
");
$batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);

// Get all courses for filter
$courses_query = $db->query("SELECT id, name FROM courses ORDER BY name");
$courses = $courses_query->fetchAll(PDO::FETCH_ASSOC);

// Initialize batch data
$batch_data = [];
$enrollment_data = [];
$attendance_data = [];
$attendance_trend_data = [];
$student_attendance_data = [];
$day_wise_attendance_data = [];
$completion_data = [];
$exam_data = [];
$feedback_data = [];
$schedule_data = [];
$resource_data = [];
$top_students_data = [];
$component_data = [];

// Get selected batch data
if (!empty($selected_batch_id)) {
    // Basic batch info including thumbnail
    $batch_stmt = $db->prepare("
        SELECT b.*, t.name as mentor_name, t.profile_picture as mentor_avatar, c.name as course_name
        FROM batches b
        LEFT JOIN trainers t ON b.batch_mentor_id = t.id
        LEFT JOIN courses c ON b.batch_name = c.id
        WHERE b.batch_id = ?
    ");
    $batch_stmt->execute([$selected_batch_id]);
    $batch_data = $batch_stmt->fetch(PDO::FETCH_ASSOC);

    if ($batch_data) {
        // Enrollment & Capacity Data
        $enrollment_stmt = $db->prepare("
            SELECT 
                b.batch_id,
                b.batch_name,
                b.max_students,
                COUNT(DISTINCT s.student_id) as current_enrollment,
                ROUND((COUNT(DISTINCT s.student_id) / NULLIF(b.max_students, 0)) * 100, 2) as enrollment_rate,
                b.time_slot,
                b.platform,
                t.name as mentor_name,
                COUNT(DISTINCT CASE WHEN s.current_status = 'active' THEN s.student_id END) as active_students,
                COUNT(DISTINCT CASE WHEN s.current_status = 'dropped' THEN s.student_id END) as dropped_students,
                COUNT(DISTINCT CASE WHEN s.current_status = 'completed' THEN s.student_id END) as completed_students
            FROM batches b
            LEFT JOIN students s ON s.batch_name = b.batch_id
            LEFT JOIN trainers t ON b.batch_mentor_id = t.id
            WHERE b.batch_id = ?
            GROUP BY b.batch_id, b.batch_name, b.max_students, b.time_slot, b.platform, t.name
        ");
        $enrollment_stmt->execute([$selected_batch_id]);
        $enrollment_data = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);

        // Total classes from schedule
        $total_classes_stmt = $db->prepare("
            SELECT COUNT(DISTINCT date) as total_classes
            FROM attendance
            WHERE batch_id = ? 
        ");
        $total_classes_stmt->execute([$selected_batch_id]);
        $total_classes_result = $total_classes_stmt->fetch(PDO::FETCH_ASSOC);
        $total_classes = $total_classes_result['total_classes'] ?? 0;

        // Day-wise attendance
        $day_wise_stmt = $db->prepare("
            SELECT 
                a.date,
                DATE_FORMAT(a.date, '%W, %M %d, %Y') as formatted_date,
                SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                COUNT(DISTINCT a.student_id) as total_students_on_day,
                ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.student_id), 0)) * 100, 2) as attendance_percentage
            FROM attendance a
            WHERE a.batch_id = ?
            GROUP BY a.date, DATE_FORMAT(a.date, '%W, %M %d, %Y')
            ORDER BY a.date DESC
        ");
        $day_wise_stmt->execute([$selected_batch_id]);
        $day_wise_attendance_data = $day_wise_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Overall Attendance Summary
        $attendance_summary_stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT a.id) as total_attendance_records,
                SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.id), 0)) * 100, 2) as overall_attendance_percentage,
                SUM(CASE WHEN a.camera_status = 'On' THEN 1 ELSE 0 END) as camera_on_count,
                SUM(CASE WHEN a.camera_status = 'Off' THEN 1 ELSE 0 END) as camera_off_count,
                COUNT(DISTINCT a.student_id) as unique_students,
                ? as total_classes
            FROM attendance a
            WHERE a.batch_id = ?
        ");
        $attendance_summary_stmt->execute([$total_classes, $selected_batch_id]);
        $attendance_summary = $attendance_summary_stmt->fetch(PDO::FETCH_ASSOC);

        // Monthly trend
        $attendance_trend_stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(a.date, '%Y-%m') as month,
                DATE_FORMAT(a.date, '%M %Y') as month_name,
                COUNT(DISTINCT a.id) as total_attendance_records,
                SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.id), 0)) * 100, 2) as attendance_percentage
            FROM attendance a
            WHERE a.batch_id = ?
            GROUP BY DATE_FORMAT(a.date, '%Y-%m'), DATE_FORMAT(a.date, '%M %Y')
            ORDER BY month ASC
        ");
        $attendance_trend_stmt->execute([$selected_batch_id]);
        $attendance_trend_data = $attendance_trend_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Student-wise attendance
        $student_attendance_stmt = $db->prepare("
            SELECT 
                s.student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                COUNT(DISTINCT a.date) as attended_days,
                SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT a.date), 0)) * 100, 2) as attendance_percentage,
                SUM(CASE WHEN a.camera_status = 'On' THEN 1 ELSE 0 END) as camera_on_count,
                ? as total_classes
            FROM students s
            LEFT JOIN attendance a ON a.student_id = s.student_id AND a.batch_id = ?
            WHERE s.batch_name = ?
            GROUP BY s.student_id, s.first_name, s.last_name
            ORDER BY attendance_percentage DESC
        ");
        $student_attendance_stmt->execute([$total_classes, $selected_batch_id, $selected_batch_id]);
        $student_attendance_data = $student_attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Completion & Dropout Analysis
        $completion_stmt = $db->prepare("
            SELECT 
                b.start_date,
                b.end_date,
                COUNT(DISTINCT s.student_id) as total_enrolled,
                COUNT(DISTINCT CASE WHEN s.current_status = 'completed' THEN s.student_id END) as completed_count,
                COUNT(DISTINCT CASE WHEN s.current_status = 'dropped' THEN s.student_id END) as dropped_count,
                COUNT(DISTINCT CASE WHEN s.current_status = 'active' THEN s.student_id END) as active_count,
                ROUND((COUNT(DISTINCT CASE WHEN s.current_status = 'dropped' THEN s.student_id END) / NULLIF(COUNT(DISTINCT s.student_id), 0)) * 100, 2) as dropout_rate,
                GROUP_CONCAT(DISTINCT s.dropout_reason SEPARATOR ', ') as dropout_reasons,
                COUNT(DISTINCT sbh.id) as transfer_count
            FROM batches b
            LEFT JOIN students s ON s.batch_name = b.batch_id
            LEFT JOIN student_batch_history sbh ON sbh.student_id = s.student_id
            WHERE b.batch_id = ?
            GROUP BY b.start_date, b.end_date
        ");
        $completion_stmt->execute([$selected_batch_id]);
        $completion_data = $completion_stmt->fetch(PDO::FETCH_ASSOC);

        // Exam Performance Data
        $exam_stmt = $db->prepare("
            SELECT 
                e.exam_id,
                e.exam_name,
                e.subject,
                e.total_marks,
                e.passing_marks,
                e.exam_type,
                e.exam_components,
                AVG(er.obtained_marks) as average_score,
                ROUND((AVG(er.obtained_marks) / NULLIF(e.total_marks, 0)) * 100, 2) as average_percentage,
                COUNT(DISTINCT er.student_id) as students_taken,
                SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) as passed_count,
                SUM(CASE WHEN er.obtained_marks < e.passing_marks THEN 1 ELSE 0 END) as failed_count,
                ROUND((SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT er.student_id), 0)) * 100, 2) as pass_rate
            FROM exams e
            LEFT JOIN exam_results er ON e.exam_id = er.exam_id
            WHERE e.batch_id = ?
            GROUP BY e.exam_id, e.exam_name, e.subject, e.total_marks, e.passing_marks, e.exam_type, e.exam_components
            ORDER BY e.exam_date DESC
        ");
        $exam_stmt->execute([$selected_batch_id]);
        $exam_data = $exam_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Component-wise performance
        $component_stmt = $db->prepare("
            SELECT 
                e.exam_id,
                e.exam_name,
                AVG(er.mcq_marks) as avg_mcq,
                AVG(er.project_marks) as avg_project,
                AVG(er.viva_marks) as avg_viva,
                e.mcq_marks as max_mcq,
                e.project_marks as max_project,
                e.viva_marks as max_viva
            FROM exams e
            LEFT JOIN exam_results er ON e.exam_id = er.exam_id
            WHERE e.batch_id = ? AND e.exam_components IS NOT NULL
            GROUP BY e.exam_id, e.exam_name, e.mcq_marks, e.project_marks, e.viva_marks
        ");
        $component_stmt->execute([$selected_batch_id]);
        $component_data = $component_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Top performing students
        $top_students_stmt = $db->prepare("
            SELECT 
                s.student_id,
                CONCAT(s.first_name, ' ', s.last_name) as student_name,
                AVG(er.obtained_marks) as average_score,
                COUNT(er.id) as exams_taken
            FROM students s
            LEFT JOIN exam_results er ON s.student_id = er.student_id
            LEFT JOIN exams e ON er.exam_id = e.exam_id
            WHERE s.batch_name = ? AND e.batch_id = ?
            GROUP BY s.student_id, s.first_name, s.last_name
            HAVING COUNT(er.id) > 0
            ORDER BY average_score DESC
            LIMIT 10
        ");
        $top_students_stmt->execute([$selected_batch_id, $selected_batch_id]);
        $top_students_data = $top_students_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Feedback Analysis
        $feedback_stmt = $db->prepare("
            SELECT 
                AVG(class_rating) as avg_class_rating,
                AVG(assignment_understanding) as avg_assignment_rating,
                AVG(practical_understanding) as avg_practical_rating,
                AVG(rating) as overall_rating,
                COUNT(DISTINCT id) as total_feedback,
                SUM(CASE WHEN satisfied = 1 THEN 1 ELSE 0 END) as satisfied_count,
                SUM(CASE WHEN is_regular = 'Yes' THEN 1 ELSE 0 END) as regular_count,
                DATE_FORMAT(date, '%Y-%m') as month,
                DATE_FORMAT(date, '%M %Y') as month_name,
                GROUP_CONCAT(DISTINCT suggestions SEPARATOR '|') as common_suggestions
            FROM feedback 
            WHERE batch_id = ?
            GROUP BY DATE_FORMAT(date, '%Y-%m'), DATE_FORMAT(date, '%M %Y')
            ORDER BY month DESC
        ");
        $feedback_stmt->execute([$selected_batch_id]);
        $feedback_data = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Overall feedback summary
        $feedback_summary_stmt = $db->prepare("
            SELECT 
                AVG(class_rating) as avg_class_rating,
                AVG(assignment_understanding) as avg_assignment_rating,
                AVG(practical_understanding) as avg_practical_rating,
                AVG(rating) as overall_rating,
                COUNT(DISTINCT id) as total_feedback,
                SUM(CASE WHEN satisfied = 1 THEN 1 ELSE 0 END) as satisfied_count,
                SUM(CASE WHEN is_regular = 'Yes' THEN 1 ELSE 0 END) as regular_count
            FROM feedback 
            WHERE batch_id = ?
        ");
        $feedback_summary_stmt->execute([$selected_batch_id]);
        $feedback_summary = $feedback_summary_stmt->fetch(PDO::FETCH_ASSOC);

        // Schedule & Utilization
        $schedule_stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_scheduled,
                SUM(CASE WHEN is_cancelled = 1 THEN 1 ELSE 0 END) as cancelled_count,
                ROUND((SUM(CASE WHEN is_cancelled = 1 THEN 1 ELSE 0 END) / NULLIF(COUNT(*), 0)) * 100, 2) as cancellation_rate,
                GROUP_CONCAT(DISTINCT cancellation_reason SEPARATOR ', ') as cancellation_reasons,
                MIN(schedule_date) as first_class,
                MAX(schedule_date) as last_class,
                AVG(TIMESTAMPDIFF(MINUTE, start_time, end_time)) as avg_duration_minutes
            FROM schedule 
            WHERE batch_id = ?
        ");
        $schedule_stmt->execute([$selected_batch_id]);
        $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);

        // Resource Distribution
        $resource_stmt = $db->prepare("
            SELECT 
                u.file_type,
                COUNT(*) as count,
                u.uploaded_by,
                MAX(u.uploaded_at) as last_upload
            FROM uploads u
            JOIN batch_uploads bu ON u.id = bu.upload_id
            WHERE bu.batch_id = ?
            GROUP BY u.file_type, u.uploaded_by
            ORDER BY count DESC
        ");
        $resource_stmt->execute([$selected_batch_id]);
        $resource_data = $resource_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Prepare chart data
$chart_data = [];
if (!empty($selected_batch_id)) {
    // Enrollment chart
    if (!empty($enrollment_data)) {
        $chart_data['enrollment'] = [
            'labels' => ['Current Enrollment', 'Available Seats'],
            'data' => [
                $enrollment_data['current_enrollment'], 
                max(0, $enrollment_data['max_students'] - $enrollment_data['current_enrollment'])
            ],
            'colors' => ['#4ade80', '#e5e7eb']
        ];
    }

    // Attendance trend
    if (!empty($attendance_trend_data)) {
        $attendance_labels = [];
        $attendance_percentages = [];
        
        foreach ($attendance_trend_data as $record) {
            $attendance_labels[] = $record['month_name'];
            $attendance_percentages[] = floatval($record['attendance_percentage']);
        }
        
        $chart_data['attendance_trend'] = [
            'labels' => $attendance_labels,
            'data' => $attendance_percentages,
            'color' => '#3b82f6'
        ];
    }

    // Exam performance
    if (!empty($exam_data)) {
        $exam_labels = [];
        $exam_scores = [];
        
        foreach ($exam_data as $exam) {
            $exam_labels[] = $exam['exam_name'];
            $exam_scores[] = floatval($exam['average_percentage']);
        }
        
        $chart_data['exam_performance'] = [
            'labels' => $exam_labels,
            'data' => $exam_scores,
            'color' => '#8b5cf6'
        ];
    }
}
?>

<div class="ml-64 p-8 transition-all duration-300">
    <!-- Main Navigation Tabs -->
    <?php include 'navbar.php'; ?>
</div>

<style>
    /* Modern CSS Variables */
    :root {
        --primary: #4361ee;
        --primary-light: #4895ef;
        --secondary: #7209b7;
        --success: #4cc9f0;
        --info: #3a86ff;
        --warning: #ff9e00;
        --danger: #f72585;
        --dark: #212529;
        --gray: #6c757d;
        --light-gray: #f8f9fa;
        --glass-bg: rgba(255, 255, 255, 0.92);
        --glass-border: rgba(255, 255, 255, 0.2);
        --shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
        --shadow-light: 0 4px 16px rgba(31, 38, 135, 0.1);
        --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
        --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
        --gradient-danger: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
    }

    /* ===== BATCHES PAGE STANDARDIZED BACKGROUND ===== */
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

    /* Gradient Stat Cards */
    .stat-card-gradient {
        border-radius:20px; color:#fff; overflow:hidden; position:relative;
        box-shadow:0 10px 25px rgba(0,0,0,0.1);
        transition:transform 0.4s ease,box-shadow 0.4s ease; padding:24px;
        cursor:pointer;
    }
    .stat-card-gradient:hover { transform:translateY(-5px); box-shadow:0 15px 35px rgba(0,0,0,0.2); }
    .stat-card-gradient::after {
        content:''; position:absolute; top:0; left:-100%; width:50%; height:100%;
        background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);
        transform:skewX(-25deg); animation:shimmer 3s infinite;
    }
    @keyframes shimmer { 100%{left:200%} }
    .scg-blue { background:linear-gradient(135deg,#3b82f6 0%,#2563eb 100%); }
    .scg-teal { background:linear-gradient(135deg,#14b8a6 0%,#0d9488 100%); }
    .scg-violet{ background:linear-gradient(135deg,#8b5cf6 0%,#7c3aed 100%); }
    .scg-orange{ background:linear-gradient(135deg,#f97316 0%,#ea580c 100%); }
    .scg-pink { background:linear-gradient(135deg,#ec4899 0%,#db2777 100%); }
    .scg-label { font-size:0.875rem; font-weight:600; text-transform:uppercase; letter-spacing:0.05em; opacity:0.9; }
    .scg-number { font-size:2.5rem; font-weight:800; line-height:1; margin-top:8px; text-shadow:0 2px 10px rgba(0,0,0,0.1); }


    /* Batch Card Styles */
    .batch-card {
        background: var(--glass-bg);
        backdrop-filter: blur(10px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: var(--shadow);
        height: 100%;
        position: relative;
        cursor: pointer;
    }

    .batch-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 25px 50px rgba(31, 38, 135, 0.25);
    }

    .batch-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: var(--gradient-primary);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.4s ease;
        z-index: 2;
    }

    .batch-card:hover::before {
        transform: scaleX(1);
    }

    /* Batch Thumbnail */
    .batch-thumbnail-wrapper {
        position: relative;
        height: 200px;
        overflow: hidden;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }

    .batch-thumbnail {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s ease;
    }

    .batch-card:hover .batch-thumbnail {
        transform: scale(1.05);
    }

    .thumbnail-placeholder {
        width: 100%;
        height: 100%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 3rem;
    }

    .batch-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .batch-card:hover .batch-overlay {
        opacity: 1;
    }

    .batch-content {
        padding: 20px;
    }

    .batch-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--dark);
        margin-bottom: 4px;
    }

    .batch-id {
        font-size: 0.75rem;
        color: var(--primary);
        font-weight: 600;
    }

    .batch-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-ongoing {
        background: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
        color: white;
    }

    .status-upcoming {
        background: linear-gradient(135deg, #ff9e00 0%, #ff5400 100%);
        color: white;
    }

    .status-completed {
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        color: white;
    }

    .status-cancelled {
        background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
        color: white;
    }

    .batch-mode {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .mode-online {
        background: rgba(76, 201, 240, 0.1);
        color: #3a86ff;
        border: 1px solid rgba(76, 201, 240, 0.3);
    }

    .mode-offline {
        background: rgba(255, 193, 7, 0.1);
        color: #ff9e00;
        border: 1px solid rgba(255, 193, 7, 0.3);
    }

    .batch-stats {
        display: flex;
        justify-content: space-between;
        margin: 15px 0;
        padding: 12px;
        background: rgba(67, 97, 238, 0.05);
        border-radius: 16px;
    }

    .stat-item {
        text-align: center;
        flex: 1;
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--primary);
    }

    .stat-label {
        font-size: 0.7rem;
        color: var(--gray);
    }

    .enrollment-progress {
        height: 6px;
        background: rgba(67, 97, 238, 0.1);
        border-radius: 3px;
        overflow: hidden;
        margin: 10px 0;
    }

    .enrollment-bar {
        height: 100%;
        background: var(--gradient-primary);
        border-radius: 3px;
        transition: width 1s ease;
    }

    .trainer-section {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: 15px;
        padding-top: 15px;
        border-top: 1px solid rgba(67, 97, 238, 0.1);
    }

    .trainer-info {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .trainer-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid var(--primary);
    }

    .avatar-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: var(--gradient-primary);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 700;
        font-size: 1rem;
    }

    .trainer-name {
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--dark);
    }

    .trainer-role {
        font-size: 0.7rem;
        color: var(--gray);
    }

    /* Animations */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .animate-fade-up {
        animation: fadeInUp 0.6s ease-out forwards;
    }

    /* Batch Grid */
    .batch-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
        gap: 24px;
    }

    @media (max-width: 768px) {
        .batch-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Scroll Container */
    .scroll-container {
        scrollbar-width: thin;
        scrollbar-color: var(--primary) #e5e7eb;
    }

    .scroll-container::-webkit-scrollbar {
        height: 6px;
    }

    .scroll-container::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 3px;
    }

    .scroll-container::-webkit-scrollbar-thumb {
        background: var(--primary);
        border-radius: 3px;
    }

    /* Print Styles */
    @media print {
        .sidebar, .header, .floating-btn, button, .scroll-btn {
            display: none !important;
        }
        .ml-64 {
            margin-left: 0 !important;
        }
        .batch-card {
            break-inside: avoid;
            box-shadow: none;
            border: 1px solid #ddd;
        }
    }
</style>

<div class="ml-64 p-8 transition-all duration-300 min-h-screen" style="background: linear-gradient(145deg,#eef2ff 0%,#e0e7ff 30%,#f0f4ff 60%,#ede9fe 100%); position:relative; overflow-x:hidden;">
    <div class="rpt-orb1"></div>
    <div class="rpt-orb2"></div>

    <div class="relative z-10">
        <div class="flex justify-between items-center mb-8 animate-fade-in">
            <h1 class="text-3xl font-bold bg-gradient-to-r from-gray-800 to-gray-600 bg-clip-text text-transparent">
                <i class="fas fa-chart-line text-blue-600 mr-3"></i>Batch Performance Reports
            </h1>
            <div class="flex space-x-4">
                <button onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-all duration-300 transform hover:scale-105 shadow-sm hover:shadow-md flex items-center gap-2">
                    <i class="fas fa-print text-blue-500"></i> Print Report
                </button>
                <button onclick="exportToPDF()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-all duration-300 transform hover:scale-105 shadow-sm hover:shadow-md flex items-center gap-2">
                    <i class="fas fa-file-pdf text-red-500"></i> Export PDF
                </button>
            </div>
        </div>

        <!-- Batch Selection Section with Thumbnails -->
        <div class="glass-panel p-6 mb-8 transition-all">
            <h2 class="text-xl font-semibold mb-4 text-gray-700 flex items-center gap-2">
                <i class="fas fa-layer-group text-blue-500"></i> Select Batch
            </h2>
            
            <!-- Ongoing Batches -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-green-800 flex items-center">
                        <i class="fas fa-play-circle mr-2 animate-pulse"></i> Ongoing Batches
                        <span class="ml-2 text-sm font-normal text-gray-500">(<?= count(array_filter($batches, fn($b) => $b['status'] === 'ongoing')) ?> batches)</span>
                    </h3>
                    <div class="flex space-x-2">
                        <button class="scroll-left-ongoing scroll-btn p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-all duration-300 hover:scale-110">
                            <i class="fas fa-chevron-left text-gray-600"></i>
                        </button>
                        <button class="scroll-right-ongoing scroll-btn p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-all duration-300 hover:scale-110">
                            <i class="fas fa-chevron-right text-gray-600"></i>
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto pb-4 scroll-container ongoing-scroll" style="scroll-behavior: smooth;">
                    <div class="flex space-x-4" style="min-width: min-content;">
                        <?php 
                        $ongoing_batches = array_filter($batches, fn($b) => $b['status'] === 'ongoing');
                        if (empty($ongoing_batches)): ?>
                            <div class="w-full text-center py-8 bg-gray-50 rounded-lg">
                                <i class="fas fa-info-circle text-gray-400 text-2xl mb-2"></i>
                                <p class="text-gray-500">No ongoing batches found</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($ongoing_batches as $batch): ?>
                                <div class="flex-shrink-0 w-80 transition-all duration-300 hover:-translate-y-2">
                                    <a href="javascript:void(0)" onclick="openBatchInfoModal(this)"
                                       data-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                                       data-name="<?= htmlspecialchars($batch['batch_name']) ?>"
                                       data-status="<?= htmlspecialchars($batch['status']) ?>"
                                       data-start="<?= date('M d, Y', strtotime($batch['start_date'])) ?>"
                                       data-end="<?= date('M d, Y', strtotime($batch['end_date'])) ?>"
                                       data-time="<?= htmlspecialchars($batch['time_slot']) ?>"
                                       data-mode="<?= htmlspecialchars($batch['mode']) ?>"
                                       data-mentor="<?= htmlspecialchars($batch['mentor_name'] ?: 'Not assigned') ?>"
                                       data-enrolled="<?= $batch['current_enrollment'] ?>"
                                       data-max="<?= $batch['max_students'] ?>"
                                       class="block batch-card <?= $selected_batch_id == $batch['batch_id'] ? 'ring-2 ring-green-500 shadow-lg' : '' ?>">
                                        <div class="batch-thumbnail-wrapper">
                                            <?php if (!empty($batch['thumbnail_path']) && file_exists('../' . $batch['thumbnail_path'])): ?>
                                                <img src="../<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                                                     alt="<?= htmlspecialchars($batch['batch_name']) ?>" 
                                                     class="batch-thumbnail">
                                            <?php else: ?>
                                                <div class="thumbnail-placeholder">
                                                    <i class="fas fa-chalkboard-teacher"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="batch-overlay">
                                                <span class="batch-status <?= $batch['status'] === 'ongoing' ? 'status-ongoing' : '' ?>">
                                                    <i class="fas fa-circle" style="font-size: 8px;"></i> <?= ucfirst($batch['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="batch-content">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <h4 class="batch-title"><?= htmlspecialchars($batch['batch_name']) ?></h4>
                                                    <span class="batch-id"><?= htmlspecialchars($batch['batch_id']) ?></span>
                                                </div>
                                                <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">
                                                    <?= $batch['current_enrollment'] ?>/<?= $batch['max_students'] ?>
                                                </span>
                                            </div>
                                            
                                            <div class="flex justify-between text-xs text-gray-500 mb-2">
                                                <span><i class="fas fa-clock mr-1"></i><?= $batch['time_slot'] ?></span>
                                                <span><i class="fas fa-calendar mr-1"></i><?= date('M d', strtotime($batch['start_date'])) ?></span>
                                            </div>
                                            <div class="enrollment-progress">
                                                <div class="enrollment-bar" style="width: <?= min(100, ($batch['current_enrollment'] / max($batch['max_students'], 1)) * 100) ?>%"></div>
                                            </div>
                                            <?php if (!empty($batch['mentor_name'])): ?>
                                                <div class="trainer-section">
                                                    <div class="trainer-info">
                                                        <?php if (!empty($batch['mentor_avatar'])): ?>
                                                            <img src="<?= htmlspecialchars($batch['mentor_avatar']) ?>" class="trainer-avatar" alt="Mentor">
                                                        <?php else: ?>
                                                            <div class="avatar-placeholder"><?= substr($batch['mentor_name'], 0, 1) ?></div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="trainer-name"><?= htmlspecialchars($batch['mentor_name']) ?></div>
                                                            <div class="trainer-role">Batch Mentor</div>
                                                        </div>
                                                    </div>
                                                    <span class="batch-mode mode-online">
                                                        <i class="fas fa-<?= $batch['mode'] === 'online' ? 'wifi' : 'building' ?>"></i>
                                                        <?= ucfirst($batch['mode']) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Batches -->
            <div class="mb-8">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-blue-800 flex items-center">
                        <i class="fas fa-clock mr-2"></i> Upcoming Batches
                        <span class="ml-2 text-sm font-normal text-gray-500">(<?= count(array_filter($batches, fn($b) => $b['status'] === 'upcoming')) ?> batches)</span>
                    </h3>
                    <div class="flex space-x-2">
                        <button class="scroll-left-upcoming scroll-btn p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-all duration-300 hover:scale-110">
                            <i class="fas fa-chevron-left text-gray-600"></i>
                        </button>
                        <button class="scroll-right-upcoming scroll-btn p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-all duration-300 hover:scale-110">
                            <i class="fas fa-chevron-right text-gray-600"></i>
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto pb-4 scroll-container upcoming-scroll" style="scroll-behavior: smooth;">
                    <div class="flex space-x-4" style="min-width: min-content;">
                        <?php 
                        $upcoming_batches = array_filter($batches, fn($b) => $b['status'] === 'upcoming');
                        if (empty($upcoming_batches)): ?>
                            <div class="w-full text-center py-8 bg-gray-50 rounded-lg">
                                <i class="fas fa-info-circle text-gray-400 text-2xl mb-2"></i>
                                <p class="text-gray-500">No upcoming batches found</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_batches as $batch): ?>
                                <div class="flex-shrink-0 w-80 transition-all duration-300 hover:-translate-y-2">
                                    <a href="javascript:void(0)" onclick="openBatchInfoModal(this)"
                                       data-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                                       data-name="<?= htmlspecialchars($batch['batch_name']) ?>"
                                       data-status="<?= htmlspecialchars($batch['status']) ?>"
                                       data-start="<?= date('M d, Y', strtotime($batch['start_date'])) ?>"
                                       data-end="<?= date('M d, Y', strtotime($batch['end_date'])) ?>"
                                       data-time="<?= htmlspecialchars($batch['time_slot']) ?>"
                                       data-mode="<?= htmlspecialchars($batch['mode']) ?>"
                                       data-mentor="<?= htmlspecialchars($batch['mentor_name'] ?: 'Not assigned') ?>"
                                       data-enrolled="<?= $batch['current_enrollment'] ?>"
                                       data-max="<?= $batch['max_students'] ?>"
                                       class="block batch-card <?= $selected_batch_id == $batch['batch_id'] ? 'ring-2 ring-blue-500 shadow-lg' : '' ?>">
                                        <div class="batch-thumbnail-wrapper">
                                            <?php if (!empty($batch['thumbnail_path']) && file_exists('../' . $batch['thumbnail_path'])): ?>
                                                <img src="../<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                                                     alt="<?= htmlspecialchars($batch['batch_name']) ?>" 
                                                     class="batch-thumbnail">
                                            <?php else: ?>
                                                <div class="thumbnail-placeholder">
                                                    <i class="fas fa-calendar-alt"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="batch-overlay">
                                                <span class="batch-status status-upcoming">
                                                    <i class="fas fa-clock"></i> Upcoming
                                                </span>
                                            </div>
                                        </div>
                                        <div class="batch-content">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <h4 class="batch-title"><?= htmlspecialchars($batch['batch_name']) ?></h4>
                                                    <span class="batch-id"><?= htmlspecialchars($batch['batch_id']) ?></span>
                                                </div>
                                                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">
                                                    Starts <?= date('M d', strtotime($batch['start_date'])) ?>
                                                </span>
                                            </div>
                                           
                                            <div class="flex justify-between text-xs text-gray-500 mb-2">
                                                <span><i class="fas fa-clock mr-1"></i><?= $batch['time_slot'] ?></span>
                                                <span><i class="fas fa-calendar mr-1"></i><?= date('M d', strtotime($batch['start_date'])) ?> - <?= date('M d', strtotime($batch['end_date'])) ?></span>
                                            </div>
                                            <?php if (!empty($batch['mentor_name'])): ?>
                                                <div class="trainer-section">
                                                    <div class="trainer-info">
                                                        <?php if (!empty($batch['mentor_avatar'])): ?>
                                                            <img src="<?= htmlspecialchars($batch['mentor_avatar']) ?>" class="trainer-avatar" alt="Mentor">
                                                        <?php else: ?>
                                                            <div class="avatar-placeholder"><?= substr($batch['mentor_name'], 0, 1) ?></div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="trainer-name"><?= htmlspecialchars($batch['mentor_name']) ?></div>
                                                            <div class="trainer-role">Batch Mentor</div>
                                                        </div>
                                                    </div>
                                                    <span class="batch-mode mode-<?= $batch['mode'] ?>">
                                                        <i class="fas fa-<?= $batch['mode'] === 'online' ? 'wifi' : 'building' ?>"></i>
                                                        <?= ucfirst($batch['mode']) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Completed Batches -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-semibold text-gray-700 flex items-center">
                        <i class="fas fa-check-circle mr-2"></i> Completed Batches
                        <span class="ml-2 text-sm font-normal text-gray-500">(<?= count(array_filter($batches, fn($b) => $b['status'] === 'completed')) ?> batches)</span>
                    </h3>
                    <div class="flex space-x-2">
                        <button class="scroll-left-completed scroll-btn p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-all duration-300 hover:scale-110">
                            <i class="fas fa-chevron-left text-gray-600"></i>
                        </button>
                        <button class="scroll-right-completed scroll-btn p-2 bg-gray-100 rounded-full hover:bg-gray-200 transition-all duration-300 hover:scale-110">
                            <i class="fas fa-chevron-right text-gray-600"></i>
                        </button>
                    </div>
                </div>
                <div class="overflow-x-auto pb-4 scroll-container completed-scroll" style="scroll-behavior: smooth;">
                    <div class="flex space-x-4" style="min-width: min-content;">
                        <?php 
                        $completed_batches = array_filter($batches, fn($b) => $b['status'] === 'completed');
                        if (empty($completed_batches)): ?>
                            <div class="w-full text-center py-8 bg-gray-50 rounded-lg">
                                <i class="fas fa-info-circle text-gray-400 text-2xl mb-2"></i>
                                <p class="text-gray-500">No completed batches found</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($completed_batches as $batch): ?>
                                <div class="flex-shrink-0 w-80 transition-all duration-300 hover:-translate-y-2">
                                    <a href="javascript:void(0)" onclick="openBatchInfoModal(this)"
                                       data-id="<?= htmlspecialchars($batch['batch_id']) ?>"
                                       data-name="<?= htmlspecialchars($batch['batch_name']) ?>"
                                       data-status="<?= htmlspecialchars($batch['status']) ?>"
                                       data-start="<?= date('M d, Y', strtotime($batch['start_date'])) ?>"
                                       data-end="<?= date('M d, Y', strtotime($batch['end_date'])) ?>"
                                       data-time="<?= htmlspecialchars($batch['time_slot']) ?>"
                                       data-mode="<?= htmlspecialchars($batch['mode']) ?>"
                                       data-mentor="<?= htmlspecialchars($batch['mentor_name'] ?: 'Not assigned') ?>"
                                       data-enrolled="<?= $batch['current_enrollment'] ?>"
                                       data-max="<?= $batch['max_students'] ?>"
                                       data-completed="<?= $batch['completed_students'] ?>"
                                       class="block batch-card <?= $selected_batch_id == $batch['batch_id'] ? 'ring-2 ring-gray-500 shadow-lg' : '' ?>">
                                        <div class="batch-thumbnail-wrapper">
                                            <?php if (!empty($batch['thumbnail_path']) && file_exists('../' . $batch['thumbnail_path'])): ?>
                                                <img src="../<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                                                     alt="<?= htmlspecialchars($batch['batch_name']) ?>" 
                                                     class="batch-thumbnail">
                                            <?php else: ?>
                                                <div class="thumbnail-placeholder">
                                                    <i class="fas fa-graduation-cap"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div class="batch-overlay">
                                                <span class="batch-status status-completed">
                                                    <i class="fas fa-check-circle"></i> Completed
                                                </span>
                                            </div>
                                        </div>
                                        <div class="batch-content">
                                            <div class="flex justify-between items-start mb-2">
                                                <div>
                                                    <h4 class="batch-title"><?= htmlspecialchars($batch['batch_name']) ?></h4>
                                                    <span class="batch-id"><?= htmlspecialchars($batch['batch_id']) ?></span>
                                                </div>
                                                <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">
                                                    <?= $batch['completed_students'] ?> completed
                                                </span>
                                            </div>
                                           
                                            <div class="flex justify-between text-xs text-gray-500 mb-2">
                                                <span><i class="fas fa-clock mr-1"></i><?= $batch['time_slot'] ?></span>
                                                <span><i class="fas fa-check-circle mr-1 text-green-500"></i>Completed</span>
                                            </div>
                                            <?php if (!empty($batch['mentor_name'])): ?>
                                                <div class="trainer-section">
                                                    <div class="trainer-info">
                                                        <?php if (!empty($batch['mentor_avatar'])): ?>
                                                            <img src="<?= htmlspecialchars($batch['mentor_avatar']) ?>" class="trainer-avatar" alt="Mentor">
                                                        <?php else: ?>
                                                            <div class="avatar-placeholder"><?= substr($batch['mentor_name'], 0, 1) ?></div>
                                                        <?php endif; ?>
                                                        <div>
                                                            <div class="trainer-name"><?= htmlspecialchars($batch['mentor_name']) ?></div>
                                                            <div class="trainer-role">Batch Mentor</div>
                                                        </div>
                                                    </div>
                                                    <span class="batch-mode mode-<?= $batch['mode'] ?>">
                                                        <i class="fas fa-<?= $batch['mode'] === 'online' ? 'wifi' : 'building' ?>"></i>
                                                        <?= ucfirst($batch['mode']) ?>
                                                    </span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($selected_batch_id) && !empty($batch_data)): ?>
            <!-- View Tabs with Modern Design -->
            <div class="flex flex-wrap gap-2 mb-6 animate-fade-in">
                <a href="?batch_id=<?= $selected_batch_id ?>&view=overview" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'overview' ? 'bg-indigo-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-chart-pie"></i> Overview
                </a>
                <a href="?batch_id=<?= $selected_batch_id ?>&view=enrollment" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'enrollment' ? 'bg-blue-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-users"></i> Enrollment & Capacity
                </a>
                <a href="?batch_id=<?= $selected_batch_id ?>&view=attendance" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'attendance' ? 'bg-teal-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-calendar-check"></i> Attendance
                </a>
                <a href="?batch_id=<?= $selected_batch_id ?>&view=completion" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'completion' ? 'bg-green-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-graduation-cap"></i> Completion & Dropout
                </a>
                <a href="?batch_id=<?= $selected_batch_id ?>&view=exams" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'exams' ? 'bg-purple-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-file-alt"></i> Exam Performance
                </a>
                <a href="?batch_id=<?= $selected_batch_id ?>&view=feedback" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'feedback' ? 'bg-pink-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-comments"></i> Feedback Analysis
                </a>
                <a href="?batch_id=<?= $selected_batch_id ?>&view=schedule" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'schedule' ? 'bg-orange-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-calendar-alt"></i> Schedule & Utilization
                </a>
                <a href="?batch_id=<?= $selected_batch_id ?>&view=resources" 
                   class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center gap-2 <?= $view_type === 'resources' ? 'bg-yellow-500 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                    <i class="fas fa-file-upload"></i> Resources
                </a>
            </div>

            <!-- Overview View -->
            <?php if ($view_type === 'overview'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <!-- Batch Header with Thumbnail -->
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                        <div class="flex items-center gap-6">
                            <?php if (!empty($batch_data['thumbnail_path']) && file_exists('../' . $batch_data['thumbnail_path'])): ?>
                                <div class="w-32 h-32 rounded-xl overflow-hidden shadow-lg flex-shrink-0">
                                    <img src="../<?= htmlspecialchars($batch_data['thumbnail_path']) ?>" 
                                         alt="<?= htmlspecialchars($batch_data['batch_name']) ?>" 
                                         class="w-full h-full object-cover">
                                </div>
                            <?php else: ?>
                                <div class="w-32 h-32 rounded-xl overflow-hidden shadow-lg flex-shrink-0 bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center">
                                    <i class="fas fa-chalkboard-teacher text-white text-4xl"></i>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h2 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($batch_data['batch_name']) ?></h2>
                                        
                                        <div class="flex items-center space-x-4 mt-2">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium 
                                                <?= $batch_data['status'] === 'ongoing' ? 'bg-green-100 text-green-800' : 
                                                   ($batch_data['status'] === 'upcoming' ? 'bg-blue-100 text-blue-800' : 
                                                   'bg-gray-100 text-gray-800') ?>">
                                                <i class="fas fa-circle mr-1 text-<?= $batch_data['status'] === 'ongoing' ? 'green' : ($batch_data['status'] === 'upcoming' ? 'blue' : 'gray') ?>-500" style="font-size: 8px;"></i>
                                                <?= ucfirst($batch_data['status']) ?>
                                            </span>
                                            <span class="text-gray-500 text-sm">
                                                <i class="fas fa-user mr-1"></i><?= htmlspecialchars($batch_data['mentor_name'] ?: 'No mentor assigned') ?>
                                            </span>
                                            <span class="text-gray-500 text-sm">
                                                <i class="fas fa-clock mr-1"></i><?= $batch_data['time_slot'] ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-3xl font-bold text-blue-600"><?= $enrollment_data['current_enrollment'] ?? 0 ?></div>
                                        <div class="text-gray-600">Current Students</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Key Metrics -->
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-6 p-6">
                        <div class="stat-card-gradient scg-blue animate-fade-in">
                            <p class="scg-label mb-2"><i class="fas fa-user-check mr-2"></i>Enrollment</p>
                            <h3 class="scg-number"><?= $enrollment_data['enrollment_rate'] ?? 0 ?>%</h3>
                        </div>
                        <div class="stat-card-gradient scg-teal animate-fade-in" style="animation-delay:.1s">
                            <p class="scg-label mb-2"><i class="fas fa-calendar-check mr-2"></i>Attendance</p>
                            <h3 class="scg-number"><?= $attendance_summary['overall_attendance_percentage'] ?? 0 ?>%</h3>
                        </div>
                        <div class="stat-card-gradient scg-violet animate-fade-in" style="animation-delay:.2s">
                            <p class="scg-label mb-2"><i class="fas fa-graduation-cap mr-2"></i>Avg Score</p>
                            <h3 class="scg-number"><?= $exam_data[0]['average_percentage'] ?? 0 ?>%</h3>
                        </div>
                        <div class="stat-card-gradient scg-orange animate-fade-in" style="animation-delay:.3s">
                            <p class="scg-label mb-2"><i class="fas fa-star mr-2"></i>Avg Feedback</p>
                            <h3 class="scg-number"><?= number_format($feedback_summary['overall_rating'] ?? 0, 1) ?></h3>
                        </div>
                    </div>

                    <!-- Charts -->
                    <?php if (!empty($chart_data)): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6">
                        <?php if (!empty($chart_data['enrollment'])): ?>
                        <div class="bg-gray-50 rounded-lg p-4 transition-all duration-300 hover:shadow-md">
                            <h3 class="text-lg font-medium text-gray-700 mb-4">Enrollment Status</h3>
                            <div class="h-64">
                                <canvas id="enrollmentChart"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($chart_data['attendance_trend'])): ?>
                        <div class="bg-gray-50 rounded-lg p-4 transition-all duration-300 hover:shadow-md">
                            <h3 class="text-lg font-medium text-gray-700 mb-4">Attendance Trend</h3>
                            <div class="h-64">
                                <canvas id="attendanceTrendChart"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($chart_data['exam_performance'])): ?>
                        <div class="bg-gray-50 rounded-lg p-4 md:col-span-2 transition-all duration-300 hover:shadow-md">
                            <h3 class="text-lg font-medium text-gray-700 mb-4">Exam Performance</h3>
                            <div class="h-64">
                                <canvas id="examPerformanceChart"></canvas>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Quick Stats -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 p-6 border-t border-indigo-100" style="background:rgba(255,255,255,.3);">
                        <div class="glass-panel p-5" style="background:rgba(239,246,255,.6); border-color:rgba(59,130,246,.2);">
                            <h3 class="font-semibold text-blue-800 flex items-center gap-2 mb-4 border-b border-blue-200 pb-2"><i class="fas fa-info-circle"></i> Batch Information</h3>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Start Date</span>
                                    <span class="font-bold text-gray-800"><?= date('M d, Y', strtotime($batch_data['start_date'])) ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">End Date</span>
                                    <span class="font-bold text-gray-800"><?= date('M d, Y', strtotime($batch_data['end_date'])) ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Platform</span>
                                    <span class="font-bold text-gray-800"><?= htmlspecialchars($batch_data['platform'] ?: 'N/A') ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Mode</span>
                                    <span class="bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full text-xs font-bold"><?= ucfirst($batch_data['mode']) ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="glass-panel p-5" style="background:rgba(240,253,244,.6); border-color:rgba(34,197,94,.2);">
                            <h3 class="font-semibold text-green-800 flex items-center gap-2 mb-4 border-b border-green-200 pb-2"><i class="fas fa-users"></i> Student Status</h3>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Active Students</span>
                                    <span class="font-bold text-green-600"><?= $enrollment_data['active_students'] ?? 0 ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Completed</span>
                                    <span class="font-bold text-blue-600"><?= $enrollment_data['completed_students'] ?? 0 ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Dropped</span>
                                    <span class="font-bold text-red-600"><?= $enrollment_data['dropped_students'] ?? 0 ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Retention Rate</span>
                                    <span class="font-bold text-gray-800">
                                        <?= ($enrollment_data['current_enrollment'] ?? 0) > 0 ? 
                                            round((($enrollment_data['active_students'] + $enrollment_data['completed_students']) / $enrollment_data['current_enrollment']) * 100, 2) : 0 ?>%
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="glass-panel p-5" style="background:rgba(250,245,255,.6); border-color:rgba(168,85,247,.2);">
                            <h3 class="font-semibold text-purple-800 flex items-center gap-2 mb-4 border-b border-purple-200 pb-2"><i class="fas fa-chart-line"></i> Academic Performance</h3>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Total Classes</span>
                                    <span class="font-bold text-gray-800"><?= $attendance_summary['total_classes'] ?? 0 ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Exams Conducted</span>
                                    <span class="font-bold text-gray-800"><?= count($exam_data) ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Pass Rate</span>
                                    <span class="font-bold text-gray-800"><?= $exam_data[0]['pass_rate'] ?? 0 ?>%</span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-60 p-2.5 rounded shadow-sm">
                                    <span class="text-gray-600 font-medium">Resources Uploaded</span>
                                    <span class="font-bold text-gray-800"><?= array_sum(array_column($resource_data, 'count')) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Attendance Summary Report (Day-wise) -->
            <?php if ($view_type === 'attendance'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <div class="bg-gradient-to-r from-green-50 to-emerald-50 p-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800">Batch Attendance Summary Report</h2>
                        <p class="text-gray-600">Comprehensive attendance tracking and analysis - Day wise breakdown</p>
                    </div>

                    <div class="p-6">
                        <!-- Attendance Overview -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                            <div class="stat-card-gradient scg-teal animate-fade-in">
                                <p class="scg-label mb-2"><i class="fas fa-chalkboard mr-2"></i>Total Classes</p>
                                <h3 class="scg-number"><?= $attendance_summary['total_classes'] ?? 0 ?></h3>
                            </div>
                            <div class="stat-card-gradient scg-blue animate-fade-in" style="animation-delay:.1s">
                                <p class="scg-label mb-2"><i class="fas fa-percent mr-2"></i>Attendance Rate</p>
                                <h3 class="scg-number"><?= $attendance_summary['overall_attendance_percentage'] ?? 0 ?>%</h3>
                            </div>
                            <div class="stat-card-gradient scg-violet animate-fade-in" style="animation-delay:.2s">
                                <p class="scg-label mb-2"><i class="fas fa-video mr-2"></i>Camera On</p>
                                <h3 class="scg-number"><?= $attendance_summary['camera_on_count'] ?? 0 ?></h3>
                            </div>
                            <div class="stat-card-gradient scg-orange animate-fade-in" style="animation-delay:.3s">
                                <p class="scg-label mb-2"><i class="fas fa-camera mr-2"></i>Camera Off</p>
                                <h3 class="scg-number"><?= $attendance_summary['camera_off_count'] ?? 0 ?></h3>
                            </div>
                        </div>

                        <!-- Day-wise Attendance Table -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                <i class="fas fa-calendar-day text-indigo-500"></i> Day-wise Attendance Breakdown
                            </h3>
                            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden transition-all duration-300 hover:shadow-lg">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Present</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Absent</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Total Students</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance %</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Visual</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php if (!empty($day_wise_attendance_data)): ?>
                                                <?php foreach ($day_wise_attendance_data as $index => $day): ?>
                                                    <tr class="hover:bg-gradient-to-r hover:from-green-50 hover:to-white transition-all duration-300 <?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?>">
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="flex items-center gap-2">
                                                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-gray-400 to-gray-600 flex items-center justify-center text-white text-sm font-bold shadow-sm">
                                                                    <?= date('d', strtotime($day['date'])) ?>
                                                                </div>
                                                                <span class="text-sm font-medium text-gray-900"><?= date('M d, Y', strtotime($day['date'])) ?></span>
                                                            </div>
                                                         </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <span class="text-sm text-gray-600"><?= date('l', strtotime($day['date'])) ?></span>
                                                         </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                                <i class="fas fa-check-circle mr-1 text-green-500"></i> <?= $day['present_count'] ?>
                                                            </span>
                                                         
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                                <i class="fas fa-times-circle mr-1 text-red-500"></i> <?= $day['absent_count'] ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                                            <?= $day['total_students_on_day'] ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                                            <div class="flex items-center justify-center gap-2">
                                                                <span class="text-sm font-medium 
                                                                    <?= ($day['attendance_percentage'] ?? 0) >= 80 ? 'text-green-600' : 
                                                                       (($day['attendance_percentage'] ?? 0) >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                                    <?= $day['attendance_percentage'] ?? 0 ?>%
                                                                </span>
                                                                <div class="w-16 bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                                                    <div class="bg-gradient-to-r from-green-500 to-green-600 h-1.5 rounded-full" style="width: <?= $day['attendance_percentage'] ?? 0 ?>%"></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="flex items-center gap-1">
                                                                <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                                                    <div class="bg-green-500 h-full" style="width: <?= ($day['present_count'] / max($day['total_students_on_day'], 1)) * 100 ?>%"></div>
                                                                </div>
                                                                <span class="text-xs text-gray-500">vs</span>
                                                                <div class="w-16 h-2 bg-gray-200 rounded-full overflow-hidden">
                                                                    <div class="bg-red-500 h-full" style="width: <?= ($day['absent_count'] / max($day['total_students_on_day'], 1)) * 100 ?>%"></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                                        <i class="fas fa-calendar-times text-4xl mb-2 block"></i>
                                                        No attendance data available for this batch
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Monthly Trends -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                    <i class="fas fa-chart-line text-blue-500"></i> Monthly Attendance Trends
                                </h3>
                                <div class="space-y-4">
                                    <?php if (!empty($attendance_trend_data)): ?>
                                        <?php foreach ($attendance_trend_data as $month_data): ?>
                                            <div class="flex items-center justify-between p-3 bg-gradient-to-r from-gray-50 to-white rounded-lg hover:shadow-md transition-all duration-300">
                                                <div>
                                                    <span class="font-medium text-gray-800">
                                                        <?= $month_data['month_name'] ?>
                                                    </span>
                                                    <div class="text-sm text-gray-600">
                                                        <?= $month_data['present_count'] ?? 0 ?> present / <?= $month_data['total_attendance_records'] ?? 0 ?> total
                                                    </div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="text-lg font-bold 
                                                        <?= ($month_data['attendance_percentage'] ?? 0) >= 80 ? 'text-green-600' : 
                                                           (($month_data['attendance_percentage'] ?? 0) >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                        <?= $month_data['attendance_percentage'] ?? 0 ?>%
                                                    </div>
                                                    <div class="text-xs text-gray-500">Attendance</div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-gray-500 text-center py-4">No monthly trend data available</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Camera Usage Statistics -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                    <i class="fas fa-camera-retro text-purple-500"></i> Camera Usage Statistics
                                </h3>
                                <div class="space-y-4">
                                    <?php 
                                    $total_camera = ($attendance_summary['camera_on_count'] ?? 0) + ($attendance_summary['camera_off_count'] ?? 0);
                                    $camera_on_percentage = $total_camera > 0 ? round((($attendance_summary['camera_on_count'] ?? 0) / $total_camera) * 100, 2) : 0;
                                    $camera_off_percentage = $total_camera > 0 ? round((($attendance_summary['camera_off_count'] ?? 0) / $total_camera) * 100, 2) : 0;
                                    ?>
                                    <div class="flex justify-between items-center p-2">
                                        <span class="text-gray-600"><i class="fas fa-video text-green-500 mr-2"></i>Camera On:</span>
                                        <div class="flex items-center space-x-2">
                                            <span class="font-medium"><?= $attendance_summary['camera_on_count'] ?? 0 ?></span>
                                            <span class="text-sm text-green-600">(<?= $camera_on_percentage ?>%)</span>
                                        </div>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                        <div class="bg-gradient-to-r from-green-500 to-green-600 h-3 rounded-full transition-all duration-1000" style="width: <?= $camera_on_percentage ?>%"></div>
                                    </div>
                                    
                                    <div class="flex justify-between items-center p-2">
                                        <span class="text-gray-600"><i class="fas fa-camera text-red-500 mr-2"></i>Camera Off:</span>
                                        <div class="flex items-center space-x-2">
                                            <span class="font-medium"><?= $attendance_summary['camera_off_count'] ?? 0 ?></span>
                                            <span class="text-sm text-red-600">(<?= $camera_off_percentage ?>%)</span>
                                        </div>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                        <div class="bg-gradient-to-r from-red-500 to-red-600 h-3 rounded-full transition-all duration-1000" style="width: <?= $camera_off_percentage ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Top Performing Students in Attendance -->
                        <?php if (!empty($student_attendance_data)): ?>
                        <div class="mt-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                                <i class="fas fa-trophy text-yellow-500"></i> Top Performing Students (Attendance)
                            </h3>
                            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden transition-all duration-300 hover:shadow-lg">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Total Classes</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Present</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance %</th>
                                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Camera On</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php 
                                            $top_attendance_students = array_slice($student_attendance_data, 0, 10);
                                            foreach ($top_attendance_students as $index => $student): 
                                            ?>
                                                <tr class="hover:bg-gradient-to-r hover:from-yellow-50 hover:to-white transition-all duration-300">
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full 
                                                            <?= $index < 3 ? 'bg-gradient-to-r from-yellow-400 to-yellow-500 text-white shadow-md' : 'bg-gray-100 text-gray-800' ?> text-sm font-medium">
                                                            <?= $index + 1 ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-sm font-bold mr-3 shadow-sm">
                                                                <?= strtoupper(substr($student['student_name'], 0, 1)) ?>
                                                            </div>
                                                            <div>
                                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($student['student_name']) ?></div>
                                                                <div class="text-sm text-gray-500"><?= htmlspecialchars($student['student_id']) ?></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900"><?= $student['total_classes'] ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900"><?= $student['present_count'] ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                                        <div class="flex items-center justify-center gap-2">
                                                            <span class="text-sm font-medium 
                                                                <?= ($student['attendance_percentage'] ?? 0) >= 80 ? 'text-green-600' : 
                                                                   (($student['attendance_percentage'] ?? 0) >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                                <?= $student['attendance_percentage'] ?? 0 ?>%
                                                            </span>
                                                            <div class="w-16 bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                                                <div class="bg-gradient-to-r from-green-500 to-green-600 h-1.5 rounded-full" style="width: <?= $student['attendance_percentage'] ?? 0 ?>%"></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                                        <span class="flex items-center justify-center gap-1">
                                                            <i class="fas fa-video text-green-500 text-xs"></i>
                                                            <?= $student['camera_on_count'] ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Enrollment & Capacity Report -->
            <?php if ($view_type === 'enrollment'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800">Batch Enrollment & Capacity Report</h2>
                        <p class="text-gray-600">Detailed enrollment statistics and capacity analysis</p>
                    </div>

                    <div class="p-6">
                        <!-- Key Enrollment Metrics -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-blue-600"><?= $enrollment_data['current_enrollment'] ?? 0 ?></div>
                                <div class="text-blue-800 font-medium">Current Enrollment</div>
                                <div class="text-sm text-blue-600 mt-2">Out of <?= $enrollment_data['max_students'] ?? 0 ?> capacity</div>
                            </div>
                            <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-green-600"><?= $enrollment_data['enrollment_rate'] ?? 0 ?>%</div>
                                <div class="text-green-800 font-medium">Enrollment Rate</div>
                                <div class="text-sm text-green-600 mt-2">Capacity Utilization</div>
                            </div>
                            <div class="bg-purple-50 border border-purple-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-purple-600">
                                    <?= max(0, ($enrollment_data['max_students'] ?? 0) - ($enrollment_data['current_enrollment'] ?? 0)) ?>
                                </div>
                                <div class="text-purple-800 font-medium">Available Seats</div>
                                <div class="text-sm text-purple-600 mt-2">Remaining capacity</div>
                            </div>
                        </div>

                        <!-- Detailed Enrollment Information -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <div class="space-y-6">
                                <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Batch Details</h3>
                                    <div class="space-y-3">
                                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                            <span class="text-gray-600">Batch ID:</span>
                                            <span class="font-medium"><?= htmlspecialchars($batch_data['batch_id']) ?></span>
                                        </div>
                                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                            <span class="text-gray-600">Batch Name:</span>
                                            <span class="font-medium"><?= htmlspecialchars($batch_data['batch_name']) ?></span>
                                        </div>
                                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                            <span class="text-gray-600">Status:</span>
                                            <span class="px-3 py-1 rounded-full text-sm font-medium 
                                                <?= $batch_data['status'] === 'ongoing' ? 'bg-green-100 text-green-800' : 
                                                   ($batch_data['status'] === 'upcoming' ? 'bg-blue-100 text-blue-800' : 
                                                   'bg-gray-100 text-gray-800') ?>">
                                                <?= ucfirst($batch_data['status']) ?>
                                            </span>
                                        </div>
                                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                            <span class="text-gray-600">Time Slot:</span>
                                            <span class="font-medium"><?= htmlspecialchars($batch_data['time_slot']) ?></span>
                                        </div>
                                        <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                            <span class="text-gray-600">Platform:</span>
                                            <span class="font-medium"><?= htmlspecialchars($batch_data['platform'] ?: 'N/A') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Mentor Information</h3>
                                    <div class="flex items-center space-x-4">
                                        <?php if (!empty($batch_data['mentor_avatar'])): ?>
                                            <img src="<?= htmlspecialchars($batch_data['mentor_avatar']) ?>" 
                                                 class="w-12 h-12 rounded-full object-cover border-2 border-blue-500 shadow-md" 
                                                 alt="Mentor">
                                        <?php else: ?>
                                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-md">
                                                <i class="fas fa-user text-white"></i>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <h4 class="font-semibold text-gray-800"><?= htmlspecialchars($batch_data['mentor_name'] ?: 'Not assigned') ?></h4>
                                            <p class="text-sm text-gray-600">Batch Mentor</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-6">
                                <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Enrollment Progress</h3>
                                    <div class="space-y-4">
                                        <div>
                                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                                <span>Enrollment Progress</span>
                                                <span><?= $enrollment_data['enrollment_rate'] ?? 0 ?>%</span>
                                            </div>
                                            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                                <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all duration-1000" 
                                                     style="width: <?= $enrollment_data['enrollment_rate'] ?? 0 ?>%"></div>
                                            </div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4 text-center">
                                            <div class="bg-blue-50 p-3 rounded-lg">
                                                <div class="text-xl font-bold text-blue-600"><?= $enrollment_data['current_enrollment'] ?? 0 ?></div>
                                                <div class="text-sm text-blue-800">Current</div>
                                            </div>
                                            <div class="bg-gray-50 p-3 rounded-lg">
                                                <div class="text-xl font-bold text-gray-600"><?= $enrollment_data['max_students'] ?? 0 ?></div>
                                                <div class="text-sm text-gray-800">Maximum</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Student Status Distribution</h3>
                                    <div class="space-y-3">
                                        <div class="flex justify-between items-center p-2 hover:bg-gray-50 rounded-lg transition">
                                            <span class="text-gray-600">Active Students:</span>
                                            <span class="font-medium text-green-600"><?= $enrollment_data['active_students'] ?? 0 ?></span>
                                        </div>
                                        <div class="flex justify-between items-center p-2 hover:bg-gray-50 rounded-lg transition">
                                            <span class="text-gray-600">Completed:</span>
                                            <span class="font-medium text-blue-600"><?= $enrollment_data['completed_students'] ?? 0 ?></span>
                                        </div>
                                        <div class="flex justify-between items-center p-2 hover:bg-gray-50 rounded-lg transition">
                                            <span class="text-gray-600">Dropped:</span>
                                            <span class="font-medium text-red-600"><?= $enrollment_data['dropped_students'] ?? 0 ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Completion & Dropout Analysis -->
            <?php if ($view_type === 'completion'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <div class="bg-gradient-to-r from-purple-50 to-indigo-50 p-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800">Batch Completion & Dropout Analysis</h2>
                        <p class="text-gray-600">Track completion rates and analyze dropout patterns</p>
                    </div>

                    <div class="p-6">
                        <!-- Completion Metrics -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                            <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-green-600"><?= $enrollment_data['completed_students'] ?? 0 ?></div>
                                <div class="text-green-800 font-medium">Completed</div>
                                <i class="fas fa-check-circle text-green-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-blue-600"><?= $enrollment_data['active_students'] ?? 0 ?></div>
                                <div class="text-blue-800 font-medium">Active</div>
                                <i class="fas fa-user-graduate text-blue-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-red-50 to-red-100 border border-red-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-red-600"><?= $enrollment_data['dropped_students'] ?? 0 ?></div>
                                <div class="text-red-800 font-medium">Dropped</div>
                                <i class="fas fa-user-slash text-red-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-orange-600">
                                    <?= $completion_data['dropout_rate'] ?? 0 ?>%
                                </div>
                                <div class="text-orange-800 font-medium">Dropout Rate</div>
                                <i class="fas fa-chart-line text-orange-400 mt-2 text-lg"></i>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Timeline Analysis -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Timeline Analysis</h3>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <span class="text-gray-600">Start Date:</span>
                                        <span class="font-medium"><?= date('M d, Y', strtotime($batch_data['start_date'])) ?></span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <span class="text-gray-600">Expected End:</span>
                                        <span class="font-medium"><?= date('M d, Y', strtotime($batch_data['end_date'])) ?></span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <span class="text-gray-600">Duration:</span>
                                        <span class="font-medium">
                                            <?php 
                                            $start = new DateTime($batch_data['start_date']);
                                            $end = new DateTime($batch_data['end_date']);
                                            $diff = $start->diff($end);
                                            echo $diff->format('%m months %d days');
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Student Status Distribution -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Student Status Distribution</h3>
                                <div class="space-y-3">
                                    <?php
                                    $total_students = $enrollment_data['current_enrollment'] ?? 0;
                                    $active_percentage = $total_students > 0 ? round(($enrollment_data['active_students'] / $total_students) * 100, 2) : 0;
                                    $completed_percentage = $total_students > 0 ? round(($enrollment_data['completed_students'] / $total_students) * 100, 2) : 0;
                                    $dropped_percentage = $total_students > 0 ? round(($enrollment_data['dropped_students'] / $total_students) * 100, 2) : 0;
                                    ?>
                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-green-500 rounded-full mr-2"></div>
                                            <span class="text-gray-600">Active</span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span class="font-medium"><?= $enrollment_data['active_students'] ?? 0 ?></span>
                                            <span class="text-sm text-gray-500">(<?= $active_percentage ?>%)</span>
                                        </div>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                        <div class="bg-gradient-to-r from-green-500 to-green-600 h-2 rounded-full transition-all duration-1000" style="width: <?= $active_percentage ?>%"></div>
                                    </div>

                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-blue-500 rounded-full mr-2"></div>
                                            <span class="text-gray-600">Completed</span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span class="font-medium"><?= $enrollment_data['completed_students'] ?? 0 ?></span>
                                            <span class="text-sm text-gray-500">(<?= $completed_percentage ?>%)</span>
                                        </div>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                        <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-2 rounded-full transition-all duration-1000" style="width: <?= $completed_percentage ?>%"></div>
                                    </div>

                                    <div class="flex justify-between items-center">
                                        <div class="flex items-center">
                                            <div class="w-3 h-3 bg-red-500 rounded-full mr-2"></div>
                                            <span class="text-gray-600">Dropped</span>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span class="font-medium"><?= $enrollment_data['dropped_students'] ?? 0 ?></span>
                                            <span class="text-sm text-gray-500">(<?= $dropped_percentage ?>%)</span>
                                        </div>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                        <div class="bg-gradient-to-r from-red-500 to-red-600 h-2 rounded-full transition-all duration-1000" style="width: <?= $dropped_percentage ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Dropout Analysis -->
                        <div class="mt-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Dropout Analysis</h3>
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <?php if (!empty($completion_data['dropout_reasons'])): ?>
                                    <div class="space-y-4">
                                        <div class="flex justify-between items-center p-3 bg-red-50 rounded-lg">
                                            <span class="text-red-800 font-medium">Primary Dropout Reasons:</span>
                                            <span class="text-red-600"><?= htmlspecialchars($completion_data['dropout_reasons']) ?></span>
                                        </div>
                                        <div class="text-sm text-gray-600">
                                            Total dropout rate: <span class="font-medium"><?= $completion_data['dropout_rate'] ?? 0 ?>%</span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-500 text-center py-4">No dropout data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Exam Performance Report -->
            <?php if ($view_type === 'exams'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 p-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800">Batch Exam Performance Report</h2>
                        <p class="text-gray-600">Comprehensive analysis of student performance in examinations</p>
                    </div>

                    <div class="p-6">
                        <!-- Exam Performance Overview -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-blue-600"><?= count($exam_data) ?></div>
                                <div class="text-blue-800 font-medium">Exams Conducted</div>
                                <i class="fas fa-file-alt text-blue-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-green-600">
                                    <?= $exam_data[0]['average_percentage'] ?? 0 ?>%
                                </div>
                                <div class="text-green-800 font-medium">Average Score</div>
                                <i class="fas fa-chart-line text-green-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-purple-600">
                                    <?= $exam_data[0]['pass_rate'] ?? 0 ?>%
                                </div>
                                <div class="text-purple-800 font-medium">Pass Rate</div>
                                <i class="fas fa-check-circle text-purple-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-orange-600">
                                    <?= $exam_data[0]['students_taken'] ?? 0 ?>
                                </div>
                                <div class="text-orange-800 font-medium">Students Participated</div>
                                <i class="fas fa-users text-orange-400 mt-2 text-lg"></i>
                            </div>
                        </div>

                        <!-- Exam Performance Table -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Exam Performance Summary</h3>
                            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden transition-all duration-300 hover:shadow-lg">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exam</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Avg Score</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pass Rate</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Students</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php if (!empty($exam_data)): ?>
                                                <?php foreach ($exam_data as $index => $exam): ?>
                                                    <tr class="hover:bg-gradient-to-r hover:from-indigo-50 hover:to-white transition-all duration-300 <?= $index % 2 === 0 ? 'bg-white' : 'bg-gray-50' ?>">
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($exam['exam_name']) ?></div>
                                                            <div class="text-sm text-gray-500"><?= ucfirst($exam['exam_type']) ?></div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($exam['subject']) ?></td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="flex items-center">
                                                                <span class="text-sm font-medium 
                                                                    <?= ($exam['average_percentage'] ?? 0) >= 70 ? 'text-green-600' : 
                                                                       (($exam['average_percentage'] ?? 0) >= 50 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                                    <?= $exam['average_percentage'] ?? 0 ?>%
                                                                </span>
                                                                <div class="ml-2 w-16 bg-gray-200 rounded-full h-1.5 overflow-hidden">
                                                                    <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-1.5 rounded-full" style="width: <?= $exam['average_percentage'] ?? 0 ?>%"></div>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="flex items-center">
                                                                <span class="text-sm font-medium 
                                                                    <?= ($exam['pass_rate'] ?? 0) >= 70 ? 'text-green-600' : 
                                                                       (($exam['pass_rate'] ?? 0) >= 50 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                                    <?= $exam['pass_rate'] ?? 0 ?>%
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                            <?= $exam['passed_count'] ?? 0 ?>/<?= $exam['students_taken'] ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                                        <i class="fas fa-file-alt text-4xl mb-2 block"></i>
                                                        No exam data available
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Component-wise Performance -->
                        <?php if (!empty($component_data)): ?>
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Component-wise Performance</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <?php foreach ($component_data as $component): ?>
                                    <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg hover:-translate-y-1">
                                        <h4 class="font-semibold text-gray-800 mb-4"><?= htmlspecialchars($component['exam_name']) ?></h4>
                                        <div class="space-y-3">
                                            <?php if ($component['max_mcq'] > 0): ?>
                                            <div>
                                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                                    <span><i class="fas fa-tasks text-blue-500 mr-1"></i> MCQ</span>
                                                    <span><?= round($component['avg_mcq'], 1) ?>/<?= $component['max_mcq'] ?></span>
                                                </div>
                                                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-2 rounded-full transition-all duration-1000" 
                                                         style="width: <?= min(100, ($component['avg_mcq'] / $component['max_mcq']) * 100) ?>%"></div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($component['max_project'] > 0): ?>
                                            <div>
                                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                                    <span><i class="fas fa-project-diagram text-green-500 mr-1"></i> Project</span>
                                                    <span><?= round($component['avg_project'], 1) ?>/<?= $component['max_project'] ?></span>
                                                </div>
                                                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                                    <div class="bg-gradient-to-r from-green-500 to-green-600 h-2 rounded-full transition-all duration-1000" 
                                                         style="width: <?= min(100, ($component['avg_project'] / $component['max_project']) * 100) ?>%"></div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($component['max_viva'] > 0): ?>
                                            <div>
                                                <div class="flex justify-between text-sm text-gray-600 mb-1">
                                                    <span><i class="fas fa-microphone text-purple-500 mr-1"></i> Viva</span>
                                                    <span><?= round($component['avg_viva'], 1) ?>/<?= $component['max_viva'] ?></span>
                                                </div>
                                                <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                                                    <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-2 rounded-full transition-all duration-1000" 
                                                         style="width: <?= min(100, ($component['avg_viva'] / $component['max_viva']) * 100) ?>%"></div>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Top Performing Students -->
                        <?php if (!empty($top_students_data)): ?>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Top Performing Students</h3>
                            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden transition-all duration-300 hover:shadow-lg">
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gradient-to-r from-gray-50 to-gray-100">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Average Score</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exams Taken</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($top_students_data as $index => $student): ?>
                                                <tr class="hover:bg-gradient-to-r hover:from-yellow-50 hover:to-white transition-all duration-300">
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full 
                                                            <?= $index < 3 ? 'bg-gradient-to-r from-yellow-400 to-yellow-500 text-white shadow-md' : 'bg-gray-100 text-gray-800' ?> text-sm font-medium">
                                                            <?= $index + 1 ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-yellow-400 to-yellow-600 flex items-center justify-center text-white text-sm font-bold mr-3 shadow-sm">
                                                                <?= strtoupper(substr($student['student_name'], 0, 1)) ?>
                                                            </div>
                                                            <div>
                                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($student['student_name']) ?></div>
                                                                <div class="text-sm text-gray-500"><?= htmlspecialchars($student['student_id']) ?></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-green-600"><?= round($student['average_score'], 2) ?></div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= $student['exams_taken'] ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Feedback Analysis Report -->
            <?php if ($view_type === 'feedback'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <div class="bg-gradient-to-r from-pink-50 to-rose-50 p-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800">Weekly Feedback Analysis Report</h2>
                        <p class="text-gray-600">Student feedback analysis and satisfaction tracking</p>
                    </div>

                    <div class="p-6">
                        <!-- Feedback Overview -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                            <div class="bg-gradient-to-br from-pink-50 to-pink-100 border border-pink-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-pink-600">
                                    <?= number_format($feedback_summary['overall_rating'] ?? 0, 1) ?>/5
                                </div>
                                <div class="text-pink-800 font-medium">Overall Rating</div>
                                <i class="fas fa-star text-pink-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-blue-600">
                                    <?= number_format($feedback_summary['avg_class_rating'] ?? 0, 1) ?>/5
                                </div>
                                <div class="text-blue-800 font-medium">Class Rating</div>
                                <i class="fas fa-chalkboard text-blue-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-green-600">
                                    <?= number_format($feedback_summary['avg_assignment_rating'] ?? 0, 1) ?>/5
                                </div>
                                <div class="text-green-800 font-medium">Assignment Rating</div>
                                <i class="fas fa-tasks text-green-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-purple-600">
                                    <?= $feedback_summary['satisfied_count'] ?? 0 ?>
                                </div>
                                <div class="text-purple-800 font-medium">Satisfied Students</div>
                                <i class="fas fa-smile text-purple-400 mt-2 text-lg"></i>
                            </div>
                        </div>

                        <!-- Monthly Feedback Trends -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Monthly Feedback Trends</h3>
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <?php if (!empty($feedback_data)): ?>
                                    <div class="space-y-4">
                                        <?php foreach ($feedback_data as $feedback): ?>
                                            <div class="flex items-center justify-between p-4 bg-gradient-to-r from-gray-50 to-white rounded-lg hover:shadow-md transition-all duration-300">
                                                <div>
                                                    <span class="font-medium text-gray-800">
                                                        <?= $feedback['month_name'] ?>
                                                    </span>
                                                    <div class="text-sm text-gray-600">
                                                        <?= $feedback['total_feedback'] ?> feedback entries
                                                    </div>
                                                </div>
                                                <div class="text-right">
                                                    <div class="flex items-center space-x-4">
                                                        <div>
                                                            <div class="text-lg font-bold text-blue-600">
                                                                <?= number_format($feedback['overall_rating'], 1) ?>/5
                                                            </div>
                                                            <div class="text-xs text-gray-500">Overall</div>
                                                        </div>
                                                        <div>
                                                            <div class="text-lg font-bold text-green-600">
                                                                <?= $feedback['satisfied_count'] ?>
                                                            </div>
                                                            <div class="text-xs text-gray-500">Satisfied</div>
                                                        </div>
                                                        <div>
                                                            <div class="text-lg font-bold text-purple-600">
                                                                <?= $feedback['regular_count'] ?>
                                                            </div>
                                                            <div class="text-xs text-gray-500">Regular</div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-500 text-center py-4">No feedback data available</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Rating Breakdown -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Rating Categories -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Rating Categories</h3>
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                            <span>Class Understanding</span>
                                            <span><?= number_format($feedback_summary['avg_class_rating'] ?? 0, 1) ?>/5</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                            <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all duration-1000" 
                                                 style="width: <?= (($feedback_summary['avg_class_rating'] ?? 0) / 5) * 100 ?>%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                            <span>Assignment Understanding</span>
                                            <span><?= number_format($feedback_summary['avg_assignment_rating'] ?? 0, 1) ?>/5</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                            <div class="bg-gradient-to-r from-green-500 to-green-600 h-3 rounded-full transition-all duration-1000" 
                                                 style="width: <?= (($feedback_summary['avg_assignment_rating'] ?? 0) / 5) * 100 ?>%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                            <span>Practical Understanding</span>
                                            <span><?= number_format($feedback_summary['avg_practical_rating'] ?? 0, 1) ?>/5</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                            <div class="bg-gradient-to-r from-purple-500 to-purple-600 h-3 rounded-full transition-all duration-1000" 
                                                 style="width: <?= (($feedback_summary['avg_practical_rating'] ?? 0) / 5) * 100 ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Satisfaction & Regularity -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Satisfaction & Regularity</h3>
                                <div class="space-y-6">
                                    <div>
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-gray-600">Student Satisfaction</span>
                                            <span class="font-medium text-green-600">
                                                <?= ($feedback_summary['total_feedback'] ?? 0) > 0 ? 
                                                    round((($feedback_summary['satisfied_count'] ?? 0) / ($feedback_summary['total_feedback'] ?? 1)) * 100, 1) : 0 ?>%
                                            </span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                            <div class="bg-gradient-to-r from-green-500 to-green-600 h-3 rounded-full transition-all duration-1000" 
                                                 style="width: <?= ($feedback_summary['total_feedback'] ?? 0) > 0 ? 
                                                    (($feedback_summary['satisfied_count'] ?? 0) / ($feedback_summary['total_feedback'] ?? 1)) * 100 : 0 ?>%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-2">
                                            <span class="text-gray-600">Regularity Pattern</span>
                                            <span class="font-medium text-blue-600">
                                                <?= ($feedback_summary['total_feedback'] ?? 0) > 0 ? 
                                                    round((($feedback_summary['regular_count'] ?? 0) / ($feedback_summary['total_feedback'] ?? 1)) * 100, 1) : 0 ?>%
                                            </span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                            <div class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all duration-1000" 
                                                 style="width: <?= ($feedback_summary['total_feedback'] ?? 0) > 0 ? 
                                                    (($feedback_summary['regular_count'] ?? 0) / ($feedback_summary['total_feedback'] ?? 1)) * 100 : 0 ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Common Suggestions -->
                        <?php if (!empty($feedback_data[0]['common_suggestions'])): ?>
                        <div class="mt-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Common Suggestions & Issues</h3>
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <div class="space-y-3">
                                    <?php 
                                    $suggestions = explode('|', $feedback_data[0]['common_suggestions']);
                                    $unique_suggestions = array_unique(array_filter($suggestions));
                                    ?>
                                    <?php foreach (array_slice($unique_suggestions, 0, 5) as $suggestion): ?>
                                        <div class="flex items-start space-x-3 p-3 bg-gray-50 rounded-lg hover:bg-gradient-to-r hover:from-yellow-50 hover:to-white transition-all duration-300">
                                            <i class="fas fa-comment text-gray-400 mt-1"></i>
                                            <span class="text-gray-700"><?= htmlspecialchars($suggestion) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Schedule & Utilization Report -->
            <?php if ($view_type === 'schedule'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <div class="bg-gradient-to-r from-amber-50 to-orange-50 p-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800">Batch Schedule & Utilization Report</h2>
                        <p class="text-gray-600">Class scheduling efficiency and resource utilization analysis</p>
                    </div>

                    <div class="p-6">
                        <!-- Schedule Metrics -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-blue-600"><?= $schedule_data['total_scheduled'] ?? 0 ?></div>
                                <div class="text-blue-800 font-medium">Total Scheduled</div>
                                <i class="fas fa-calendar-alt text-blue-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-red-50 to-red-100 border border-red-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-red-600"><?= $schedule_data['cancelled_count'] ?? 0 ?></div>
                                <div class="text-red-800 font-medium">Cancelled</div>
                                <i class="fas fa-ban text-red-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-orange-600"><?= $schedule_data['cancellation_rate'] ?? 0 ?>%</div>
                                <div class="text-orange-800 font-medium">Cancellation Rate</div>
                                <i class="fas fa-chart-line text-orange-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-green-600">
                                    <?= ($schedule_data['total_scheduled'] ?? 0) - ($schedule_data['cancelled_count'] ?? 0) ?>
                                </div>
                                <div class="text-green-800 font-medium">Conducted</div>
                                <i class="fas fa-check-circle text-green-400 mt-2 text-lg"></i>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            <!-- Schedule Analysis -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Schedule Analysis</h3>
                                <div class="space-y-4">
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <span class="text-gray-600">First Class:</span>
                                        <span class="font-medium"><?= $schedule_data['first_class'] ? date('M d, Y', strtotime($schedule_data['first_class'])) : 'N/A' ?></span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <span class="text-gray-600">Last Class:</span>
                                        <span class="font-medium"><?= $schedule_data['last_class'] ? date('M d, Y', strtotime($schedule_data['last_class'])) : 'N/A' ?></span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <span class="text-gray-600">Average Duration:</span>
                                        <span class="font-medium"><?= round(($schedule_data['avg_duration_minutes'] ?? 0) / 60, 1) ?> hours</span>
                                    </div>
                                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                        <span class="text-gray-600">Conducted Classes:</span>
                                        <span class="font-medium text-green-600">
                                            <?= ($schedule_data['total_scheduled'] ?? 0) - ($schedule_data['cancelled_count'] ?? 0) ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Cancellation Analysis -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Cancellation Analysis</h3>
                                <div class="space-y-4">
                                    <div>
                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                            <span>Cancellation Rate</span>
                                            <span><?= $schedule_data['cancellation_rate'] ?? 0 ?>%</span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                                            <div class="bg-gradient-to-r from-red-500 to-red-600 h-3 rounded-full transition-all duration-1000" 
                                                 style="width: <?= $schedule_data['cancellation_rate'] ?? 0 ?>%"></div>
                                        </div>
                                    </div>
                                    <?php if (!empty($schedule_data['cancellation_reasons'])): ?>
                                    <div>
                                        <h4 class="font-medium text-gray-700 mb-2">Cancellation Reasons</h4>
                                        <div class="text-sm text-gray-600 bg-red-50 p-3 rounded-lg">
                                            <?= htmlspecialchars($schedule_data['cancellation_reasons']) ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Time Utilization -->
                        <div class="mt-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Time Utilization Analysis</h3>
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-blue-600"><?= $schedule_data['total_scheduled'] ?? 0 ?></div>
                                        <div class="text-sm text-gray-600">Planned Classes</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold text-green-600">
                                            <?= ($schedule_data['total_scheduled'] ?? 0) - ($schedule_data['cancelled_count'] ?? 0) ?>
                                        </div>
                                        <div class="text-sm text-gray-600">Actual Classes</div>
                                    </div>
                                    <div class="text-center">
                                        <div class="text-2xl font-bold 
                                            <?= ($schedule_data['cancellation_rate'] ?? 0) < 10 ? 'text-green-600' : 
                                               (($schedule_data['cancellation_rate'] ?? 0) < 20 ? 'text-yellow-600' : 'text-red-600') ?>">
                                            <?= 100 - ($schedule_data['cancellation_rate'] ?? 0) ?>%
                                        </div>
                                        <div class="text-sm text-gray-600">Utilization Rate</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Resource Distribution Report -->
            <?php if ($view_type === 'resources'): ?>
                <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl overflow-hidden mb-8 transition-all duration-500 hover:shadow-2xl animate-fade-in">
                    <div class="bg-gradient-to-r from-teal-50 to-cyan-50 p-6 border-b border-gray-200">
                        <h2 class="text-2xl font-bold text-gray-800">Resource Distribution Report</h2>
                        <p class="text-gray-600">Learning materials and resource allocation analysis</p>
                    </div>

                    <div class="p-6">
                        <!-- Resource Overview -->
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                            <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-blue-600">
                                    <?= array_sum(array_column($resource_data, 'count')) ?>
                                </div>
                                <div class="text-blue-800 font-medium">Total Resources</div>
                                <i class="fas fa-file-alt text-blue-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-green-50 to-green-100 border border-green-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-green-600">
                                    <?= count(array_unique(array_column($resource_data, 'uploaded_by'))) ?>
                                </div>
                                <div class="text-green-800 font-medium">Contributors</div>
                                <i class="fas fa-users text-green-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-purple-50 to-purple-100 border border-purple-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-purple-600">
                                    <?= count(array_unique(array_column($resource_data, 'file_type'))) ?>
                                </div>
                                <div class="text-purple-800 font-medium">File Types</div>
                                <i class="fas fa-file-code text-purple-400 mt-2 text-lg"></i>
                            </div>
                            <div class="bg-gradient-to-br from-orange-50 to-orange-100 border border-orange-200 rounded-lg p-4 text-center transition-all duration-300 hover:scale-105 hover:shadow-lg">
                                <div class="text-3xl font-bold text-orange-600">
                                    <?= !empty($resource_data) ? date('M d, Y', strtotime($resource_data[0]['last_upload'])) : 'N/A' ?>
                                </div>
                                <div class="text-orange-800 font-medium">Last Upload</div>
                                <i class="fas fa-upload text-orange-400 mt-2 text-lg"></i>
                            </div>
                        </div>

                        <!-- Resource Distribution by Type -->
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Resource Distribution by Type</h3>
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <?php if (!empty($resource_data)): ?>
                                    <div class="space-y-4">
                                        <?php 
                                        // Group by file type
                                        $type_groups = [];
                                        foreach ($resource_data as $resource) {
                                            $type = $resource['file_type'] ?: 'Unknown';
                                            if (!isset($type_groups[$type])) {
                                                $type_groups[$type] = 0;
                                            }
                                            $type_groups[$type] += $resource['count'];
                                        }
                                        ?>
                                        <?php foreach ($type_groups as $type => $count): ?>
                                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:shadow-md transition-all duration-300">
                                                <div class="flex items-center space-x-3">
                                                    <i class="fas 
                                                        <?= strpos(strtolower($type), 'Assignment') !== false ? 'fa-file-alt text-blue-500' : 
                                                           (strpos(strtolower($type), 'Notes') !== false ? 'fa-book text-green-500' : 
                                                           (strpos(strtolower($type), 'Test') !== false ? 'fa-file-pdf text-red-500' : 
                                                           (strpos(strtolower($type), 'Lab') !== false ? 'fa-flask text-purple-500' : 
                                                           'fa-file text-gray-500'))) ?> text-xl">
                                                    </i>
                                                    <span class="font-medium text-gray-800"><?= ucfirst($type) ?></span>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <span class="font-medium"><?= $count ?></span>
                                                    <span class="text-sm text-gray-500">files</span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-500 text-center py-4">No resource data available</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Contributor Activity -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Contributor Activity</h3>
                            <div class="bg-white border border-gray-200 rounded-lg p-6 transition-all duration-300 hover:shadow-lg">
                                <?php if (!empty($resource_data)): ?>
                                    <div class="space-y-4">
                                        <?php 
                                        // Group by uploaded_by
                                        $contributor_groups = [];
                                        foreach ($resource_data as $resource) {
                                            $contributor = $resource['uploaded_by'] ?: 'Unknown';
                                            if (!isset($contributor_groups[$contributor])) {
                                                $contributor_groups[$contributor] = [
                                                    'count' => 0,
                                                    'last_upload' => $resource['last_upload']
                                                ];
                                            }
                                            $contributor_groups[$contributor]['count'] += $resource['count'];
                                            if (strtotime($resource['last_upload']) > strtotime($contributor_groups[$contributor]['last_upload'])) {
                                                $contributor_groups[$contributor]['last_upload'] = $resource['last_upload'];
                                            }
                                        }
                                        ?>
                                        <?php foreach ($contributor_groups as $contributor => $data): ?>
                                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:shadow-md transition-all duration-300">
                                                <div>
                                                    <span class="font-medium text-gray-800"><?= htmlspecialchars($contributor) ?></span>
                                                    <div class="text-sm text-gray-600">
                                                        Last upload: <?= date('M d, Y', strtotime($data['last_upload'])) ?>
                                                    </div>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <span class="font-medium text-blue-600"><?= $data['count'] ?></span>
                                                    <span class="text-sm text-gray-500">contributions</span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-500 text-center py-4">No contributor data available</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif (!empty($selected_batch_id)): ?>
            <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl p-8 text-center animate-fade-in">
                <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Batch Not Found</h2>
                <p class="text-gray-600">The selected batch could not be found or you don't have permission to view it.</p>
                <a href="batches.php" class="inline-block mt-4 bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-2 rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all duration-300 shadow-md hover:shadow-lg">
                    Back to Batches
                </a>
            </div>
        <?php else: ?>
            <div class="bg-white/80 backdrop-blur-sm rounded-2xl shadow-xl p-8 text-center animate-fade-in">
                <i class="fas fa-chart-bar text-blue-500 text-4xl mb-4"></i>
                <h2 class="text-xl font-semibold text-gray-800 mb-2">Select a Batch</h2>
                <p class="text-gray-600">Choose a batch from the sections above to view detailed reports and analytics.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Custom CSS for Animations -->
<style>
    @keyframes blob {
        0% { transform: translate(0px, 0px) scale(1); }
        33% { transform: translate(30px, -50px) scale(1.1); }
        66% { transform: translate(-20px, 20px) scale(0.9); }
        100% { transform: translate(0px, 0px) scale(1); }
    }
    
    @keyframes fade-in {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    @keyframes slide-up {
        from { opacity: 0; transform: translateY(40px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .animate-blob {
        animation: blob 7s infinite;
    }
    
    .animation-delay-2000 {
        animation-delay: 2s;
    }
    
    .animation-delay-4000 {
        animation-delay: 4s;
    }
    
    .animate-fade-in {
        animation: fade-in 0.6s ease-out;
    }
    
    .animate-slide-up {
        animation: slide-up 0.5s ease-out;
    }
    
    .scroll-container {
        scrollbar-width: thin;
        scrollbar-color: var(--primary) #e5e7eb;
    }
    
    .scroll-container::-webkit-scrollbar {
        height: 6px;
    }
    
    .scroll-container::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 3px;
    }
    
    .scroll-container::-webkit-scrollbar-thumb {
        background: var(--primary);
        border-radius: 3px;
    }
    
    .scroll-container::-webkit-scrollbar-thumb:hover {
        background: #3b82f6;
    }

    /* Print styles */
    @media print {
        .ml-64 {
            margin-left: 0 !important;
        }
        .sidebar, .header, .fixed, .scroll-left-ongoing, .scroll-right-ongoing,
        .scroll-left-upcoming, .scroll-right-upcoming, .scroll-left-completed, .scroll-right-completed,
        button, .flex-space-x-4, .bg-gradient-to-r, .animate-blob, .backdrop-blur-sm {
            display: none !important;
        }
        body {
            background: white !important;
        }
        .bg-white\/80 {
            background: white !important;
        }
        .shadow-xl, .shadow-md {
            box-shadow: none !important;
        }
        .border {
            border: 1px solid #e5e7eb !important;
        }
        .batch-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
    }
</style>

<script>
// Horizontal scroll functionality for batch grids
document.querySelectorAll('.scroll-left-ongoing, .scroll-right-ongoing, .scroll-left-upcoming, .scroll-right-upcoming, .scroll-left-completed, .scroll-right-completed').forEach(button => {
    button.addEventListener('click', function() {
        let scrollContainer;
        if (this.classList.contains('scroll-left-ongoing') || this.classList.contains('scroll-right-ongoing')) {
            scrollContainer = document.querySelector('.ongoing-scroll');
        } else if (this.classList.contains('scroll-left-upcoming') || this.classList.contains('scroll-right-upcoming')) {
            scrollContainer = document.querySelector('.upcoming-scroll');
        } else {
            scrollContainer = document.querySelector('.completed-scroll');
        }
        
        const scrollAmount = 300;
        if (this.classList.contains('scroll-left-ongoing') || this.classList.contains('scroll-left-upcoming') || this.classList.contains('scroll-left-completed')) {
            scrollContainer.scrollBy({ left: -scrollAmount, behavior: 'smooth' });
        } else {
            scrollContainer.scrollBy({ left: scrollAmount, behavior: 'smooth' });
        }
    });
});

// Chart.js initialization
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($chart_data)): ?>
        // Enrollment Chart
        <?php if (!empty($chart_data['enrollment'])): ?>
            const enrollmentCtx = document.getElementById('enrollmentChart');
            if (enrollmentCtx) {
                new Chart(enrollmentCtx.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($chart_data['enrollment']['labels']) ?>,
                        datasets: [{
                            data: <?= json_encode($chart_data['enrollment']['data']) ?>,
                            backgroundColor: <?= json_encode($chart_data['enrollment']['colors']) ?>,
                            borderWidth: 2,
                            borderColor: '#fff',
                            hoverOffset: 15
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: { size: 12 },
                                    usePointStyle: true,
                                    padding: 20
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                },
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                titleColor: '#fff',
                                bodyColor: '#e5e7eb',
                                padding: 10,
                                cornerRadius: 8
                            }
                        },
                        animation: {
                            animateRotate: true,
                            duration: 1500,
                            easing: 'easeOutCubic'
                        }
                    }
                });
            }
        <?php endif; ?>

        // Attendance Trend Chart
        <?php if (!empty($chart_data['attendance_trend'])): ?>
            const attendanceCtx = document.getElementById('attendanceTrendChart');
            if (attendanceCtx) {
                new Chart(attendanceCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($chart_data['attendance_trend']['labels']) ?>,
                        datasets: [{
                            label: 'Attendance Rate (%)',
                            data: <?= json_encode($chart_data['attendance_trend']['data']) ?>,
                            borderColor: '<?= $chart_data['attendance_trend']['color'] ?>',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 3,
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '<?= $chart_data['attendance_trend']['color'] ?>',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            pointHoverBackgroundColor: '<?= $chart_data['attendance_trend']['color'] ?>'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    callback: function(value) { return value + '%'; },
                                    stepSize: 20
                                },
                                grid: { color: '#e5e7eb', drawBorder: true }
                            },
                            x: { grid: { display: false } }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) { return `Attendance: ${context.raw}%`; }
                                },
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                titleColor: '#fff',
                                bodyColor: '#e5e7eb',
                                padding: 10,
                                cornerRadius: 8
                            }
                        },
                        animation: { duration: 1500, easing: 'easeOutCubic' }
                    }
                });
            }
        <?php endif; ?>

        // Exam Performance Chart
        <?php if (!empty($chart_data['exam_performance'])): ?>
            const examCtx = document.getElementById('examPerformanceChart');
            if (examCtx) {
                new Chart(examCtx.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($chart_data['exam_performance']['labels']) ?>,
                        datasets: [{
                            label: 'Average Score (%)',
                            data: <?= json_encode($chart_data['exam_performance']['data']) ?>,
                            backgroundColor: '<?= $chart_data['exam_performance']['color'] ?>',
                            borderColor: '<?= $chart_data['exam_performance']['color'] ?>',
                            borderWidth: 2,
                            borderRadius: 8,
                            barPercentage: 0.65,
                            categoryPercentage: 0.8
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                ticks: {
                                    callback: function(value) { return value + '%'; },
                                    stepSize: 20
                                },
                                grid: { color: '#e5e7eb' }
                            },
                            x: {
                                grid: { display: false },
                                ticks: { maxRotation: 45, minRotation: 45 }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) { return `Score: ${context.raw}%`; }
                                },
                                backgroundColor: 'rgba(0,0,0,0.8)',
                                titleColor: '#fff',
                                bodyColor: '#e5e7eb',
                                padding: 10,
                                cornerRadius: 8
                            }
                        },
                        animation: { duration: 1500, easing: 'easeOutCubic' }
                    }
                });
            }
        <?php endif; ?>
    <?php endif; ?>
});

// Export to PDF function
function exportToPDF() {
    alert('PDF export functionality would be implemented here. This could integrate with libraries like jsPDF or generate server-side PDFs.');
}

// Print function
function printReport() {
    window.print();
}
</script>

<style>
/* Modal CSS */
.trn-modal-backdrop {
    position: fixed; top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.6); backdrop-filter: blur(5px);
    display: flex; align-items: center; justify-content: center;
    z-index: 9999; opacity: 0; pointer-events: none; transition: opacity 0.3s;
}
.trn-modal-backdrop.active { opacity: 1; pointer-events: auto; }
.trn-modal-box {
    background: #fff; width: 90%; max-width: 600px; border-radius: 24px;
    overflow: hidden; transform: translateY(30px) scale(0.95); transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
}
.trn-modal-backdrop.active .trn-modal-box { transform: translateY(0) scale(1); }
.trn-modal-header {
    padding: 24px; color: #fff; display: flex; justify-content: space-between; align-items: center;
}
.trn-close-btn {
    background: rgba(255,255,255,0.2); border: none; width: 36px; height: 36px;
    border-radius: 50%; color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background 0.2s;
}
.trn-close-btn:hover { background: rgba(255,255,255,0.4); }
.trn-modal-body { padding: 24px; background: #f8fafc; }
</style>

<!-- Batch Overview Modal -->
<div class="trn-modal-backdrop" id="batchInfoModal" onclick="closeBatchModal(event)">
  <div class="trn-modal-box">
    <div class="trn-modal-header" style="background:linear-gradient(135deg,#3b82f6,#8b5cf6)">
      <div class="flex items-center gap-4">
        <div class="bg-white/20 p-4 rounded-xl text-2xl shadow-inner"><i class="fas fa-layer-group"></i></div>
        <div>
          <h4 id="batchModalName" class="text-2xl font-bold m-0 tracking-wide">Batch Name</h4>
          <small id="batchModalId" class="opacity-80 font-mono tracking-wider">Batch ID</small>
        </div>
      </div>
      <button class="trn-close-btn" onclick="closeBatchModal()"><i class="fas fa-times"></i></button>
    </div>
    <div class="trn-modal-body">
      <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <span class="text-xs text-gray-500 block mb-2 uppercase tracking-wider font-semibold"><i class="fas fa-info-circle mr-1"></i>Status</span>
            <span id="batchModalStatus" class="font-bold px-3 py-1.5 rounded-lg text-sm shadow-inner inline-block"></span>
        </div>
        <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <span class="text-xs text-gray-500 block mb-2 uppercase tracking-wider font-semibold"><i class="fas fa-calendar-alt mr-1"></i>Duration</span>
            <div class="font-bold text-gray-800 text-sm"><span id="batchModalStart"></span> <br><span class="text-gray-400 font-normal text-xs">to</span> <span id="batchModalEnd"></span></div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <span class="text-xs text-gray-500 block mb-2 uppercase tracking-wider font-semibold"><i class="fas fa-clock mr-1"></i>Time Slot</span>
            <div id="batchModalTime" class="font-bold text-gray-800 text-sm"></div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <span class="text-xs text-gray-500 block mb-2 uppercase tracking-wider font-semibold"><i class="fas fa-laptop-house mr-1"></i>Mode</span>
            <div id="batchModalMode" class="font-bold text-indigo-600 text-sm uppercase tracking-wide"></div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <span class="text-xs text-gray-500 block mb-2 uppercase tracking-wider font-semibold"><i class="fas fa-chalkboard-teacher mr-1"></i>Mentor</span>
            <div id="batchModalMentor" class="font-bold text-gray-800 text-sm"></div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm hover:shadow-md transition-shadow">
            <span class="text-xs text-gray-500 block mb-2 uppercase tracking-wider font-semibold"><i class="fas fa-users mr-1"></i>Enrollment</span>
            <div class="font-bold text-blue-600 text-lg"><span id="batchModalEnrolled"></span> <span class="text-gray-400 text-sm font-normal">/ <span id="batchModalMax"></span></span></div>
        </div>
      </div>
      
    </div>
  </div>
</div>

<script>
function openBatchInfoModal(el) {
    const data = el.dataset;
    document.getElementById('batchModalId').textContent = data.id;
    document.getElementById('batchModalName').textContent = data.name;
    
    const statusEl = document.getElementById('batchModalStatus');
    statusEl.textContent = data.status.toUpperCase();
    if(data.status === 'ongoing') {
        statusEl.className = 'font-bold px-3 py-1.5 rounded-lg text-sm shadow-inner inline-block bg-green-100 text-green-800 border border-green-200';
    } else if(data.status === 'completed') {
        statusEl.className = 'font-bold px-3 py-1.5 rounded-lg text-sm shadow-inner inline-block bg-gray-100 text-gray-800 border border-gray-200';
    } else {
        statusEl.className = 'font-bold px-3 py-1.5 rounded-lg text-sm shadow-inner inline-block bg-blue-100 text-blue-800 border border-blue-200';
    }
    
    document.getElementById('batchModalStart').textContent = data.start;
    document.getElementById('batchModalEnd').textContent = data.end;
    document.getElementById('batchModalTime').textContent = data.time;
    document.getElementById('batchModalMode').textContent = data.mode;
    document.getElementById('batchModalMentor').textContent = data.mentor;
    document.getElementById('batchModalEnrolled').textContent = data.enrolled;
    document.getElementById('batchModalMax').textContent = data.max;
    
    document.getElementById('batchInfoModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeBatchModal(e) {
    if(e && e.target !== document.getElementById('batchInfoModal')) return;
    document.getElementById('batchInfoModal').classList.remove('active');
    document.body.style.overflow = '';
}
</script>

<?php require_once '../footer.php'; ?>