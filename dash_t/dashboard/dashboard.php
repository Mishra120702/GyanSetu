<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    // Localhost friendly: do not force HTTPS-only cookies in XAMPP
    'secure' => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

// Check if user is logged in as trainer
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../logout.php");
    exit;
}

require_once '../db_connection.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $trainer_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $stmt->execute([$trainer_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        header("Location: ../logout.php");
        exit;
    }

    // Get courses assigned to this trainer
    $courses_stmt = $db->prepare("
        SELECT bc.id as batch_course_id, bc.batch_id, bc.course_id, c.name as course_name, 
               b.batch_name, b.start_date, b.end_date, b.status, 
               b.time_slot, b.mode, b.max_students, b.current_enrollment,
               b.meeting_link, b.platform,
               (SELECT COUNT(DISTINCT student_id) FROM students WHERE batch_name = b.batch_id OR batch_name_2 = b.batch_id OR batch_name_3 = b.batch_id OR batch_name_4 = b.batch_id) as student_count
        FROM batch_courses bc
        JOIN batches b ON bc.batch_id = b.batch_id
        JOIN courses c ON bc.course_id = c.id
        WHERE bc.trainer_id = ? 
        ORDER BY 
            CASE b.status 
                WHEN 'ongoing' THEN 1
                WHEN 'upcoming' THEN 2
                WHEN 'completed' THEN 3
                WHEN 'cancelled' THEN 4
            END,
            b.created_at DESC
    ");
    $courses_stmt->execute([$trainer['id']]);
    $batches = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get today's classes
    $today = date('Y-m-d');
    $today_classes_stmt = $db->prepare("SELECT DISTINCT s.*, b.batch_name, b.meeting_link, b.platform
                         FROM schedule s 
                         JOIN batches b ON s.batch_id = b.batch_id 
                         JOIN batch_courses bc ON b.batch_id = bc.batch_id
                         WHERE bc.trainer_id = ? AND s.schedule_date = CURDATE() 
                         AND s.is_cancelled = 0
                         ORDER BY s.start_time");
    $today_classes_stmt->execute([$trainer['id']]);
    $today_classes = $today_classes_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if there's any ongoing class right now
    $current_time = date('H:i:s');
    $current_class = null;
    foreach ($today_classes as $class) {
        if ($current_time >= $class['start_time'] && $current_time <= $class['end_time']) {
            $current_class = $class;
            break;
        }
    }

    // Get upcoming classes - FIXED: Properly filter by trainer's batches only
    $stmt = $db->prepare("
        SELECT DISTINCT s.*, b.batch_name 
        FROM schedule s 
        JOIN batches b ON s.batch_id = b.batch_id 
        JOIN batch_courses bc ON b.batch_id = bc.batch_id
        WHERE bc.trainer_id = ? 
        AND (
            s.schedule_date > CURDATE() 
            OR (s.schedule_date = CURDATE() AND s.end_time > NOW())
        )
        AND s.is_cancelled = 0
        ORDER BY s.schedule_date ASC, s.start_time ASC 
        LIMIT 5
    ");
    $stmt->execute([$trainer['id']]);
    $upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get attendance stats
    $attendance_stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(a.date, '%Y-%m') as month,
            COUNT(*) as total_classes,
            SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) as present_count,
            CASE 
                WHEN COUNT(*) > 0 THEN ROUND((SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) / COUNT(*)) * 100)
                ELSE 0 
            END as attendance_percentage
        FROM attendance a
        JOIN batches b ON a.batch_id = b.batch_id
        JOIN batch_courses bc ON b.batch_id = bc.batch_id
        WHERE bc.trainer_id = ? AND a.date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY DATE_FORMAT(a.date, '%Y-%m')
        ORDER BY month ASC
    ");
    $attendance_stmt->execute([$trainer['id']]);
    $attendance_data = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent students - MODIFIED: Check all three batch columns
    $students_stmt = $db->prepare("
        SELECT s.student_id, s.first_name, s.last_name, 
               s.batch_name, s.batch_name_2, s.batch_name_3,
               s.current_status, 
               COALESCE(b1.batch_name, b2.batch_name, b3.batch_name) as batch_full_name,
               DATE(s.enrollment_date) as enrolled_date,
               s.email, s.phone_number, s.profile_picture,
               c.name as course_name
        FROM students s
        LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
        LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
        LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
        LEFT JOIN courses c ON s.course = c.id
        LEFT JOIN batch_courses bc1 ON b1.batch_id = bc1.batch_id AND bc1.trainer_id = ?
        LEFT JOIN batch_courses bc2 ON b2.batch_id = bc2.batch_id AND bc2.trainer_id = ?
        LEFT JOIN batch_courses bc3 ON b3.batch_id = bc3.batch_id AND bc3.trainer_id = ?
        WHERE (bc1.id IS NOT NULL OR bc2.id IS NOT NULL OR bc3.id IS NOT NULL)
        AND s.current_status != 'dropped'
        ORDER BY s.enrollment_date DESC
        LIMIT 8
    ");
    $students_stmt->execute([$trainer['id'], $trainer['id'], $trainer['id']]);
    $recent_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total students count for the trainer - MODIFIED: Check all three batch columns
    $total_students_stmt = $db->prepare("
        SELECT COUNT(DISTINCT s.student_id) as total_students
        FROM students s
        LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
        LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
        LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
        LEFT JOIN batch_courses bc1 ON b1.batch_id = bc1.batch_id AND bc1.trainer_id = ?
        LEFT JOIN batch_courses bc2 ON b2.batch_id = bc2.batch_id AND bc2.trainer_id = ?
        LEFT JOIN batch_courses bc3 ON b3.batch_id = bc3.batch_id AND bc3.trainer_id = ?
        WHERE (bc1.id IS NOT NULL OR bc2.id IS NOT NULL OR bc3.id IS NOT NULL)
        AND s.current_status != 'dropped'
    ");
    $total_students_stmt->execute([$trainer['id'], $trainer['id'], $trainer['id']]);
    $total_students_result = $total_students_stmt->fetch(PDO::FETCH_ASSOC);
    $total_students = $total_students_result['total_students'];

    // Get active students count - MODIFIED: Check all three batch columns
    $active_students_stmt = $db->prepare("
        SELECT COUNT(DISTINCT s.student_id) as active_count
        FROM students s
        LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
        LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
        LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
        LEFT JOIN batch_courses bc1 ON b1.batch_id = bc1.batch_id AND bc1.trainer_id = ?
        LEFT JOIN batch_courses bc2 ON b2.batch_id = bc2.batch_id AND bc2.trainer_id = ?
        LEFT JOIN batch_courses bc3 ON b3.batch_id = bc3.batch_id AND bc3.trainer_id = ?
        WHERE (bc1.id IS NOT NULL OR bc2.id IS NOT NULL OR bc3.id IS NOT NULL)
        AND s.current_status = 'active'
    ");
    $active_students_stmt->execute([$trainer['id'], $trainer['id'], $trainer['id']]);
    $active_students_result = $active_students_stmt->fetch(PDO::FETCH_ASSOC);
    $active_students = $active_students_result['active_count'];

    // Get completed students count - MODIFIED: Check all three batch columns
    $completed_students_stmt = $db->prepare("
        SELECT COUNT(DISTINCT s.student_id) as completed_count
        FROM students s
        LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
        LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
        LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
        LEFT JOIN batch_courses bc1 ON b1.batch_id = bc1.batch_id AND bc1.trainer_id = ?
        LEFT JOIN batch_courses bc2 ON b2.batch_id = bc2.batch_id AND bc2.trainer_id = ?
        LEFT JOIN batch_courses bc3 ON b3.batch_id = bc3.batch_id AND bc3.trainer_id = ?
        WHERE (bc1.id IS NOT NULL OR bc2.id IS NOT NULL OR bc3.id IS NOT NULL)
        AND s.current_status = 'completed'
    ");
    $completed_students_stmt->execute([$trainer['id'], $trainer['id'], $trainer['id']]);
    $completed_students_result = $completed_students_stmt->fetch(PDO::FETCH_ASSOC);
    $completed_students = $completed_students_result['completed_count'];

    // Get batch progress data
    $batch_progress_data = [];
    foreach ($batches as $batch) {
        $batch_course_id = $batch['batch_course_id'];
        $batch_id = $batch['batch_id'];
        $course_id = $batch['course_id'];
        
        // Get progress data for batch
        $progress_stmt = $db->prepare("
            SELECT 
                COUNT(mt.id) as total_topics,
                SUM(mt.covered_by_trainer) as covered_topics,
                COUNT(st.id) as total_sub_topics,
                SUM(CASE WHEN st.theory_completed = 1 AND st.practical_completed = 1 THEN 1 ELSE 0 END) as completed_sub_topics,
                SUM(st.theory_completed) as theory_completed,
                SUM(st.practical_completed) as practical_completed
            FROM main_topics mt
            LEFT JOIN sub_topics st ON mt.id = st.main_topic_id
            WHERE mt.batch_name = ? AND mt.course_id = ?
        ");
        $progress_stmt->execute([$batch_id, $course_id]);
        $progress = $progress_stmt->fetch(PDO::FETCH_ASSOC);
        
        $total_topics = $progress['total_topics'] ?? 0;
        $covered_topics = $progress['covered_topics'] ?? 0;
        $total_sub_topics = $progress['total_sub_topics'] ?? 0;
        $completed_sub_topics = $progress['completed_sub_topics'] ?? 0;
        $theory_completed = $progress['theory_completed'] ?? 0;
        $practical_completed = $progress['practical_completed'] ?? 0;
        
        $batch_progress_data[$batch_course_id] = [
            'total_topics' => $total_topics,
            'covered_topics' => $covered_topics,
            'total_sub_topics' => $total_sub_topics,
            'completed_sub_topics' => $completed_sub_topics,
            'theory_completed' => $theory_completed,
            'practical_completed' => $practical_completed,
            'topic_progress' => $total_topics > 0 ? round(($covered_topics / $total_topics) * 100) : 0,
            'sub_topic_progress' => $total_sub_topics > 0 ? round(($completed_sub_topics / $total_sub_topics) * 100) : 0,
            'theory_progress' => $total_sub_topics > 0 ? round(($theory_completed / $total_sub_topics) * 100) : 0,
            'practical_progress' => $total_sub_topics > 0 ? round(($practical_completed / $total_sub_topics) * 100) : 0
        ];
    }

    // Get count of active courses for the trainer (not completed/cancelled)
    $ongoing_batches_stmt = $db->prepare("
        SELECT COUNT(DISTINCT bc.course_id) as ongoing_count
        FROM batch_courses bc
        JOIN batches b ON bc.batch_id = b.batch_id
        WHERE bc.trainer_id = ? 
        AND b.status NOT IN ('completed', 'cancelled')
    ");
    $ongoing_batches_stmt->execute([$trainer['id']]);
    $ongoing_batches_result = $ongoing_batches_stmt->fetch(PDO::FETCH_ASSOC);
    $ongoing_batches_count = $ongoing_batches_result['ongoing_count'] ?? 0;

    // Dashboard derived metrics for the refreshed UI
    $avg_attendance = 0;
    if (count($attendance_data) > 0) {
        $attendance_sum = 0;
        $attendance_count = 0;
        foreach ($attendance_data as $row) {
            if (($row['attendance_percentage'] ?? 0) > 0) {
                $attendance_sum += $row['attendance_percentage'];
                $attendance_count++;
            }
        }
        $avg_attendance = $attendance_count > 0 ? round($attendance_sum / $attendance_count) : 0;
    }

    $overall_progress = 0;
    $progress_count = 0;
    foreach ($batch_progress_data as $progress) {
        if (($progress['topic_progress'] ?? 0) > 0) {
            $overall_progress += $progress['topic_progress'];
            $progress_count++;
        }
    }
    $overall_progress = $progress_count > 0 ? round($overall_progress / $progress_count) : 0;

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Trainer Dashboard | ASD Academy</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .glass-card:hover {
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }
        
        .gradient-text {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @keyframes float {
            0% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(5deg); }
            100% { transform: translateY(0px) rotate(0deg); }
        }
        
        .hover-lift {
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), box-shadow 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .hover-lift:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-ongoing {
            background: rgba(34, 197, 94, 0.12);
            color: #16a34a;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }
        
        .status-upcoming {
            background: rgba(59, 130, 246, 0.12);
            color: #234C6A;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .status-completed {
            background: rgba(249, 115, 22, 0.12);
            color: #ea580c;
            border: 1px solid rgba(249, 115, 22, 0.2);
        }
        
        .status-cancelled {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .progress-bar-container {
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
            background: #f1f5f9;
        }
        
        .progress-bar-fill {
            height: 100%;
            transition: width 0.8s ease-in-out;
            border-radius: 3px;
        }
        
        .stats-card {
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .stats-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 32px rgba(0, 0, 0, 0.1);
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, #1B3C53, #234C6A);
        }
        
        .floating-shape {
            position: absolute;
            border-radius: 50%;
            opacity: 0.05;
            z-index: -1;
            filter: blur(40px);
        }
        
        .ripple-effect {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            transform: scale(0);
            animation: ripple 0.6s linear;
        }
        
        @keyframes ripple {
            to { transform: scale(4); opacity: 0; }
        }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        
        .table-row-hover {
            transition: all 0.2s ease;
        }
        
        .table-row-hover:hover {
            background-color: rgba(241, 245, 249, 0.8);
        }
        
        .stats-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        
        .quick-action-btn {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 14px;
            overflow: hidden;
        }
        
        .quick-action-btn:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
        }
        
        .quick-action-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            margin-bottom: 12px;
        }
        
        .live-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            padding: 4px 10px;
            background: linear-gradient(135deg, #f5576c, #f093fb);
            color: white;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            animation: pulse 1.5s infinite;
            z-index: 10;
        }
        
        .join-class-btn {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(27,60,83, 0.3);
        }
        
        .join-class-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(27,60,83, 0.4);
        }
        
        .wave-animation {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 80px;
            background: url('data:image/svg+xml;utf8,<svg viewBox="0 0 1200 120" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none"><path d="M0,0V46.29c47.79,22.2,103.59,32.17,158,28,70.36-5.37,136.33-33.31,206.8-37.5C438.64,32.43,512.34,53.67,583,72.05c69.27,18,138.3,24.88,209.4,13.08,36.15-6,69.85-17.84,104.45-29.34C989.49,25,1113-14.29,1200,52.47V0Z" fill="%23667eea" opacity=".05"/></svg>');
            background-size: cover;
            background-repeat: no-repeat;
            opacity: 0.5;
            z-index: -1;
        }
        

        /* Visual-only polish: same modern theme, no new workflow added */
        .dashboard-shell {
            background:
                radial-gradient(circle at top left, rgba(35,76,106, .12), transparent 34%),
                radial-gradient(circle at top right, rgba(69,104,130, .10), transparent 30%),
                linear-gradient(180deg, #f8fbff 0%, #EEF3F6 48%, #f8fafc 100%);
        }

        .trainer-hero {
            box-shadow: 0 24px 70px rgba(27, 60, 83, .28), inset 0 1px 0 rgba(255,255,255,.22);
        }

        .glass-card {
            border: 1px solid rgba(226, 232, 240, .95);
            box-shadow: 0 14px 42px rgba(15, 23, 42, .08);
        }

        .glass-card:hover {
            box-shadow: 0 20px 52px rgba(79, 70, 229, .13);
        }

        .stats-card {
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(255,255,255,.98), rgba(248,250,252,.96));
        }

        .table-row-hover:hover {
            background: linear-gradient(90deg, rgba(35,76,106, .06), rgba(69,104,130, .04));
        }

        .section-title-pill {
            box-shadow: 0 10px 30px rgba(79, 70, 229, .12);
        }

        /* Mobile Navigation Styles */
        .mobile-nav-link {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-radius: 0.5rem;
            transition: all 0.3s;
        }
        
        .mobile-nav-link.active {
            background-color: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-weight: 600;
        }
        
        #mobileMenu {
            transition: opacity 0.3s ease-in-out;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .glass-card {
                border-radius: 12px;
            }
            
            .stats-icon {
                width: 48px;
                height: 48px;
                font-size: 20px;
            }
            
            .quick-action-icon {
                width: 40px;
                height: 40px;
                font-size: 18px;
            }
            
            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }
            
            .table-responsive table {
                min-width: 640px;
            }
        }
        
        @media (max-width: 480px) {
            .glass-card {
                padding: 1rem !important;
            }
            
            .stats-card {
                padding: 1.25rem !important;
            }
            
            .quick-action-btn {
                padding: 1rem !important;
            }
            
            .status-badge {
                padding: 3px 8px;
                font-size: 0.65rem;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #5a6fd8, #6a4190);
        }
        
        /* Loading skeleton */
        .loading-skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }
        
        @keyframes loading {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        
        /* Smooth transitions */
        * {
            -webkit-tap-highlight-color: transparent;
        }
        
        /* Avatar gradients */
        .avatar-gradient {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
        }
        
        .avatar-gradient-2 {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }
        
        .avatar-gradient-3 {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }
        
        .avatar-gradient-4 {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
        }
        
        .avatar-gradient-5 {
            background: linear-gradient(135deg, #fa709a, #fee140);
        }
        
        /* Animation delays */
        .animation-delay-100 { animation-delay: 100ms; }
        .animation-delay-200 { animation-delay: 200ms; }
        .animation-delay-300 { animation-delay: 300ms; }
        .animation-delay-400 { animation-delay: 400ms; }
        .animation-delay-500 { animation-delay: 500ms; }


        /* === Modern Trainer Dashboard Refresh === */
        .dashboard-shell {
            background:
                radial-gradient(circle at top left, rgba(27,60,83, 0.16), transparent 30%),
                radial-gradient(circle at top right, rgba(69,104,130, 0.12), transparent 26%),
                linear-gradient(180deg, #f8fbff 0%, #f3f4f8 48%, #EEF3F6 100%);
        }

        .trainer-hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.25rem, 2.6vw, 2rem);
            background:
                linear-gradient(135deg, rgba(27,60,83, 0.95) 0%, rgba(35,76,106, 0.93) 48%, rgba(69,104,130, 0.9) 100%);
            color: #fff;
            box-shadow: 0 22px 55px rgba(27, 60, 83, 0.28);
        }

        .trainer-hero::before {
            content: '';
            position: absolute;
            width: 360px;
            height: 360px;
            right: -120px;
            top: -140px;
            border-radius: 999px;
            background: rgba(210, 193, 182, .22);
            filter: blur(3px);
        }

        .trainer-hero::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            left: 38%;
            bottom: -150px;
            border-radius: 999px;
            background: rgba(210, 193, 182, .18);
            filter: blur(2px);
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .55rem .8rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, .16);
            border: 1px solid rgba(255, 255, 255, .22);
            backdrop-filter: blur(12px);
            font-size: .82rem;
            font-weight: 700;
            color: rgba(255,255,255,.95);
        }

        .hero-stat-card {
            border-radius: 20px;
            padding: 1rem;
            background: rgba(255, 255, 255, .14);
            border: 1px solid rgba(255, 255, 255, .20);
            backdrop-filter: blur(14px);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.15);
        }

        .hero-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .55rem;
            border-radius: 16px;
            padding: .8rem 1rem;
            font-weight: 800;
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }

        .hero-action:hover {
            transform: translateY(-2px);
        }

        .hero-action-primary {
            background: #fff;
            color: #1B3C53;
            box-shadow: 0 12px 25px rgba(15, 23, 42, .18);
        }

        .hero-action-secondary {
            background: rgba(255,255,255,.14);
            color: #fff;
            border: 1px solid rgba(255,255,255,.25);
        }

        .section-title-pill {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .35rem .75rem;
            border-radius: 999px;
            background: rgba(27,60,83, .10);
            color: #1B3C53;
            font-size: .75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .quick-action-modern {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            min-height: 128px;
        }

        .quick-action-modern::after {
            content: '';
            position: absolute;
            inset: auto -35% -55% auto;
            width: 120px;
            height: 120px;
            border-radius: 999px;
            background: rgba(27,60,83,.10);
            z-index: -1;
            transition: transform .25s ease;
        }

        .quick-action-modern:hover::after {
            transform: scale(1.25);
        }

        .empty-state-soft {
            border: 1px dashed #cbd5e1;
            background: linear-gradient(135deg, rgba(248,250,252,.9), rgba(239,246,255,.85));
            border-radius: 18px;
        }

        .metric-subtext {
            color: #64748b;
            font-size: .78rem;
            font-weight: 600;
        }

        .glass-card {
            border-color: rgba(226, 232, 240, 0.8);
        }

        .stats-card::before {
            height: 5px;
            background: linear-gradient(90deg, #234C6A, #234C6A, #456882);
        }

        @media (max-width: 768px) {
            .trainer-hero {
                border-radius: 22px;
            }
            .hero-stat-card {
                padding: .85rem;
            }
        }

        .feature-shell {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(255,255,255,.94), rgba(248,250,255,.97));
            border: 1px solid rgba(226,232,240,.9);
            box-shadow: 0 18px 40px rgba(15,23,42,.07);
        }

        .feature-shell::before {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            top: 0;
            height: 4px;
            background: var(--feature-accent, linear-gradient(90deg, #1B3C53, #234C6A, #456882));
        }

        .feature-shell::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -55px;
            top: -55px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .7;
            pointer-events: none;
        }

        .feature-shell > * { position: relative; z-index: 1; }

        .feature-blue {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(59,130,246,.16), rgba(69,104,130,.04) 58%, transparent 70%);
        }

        .feature-orange {
            --feature-accent: linear-gradient(90deg, #f97316, #f59e0b, #fb7185);
            --feature-glow: radial-gradient(circle, rgba(249,115,22,.14), rgba(251,113,133,.05) 58%, transparent 70%);
        }

        .feature-violet {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 58%, transparent 70%);
        }

        .feature-emerald {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.14), rgba(69,104,130,.05) 58%, transparent 70%);
        }

        .feature-indigo {
            --feature-accent: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(79,70,229,.14), rgba(69,104,130,.05) 58%, transparent 70%);
        }

        .feature-shell .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .36rem .72rem;
            border-radius: 999px;
            margin-bottom: .75rem;
            background: rgba(255,255,255,.82);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }

        .feature-item {
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            border: 1px solid rgba(226,232,240,.85);
            background: linear-gradient(135deg, rgba(255,255,255,.94), rgba(248,250,255,.92));
            box-shadow: 0 12px 28px rgba(15,23,42,.05);
        }

        .feature-item::after {
            content: "";
            position: absolute;
            inset: auto -38px -44px auto;
            width: 110px;
            height: 110px;
            border-radius: 999px;
            background: rgba(27,60,83,.08);
        }

        .feature-item > * { position: relative; z-index: 1; }

        .feature-shell .table-responsive {
            border-radius: 18px;
            border: 1px solid rgba(226,232,240,.7);
            background: rgba(255,255,255,.66);
        }

        .feature-shell .empty-state-soft { background: linear-gradient(135deg, rgba(255,255,255,.92), rgba(243,244,255,.88)); }
    
