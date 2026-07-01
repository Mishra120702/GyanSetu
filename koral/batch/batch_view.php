<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// Get batch ID from URL
$batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : null;

if (!$batch_id) {
    header("Location: ../batch/batch_list.php");
    exit();
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get batch details with mentor information
    $stmt = $db->prepare("SELECT 
                            b.batch_id,
                            b.batch_name,
                            b.course_description,
                            b.start_date,
                            b.end_date,
                            b.time_slot,
                            b.platform,
                            b.meeting_link,
                            b.thumbnail_path,
                            b.max_students,
                            b.current_enrollment,
                            b.mode,
                            b.status,
                            b.academic_year,
                            t.name as mentor_name,
                            t.email as mentor_email,
                            t.profile_picture as mentor_avatar
                        FROM batches b
                        LEFT JOIN trainers t ON b.batch_mentor_id = t.id
                        WHERE b.batch_id = ?");
    $stmt->execute([$batch_id]);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$batch) {
        header("Location: ../batch/batch_list.php");
        exit();
    }
    
    // Get students in this batch
    if ($batch['status'] === 'completed') {
        // For completed batches, show all students who were ever part of this batch
        $stmt = $db->prepare("
            SELECT DISTINCT
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.phone_number,
                s.enrollment_date,
                s.current_status,
                s.batch_name as current_batch,
                s.batch_name_2,
                s.batch_name_3,
                s.batch_name_4,
                CASE 
                    WHEN s.batch_name = :batch_id OR s.batch_name_2 = :batch_id OR s.batch_name_3 = :batch_id OR s.batch_name_4 = :batch_id THEN 'current'
                    WHEN EXISTS (
                        SELECT 1 FROM student_batch_history h 
                        WHERE h.student_id = s.student_id AND h.from_batch_id = :batch_id
                    ) THEN 'transferred'
                    ELSE 'historical'
                END as student_type,
                (SELECT h2.to_batch_id FROM student_batch_history h2 
                 WHERE h2.student_id = s.student_id AND h2.from_batch_id = :batch_id2 
                 ORDER BY h2.transfer_date DESC LIMIT 1) as transferred_to_batch,
                CASE 
                    WHEN s.batch_name = :batch_id3 THEN 'batch_name'
                    WHEN s.batch_name_2 = :batch_id4 THEN 'batch_name_2'
                    WHEN s.batch_name_3 = :batch_id5 THEN 'batch_name_3'
                    WHEN s.batch_name_4 = :batch_id6 THEN 'batch_name_4'
                    ELSE NULL
                END as batch_field
            FROM students s
            WHERE s.student_id IN (
                SELECT student_id FROM student_batch_history WHERE from_batch_id = :batch_id7
                UNION
                SELECT student_id FROM students WHERE batch_name = :batch_id8 OR batch_name_2 = :batch_id9 OR batch_name_3 = :batch_id10 OR batch_name_4 = :batch_id11
            )
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute([
            'batch_id' => $batch_id,
            'batch_id2' => $batch_id,
            'batch_id3' => $batch_id,
            'batch_id4' => $batch_id,
            'batch_id5' => $batch_id,
            'batch_id6' => $batch_id,
            'batch_id7' => $batch_id,
            'batch_id8' => $batch_id,
            'batch_id9' => $batch_id,
            'batch_id10' => $batch_id,
            'batch_id11' => $batch_id
        ]);
    } else {
        // For ongoing/upcoming batches, show current students from all batch fields
        $stmt = $db->prepare("
            SELECT 
                s.student_id,
                s.first_name,
                s.last_name,
                s.email,
                s.phone_number,
                s.enrollment_date,
                s.current_status,
                s.batch_name as current_batch,
                s.batch_name_2,
                s.batch_name_3,
                s.batch_name_4,
                'current' as student_type,
                NULL as transferred_to_batch,
                CASE 
                    WHEN s.batch_name = :batch_id THEN 'batch_name'
                    WHEN s.batch_name_2 = :batch_id2 THEN 'batch_name_2'
                    WHEN s.batch_name_3 = :batch_id3 THEN 'batch_name_3'
                    WHEN s.batch_name_4 = :batch_id4 THEN 'batch_name_4'
                    ELSE NULL
                END as batch_field
            FROM students s
            WHERE s.batch_name = :batch_id5 OR s.batch_name_2 = :batch_id6 OR s.batch_name_3 = :batch_id7 OR s.batch_name_4 = :batch_id8
            ORDER BY s.first_name, s.last_name
        ");
        $stmt->execute([
            'batch_id' => $batch_id,
            'batch_id2' => $batch_id,
            'batch_id3' => $batch_id,
            'batch_id4' => $batch_id,
            'batch_id5' => $batch_id,
            'batch_id6' => $batch_id,
            'batch_id7' => $batch_id,
            'batch_id8' => $batch_id
        ]);
    }
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count current students only
    $current_students_count = 0;
    $transferred_students_count = 0;
    $batch_name_count = 0;
    $batch_name_2_count = 0;
    $batch_name_3_count = 0;
    $batch_name_4_count = 0;
    
    foreach ($students as $student) {
        if ($student['student_type'] === 'current' && $student['current_status'] === 'active') {
            $current_students_count++;
            
            // Count which batch field contains this batch
            if ($student['batch_field'] === 'batch_name') {
                $batch_name_count++;
            } elseif ($student['batch_field'] === 'batch_name_2') {
                $batch_name_2_count++;
            } elseif ($student['batch_field'] === 'batch_name_3') {
                $batch_name_3_count++;
            } elseif ($student['batch_field'] === 'batch_name_4') {
                $batch_name_4_count++;
            }
        } elseif ($student['student_type'] === 'transferred') {
            $transferred_students_count++;
        }
    }
    
    // Get transfer history for this batch
    $stmt = $db->prepare("
        SELECT 
            h.*,
            s.first_name,
            s.last_name,
            s.email,
            fb.batch_name as from_batch_name,
            tb.batch_name as to_batch_name,
            u.name as transferred_by_name
        FROM student_batch_history h
        JOIN students s ON h.student_id = s.student_id
        LEFT JOIN batches fb ON h.from_batch_id = fb.batch_id
        LEFT JOIN batches tb ON h.to_batch_id = tb.batch_id
        LEFT JOIN users u ON h.transferred_by = u.id
        WHERE h.from_batch_id = ?
        ORDER BY h.transfer_date DESC
    ");
    $stmt->execute([$batch_id]);
    $transfer_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get other available batches for transfer
    $stmt = $db->prepare("SELECT batch_id, batch_name 
                          FROM batches 
                          WHERE batch_id != ? AND status IN ('upcoming', 'ongoing')
                          ORDER BY start_date ASC");
    $stmt->execute([$batch_id]);
    $available_batches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance statistics
    $attendance_stats = [];
    $recent_attendance = [];
    
    // Get total number of scheduled sessions for this batch
    $stmt = $db->prepare("SELECT COUNT(*) as total_sessions FROM schedule WHERE batch_id = ? AND is_cancelled = 0");
    $stmt->execute([$batch_id]);
    $sessions_result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_scheduled_sessions = $sessions_result['total_sessions'] ?: 0;
    
    // Get overall attendance statistics by counting actual attendance records
    if ($total_scheduled_sessions > 0) {
        // Calculate total possible attendance records (sessions * current students)
        $stmt = $db->prepare("SELECT COUNT(*) as total_possible_attendance FROM attendance WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $total_possible = $stmt->fetch(PDO::FETCH_ASSOC)['total_possible_attendance'];
        
        // Count present and absent
        $stmt = $db->prepare("SELECT 
                                COUNT(*) as total_records,
                                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count
                              FROM attendance 
                              WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $attendance_stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($attendance_stats['total_records'] > 0) {
            $attendance_stats['present_percentage'] = round(($attendance_stats['present_count'] / $attendance_stats['total_records']) * 100, 1);
            $attendance_stats['absent_percentage'] = round(($attendance_stats['absent_count'] / $attendance_stats['total_records']) * 100, 1);
        } else {
            $attendance_stats['present_percentage'] = 0;
            $attendance_stats['absent_percentage'] = 0;
        }
        
        // Calculate attendance coverage (how many sessions have attendance taken)
        $stmt = $db->prepare("SELECT COUNT(DISTINCT date) as sessions_with_attendance FROM attendance WHERE batch_id = ?");
        $stmt->execute([$batch_id]);
        $coverage = $stmt->fetch(PDO::FETCH_ASSOC);
        $attendance_stats['sessions_with_attendance'] = $coverage['sessions_with_attendance'] ?: 0;
        $attendance_stats['attendance_coverage'] = round(($attendance_stats['sessions_with_attendance'] / $total_scheduled_sessions) * 100, 1);
    } else {
        $attendance_stats = [
            'total_records' => 0,
            'present_count' => 0,
            'absent_count' => 0,
            'present_percentage' => 0,
            'absent_percentage' => 0,
            'sessions_with_attendance' => 0,
            'attendance_coverage' => 0
        ];
    }
    
    // Attendance by student (only for current students)
    if ($current_students_count > 0 && $total_scheduled_sessions > 0) {
        // Get all student IDs who have this batch in any batch field
        $student_ids = [];
        foreach ($students as $student) {
            if ($student['student_type'] === 'current' && $student['current_status'] === 'active') {
                $student_ids[] = $student['student_id'];
            }
        }
        
        if (!empty($student_ids)) {
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            
            $stmt = $db->prepare("
                SELECT 
                    s.student_id,
                    s.first_name,
                    s.last_name,
                    COUNT(a.id) as total_attendance_records,
                    SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_count,
                    SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                    ROUND(
                        COALESCE(
                            SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / 
                            NULLIF(COUNT(a.id), 0) * 100, 
                        0), 1
                    ) as attendance_percentage
                FROM students s
                LEFT JOIN attendance a ON s.student_id = a.student_id AND a.batch_id = ?
                WHERE s.student_id IN ($placeholders)
                GROUP BY s.student_id, s.first_name, s.last_name
                ORDER BY attendance_percentage DESC
            ");
            
            $params = array_merge([$batch_id], $student_ids);
            $stmt->execute($params);
            $student_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $student_attendance = [];
        }
    } else {
        $student_attendance = [];
    }
    
    // Recent attendance (last 5 sessions with attendance)
    if ($attendance_stats['sessions_with_attendance'] > 0) {
        $stmt = $db->prepare("
            SELECT 
                date,
                COUNT(*) as total_students,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent_count,
                ROUND(SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as attendance_percentage
            FROM attendance
            WHERE batch_id = ?
            GROUP BY date
            ORDER BY date DESC
            LIMIT 5
        ");
        $stmt->execute([$batch_id]);
        $recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Batch <?= htmlspecialchars($batch['batch_id']) ?> | ASD Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --accent-color: #4cc9f0;
            --light-bg: #f8f9fa;
            --dark-text: #212529;
            --light-text: #6c757d;
            --success-color: #4bb543;
            --danger-color: #f94144;
            --warning-color: #f8961e;
            --info-color: #17a2b8;
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(255, 255, 255, 0.18);
            --shadow: 0 8px 32px rgba(31, 38, 135, 0.15);
            --gradient-primary: linear-gradient(135deg, #4361ee 0%, #3a0ca3 100%);
            --gradient-overlay: linear-gradient(to bottom, rgba(0,0,0,0) 0%, rgba(0,0,0,0.7) 100%);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .profile-picture {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            object-fit: cover;
        }
        
        .stat-card {
            transition: all 0.3s ease;
            border-radius: 10px;
            overflow: hidden;
            border-left: 4px solid var(--primary-color);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        
        .attendance-card {
            transition: all 0.3s ease;
            border-radius: 10px;
            overflow: hidden;
            border-top: 4px solid var(--primary-color);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        
        .attendance-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            font-weight: 500;
        }
        
        .nav-tabs .nav-link {
            color: var(--light-text);
            border: none;
            padding: 12px 20px;
            font-weight: 500;
        }
        
        .nav-tabs .nav-link:hover {
            border: none;
            color: var(--primary-color);
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            transition: all 0.3s ease;
        }
        
        .student-avatar:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .bg-ongoing {
            background: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
            color: white;
        }
        
        .bg-completed {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
        }
        
        .bg-upcoming {
            background: linear-gradient(135deg, #ff9e00 0%, #ff5400 100%);
            color: white;
        }
        
        .bg-cancelled {
            background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            color: white;
        }
        
        .bg-transferred {
            background-color: rgba(23, 162, 184, 0.9);
            color: white;
        }
        
        .progress-thin {
            height: 6px;
        }
        
        .attendance-progress {
            height: 24px;
            border-radius: 12px;
        }
        
        .student-row {
            transition: all 0.3s ease;
        }
        
        .student-row:hover {
            background-color: #f8f9fa;
            transform: translateX(5px);
        }
        
        .action-btn {
            transition: all 0.3s ease;
            opacity: 0.7;
        }
        
        .action-btn:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        
        .progress-bar {
            transition: width 1s ease-in-out;
        }
        
        .batch-header {
            background: var(--gradient-primary);
            position: relative;
            overflow: hidden;
            min-height: 300px;
        }
        
        .batch-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 60%);
            transform: rotate(30deg);
        }
        
        /* Thumbnail Styles - New Impressive Design */
        .batch-thumbnail-container {
            position: relative;
            width: 100%;
            height: 400px;
            overflow: hidden;
            border-radius: 24px;
            box-shadow: var(--shadow);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }
        
        .batch-thumbnail-container:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(31, 38, 135, 0.3);
        }
        
        .batch-thumbnail {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease;
        }
        
        .batch-thumbnail-container:hover .batch-thumbnail {
            transform: scale(1.1);
        }
        
        .thumbnail-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-overlay);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 32px;
            opacity: 0;
            transition: all 0.4s ease;
        }
        
        .batch-thumbnail-container:hover .thumbnail-overlay {
            opacity: 1;
        }
        
        .thumbnail-badge {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 30px;
            padding: 12px 24px;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            animation: float 3s ease-in-out infinite;
        }
        
        .thumbnail-title {
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 8px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
            transform: translateY(20px);
            transition: transform 0.4s ease 0.1s;
        }
        
        .batch-thumbnail-container:hover .thumbnail-title {
            transform: translateY(0);
        }
        
        .thumbnail-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 1.2rem;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
            transform: translateY(20px);
            transition: transform 0.4s ease 0.2s;
        }
        
        .batch-thumbnail-container:hover .thumbnail-subtitle {
            transform: translateY(0);
        }
        
        .thumbnail-stats {
            display: flex;
            gap: 24px;
            margin-top: 20px;
            transform: translateY(20px);
            transition: transform 0.4s ease 0.3s;
        }
        
        .batch-thumbnail-container:hover .thumbnail-stats {
            transform: translateY(0);
        }
        
        .thumbnail-stat-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(5px);
            padding: 8px 16px;
            border-radius: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .thumbnail-stat-item i {
            font-size: 1.2rem;
        }
        
        .thumbnail-placeholder {
            width: 100%;
            height: 400px;
            background: var(--gradient-primary);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            border-radius: 24px;
            position: relative;
            overflow: hidden;
        }
        
        .thumbnail-placeholder::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0) 60%);
            animation: rotate 20s linear infinite;
        }
        
        .thumbnail-placeholder i {
            font-size: 6rem;
            margin-bottom: 20px;
            animation: pulse 2s ease-in-out infinite;
        }
        
        .thumbnail-placeholder .placeholder-text {
            font-size: 1.5rem;
            font-weight: 600;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-5px); }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .sparkle {
            position: absolute;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 50%;
            animation: sparkle 3s infinite;
        }
        
        @keyframes sparkle {
            0% { opacity: 0; transform: translateY(0) rotate(0deg); }
            50% { opacity: 1; }
            100% { opacity: 0; transform: translateY(-20px) rotate(360deg); }
        }
        
        .tooltip-custom {
            position: relative;
        }
        
        .tooltip-custom::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 5px 10px;
            background: #333;
            color: #fff;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .tooltip-custom:hover::after {
            opacity: 1;
            visibility: visible;
            bottom: calc(100% + 5px);
        }
        
        .hover-lift {
            transition: all 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .text-gradient {
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .icon-wrapper {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(67, 97, 238, 0.1);
            margin-right: 15px;
            transition: all 0.3s ease;
        }
        
        .icon-wrapper:hover {
            background: rgba(67, 97, 238, 0.2);
            transform: rotate(10deg) scale(1.1);
        }
        
        .stats-icon {
            font-size: 1.5rem;
            color: var(--primary-color);
        }
        
        .student-count-badge {
            position: relative;
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            background: var(--primary-color);
            color: white;
            font-weight: bold;
            animation: pulse 2s infinite;
        }
        
        .transferred-badge {
            background: var(--info-color);
        }
        
        .batch-field-badge {
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 8px;
            margin-left: 5px;
            background: var(--warning-color);
            color: white;
        }
        
        .batch-field-1 {
            background: var(--primary-color);
        }
        
        .batch-field-2 {
            background: var(--info-color);
        }
        
        .batch-field-3 {
            background: var(--warning-color);
        }
        
        .batch-field-4 {
            background: #9c27b0;
            color: white;
        }
        
        .student-type-indicator {
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        
        .type-current {
            background: var(--success-color);
            color: white;
        }
        
        .type-transferred {
            background: var(--info-color);
            color: white;
        }
        
        .type-historical {
            background: var(--warning-color);
            color: white;
        }
        
        .attendance-percentage-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            margin: 0 auto 10px;
        }
        
        .attendance-high {
            background: rgba(75, 181, 67, 0.1);
            border: 3px solid #4bb543;
            color: #4bb543;
        }
        
        .attendance-medium {
            background: rgba(248, 150, 30, 0.1);
            border: 3px solid #f8961e;
            color: #f8961e;
        }
        
        .attendance-low {
            background: rgba(249, 65, 68, 0.1);
            border: 3px solid #f94144;
            color: #f94144;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(67, 97, 238, 0); }
            100% { box-shadow: 0 0 0 0 rgba(67, 97, 238, 0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .session-stats-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
        }
        
        .session-count {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .coverage-badge {
            font-size: 0.85rem;
            padding: 3px 8px;
            border-radius: 15px;
            font-weight: 500;
        }
        
        .coverage-good {
            background: rgba(75, 181, 67, 0.2);
            color: #4bb543;
        }
        
        .coverage-fair {
            background: rgba(248, 150, 30, 0.2);
            color: #f8961e;
        }
        
        .coverage-poor {
            background: rgba(249, 65, 68, 0.2);
            color: #f94144;
        }
        
        .batch-distribution {
            display: flex;
            margin-top: 10px;
            gap: 5px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .distribution-item {
            display: flex;
            align-items: center;
            font-size: 0.8rem;
            padding: 2px 6px;
            border-radius: 4px;
            background: #f8f9fa;
        }
        
        .distribution-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 4px;
        }
        
        .dot-primary {
            background: var(--primary-color);
        }
        
        .dot-info {
            background: var(--info-color);
        }
        
        .dot-warning {
            background: var(--warning-color);
        }
        
        .dot-purple {
            background: #9c27b0;
        }
        
        .glass-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            box-shadow: var(--shadow);
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php include '../header.php'; ?>
    <?php include '../sidebar.php'; ?>
    
    <div class="flex-1 ml-0 md:ml-64 min-h-screen">
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <div class="icon-wrapper">
                    <i class="fas fa-users-class text-blue-500"></i>
                </div>
                <span>Batch Details</span>
            </h1>
            <div class="flex items-center space-x-4">
                <a href="../batch/batch_list.php" class="btn btn-sm btn-outline-secondary hover-lift">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Batches
                </a>
            </div>
        </header>
        
        <div class="p-4 md:p-6">
            <!-- Impressive Thumbnail Section -->
            <div class="mb-6 fade-in">
                <?php if (!empty($batch['thumbnail_path'])): ?>
                    <div class="batch-thumbnail-container">
                        <img src="../<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                             alt="<?= htmlspecialchars($batch['batch_name']) ?>" 
                             class="batch-thumbnail">
                        <div class="thumbnail-badge">
                            <i class="fas fa-crown me-2"></i>
                            <?= htmlspecialchars($batch['batch_id']) ?>
                        </div>
                        <div class="thumbnail-overlay">
                            <h2 class="thumbnail-title"><?= htmlspecialchars($batch['batch_name']) ?></h2>
                            <p class="thumbnail-subtitle">
                                <?= !empty($batch['course_description']) ? 
                                    substr(htmlspecialchars($batch['course_description']), 0, 100) . '...' : 
                                    'No description available' ?>
                            </p>
                            <div class="thumbnail-stats">
                                <div class="thumbnail-stat-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?= date('M j, Y', strtotime($batch['start_date'])) ?></span>
                                </div>
                                <div class="thumbnail-stat-item">
                                    <i class="fas fa-calendar-check"></i>
                                    <span><?= date('M j, Y', strtotime($batch['end_date'])) ?></span>
                                </div>
                                <div class="thumbnail-stat-item">
                                    <i class="fas fa-users"></i>
                                    <span><?= $current_students_count ?>/<?= htmlspecialchars($batch['max_students'] ?? 'N/A') ?> Students</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="thumbnail-placeholder">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <div class="placeholder-text"><?= htmlspecialchars($batch['batch_name']) ?></div>
                        <div class="mt-3 opacity-75">
                            <i class="fas fa-calendar-alt me-2"></i>
                            <?= date('M j, Y', strtotime($batch['start_date'])) ?> - 
                            <?= date('M j, Y', strtotime($batch['end_date'])) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Batch Header Card (Now with less prominence since thumbnail is above) -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 fade-in">
                <div class="batch-header p-6 text-white relative" style="min-height: 100px;">
                    <!-- Sparkle animations -->
                    <div class="sparkle" style="top: 20%; left: 10%; width: 5px; height: 5px; animation-delay: 0s;"></div>
                    <div class="sparkle" style="top: 60%; left: 80%; width: 8px; height: 8px; animation-delay: 1s;"></div>
                    <div class="sparkle" style="top: 30%; left: 50%; width: 6px; height: 6px; animation-delay: 2s;"></div>
                    
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                        <div>
                            <h2 class="text-3xl font-bold mb-1"><?= htmlspecialchars($batch['batch_name']) ?></h2>
                            <p class="text-blue-100">Batch ID: <?= htmlspecialchars($batch['batch_id']) ?></p>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <span class="status-badge <?= $batch['status'] === 'ongoing' ? 'bg-ongoing' : 
                                                       ($batch['status'] === 'completed' ? 'bg-completed' : 
                                                       ($batch['status'] === 'upcoming' ? 'bg-upcoming' : 'bg-cancelled')) ?> pulse">
                                <?= htmlspecialchars(ucfirst($batch['status'])) ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Batch Stats -->
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
                        <div class="stat-card bg-white border border-gray-200 p-4 rounded-lg hover-lift">
                            <div class="text-sm text-gray-500 flex items-center">
                                <i class="fas fa-calendar-start mr-2 text-blue-500"></i> Start Date
                            </div>
                            <div class="text-xl font-bold mt-1"><?= date('M j, Y', strtotime($batch['start_date'])) ?></div>
                        </div>
                        <div class="stat-card bg-white border border-gray-200 p-4 rounded-lg hover-lift">
                            <div class="text-sm text-gray-500 flex items-center">
                                <i class="fas fa-calendar-day mr-2 text-blue-500"></i> End Date
                            </div>
                            <div class="text-xl font-bold mt-1"><?= date('M j, Y', strtotime($batch['end_date'])) ?></div>
                        </div>
                        <div class="stat-card bg-white border border-gray-200 p-4 rounded-lg hover-lift">
                            <div class="text-sm text-gray-500 flex items-center">
                                <i class="fas fa-laptop-code mr-2 text-blue-500"></i> Mode
                            </div>
                            <div class="text-xl font-bold mt-1"><?= htmlspecialchars(ucfirst($batch['mode'])) ?></div>
                        </div>
                        <div class="stat-card bg-white border border-gray-200 p-4 rounded-lg hover-lift">
                            <div class="text-sm text-gray-500 flex items-center">
                                <i class="fas fa-user-graduate mr-2 text-blue-500"></i> Current Students
                            </div>
                            <div class="text-xl font-bold mt-1 counter" id="studentCounter"><?= $current_students_count ?></div>
                            <div class="text-xs text-gray-500">of <?= htmlspecialchars($batch['max_students'] ?? 'N/A') ?> max</div>
                            <?php if ($batch_name_count > 0 || $batch_name_2_count > 0 || $batch_name_3_count > 0 || $batch_name_4_count > 0): ?>
                            <div class="batch-distribution">
                                <?php if ($batch_name_count > 0): ?>
                                <div class="distribution-item">
                                    <span class="distribution-dot dot-primary"></span>
                                    <span>B1: <?= $batch_name_count ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($batch_name_2_count > 0): ?>
                                <div class="distribution-item">
                                    <span class="distribution-dot dot-info"></span>
                                    <span>B2: <?= $batch_name_2_count ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($batch_name_3_count > 0): ?>
                                <div class="distribution-item">
                                    <span class="distribution-dot dot-warning"></span>
                                    <span>B3: <?= $batch_name_3_count ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($batch_name_4_count > 0): ?>
                                <div class="distribution-item">
                                    <span class="distribution-dot dot-purple"></span>
                                    <span>B4: <?= $batch_name_4_count ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($batch['status'] === 'completed' && $transferred_students_count > 0): ?>
                        <div class="stat-card bg-white border border-gray-200 p-4 rounded-lg hover-lift">
                            <div class="text-sm text-gray-500 flex items-center">
                                <i class="fas fa-exchange-alt mr-2 text-info"></i> Transferred Out
                            </div>
                            <div class="text-xl font-bold mt-1 text-info"><?= $transferred_students_count ?></div>
                            <div class="text-xs text-gray-500">Students moved to other batches</div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Batch Details Sections -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Mentor Information -->
                        <div class="bg-white border border-gray-200 rounded-lg p-6 highlight-card hover-lift">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                                <div class="icon-wrapper">
                                    <i class="fas fa-chalkboard-teacher text-blue-500"></i>
                                </div>
                                Batch Mentor
                            </h3>
                            <?php if (!empty($batch['mentor_name'])): ?>
                                <div class="flex items-center space-x-4">
                                    <?php if (!empty($batch['mentor_avatar'])): ?>
                                        <img src="../<?= htmlspecialchars($batch['mentor_avatar']) ?>" 
                                             alt="<?= htmlspecialchars($batch['mentor_name']) ?>" 
                                             class="profile-picture" style="width: 60px; height: 60px;">
                                    <?php else: ?>
                                        <div class="student-avatar floating" style="width: 60px; height: 60px;">
                                            <i class="fas fa-user-tie text-gray-500 fa-2x"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-medium"><?= htmlspecialchars($batch['mentor_name']) ?></p>
                                        <p class="text-sm text-gray-500"><?= htmlspecialchars($batch['mentor_email'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500"><i class="fas fa-info-circle mr-2"></i> No mentor assigned to this batch</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Platform Information -->
                        <div class="bg-white border border-gray-200 rounded-lg p-6 highlight-card hover-lift">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                                <div class="icon-wrapper">
                                    <i class="fas fa-laptop text-blue-500"></i>
                                </div>
                                Platform Details
                            </h3>
                            <?php if ($batch['mode'] === 'online'): ?>
                                <div class="space-y-3">
                                    <div>
                                        <p class="text-sm text-gray-500">Platform</p>
                                        <p class="font-medium"><?= htmlspecialchars($batch['platform'] ?? 'Not specified') ?></p>
                                    </div>
                                    <?php if (!empty($batch['meeting_link'])): ?>
                                        <div>
                                            <p class="text-sm text-gray-500">Meeting Link</p>
                                            <a href="<?= htmlspecialchars($batch['meeting_link']) ?>" target="_blank" class="font-medium text-blue-600 hover:underline break-all tooltip-custom" data-tooltip="Open meeting link">
                                                <?= htmlspecialchars($batch['meeting_link']) ?>
                                                <i class="fas fa-external-link-alt ml-1 text-xs"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500"><i class="fas fa-info-circle mr-2"></i> Offline batch - no platform information</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Batch Actions -->
                        <div class="bg-white border border-gray-200 rounded-lg p-6 highlight-card hover-lift">
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                                <div class="icon-wrapper">
                                    <i class="fas fa-cog text-blue-500"></i>
                                </div>
                                Actions
                            </h3>
                            <div class="space-y-3">
                                <!-- Progress Button -->
                                <a href="progress_batch.php?batch_id=<?php echo urlencode($batch_id); ?>" class="block w-full px-4 py-2 border border-gray-300 rounded-md text-center text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 hover-lift">
                                    <i class="fas fa-chart-line"></i> Progress
                                </a>

                                <a href="manage_assignments.php?batch_id=<?= $batch['batch_id'] ?>" class="block w-full px-4 py-2 border border-gray-300 rounded-md text-center text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 hover-lift">
                                    <i class="fas fa-tasks mr-2 text-danger"></i> Manage Assignments
                                </a>

                                <a href="../admin_test/create_test.php?batch_id=<?= urlencode($batch['batch_id']) ?>" class="block w-full px-4 py-2 border border-gray-300 rounded-md text-center text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 hover-lift">
                                    <i class="fas fa-file-alt mr-2 text-purple-500"></i> Manage Tests
                                </a>

                                <a href="manage_student.php?batch_id=<?= $batch['batch_id'] ?>" class="block w-full px-4 py-2 border border-gray-300 rounded-md text-center text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 hover-lift">
                                    <i class="fas fa-users mr-2"></i> Manage Students
                                </a>
                                
                                <a href="../schedule/schedule.php?batch_id=<?= $batch['batch_id'] ?>" class="block w-full px-4 py-2 border border-gray-300 rounded-md text-center text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 hover-lift">
                                    <i class="fas fa-calendar-alt mr-2"></i> View Schedule
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Students Section -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 fade-in">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <div class="flex items-center">
                        <div class="icon-wrapper">
                            <i class="fas fa-user-graduate text-blue-500"></i>
                        </div>
                        <h2 class="text-xl font-bold text-gray-800 ml-3">
                            Students
                            <span class="student-count-badge"><?= $current_students_count ?> Active</span>
                            <?php if ($transferred_students_count > 0): ?>
                            <span class="student-count-badge transferred-badge ml-2"><?= $transferred_students_count ?> Transferred</span>
                            <?php endif; ?>
                        </h2>
                    </div>
                    <div class="flex space-x-2">
                        <a href="manage_student.php?batch_id=<?= $batch['batch_id'] ?>" class="btn btn-primary btn-sm hover-lift">
                            <i class="fas fa-users-cog mr-2"></i> Manage Students
                        </a>
                        <?php if ($batch['status'] !== 'completed'): ?>
                        <a href="../student/add_student.php?batch_id=<?= $batch['batch_id'] ?>" class="btn btn-success btn-sm hover-lift">
                            <i class="fas fa-user-plus mr-2"></i> Add Student
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="p-6">
                    <?php if (count($students) > 0): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enrollment Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Batch Field</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($students as $student): ?>
                                        <tr class="student-row hover:bg-gray-50 transition-colors">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="student-avatar">
                                                        <i class="fas fa-user text-gray-400"></i>
                                                    </div>
                                                    <div>
                                                        <div class="font-medium text-gray-900">
                                                            <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">ID: <?= htmlspecialchars($student['student_id']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900"><?= htmlspecialchars($student['email']) ?></div>
                                                <div class="text-sm text-gray-500"><?= htmlspecialchars($student['phone_number'] ?? 'N/A') ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= date('M j, Y', strtotime($student['enrollment_date'])) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                    <?= $student['current_status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                       ($student['current_status'] === 'transferred' ? 'bg-blue-100 text-blue-800' : 
                                                       'bg-red-100 text-red-800') ?>">
                                                    <?= htmlspecialchars(ucfirst($student['current_status'])) ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="student-type-indicator 
                                                    <?= $student['student_type'] === 'current' ? 'type-current' : 
                                                       ($student['student_type'] === 'transferred' ? 'type-transferred' : 'type-historical') ?>">
                                                    <?= htmlspecialchars(ucfirst($student['student_type'])) ?>
                                                </span>
                                                <?php if ($student['student_type'] === 'current' && !empty($student['batch_field'])): ?>
                                                    <span class="batch-field-badge batch-field-<?= substr($student['batch_field'], -1) ?>">
                                                        <?= strtoupper($student['batch_field']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php if ($student['student_type'] === 'current'): ?>
                                                    <div class="flex flex-col space-y-1">
                                                        <?php if ($student['batch_name'] == $batch_id): ?>
                                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                                <i class="fas fa-check mr-1"></i> Batch 1
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($student['batch_name_2'] == $batch_id): ?>
                                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-indigo-100 text-indigo-800">
                                                                <i class="fas fa-check mr-1"></i> Batch 2
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($student['batch_name_3'] == $batch_id): ?>
                                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-purple-100 text-purple-800">
                                                                <i class="fas fa-check mr-1"></i> Batch 3
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($student['batch_name_4'] == $batch_id): ?>
                                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-pink-100 text-pink-800">
                                                                <i class="fas fa-check mr-1"></i> Batch 4
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif ($student['student_type'] === 'transferred' && !empty($student['transferred_to_batch'])): ?>
                                                    <span class="text-info font-medium">
                                                        Transferred to: <?= htmlspecialchars($student['transferred_to_batch']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-gray-400">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <div class="flex space-x-2">
                                                    <a href="student_view.php?student_id=<?= $student['student_id'] ?>" 
                                                       class="text-blue-600 hover:text-blue-900 action-btn" 
                                                       title="View Profile">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <?php if ($student['student_type'] === 'current' && $student['current_status'] === 'active'): ?>
                                                        <a href="../student/edit_student.php?student_id=<?= $student['student_id'] ?>" 
                                                           class="text-green-600 hover:text-green-900 action-btn" 
                                                           title="Edit Student">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <?php if ($batch['status'] !== 'completed'): ?>
                                                        <button class="text-red-600 hover:text-red-900 action-btn transfer-student-btn" 
                                                                data-student-id="<?= $student['student_id'] ?>"
                                                                data-student-name="<?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>"
                                                                title="Transfer Student">
                                                            <i class="fas fa-exchange-alt"></i>
                                                        </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-user-graduate text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-700 mb-2">No Students Found</h3>
                            <p class="text-gray-500 mb-4">This batch doesn't have any students yet.</p>
                            <?php if ($batch['status'] !== 'completed'): ?>
                            <a href="../student/add_student.php?batch_id=<?= $batch['batch_id'] ?>" class="btn btn-primary">
                                <i class="fas fa-user-plus mr-2"></i> Add First Student
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Transfer History Section (Only for completed batches) -->
            <?php if ($batch['status'] === 'completed' && count($transfer_history) > 0): ?>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 fade-in">
                <div class="p-6 border-b border-gray-200 flex items-center">
                    <div class="icon-wrapper">
                        <i class="fas fa-exchange-alt text-blue-500"></i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800 ml-3">
                        Transfer History
                    </h2>
                </div>
                
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transfer Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Transferred By</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($transfer_history as $transfer): ?>
                                    <tr class="hover:bg-gray-50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="student-avatar">
                                                    <i class="fas fa-user text-gray-400"></i>
                                                </div>
                                                <div>
                                                    <div class="font-medium text-gray-900">
                                                        <?= htmlspecialchars($transfer['first_name'] . ' ' . $transfer['last_name']) ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">ID: <?= htmlspecialchars($transfer['student_id']) ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <span class="text-red-600 font-medium"><?= htmlspecialchars($transfer['from_batch_name']) ?></span>
                                                <i class="fas fa-arrow-right mx-2 text-gray-400"></i>
                                                <span class="text-green-600 font-medium"><?= htmlspecialchars($transfer['to_batch_name']) ?></span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= date('M j, Y g:i A', strtotime($transfer['transfer_date'])) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?= htmlspecialchars($transfer['transferred_by_name'] ?? 'System') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Attendance Overview Section -->
            <?php if ($batch['status'] !== 'upcoming'): ?>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-6 fade-in">
                <div class="p-6 border-b border-gray-200 flex items-center">
                    <div class="icon-wrapper">
                        <i class="fas fa-clipboard-check text-blue-500"></i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800 ml-3">
                        Attendance Overview
                    </h2>
                </div>
                
                <div class="p-6">
                    <?php if ($total_scheduled_sessions > 0): ?>
                        <!-- Overall Attendance Statistics -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                            <!-- Overall Attendance Card -->
                            <div class="attendance-card bg-white border border-gray-200 p-6 rounded-lg hover-lift">
                                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                                    <i class="fas fa-chart-pie mr-2 text-blue-500"></i>
                                    Overall Attendance
                                </h3>
                                <div class="text-center mb-4">
                                    <?php
                                    $attendanceClass = 'attendance-high';
                                    if ($attendance_stats['present_percentage'] < 70) {
                                        $attendanceClass = 'attendance-low';
                                    } elseif ($attendance_stats['present_percentage'] < 85) {
                                        $attendanceClass = 'attendance-medium';
                                    }
                                    ?>
                                    <div class="attendance-percentage-circle <?= $attendanceClass ?>">
                                        <?= $attendance_stats['present_percentage'] ?>%
                                    </div>
                                    <p class="text-sm text-gray-600">Overall Attendance Rate</p>
                                </div>
                                <div class="grid grid-cols-2 gap-4 text-center">
                                    <div>
                                        <div class="text-2xl font-bold text-green-600"><?= $attendance_stats['present_count'] ?></div>
                                        <div class="text-sm text-gray-600">Present</div>
                                    </div>
                                    <div>
                                        <div class="text-2xl font-bold text-red-600"><?= $attendance_stats['absent_count'] ?></div>
                                        <div class="text-sm text-gray-600">Absent</div>
                                    </div>
                                </div>
                                <div class="mt-4">
                                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                                        <span>Total Attendance Records</span>
                                        <span><?= $attendance_stats['total_records'] ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-green-600 h-2.5 rounded-full" 
                                             style="width: <?= $attendance_stats['present_percentage'] ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Session Statistics Card -->
                            <div class="attendance-card bg-white border border-gray-200 p-6 rounded-lg hover-lift">
                                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                                    <i class="fas fa-calendar-check mr-2 text-blue-500"></i>
                                    Session Statistics
                                </h3>
                                <div class="session-stats-card mb-4">
                                    <div class="session-count"><?= $total_scheduled_sessions ?></div>
                                    <div class="text-sm text-gray-600">Total Scheduled Sessions</div>
                                </div>
                                <div class="space-y-3">
                                    <div>
                                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                                            <span>Sessions with Attendance</span>
                                            <span><?= $attendance_stats['sessions_with_attendance'] ?></span>
                                        </div>
                                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                                            <?php 
                                            $coverageClass = 'coverage-poor';
                                            if ($attendance_stats['attendance_coverage'] >= 80) {
                                                $coverageClass = 'coverage-good';
                                            } elseif ($attendance_stats['attendance_coverage'] >= 50) {
                                                $coverageClass = 'coverage-fair';
                                            }
                                            ?>
                                            <div class="h-2.5 rounded-full <?= $coverageClass ?>" 
                                                 style="width: <?= $attendance_stats['attendance_coverage'] ?>%"></div>
                                        </div>
                                        <div class="text-right mt-1">
                                            <span class="coverage-badge <?= $coverageClass ?>">
                                                <?= $attendance_stats['attendance_coverage'] ?>% Coverage
                                            </span>
                                        </div>
                                    </div>
                                    <?php if ($current_students_count > 0): ?>
                                    <div class="pt-3 border-t border-gray-200">
                                        <div class="text-sm text-gray-600">
                                            <i class="fas fa-info-circle mr-1"></i>
                                            Based on <?= $current_students_count ?> active students × <?= $attendance_stats['sessions_with_attendance'] ?> sessions
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Recent Sessions Card -->
                            <div class="attendance-card bg-white border border-gray-200 p-6 rounded-lg hover-lift">
                                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                                    <i class="fas fa-history mr-2 text-blue-500"></i>
                                    Recent Sessions
                                </h3>
                                <?php if (count($recent_attendance) > 0): ?>
                                    <div class="space-y-3">
                                        <?php foreach ($recent_attendance as $session): ?>
                                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                                <div>
                                                    <div class="font-medium text-gray-900">
                                                        <?= date('M j', strtotime($session['date'])) ?>
                                                        <span class="text-xs text-gray-500 ml-2"><?= date('D', strtotime($session['date'])) ?></span>
                                                    </div>
                                                    <div class="text-sm text-gray-600">
                                                        <?= $session['present_count'] ?>/<?= $session['total_students'] ?> present
                                                    </div>
                                                </div>
                                                <div class="text-lg font-bold 
                                                    <?= $session['attendance_percentage'] >= 85 ? 'text-green-600' : 
                                                       ($session['attendance_percentage'] >= 70 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                    <?= $session['attendance_percentage'] ?>%
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-6">
                                        <i class="fas fa-calendar-times text-gray-300 text-3xl mb-3"></i>
                                        <p class="text-gray-500">No attendance sessions recorded yet</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Student Attendance Details -->
                        <?php if (count($student_attendance) > 0): ?>
                        <div>
                            <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                                <i class="fas fa-user-graduate mr-2 text-blue-500"></i>
                                Student Attendance Details
                            </h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance Records</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Present</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Absent</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance %</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($student_attendance as $student_att): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="student-avatar">
                                                            <i class="fas fa-user text-gray-400"></i>
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-gray-900">
                                                                <?= htmlspecialchars($student_att['first_name'] . ' ' . $student_att['last_name']) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">ID: <?= htmlspecialchars($student_att['student_id']) ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900"><?= $student_att['total_attendance_records'] ?: 0 ?></div>
                                                    <div class="text-xs text-gray-500">of <?= $attendance_stats['sessions_with_attendance'] ?> sessions</div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-green-600"><?= $student_att['present_count'] ?: 0 ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-red-600"><?= $student_att['absent_count'] ?: 0 ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="w-full bg-gray-200 rounded-full h-2.5 mr-3">
                                                            <div class="h-2.5 rounded-full 
                                                                <?= $student_att['attendance_percentage'] >= 85 ? 'bg-green-600' : 
                                                                   ($student_att['attendance_percentage'] >= 70 ? 'bg-yellow-500' : 'bg-red-600') ?>" 
                                                                 style="width: <?= min($student_att['attendance_percentage'], 100) ?>%"></div>
                                                        </div>
                                                        <span class="text-sm font-medium 
                                                            <?= $student_att['attendance_percentage'] >= 85 ? 'text-green-600' : 
                                                               ($student_att['attendance_percentage'] >= 70 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                            <?= $student_att['attendance_percentage'] ?>%
                                                        </span>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if ($student_att['total_attendance_records'] > 0): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                        <?= $student_att['attendance_percentage'] >= 85 ? 'bg-green-100 text-green-800' : 
                                                           ($student_att['attendance_percentage'] >= 70 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                                        <?= $student_att['attendance_percentage'] >= 85 ? 'Excellent' : 
                                                           ($student_att['attendance_percentage'] >= 70 ? 'Good' : 'Needs Improvement') ?>
                                                    </span>
                                                    <?php else: ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                        No Data
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-user-graduate text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-700 mb-2">No Student Attendance Data</h3>
                            <p class="text-gray-500">No attendance records found for active students in this batch.</p>
                        </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-calendar-alt text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-700 mb-2">No Scheduled Sessions</h3>
                            <p class="text-gray-500 mb-4">This batch doesn't have any scheduled sessions yet.</p>
                            <?php if ($batch['status'] === 'ongoing'): ?>
                            <a href="../schedule/add_schedule.php?batch_id=<?= $batch['batch_id'] ?>" class="btn btn-primary">
                                <i class="fas fa-plus mr-2"></i> Add Schedule
                            </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Transfer Student Modal -->
    <?php if ($batch['status'] !== 'completed'): ?>
    <div class="modal fade" id="transferStudentModal" tabindex="-1" aria-labelledby="transferStudentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="transferStudentModalLabel">Transfer Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="transferStudentForm">
                        <input type="hidden" id="transferStudentId" name="student_id">
                        <div class="mb-3">
                            <label for="studentName" class="form-label">Student Name</label>
                            <input type="text" class="form-control" id="studentName" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="targetBatch" class="form-label">Transfer to Batch</label>
                            <select class="form-select" id="targetBatch" name="target_batch_id" required>
                                <option value="">Select target batch</option>
                                <?php foreach ($available_batches as $batch_option): ?>
                                    <option value="<?= $batch_option['batch_id'] ?>">
                                        <?= htmlspecialchars($batch_option['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="transferDate" class="form-label">Transfer Date</label>
                            <input type="date" class="form-control" id="transferDate" name="transfer_date" required>
                        </div>
                        <div class="mb-3">
                            <label for="transferReason" class="form-label">Reason for Transfer</label>
                            <textarea class="form-control" id="transferReason" name="transfer_reason" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmTransferBtn">Transfer Student</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        
        <?php if ($batch['status'] !== 'completed'): ?>
        // Set current date for transfer forms
        document.getElementById('transferDate').valueAsDate = new Date();
        
        // Transfer Student Modal
        const transferStudentModal = new bootstrap.Modal(document.getElementById('transferStudentModal'));
        const transferStudentBtns = document.querySelectorAll('.transfer-student-btn');
        
        transferStudentBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const studentId = this.getAttribute('data-student-id');
                const studentName = this.getAttribute('data-student-name');
                
                document.getElementById('transferStudentId').value = studentId;
                document.getElementById('studentName').value = studentName;
                
                transferStudentModal.show();
            });
        });
        
        // Confirm Transfer (Single Student)
        document.getElementById('confirmTransferBtn').addEventListener('click', function() {
            const form = document.getElementById('transferStudentForm');
            const formData = new FormData(form);
            
            // Add current batch ID to the form data
            formData.append('current_batch', '<?= $batch_id ?>');
            
            fetch('transfer_student.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Student transferred successfully!');
                    transferStudentModal.hide();
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while transferring the student.');
            });
        });
        <?php endif; ?>
        
        // Counter animation
        const counter = document.getElementById('studentCounter');
        if (counter) {
            setTimeout(() => {
                counter.classList.add('updated');
                setTimeout(() => {
                    counter.classList.remove('updated');
                }, 500);
            }, 1000);
        }
        
        // Add sparkle elements dynamically
        function addSparkles() {
            const header = document.querySelector('.batch-header');
            for (let i = 0; i < 8; i++) {
                const sparkle = document.createElement('div');
                sparkle.className = 'sparkle';
                sparkle.style.top = Math.random() * 100 + '%';
                sparkle.style.left = Math.random() * 100 + '%';
                sparkle.style.width = Math.random() * 6 + 4 + 'px';
                sparkle.style.height = sparkle.style.width;
                sparkle.style.animationDelay = Math.random() * 3 + 's';
                header.appendChild(sparkle);
            }
        }
        
        // Initialize sparkles
        addSparkles();
        
        // Add click handler for thumbnail to view full size
        document.querySelectorAll('.batch-thumbnail-container').forEach(container => {
            container.addEventListener('click', function() {
                const img = this.querySelector('img');
                if (img) {
                    // Create a modal for full-size image view
                    const modalHtml = `
                        <div class="modal fade" id="fullImageModal" tabindex="-1">
                            <div class="modal-dialog modal-xl modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-body p-0">
                                        <img src="${img.src}" class="img-fluid w-100" alt="Full size thumbnail">
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    document.body.insertAdjacentHTML('beforeend', modalHtml);
                    const modal = new bootstrap.Modal(document.getElementById('fullImageModal'));
                    modal.show();
                    document.getElementById('fullImageModal').addEventListener('hidden.bs.modal', function() {
                        this.remove();
                    });
                }
            });
        });
    </script>
</body>
</html>