/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
}
body {
    background:
        radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
        radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
        linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-purple-500,.text-purple-600,.text-indigo-500,.text-indigo-600,.text-blue-500,.text-blue-600,.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-purple-200,.border-indigo-200,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    </style>
<style>

/* ===== Company Source Safe UI Patch: dashboard box coloring only ===== */
/* UI-only patch. No PHP query, session, form, link, JS, ID, name, AJAX, or DB logic touched. */

.dashboard-shell {
    background:
        radial-gradient(circle at 8% 8%, rgba(27,60,83,.10), transparent 28%),
        radial-gradient(circle at 92% 6%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
}

/* Performance Snapshot: colorful cards like previous approved theme */
.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card {
    position: relative !important;
    overflow: hidden !important;
    min-height: 158px !important;
    color: #ffffff !important;
    border-radius: 24px !important;
    border: 1.5px solid rgba(255,255,255,.36) !important;
    box-shadow:
        0 20px 42px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card::before {
    height: 0 !important;
    display: none !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card::after {
    content: "" !important;
    position: absolute !important;
    right: -44px !important;
    top: -44px !important;
    width: 132px !important;
    height: 132px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.14) !important;
    pointer-events: none !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card > * {
    position: relative !important;
    z-index: 2 !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card:hover {
    transform: translateY(-5px) !important;
    filter: brightness(1.05) !important;
    box-shadow:
        0 30px 64px rgba(27,60,83,.26),
        inset 0 1px 0 rgba(255,255,255,.26) !important;
}

/* Requested color mapping for trainer dashboard snapshot */
.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card:nth-child(1) {
    background: linear-gradient(135deg, #6d28d9 0%, #7c3aed 54%, #a78bfa 100%) !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card:nth-child(2) {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%) !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card:nth-child(3) {
    background: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%) !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card:nth-child(4) {
    background: linear-gradient(135deg, #3f8f0c 0%, #65a30d 54%, #a3e635 100%) !important;
}

/* Snapshot text white and readable */
.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card h3,
.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card p,
.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card span {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.18) !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card h3 {
    opacity: .92 !important;
    font-weight: 900 !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card p {
    font-weight: 1000 !important;
}

/* Snapshot icon circular shade + effect */
.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card .stats-icon {
    width: 54px !important;
    height: 54px !important;
    min-width: 54px !important;
    min-height: 54px !important;
    border-radius: 999px !important;
    margin-bottom: 16px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.5px solid rgba(255,255,255,.46) !important;
    box-shadow:
        0 13px 28px rgba(0,0,0,.20),
        inset 0 1px 0 rgba(255,255,255,.24) !important;
    transition: transform .22s ease, box-shadow .22s ease !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card .stats-icon i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card:hover .stats-icon {
    transform: translateY(-2px) scale(1.10) rotate(3deg) !important;
    box-shadow:
        0 18px 36px rgba(0,0,0,.26),
        0 0 0 8px rgba(255,255,255,.15),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}

/* Progress bars inside colored cards */
.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card .progress-bar-container {
    background: rgba(255,255,255,.30) !important;
    height: 7px !important;
    border-radius: 999px !important;
}

.dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card .progress-bar-fill {
    background: rgba(255,255,255,.82) !important;
    box-shadow: 0 0 12px rgba(255,255,255,.22) !important;
}

/* Inner content panels: subtle shaded boxes, not backend-interfering */
.dashboard-shell .feature-shell {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.10), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.88)) !important;
    border: 1.6px solid rgba(210,193,182,.68) !important;
    box-shadow:
        0 20px 48px rgba(27,60,83,.12),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.dashboard-shell .feature-shell:hover {
    transform: translateY(-3px) !important;
    border-color: rgba(35,76,106,.42) !important;
    box-shadow:
        0 28px 60px rgba(27,60,83,.17),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}

.dashboard-shell .feature-shell::before {
    background: linear-gradient(90deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
    height: 5px !important;
}

/* Recent students and upcoming classes slightly darker shaded boxes */
.dashboard-shell .feature-violet,
.dashboard-shell .feature-emerald {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.13), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.28), transparent 30%),
        linear-gradient(135deg, rgba(250,248,245,.99), rgba(238,243,246,.91)) !important;
}

.dashboard-shell .feature-item {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.07), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.88)) !important;
    border: 1.35px solid rgba(210,193,182,.70) !important;
    box-shadow:
        0 12px 28px rgba(27,60,83,.075),
        inset 0 1px 0 rgba(255,255,255,.82) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.dashboard-shell .feature-item:hover {
    transform: translateY(-3px) !important;
    border-color: rgba(35,76,106,.42) !important;
    box-shadow:
        0 18px 36px rgba(27,60,83,.13),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
}

/* Keep table headers and action buttons theme-matched */
.dashboard-shell .feature-shell thead,
.dashboard-shell .feature-shell thead tr {
    background: linear-gradient(135deg, rgba(210,193,182,.36), rgba(238,243,246,.95)) !important;
}

.dashboard-shell .feature-shell thead th {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

.dashboard-shell .feature-shell a[href*="my_courses.php"],
.dashboard-shell .feature-shell a[href*="students.php"],
.dashboard-shell .feature-shell a[href*="schedule.php"] {
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.dashboard-shell .feature-shell a[href*="my_courses.php"]:hover,
.dashboard-shell .feature-shell a[href*="students.php"]:hover,
.dashboard-shell .feature-shell a[href*="schedule.php"]:hover {
    transform: translateY(-2px) !important;
    filter: brightness(1.04) !important;
}

/* Empty box shade */
.dashboard-shell .empty-state-soft {
    background:
        radial-gradient(circle at 50% 0%, rgba(69,104,130,.08), transparent 40%),
        linear-gradient(135deg, rgba(255,253,250,.96), rgba(238,243,246,.88)) !important;
    border: 1.6px dashed rgba(69,104,130,.36) !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.82) !important;
}

@media (max-width: 640px) {
    .dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card {
        min-height: 142px !important;
        padding: 1rem !important;
    }

    .dashboard-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-card .stats-icon {
        width: 46px !important;
        height: 46px !important;
        min-width: 46px !important;
        min-height: 46px !important;
    }
}

</style>
</head>
<body class="relative overflow-x-hidden">
    <!-- Floating Background Shapes -->
    <div class="floating-shape w-96 h-96 bg-purple-300 top-0 -left-24" style="animation: float 20s ease-in-out infinite;"></div>
    <div class="floating-shape w-80 h-80 bg-blue-300 bottom-0 -right-20" style="animation: float 25s ease-in-out infinite reverse;"></div>
    
    <!-- Wave Animation -->
    <div class="wave-animation"></div>
    
    <!-- Sidebar (Desktop) -->
    <?php include '../t_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Mobile Header (Visible only on mobile) -->
        <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 lg:hidden">
            <!-- Mobile Menu Button -->
            <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            
            <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-tachometer-alt text-indigo-600 text-sm"></i>
                </div>
                <span>Dashboard</span>
            </h1>
            
            <div class="flex items-center space-x-3">
                <!-- User Profile/Indicator -->
                <div class="relative">
                    <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-chalkboard-teacher text-indigo-600"></i>
                    </div>
                </div>
            </div>
        </header>

        <!-- Desktop Header (Hidden on mobile) -->
        <header class="hidden lg:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
            <div class="flex-1"></div> <!-- Spacer for centering -->
            
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-tachometer-alt text-indigo-600 text-xl"></i>
                </div>
                <span>Trainer Dashboard</span>
            </h1>
            
            <div class="flex-1 flex justify-end items-center space-x-4">
                <!-- Join Class Button - Only show if there's an ongoing class -->
                <?php if ($current_class): ?>
                <div class="relative">
                    <a href="<?php echo htmlspecialchars($current_class['meeting_link'] ?? '#'); ?>" 
                       target="_blank" 
                       class="join-class-btn text-sm">
                        <i class="fas fa-video mr-2"></i>
                        <span>Join Current Class</span>
                    </a>
                    <span class="live-badge">LIVE</span>
                </div>
                <?php endif; ?>
                
                <?php include '../trainer_notification_bell.php'; ?>
                
                <div class="animate-pulse bg-indigo-100 rounded-full p-2 ml-4">
                    <i class="fas fa-chalkboard-teacher text-indigo-600"></i>
                </div>
            </div>
        </header>

        <!-- Mobile Navigation Menu -->
        <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden lg:hidden">
            <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs bg-gradient-to-b from-blue-50 to-indigo-50 shadow-xl transform transition-transform duration-300 -translate-x-full">
                <!-- Mobile Menu Header -->
                <div class="p-4 border-b border-blue-200 bg-gradient-to-r from-blue-100 to-indigo-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                        </div>
                        <button onclick="toggleMobileMenu()" class="text-gray-500 hover:text-indigo-600 text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- User Info -->
                    <div class="mt-4 flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold">
                            <?= strtoupper(substr($trainer['name'] ?? 'T', 0, 1)) ?>
                        </div>
                        <div>
                            <p class="font-medium text-gray-800"><?= htmlspecialchars($trainer['name'] ?? 'Trainer') ?></p>
                            <p class="text-xs text-gray-600">Trainer</p>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile Navigation Links -->
                <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                    
                    <a href="../dashboard/dashboard.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'bg-white shadow-md text-blue-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-tachometer-alt <?= $current_page == 'dashboard.php' ? 'text-blue-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>

                    <a href="../courses/my_courses.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'batches.php' ? 'bg-white shadow-md text-green-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-book-open <?= $current_page == 'batches.php' ? 'text-green-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">My Courses</span>
                    </a>

                    <a href="../students/students.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'students.php' ? 'bg-white shadow-md text-purple-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-user-graduate <?= $current_page == 'students.php' ? 'text-purple-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">My Students</span>
                    </a>

                    <a href="../schedule/schedule.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'schedule.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-calendar-alt <?= $current_page == 'schedule.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">Schedule</span>
                    </a>

                    <a href="../attendance/trainer_attendance.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'trainer_attendance.php' ? 'bg-white shadow-md text-red-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-clipboard-check <?= $current_page == 'trainer_attendance.php' ? 'text-red-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">Attendance</span>
                    </a>

                    <a href="../feedback/weekly_feedback.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'weekly_feedback.php' ? 'bg-white shadow-md text-indigo-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-comment-dots <?= $current_page == 'weekly_feedback.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">Feedback</span>
                    </a>

                    <a href="../exam/trainer_dashboard.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'trainer_dashboard.php' ? 'bg-white shadow-md text-amber-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-file-alt <?= $current_page == 'trainer_dashboard.php' ? 'text-amber-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">Exams</span>
                    </a>

                    <a href="../content/trainer_content.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'trainer_content.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-tasks <?= $current_page == 'trainer_content.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">Study Materials</span>
                    </a>
                    
                    <a href="../profile.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'profile.php' ? 'bg-white shadow-md text-pink-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-user <?= $current_page == 'profile.php' ? 'text-pink-600' : 'text-gray-500' ?>"></i>
                        </div>
                        <span class="font-medium">Profile</span>
                    </a>
                    
                    <!-- Logout Button -->
                    <a href="../logout.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-50 hover:text-red-600 text-gray-700 mt-4 border-t pt-4"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-sign-out-alt text-red-500"></i>
                        </div>
                        <span class="font-medium">Logout</span>
                    </a>
                </nav>
            </div>
        </div>

        <div class="dashboard-shell p-4 lg:p-8 min-h-screen">
            <!-- Modern Welcome / Command Center -->
            <section class="trainer-hero mb-5 sm:mb-7" data-aos="fade-up">
                <div class="relative z-10 grid grid-cols-1 xl:grid-cols-[1.45fr_.95fr] gap-6 items-stretch">
                    <div class="flex flex-col justify-between gap-6">
                        <div>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="hero-chip"><i class="fas fa-chart-line"></i> Trainer Overview</span>
                                <span class="hero-chip"><i class="fas fa-calendar-day"></i> <?php echo date('l, d M Y'); ?></span>
                                <?php if ($current_class): ?>
                                    <span class="hero-chip bg-red-500/30"><i class="fas fa-circle text-red-200 animate-pulse"></i> Live class running</span>
                                <?php endif; ?>
                            </div>

                            <h2 class="text-3xl sm:text-4xl font-extrabold leading-tight mb-3">
                                Hello, <?php echo htmlspecialchars($trainer['name']); ?> 👋
                            </h2>
                            <p class="text-white/80 text-sm sm:text-base max-w-2xl">
                                A clean overview of your assigned courses, students, classes, attendance, and batch progress.
                            </p>
                        </div>

                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="hero-stat-card">
                            <p class="text-white/70 text-xs font-bold uppercase tracking-wide">Courses</p>
                            <p class="text-3xl font-black mt-1"><?php echo count($batches); ?></p>
                            <p class="text-white/65 text-xs mt-1">Assigned batches</p>
                        </div>
                        <div class="hero-stat-card">
                            <p class="text-white/70 text-xs font-bold uppercase tracking-wide">Students</p>
                            <p class="text-3xl font-black mt-1"><?php echo $total_students; ?></p>
                            <p class="text-white/65 text-xs mt-1"><?php echo $active_students; ?> active</p>
                        </div>
                        <div class="hero-stat-card">
                            <p class="text-white/70 text-xs font-bold uppercase tracking-wide">Avg Attendance</p>
                            <p class="text-3xl font-black mt-1"><?php echo $avg_attendance; ?>%</p>
                            <div class="mt-3 h-2 rounded-full bg-white/20 overflow-hidden">
                                <div class="h-full rounded-full bg-white" style="width: <?php echo min(100, $avg_attendance); ?>%"></div>
                            </div>
                        </div>
                        <div class="hero-stat-card">
                            <p class="text-white/70 text-xs font-bold uppercase tracking-wide">Progress</p>
                            <p class="text-3xl font-black mt-1"><?php echo $overall_progress; ?>%</p>
                            <div class="mt-3 h-2 rounded-full bg-white/20 overflow-hidden">
                                <div class="h-full rounded-full bg-white" style="width: <?php echo min(100, $overall_progress); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($current_class): ?>
                <div class="relative z-10 mt-5 p-4 rounded-2xl bg-white/14 border border-white/20 backdrop-blur-md">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl bg-white/20 flex items-center justify-center">
                                <i class="fas fa-broadcast-tower text-white"></i>
                            </div>
                            <div>
                                <h3 class="font-extrabold text-white">Class in Progress</h3>
                                <p class="text-white/75 text-sm">
                                    <?php echo htmlspecialchars($current_class['batch_name']); ?> · <?php echo date('g:i A', strtotime($current_class['start_time'])); ?> - <?php echo date('g:i A', strtotime($current_class['end_time'])); ?>
                                </p>
                            </div>
                        </div>
                        <?php if (!empty($current_class['meeting_link'])): ?>
                        <a href="<?php echo htmlspecialchars($current_class['meeting_link']); ?>" target="_blank" class="hero-action hero-action-primary text-sm">
                            <i class="fas fa-video"></i> Continue Class
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </section>

            <div class="flex items-center justify-between mb-3">
                <span class="section-title-pill"><i class="fas fa-chart-pie"></i> Performance Snapshot</span>
            </div>

            <!-- Stats Cards -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4 sm:mb-6">
                <!-- Modified: Active Courses now shows only ongoing courses count -->
                <div class="stats-card glass-card p-4 sm:p-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="stats-icon bg-gradient-to-r from-blue-500 to-cyan-500">
                        <i class="fas fa-book-open"></i>
                    </div>
                    <h3 class="text-sm sm:text-base font-semibold text-gray-800 mb-2">Active Courses</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-blue-600 mb-3"><?php echo $ongoing_batches_count; ?></p>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill bg-gradient-to-r from-blue-400 to-cyan-400" style="width: <?php echo min(100, $ongoing_batches_count * 25); ?>%"></div>
                    </div>
                </div>

                <div class="stats-card glass-card p-4 sm:p-6" data-aos="fade-up" data-aos-delay="150">
                    <div class="stats-icon bg-gradient-to-r from-purple-500 to-pink-500">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <h3 class="text-sm sm:text-base font-semibold text-gray-800 mb-2">Total Students</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-purple-600 mb-3"><?php echo $total_students; ?></p>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill bg-gradient-to-r from-purple-400 to-pink-400" style="width: <?php echo min(100, ($total_students > 0 ? ($total_students / 100) * 100 : 0)); ?>%"></div>
                    </div>
                </div>

                <div class="stats-card glass-card p-4 sm:p-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="stats-icon bg-gradient-to-r from-green-500 to-emerald-500">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <h3 class="text-sm sm:text-base font-semibold text-gray-800 mb-2">Active Students</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-green-600 mb-3"><?php echo $active_students; ?></p>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill bg-gradient-to-r from-green-400 to-emerald-400" style="width: <?php echo $total_students > 0 ? round(($active_students / $total_students) * 100) : 0; ?>%"></div>
                    </div>
                </div>

                <div class="stats-card glass-card p-4 sm:p-6" data-aos="fade-up" data-aos-delay="250">
                    <div class="stats-icon bg-gradient-to-r from-amber-500 to-orange-500">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <h3 class="text-sm sm:text-base font-semibold text-gray-800 mb-2">Avg Attendance</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-amber-600 mb-3">
                        <?php 
                        $avg_attendance = 0;
                        if (count($attendance_data) > 0) {
                            $sum = 0;
                            $count = 0;
                            foreach ($attendance_data as $row) {
                                if ($row['attendance_percentage'] > 0) {
                                    $sum += $row['attendance_percentage'];
                                    $count++;
                                }
                            }
                            $avg_attendance = $count > 0 ? round($sum / $count) : 0;
                        }
                        echo $avg_attendance . '%';
                        ?>
                    </p>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill bg-gradient-to-r from-amber-400 to-orange-400" style="width: <?php echo $avg_attendance; ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- My Courses Table -->
            <div class="glass-card feature-shell feature-blue p-4 sm:p-6 mb-4 sm:mb-6" data-aos="fade-up" data-aos-delay="300">
                <div class="section-kicker"><i class="fas fa-layer-group"></i> Course Overview</div>
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-6 gap-2">
                    <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-list-check text-blue-500"></i>
                        My Courses
                    </h3>
                    <a href="../courses/my_courses.php" class="px-3 sm:px-4 py-2 bg-gradient-to-r from-blue-500 to-cyan-500 text-white rounded-lg font-semibold text-sm hover:opacity-90 transition-opacity flex items-center gap-2">
                        <i class="fas fa-eye"></i>
                        <span class="hidden sm:inline">View All</span>
                    </a>
                </div>
                
                <div class="table-responsive overflow-x-auto rounded-lg">
                    <table class="w-full min-w-max">
                        <thead class="bg-gradient-to-r from-blue-50 to-cyan-50">
                            <tr>
                                <th class="py-3 px-3 sm:px-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Course (Batch)</th>
                                <th class="py-3 px-3 sm:px-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider hidden sm:table-cell">Schedule</th>
                                <th class="py-3 px-3 sm:px-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Students</th>
                                <th class="py-3 px-3 sm:px-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider hidden md:table-cell">Status</th>
                                <th class="py-3 px-3 sm:px-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider hidden lg:table-cell">Progress</th>
                                <th class="py-3 px-3 sm:px-4 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (count($batches) > 0): ?>
                                <?php foreach ($batches as $index => $batch): 
                                    $progress = $batch_progress_data[$batch['batch_course_id']] ?? ['topic_progress' => 0];
                                ?>
                                    <tr class="table-row-hover hover:bg-blue-50/50 transition-all duration-300">
                                        <td class="py-3 px-3 sm:px-4">
                                            <div>
                                                <span class="font-medium text-gray-900 text-sm block"><?php echo htmlspecialchars($batch['course_name']); ?></span>
                                                <span class="text-xs text-gray-600 block"><i class="fas fa-layer-group text-[10px] mr-1 text-gray-400"></i><?php echo htmlspecialchars($batch['batch_name']); ?> (<?php echo htmlspecialchars($batch['batch_id']); ?>)</span>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 sm:px-4 hidden sm:table-cell">
                                            <div class="text-xs sm:text-sm text-gray-600"><?php echo htmlspecialchars($batch['time_slot']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($batch['start_date'])); ?></div>
                                        </td>
                                        <td class="py-3 px-3 sm:px-4">
                                            <div class="flex items-center">
                                                <div class="w-16 sm:w-20 bg-gray-200 rounded-full h-1.5 sm:h-2 mr-2">
                                                    <div class="bg-green-500 h-full rounded-full" style="width: <?php echo min(100, ($batch['student_count'] / max(1, $batch['max_students'])) * 100); ?>%"></div>
                                                </div>
                                                <span class="text-xs sm:text-sm font-medium text-gray-700"><?php echo $batch['student_count']; ?>/<?php echo $batch['max_students']; ?></span>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 sm:px-4 hidden md:table-cell">
                                            <span class="status-badge status-<?php echo $batch['status']; ?>">
                                                <?php echo ucfirst($batch['status']); ?>
                                            </span>
                                        </td>
                                        <td class="py-3 px-3 sm:px-4 hidden lg:table-cell">
                                            <div class="flex items-center">
                                                <div class="w-20 sm:w-24 bg-gray-200 rounded-full h-1.5 sm:h-2 mr-2">
                                                    <div class="bg-gradient-to-r from-purple-500 to-pink-500 h-full rounded-full" style="width: <?php echo $progress['topic_progress']; ?>%"></div>
                                                </div>
                                                <span class="text-xs sm:text-sm font-bold text-purple-600"><?php echo $progress['topic_progress']; ?>%</span>
                                            </div>
                                        </td>
                                        <td class="py-3 px-3 sm:px-4">
                                            <div class="flex gap-1">
                                                <?php if ($batch['meeting_link']): ?>
                                                <a href="<?php echo htmlspecialchars($batch['meeting_link']); ?>" 
                                                   target="_blank" 
                                                   class="px-2 py-1 bg-gradient-to-r from-blue-500 to-cyan-500 text-white text-xs rounded-lg font-medium hover:opacity-90 transition-opacity flex items-center gap-1"
                                                   title="Join Class">
                                                    <i class="fas fa-video text-xs mr-2"></i>
                                                    <span class="hidden sm:inline">Join</span>
                                                </a>
                                                <?php else: ?>
                                                <span class="px-2 py-1 bg-gray-100 text-gray-500 text-xs rounded-lg font-medium flex items-center gap-1">
                                                    <i class="fas fa-link-slash text-xs"></i>
                                                    <span class="hidden sm:inline">No Link</span>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="py-10 text-center">
                                        <div class="empty-state-soft max-w-md mx-auto p-6">
                                            <div class="w-14 h-14 mx-auto rounded-2xl bg-indigo-100 text-indigo-500 flex items-center justify-center mb-3">
                                                <i class="fas fa-layer-group text-2xl"></i>
                                            </div>
                                            <p class="font-bold text-gray-700">No batches assigned yet</p>
                                            <p class="text-sm text-gray-500 mt-1">Once admin assigns a batch, courses and students will appear here automatically.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Today's Classes -->
            <?php if (count($today_classes) > 0): ?>
            <div class="glass-card feature-shell feature-orange p-4 sm:p-6 mb-4 sm:mb-6" data-aos="fade-up" data-aos-delay="100">
                <div class="section-kicker"><i class="fas fa-calendar-day"></i> Live Schedule</div>
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-6 gap-2">
                    <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2">
                        <i class="fas fa-calendar-day text-orange-500"></i>
                        Today's Classes
                    </h3>
                    <span class="px-3 py-1 bg-orange-100 text-orange-800 rounded-full text-xs sm:text-sm font-semibold">
                        <?php echo count($today_classes); ?> classes
                    </span>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
                    <?php foreach ($today_classes as $index => $class): 
                        $is_current = ($current_class && $current_class['id'] == $class['id']);
                        $is_past = (date('H:i:s') > $class['end_time']);
                        $is_upcoming = (date('H:i:s') < $class['start_time']);
                    ?>
                    <div class="class-card feature-item p-3 sm:p-4 bg-white rounded-xl border-l-4 <?php echo $is_current ? 'border-red-500' : ($is_past ? 'border-gray-400' : 'border-green-500'); ?> hover-lift">
                        <?php if ($is_current): ?>
                        <div class="live-badge" style="top: 8px; right: 8px;">LIVE</div>
                        <?php endif; ?>
                        
                        <div class="flex justify-between items-start mb-2 sm:mb-3">
                            <div class="flex-1">
                                <h4 class="font-bold text-gray-800 text-sm sm:text-base"><?php echo htmlspecialchars($class['batch_name']); ?></h4>
                                <p class="text-xs text-gray-600 mt-1 truncate"><?php echo htmlspecialchars($class['topic']); ?></p>
                            </div>
                            <span class="px-2 py-1 text-xs rounded-full ml-2
                                <?php echo $is_current ? 'bg-red-100 text-red-800' : 
                                       ($is_past ? 'bg-gray-100 text-gray-800' : 
                                       'bg-green-100 text-green-800'); ?>">
                                <?php echo $is_current ? 'Now' : 
                                       ($is_past ? 'Done' : 'Up'); ?>
                            </span>
                        </div>
                        
                        <div class="flex flex-wrap items-center text-xs text-gray-600 gap-2 mb-2 sm:mb-3">
                            <span class="flex items-center">
                                <i class="fas fa-clock mr-1"></i>
                                <?php echo date('g:i A', strtotime($class['start_time'])); ?>
                            </span>
                            <span class="flex items-center">
                                <i class="fas fa-video mr-1"></i>
                                <?php echo htmlspecialchars($class['platform'] ?? 'Online'); ?>
                            </span>
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <?php if ($class['meeting_link']): ?>
                            <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" 
                               target="_blank" 
                               class="px-2 sm:px-3 py-1.5 sm:py-2 bg-gradient-to-r from-blue-500 to-indigo-500 text-white rounded-lg text-xs sm:text-sm font-medium hover:opacity-90 transition-opacity flex items-center <?php echo $is_past ? 'opacity-50 cursor-not-allowed' : ''; ?>"
                               <?php echo $is_past ? 'disabled' : ''; ?>>
                                <i class="fas fa-external-link-alt mr-1"></i>
                                <?php echo $is_current ? 'Join Now' : ($is_past ? 'Ended' : 'Join'); ?>
                            </a>
                            <?php else: ?>
                            <span class="px-3 py-2 bg-gray-100 text-gray-500 rounded-lg text-xs font-medium">
                                <i class="fas fa-link-slash mr-1"></i> No Link
                            </span>
                            <?php endif; ?>
                            
                            <?php if (!$is_past && $class['meeting_link']): ?>
                            <button onclick="copyMeetingLink('<?php echo htmlspecialchars($class['meeting_link']); ?>')" 
                                    class="w-8 h-8 sm:w-9 sm:h-9 flex items-center justify-center bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                <i class="fas fa-copy text-xs"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-6 mb-4 sm:mb-6">
                <!-- Recent Students -->
                <div class="glass-card feature-shell feature-violet p-4 sm:p-6" data-aos="fade-up" data-aos-delay="150">
                    <div class="section-kicker"><i class="fas fa-user-graduate"></i> Learner Spotlight</div>
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-6 gap-2">
                        <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-user-graduate text-purple-500"></i>
                            Recent Students
                        </h3>
                        <a href="../students/students.php" class="text-xs sm:text-sm text-purple-600 hover:text-purple-800 font-medium transition-colors flex items-center gap-1">
                            View All <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    
                    <?php if (count($recent_students) > 0): ?>
                        <div class="space-y-2 sm:space-y-3">
                            <?php foreach ($recent_students as $index => $student): ?>
                                <div class="table-row-hover feature-item p-3 sm:p-4 bg-gray-50 rounded-xl flex items-center justify-between hover-lift animation-delay-<?php echo ($index + 1) * 100; ?>">
                                    <div class="flex items-center gap-3 flex-1 min-w-0">
                                        <?php 
                                        if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): 
                                        ?>
                                            <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                 alt="<?php echo htmlspecialchars($student['first_name']); ?>" 
                                                 class="h-9 w-9 sm:h-10 sm:w-10 rounded-lg object-cover">
                                        <?php else: 
                                            $avatarClass = 'avatar-gradient';
                                            $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                                            switch(rand(1,5)) {
                                                case 1: $avatarClass = 'avatar-gradient'; break;
                                                case 2: $avatarClass = 'avatar-gradient-2'; break;
                                                case 3: $avatarClass = 'avatar-gradient-3'; break;
                                                case 4: $avatarClass = 'avatar-gradient-4'; break;
                                                case 5: $avatarClass = 'avatar-gradient-5'; break;
                                            }
                                        ?>
                                            <div class="student-avatar <?php echo $avatarClass; ?>">
                                                <?php echo $initials; ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex-1 min-w-0">
                                            <h4 class="font-semibold text-gray-800 text-sm sm:text-base truncate"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h4>
                                            <p class="text-xs text-gray-600 truncate"><?php echo htmlspecialchars($student['course_name'] ?? 'No Course'); ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right ml-2">
                                        <span class="text-xs text-gray-500 block">Enrolled</span>
                                        <span class="text-xs sm:text-sm font-medium text-gray-700"><?php echo date('M j', strtotime($student['enrolled_date'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-6 sm:py-8">
                            <i class="fas fa-user-graduate text-3xl sm:text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500 text-sm sm:text-base">No students found</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Upcoming Classes - FIXED: Now only shows trainer's assigned batches -->
                <div class="glass-card feature-shell feature-emerald p-4 sm:p-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="section-kicker"><i class="fas fa-calendar-alt"></i> Upcoming Planner</div>
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-6 gap-2">
                        <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-calendar-alt text-green-500"></i>
                            Upcoming Classes
                        </h3>
                        <a href="../schedule/schedule.php" class="text-xs sm:text-sm text-green-600 hover:text-green-800 font-medium transition-colors flex items-center gap-1">
                            View All <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                    
                    <?php if (count($upcoming_classes) > 0): ?>
                        <div class="space-y-2 sm:space-y-3">
                            <?php foreach ($upcoming_classes as $index => $class): ?>
                                <div class="class-card feature-item p-3 sm:p-4 bg-white rounded-xl hover-lift animation-delay-<?php echo ($index + 1) * 100; ?>">
                                    <div class="flex justify-between items-start gap-2">
                                        <div class="flex-1 min-w-0">
                                            <h4 class="font-semibold text-gray-800 text-sm sm:text-base truncate"><?php echo htmlspecialchars($class['batch_name']); ?></h4>
                                            <p class="text-xs text-gray-600 mt-1 truncate"><?php echo htmlspecialchars($class['topic']); ?></p>
                                            <div class="flex flex-wrap items-center mt-2 gap-2 sm:gap-3">
                                                <span class="text-xs text-gray-500 flex items-center gap-1">
                                                    <i class="fas fa-calendar-day"></i>
                                                    <?php echo date('M j, Y', strtotime($class['schedule_date'])); ?>
                                                </span>
                                                <span class="text-xs text-gray-500 flex items-center gap-1">
                                                    <i class="fas fa-clock"></i>
                                                    <?php echo date('g:i A', strtotime($class['start_time'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="px-2 sm:px-3 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold">
                                                <?php echo date('D', strtotime($class['schedule_date'])); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-6 sm:py-8">
                            <i class="fas fa-calendar-times text-3xl sm:text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500 text-sm sm:text-base">No upcoming classes</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>


            <!-- Footer -->
            <footer class="mt-8 px-6 py-4 border-t border-gray-100 text-center text-sm text-gray-500">
                <p>© <?php echo date('Y'); ?> ASD Academy. All rights reserved.</p>
            </footer>
        </div>
    </div>

    <!-- Mobile Navigation Scripts -->
    <script>
        // Function to toggle mobile menu
        function toggleMobileMenu() {
            const mobileMenu = document.getElementById('mobileMenu');
            const mobileMenuContent = mobileMenu.querySelector('div');
            
            if (mobileMenu.classList.contains('hidden')) {
                mobileMenu.classList.remove('hidden');
                setTimeout(() => {
                    mobileMenuContent.classList.remove('-translate-x-full');
                }, 10);
            } else {
                mobileMenuContent.classList.add('-translate-x-full');
                setTimeout(() => {
                    mobileMenu.classList.add('hidden');
                }, 300);
            }
        }

        // Close mobile menu when clicking outside
        document.getElementById('mobileMenu').addEventListener('click', function(e) {
            if (e.target.id === 'mobileMenu') {
                toggleMobileMenu();
            }
        });

        // Add staggered animations for table rows
        document.addEventListener('DOMContentLoaded', function() {
            const rows = document.querySelectorAll('tbody tr');
            rows.forEach((row, index) => {
                row.style.animationDelay = `${index * 0.05}s`;
            });
            
            // Animate cards on page load
            const cards = document.querySelectorAll('.glass-card');
            cards.forEach((card, index) => {
                card.classList.add('animate-fade-in');
            });
            
            // Animate progress bars
            const progressBars = document.querySelectorAll('.progress-bar-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
            
            // Show welcome toast
            setTimeout(() => {
                showToast('Trainer dashboard refreshed successfully.', 'success');
            }, 1000);
            
            // Add ripple effect to interactive elements
            document.querySelectorAll('button, .ripple, .quick-action-btn').forEach(element => {
                element.addEventListener('click', function(e) {
                    if (this.disabled || this.classList.contains('disabled')) return;
                    
                    const rect = this.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    
                    const ripple = document.createElement('span');
                    ripple.classList.add('ripple-effect');
                    ripple.style.left = x + 'px';
                    ripple.style.top = y + 'px';
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => ripple.remove(), 600);
                });
            });
        });

        // Toast notification function
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 px-4 py-3 rounded-lg shadow-xl text-white font-semibold z-50 transform translate-x-full opacity-0 transition-all duration-300 ${type === 'success' ? 'bg-gradient-to-r from-green-500 to-emerald-500' : 'bg-gradient-to-r from-blue-500 to-cyan-500'}`;
            toast.textContent = message;
            toast.style.backdropFilter = 'blur(10px)';
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
                toast.classList.add('translate-x-0', 'opacity-100');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Copy meeting link to clipboard
        function copyMeetingLink(link) {
            navigator.clipboard.writeText(link).then(() => {
                showToast('Meeting link copied!', 'success');
            }).catch(err => {
                console.error('Failed to copy: ', err);
                showToast('Failed to copy link', 'error');
            });
        }

        // Handle ESC key to close mobile menu
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const mobileMenu = document.getElementById('mobileMenu');
                if (!mobileMenu.classList.contains('hidden')) {
                    toggleMobileMenu();
                }
            }
        });

        // Add active state to current page link in mobile menu
        document.addEventListener('DOMContentLoaded', function() {
            const currentPage = '<?= basename($_SERVER['PHP_SELF']) ?>';
            const mobileLinks = document.querySelectorAll('.mobile-nav-link');
            
            mobileLinks.forEach(link => {
                const href = link.getAttribute('href');
                if (href && href.includes(currentPage)) {
                    link.classList.add('bg-white', 'shadow-md');
                    const icon = link.querySelector('i');
                    if (icon) {
                        if (currentPage === 'dashboard.php') {
                            link.classList.add('text-blue-600');
                            icon.classList.add('text-blue-600');
                        } else if (currentPage === 'batches.php') {
                            link.classList.add('text-green-600');
                            icon.classList.add('text-green-600');
                        } else if (currentPage === 'students.php') {
                            link.classList.add('text-purple-600');
                            icon.classList.add('text-purple-600');
                        } else if (currentPage === 'schedule.php') {
                            link.classList.add('text-yellow-600');
                            icon.classList.add('text-yellow-600');
                        } else if (currentPage === 'trainer_attendance.php') {
                            link.classList.add('text-red-600');
                            icon.classList.add('text-red-600');
                        } else if (currentPage === 'weekly_feedback.php') {
                            link.classList.add('text-indigo-600');
                            icon.classList.add('text-indigo-600');
                        } else if (currentPage === 'trainer_dashboard.php') {
                            link.classList.add('text-amber-600');
                            icon.classList.add('text-amber-600');
                        } else if (currentPage === 'trainer_content.php') {
                            link.classList.add('text-cyan-600');
                            icon.classList.add('text-cyan-600');
                        } else if (currentPage === 'profile.php') {
                            link.classList.add('text-pink-600');
                            icon.classList.add('text-pink-600');
                        }
                    }
                }
            });
        });

        // Add a subtle confetti effect on page load
        function createConfetti() {
            const colors = ['#1B3C53', '#234C6A', '#f093fb', '#f5576c', '#43e97b', '#38f9d7'];
            
            for (let i = 0; i < 30; i++) {
                const confetti = document.createElement('div');
                confetti.style.position = 'fixed';
                confetti.style.width = Math.random() * 8 + 4 + 'px';
                confetti.style.height = Math.random() * 8 + 4 + 'px';
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.borderRadius = Math.random() > 0.5 ? '50%' : '0';
                confetti.style.top = '-10px';
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.opacity = '0.7';
                confetti.style.zIndex = '9998';
                confetti.style.pointerEvents = 'none';
                
                document.body.appendChild(confetti);
                
                const animation = confetti.animate([
                    { 
                        transform: `translateY(0) rotate(0deg)`,
                        opacity: 0.7
                    },
                    { 
                        transform: `translateY(${window.innerHeight}px) rotate(${Math.random() * 360}deg)`,
                        opacity: 0
                    }
                ], {
                    duration: Math.random() * 2000 + 1500,
                    easing: 'cubic-bezier(0.215, 0.61, 0.355, 1)'
                });
                
                animation.onfinish = () => confetti.remove();
            }
        }
        
        setTimeout(() => {
            createConfetti();
        }, 500);
    </script>
</body>
</html>