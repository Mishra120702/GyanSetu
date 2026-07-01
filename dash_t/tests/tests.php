<?php
// tests.php - Trainer Test Results Dashboard
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

// Check if user is logged in as trainer
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'mentor') {
    header("Location: ../logout.php");
    exit;
}

require_once '../db_connection.php';

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

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

    // Get all batches assigned to this trainer (for filter dropdown)
    $batches_stmt = $db->prepare("
        SELECT b.batch_id, b.batch_name, b.status
        FROM batches b 
        WHERE b.batch_mentor_id = ? 
        ORDER BY 
            CASE b.status 
                WHEN 'ongoing' THEN 1
                WHEN 'upcoming' THEN 2
                WHEN 'completed' THEN 3
                ELSE 4
            END,
            b.created_at DESC
    ");
    $batches_stmt->execute([$trainer['id']]);
    $all_batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get selected batch from filter (default to 'all')
    $selected_batch = isset($_GET['batch']) ? $_GET['batch'] : 'all';
    $search_term = isset($_GET['search']) ? $_GET['search'] : '';
    
    // Get all tests for trainer's batches with statistics
    $tests_query = "
        SELECT 
            t.*,
            b.batch_name,
            b.batch_id as batch_code,
            COUNT(DISTINCT tq.id) as question_count,
            COUNT(DISTINCT ta.id) as total_attempts,
            COUNT(DISTINCT CASE WHEN ta.status = 'submitted' THEN ta.student_id END) as completed_attempts,
            ROUND(AVG(CASE WHEN ta.status = 'submitted' THEN ta.percentage END), 2) as avg_score,
            MAX(CASE WHEN ta.status = 'submitted' THEN ta.percentage END) as highest_score,
            MIN(CASE WHEN ta.status = 'submitted' THEN ta.percentage END) as lowest_score,
            COUNT(DISTINCT CASE WHEN ta.status = 'submitted' AND ta.percentage >= t.passing_marks THEN ta.student_id END) as passed_count,
            COUNT(DISTINCT CASE WHEN ta.status = 'submitted' AND ta.percentage < t.passing_marks THEN ta.student_id END) as failed_count,
            (SELECT COUNT(DISTINCT student_id) FROM test_attempts WHERE test_id = t.id AND status = 'submitted') as unique_students_completed
        FROM tests t
        JOIN batches b ON t.batch_id = b.batch_id
        LEFT JOIN test_questions tq ON t.id = tq.test_id
        LEFT JOIN test_attempts ta ON t.id = ta.test_id
        WHERE b.batch_mentor_id = :trainer_id
    ";
    
    $params = [':trainer_id' => $trainer['id']];
    
    if ($selected_batch !== 'all') {
        $tests_query .= " AND t.batch_id = :batch_id";
        $params[':batch_id'] = $selected_batch;
    }
    
    if (!empty($search_term)) {
        $tests_query .= " AND (t.title LIKE :search OR t.subject LIKE :search OR t.description LIKE :search)";
        $params[':search'] = "%$search_term%";
    }
    
    $tests_query .= " GROUP BY t.id ORDER BY 
        CASE 
            WHEN t.is_active = 1 AND NOW() BETWEEN t.start_date AND t.end_date THEN 1
            WHEN t.is_active = 1 AND NOW() < t.start_date THEN 2
            WHEN t.is_active = 1 AND NOW() > t.end_date THEN 3
            ELSE 4
        END,
        t.created_at DESC";
    
    $tests_stmt = $db->prepare($tests_query);
    $tests_stmt->execute($params);
    $tests = $tests_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get overall statistics
    $stats_query = "
        SELECT 
            COUNT(DISTINCT t.id) as total_tests,
            COUNT(DISTINCT CASE WHEN t.is_active = 1 AND NOW() BETWEEN t.start_date AND t.end_date THEN t.id END) as active_tests,
            COUNT(DISTINCT CASE WHEN t.is_active = 1 AND NOW() < t.start_date THEN t.id END) as upcoming_tests,
            COUNT(DISTINCT CASE WHEN t.is_active = 1 AND NOW() > t.end_date THEN t.id END) as past_tests,
            COUNT(DISTINCT ta.id) as total_attempts,
            COUNT(DISTINCT CASE WHEN ta.status = 'submitted' THEN ta.student_id END) as unique_students_attempted,
            ROUND(AVG(CASE WHEN ta.status = 'submitted' THEN ta.percentage END), 2) as overall_avg_score,
            SUM(CASE WHEN ta.status = 'submitted' AND ta.percentage >= t.passing_marks THEN 1 ELSE 0 END) as total_passed,
            COUNT(CASE WHEN ta.status = 'submitted' THEN 1 END) as total_completed_attempts
        FROM tests t
        JOIN batches b ON t.batch_id = b.batch_id
        LEFT JOIN test_attempts ta ON t.id = ta.test_id
        WHERE b.batch_mentor_id = :trainer_id
    ";
    
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute([':trainer_id' => $trainer['id']]);
    $overall_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent test attempts with student details
    $recent_attempts_query = "
        SELECT 
            ta.*,
            t.title as test_title,
            t.total_marks,
            t.passing_marks,
            b.batch_name,
            s.first_name,
            s.last_name,
            s.student_id,
            s.profile_picture,
            CASE 
                WHEN ta.percentage >= t.passing_marks THEN 'Passed'
                ELSE 'Failed'
            END as result_status,
            CASE 
                WHEN ta.percentage >= 90 THEN 'Outstanding'
                WHEN ta.percentage >= 75 THEN 'Excellent'
                WHEN ta.percentage >= 60 THEN 'Good'
                WHEN ta.percentage >= t.passing_marks THEN 'Satisfactory'
                ELSE 'Needs Improvement'
            END as performance_level,
            RANK() OVER (PARTITION BY t.id ORDER BY ta.percentage DESC) as test_rank
        FROM test_attempts ta
        JOIN tests t ON ta.test_id = t.id
        JOIN batches b ON t.batch_id = b.batch_id
        JOIN students s ON ta.student_id = s.student_id
        WHERE b.batch_mentor_id = :trainer_id
        AND ta.status = 'submitted'
        ORDER BY ta.submitted_at DESC
        LIMIT 10
    ";
    
    $recent_stmt = $db->prepare($recent_attempts_query);
    $recent_stmt->execute([':trainer_id' => $trainer['id']]);
    $recent_attempts = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get top performers
    $top_performers_query = "
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            s.profile_picture,
            MAX(b.batch_name) as batch_name,
            COUNT(DISTINCT ta.test_id) as tests_taken,
            ROUND(AVG(ta.percentage), 2) as avg_score,
            SUM(CASE WHEN ta.percentage >= t.passing_marks THEN 1 ELSE 0 END) as tests_passed,
            MAX(ta.percentage) as highest_score
        FROM test_attempts ta
        JOIN tests t ON ta.test_id = t.id
        JOIN batches b ON t.batch_id = b.batch_id
        JOIN students s ON ta.student_id = s.student_id
        WHERE b.batch_mentor_id = :trainer_id
        AND ta.status = 'submitted'
        GROUP BY s.student_id, s.first_name, s.last_name, s.profile_picture
        HAVING COUNT(DISTINCT ta.test_id) >= 1
        ORDER BY avg_score DESC
        LIMIT 5
    ";
    
    $top_performers_stmt = $db->prepare($top_performers_query);
    $top_performers_stmt->execute([':trainer_id' => $trainer['id']]);
    $top_performers = $top_performers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate pass rate percentage
    $pass_rate = 0;
    if ($overall_stats['total_completed_attempts'] > 0) {
        $pass_rate = round(($overall_stats['total_passed'] / $overall_stats['total_completed_attempts']) * 100, 2);
    }
    
    // Get performance by test type
    $test_type_stats_query = "
        SELECT 
            CASE 
                WHEN NOW() BETWEEN t.start_date AND t.end_date THEN 'Active'
                WHEN NOW() < t.start_date THEN 'Upcoming'
                ELSE 'Completed'
            END as test_status_group,
            COUNT(DISTINCT t.id) as test_count,
            COUNT(DISTINCT ta.id) as attempt_count,
            ROUND(AVG(CASE WHEN ta.status = 'submitted' THEN ta.percentage END), 2) as avg_score
        FROM tests t
        JOIN batches b ON t.batch_id = b.batch_id
        LEFT JOIN test_attempts ta ON t.id = ta.test_id AND ta.status = 'submitted'
        WHERE b.batch_mentor_id = :trainer_id
        GROUP BY test_status_group
    ";
    
    $test_type_stmt = $db->prepare($test_type_stats_query);
    $test_type_stmt->execute([':trainer_id' => $trainer['id']]);
    $test_type_stats = $test_type_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Tests & Exams - Trainer Dashboard | ASD Academy</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 35%, #F6F1ED 70%, #f0f9ff 100%);
            background-size: 200% 200%;
            animation: gradientShift 18s ease infinite;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100"><rect width="100" height="100" fill="none"/><path d="M0,50 Q25,40 50,50 T100,50" stroke="%231B3C53" stroke-width="0.5" fill="none" opacity="0.1"/><path d="M0,30 Q25,20 50,30 T100,30" stroke="%23234C6A" stroke-width="0.5" fill="none" opacity="0.1"/></svg>');
            pointer-events: none;
            z-index: -1;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 24px;
            box-shadow: 0 4px 20px rgba(27,60,83, 0.07);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .glass-card:hover {
            box-shadow: 0 12px 36px rgba(27,60,83, 0.15);
            transform: translateY(-2px);
        }
        
        .gradient-text {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 50%, #456882 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: 700;
        }
        
        .hero-banner {
            background: linear-gradient(120deg, #1B3C53 0%, #234C6A 45%, #456882 100%);
            background-size: 200% 200%;
            animation: gradientShift 10s ease infinite;
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            box-shadow: 0 16px 40px rgba(27, 60, 83, 0.25);
        }
        
        .hero-banner::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(255,255,255,0.18), transparent 40%),
                        radial-gradient(circle at 85% 80%, rgba(255,255,255,0.12), transparent 45%);
            pointer-events: none;
        }
        
        .glow-chip {
            backdrop-filter: blur(6px);
            background: rgba(255, 255, 255, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        .stat-tile {
            position: relative;
            border-radius: 16px;
            padding: 1px;
            overflow: hidden;
        }
        
        .stat-tile-inner {
            background: rgba(255, 255, 255, 0.94);
            border-radius: 15px;
            height: 100%;
        }
        
        .stat-tile-blue { background: linear-gradient(135deg, #93c5fd, #234C6A, #1B3C53); }
        .stat-tile-green { background: linear-gradient(135deg, #6ee7b7, #10b981, #059669); }
        .stat-tile-purple { background: linear-gradient(135deg, #d8b4fe, #234C6A, #234C6A); }
        .stat-tile-amber { background: linear-gradient(135deg, #fde68a, #f59e0b, #ea580c); }
        
        .icon-orb {
            box-shadow: 0 6px 16px -2px rgba(0,0,0,0.18), inset 0 1px 1px rgba(255,255,255,0.4);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        
        .stat-tile:hover .icon-orb {
            transform: scale(1.12) rotate(-6deg);
        }
        
        .test-card {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(27,60,83, 0.08);
        }
        
        .test-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.35), transparent);
            transition: left 0.7s ease;
            z-index: 1;
        }
        
        .test-card:hover::before {
            left: 100%;
        }
        
        .test-card::after {
            content: '';
            position: absolute;
            top: -2px; left: -2px; right: -2px; bottom: -2px;
            border-radius: 26px;
            background: linear-gradient(135deg, #1B3C53, #456882, #f59e0b);
            opacity: 0;
            transition: opacity 0.4s ease;
            z-index: -1;
        }
        
        .test-card:hover::after {
            opacity: 0.5;
        }
        
        .test-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 24px 48px rgba(27, 60, 83, 0.18);
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
        }
        
        .status-active {
            background: linear-gradient(135deg, #34d399, #059669);
            color: white;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35);
        }
        
        .status-upcoming {
            background: linear-gradient(135deg, #60a5fa, #234C6A);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.35);
        }
        
        .status-completed {
            background: linear-gradient(135deg, #fb923c, #ea580c);
            color: white;
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.35);
        }
        
        .status-inactive {
            background: linear-gradient(135deg, #f87171, #dc2626);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
        }
        
        .progress-ring {
            transition: stroke-dashoffset 1s ease;
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }
        
        .rank-1 {
            background: linear-gradient(135deg, #fde047, #fbbf24, #f59e0b);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        }
        
        .rank-2 {
            background: linear-gradient(135deg, #d1d5db, #9ca3af, #6b7280);
            box-shadow: 0 4px 12px rgba(107, 114, 128, 0.3);
        }
        
        .rank-3 {
            background: linear-gradient(135deg, #d97706, #92400e, #b45309);
            box-shadow: 0 4px 12px rgba(146, 64, 14, 0.35);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
        }
        
        .floating-element {
            animation: float 6s ease-in-out infinite;
        }
        
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .search-bar {
            transition: all 0.3s ease;
            background: white;
        }
        
        .search-bar:focus-within {
            box-shadow: 0 10px 25px rgba(35,76,106, 0.2);
            transform: translateY(-2px);
            border-color: #456882 !important;
        }
        
        .table-row-hover {
            transition: all 0.25s ease;
            border: 1px solid transparent;
        }
        
        .table-row-hover:hover {
            background: linear-gradient(135deg, rgba(27,60,83, 0.08), rgba(69, 104, 130, 0.08));
            border-color: rgba(35,76,106, 0.15);
            transform: translateX(6px);
            box-shadow: 0 6px 16px rgba(27,60,83, 0.1);
        }
        
        .badge-pass {
            background: linear-gradient(135deg, #10b981, #34d399);
            color: white;
            box-shadow: 0 3px 10px rgba(16, 185, 129, 0.3);
        }
        
        .badge-fail {
            background: linear-gradient(135deg, #ef4444, #f87171);
            color: white;
            box-shadow: 0 3px 10px rgba(239, 68, 68, 0.3);
        }
        
        .performance-outstanding { background: linear-gradient(135deg, #234C6A, #456882); }
        .performance-excellent { background: linear-gradient(135deg, #234C6A, #60a5fa); }
        .performance-good { background: linear-gradient(135deg, #10b981, #34d399); }
        .performance-satisfactory { background: linear-gradient(135deg, #f59e0b, #fbbf24); }
        .performance-needs-improvement { background: linear-gradient(135deg, #ef4444, #f87171); }
        
        .student-avatar {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            color: white;
        }
        
        .scrollbar-thin::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            border-radius: 10px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #1B3C53, #234C6A);
        }
        
        .card-enter {
            animation: cardEnter 0.6s cubic-bezier(0.4, 0, 0.2, 1) forwards;
            opacity: 0;
            transform: translateY(20px);
        }
        
        @keyframes cardEnter {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .hover-lift {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
        }
        
        .ripple-effect {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        
        @keyframes ripple {
            to { transform: scale(4); opacity: 0; }
        }
        
        .confetti {
            position: fixed;
            width: 10px;
            height: 10px;
            background: linear-gradient(135deg, #1B3C53, #234C6A);
            opacity: 0.7;
            pointer-events: none;
            z-index: 9999;
        }
    
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
.text-[#234C6A],.text-[#234C6A],.text-[#1B3C53],.text-[#1B3C53],.text-[#234C6A],.text-[#234C6A],.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-[#D2C1B6]/50,.border-[#D2C1B6]/50,.border-blue-200 {
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

    
        /* Tests page official palette fix */
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
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

        .test-page-header,
        .exam-top-bar,
        .page-hero,
        .dashboard-hero,
        .tests-hero,
        header.bg-gradient-to-r,
        .bg-gradient-to-r.from-purple-500.via-purple-600.to-pink-500,
        .bg-gradient-to-r.from-[#1B3C53].via-[#234C6A].to-[#456882],
        [class*="from-purple"][class*="to-pink"] {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
        }

        .glass-card,
        .stat-card,
        .filter-card,
        .analytics-card {
            border-color: rgba(210,193,182,.38) !important;
        }

        .btn-primary,
        .view-toggle.active,
        .tab-button.active,
        button[type="submit"],
        .apply-filter-btn {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            border-color: transparent !important;
        }

        .text-[#234C6A],
        .text-[#234C6A],
        .text-indigo-500,
        .text-[#1B3C53],
        .text-blue-500,
        .text-blue-600 {
            color: #234C6A !important;
        }

        .bg-purple-50,
        .bg-indigo-50,
        .bg-blue-50 {
            background-color: rgba(210,193,182,.22) !important;
        }

        .shadow-purple,
        .shadow-indigo {
            box-shadow: 0 14px 30px rgba(27,60,83,.18) !important;
        }

    
        /* FINAL official palette lock for Tests page */
        .official-gradient {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            background-image: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
        }

        .hero-banner {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            background-image: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            box-shadow: 0 16px 40px rgba(27, 60, 83, 0.25) !important;
        }

        header.official-gradient {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
            background-image: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
        }

        .stat-tile-blue,
        .stat-tile-purple {
            background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
        }

        .test-card::after {
            background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
        }

        .search-bar:focus-within {
            border-color: #456882 !important;
            box-shadow: 0 10px 25px rgba(35,76,106, 0.2) !important;
        }

        .table-row-hover:hover {
            background: linear-gradient(135deg, rgba(27,60,83, 0.08), rgba(69,104,130, 0.08)) !important;
            border-color: rgba(35,76,106, 0.15) !important;
        }

        .performance-outstanding,
        .performance-excellent {
            background: linear-gradient(135deg, #1B3C53, #456882) !important;
        }

        .floating-element.bg-[#456882\/30],
        .floating-element.bg-[#D2C1B6\/40],
        .floating-element.bg-blue-300 {
            background-color: rgba(210, 193, 182, 0.35) !important;
        }

        .glow-chip {
            background: rgba(255, 255, 255, 0.16) !important;
            border: 1px solid rgba(210,193,182,.45) !important;
        }

        .shadow-lg,
        .shadow-xl,
        .shadow-md {
            --tw-shadow-color: rgba(27,60,83,.18);
        }

    </style>
<style>

/* ===== SAFE FIX v3: compact hero like previous approved pages ===== */
/* CSS-only. PHP, filters, DB queries, result cards, links and JS untouched. */

/* Stop entrance animation from making layout jump/blank */
.card-enter {
    opacity: 1 !important;
    transform: none !important;
    animation: none !important;
}

/* Page background same company theme */
body,
.p-4.lg\:p-8.bg-gray-50,
.bg-gray-50.min-h-screen {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.08), transparent 28%),
        radial-gradient(circle at 90% 10%, rgba(69,104,130,.09), transparent 32%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 48%, #FBFAF8 100%) !important;
}

/* Keep sidebar layout safe */
@media (min-width: 1024px) {
    .flex-1.ml-0.lg\:ml-64 {
        margin-left: 16rem !important;
    }
}

/* Header visible */
header.official-gradient,
header.hidden.lg\:flex,
header.lg\:hidden {
    background:
        radial-gradient(circle at 96% 0%, rgba(255,255,255,.16), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 50%, #456882 100%) !important;
    border-bottom: 1px solid rgba(255,255,255,.18) !important;
    box-shadow: 0 14px 32px rgba(27,60,83,.18) !important;
}

header.official-gradient *,
header.hidden.lg\:flex *,
header.lg\:hidden * {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

/* Main max width: like old approved trainer pages, not a tiny floating island */
.max-w-7xl {
    max-width: 100% !important;
}

/* Compact hero: full width + normal height */
.hero-banner {
    width: 100% !important;
    max-width: 100% !important;
    min-height: 0 !important;
    height: auto !important;
    padding: 24px 28px !important;
    margin: 0 0 24px 0 !important;
    border-radius: 24px !important;
    background:
        radial-gradient(circle at 92% 20%, rgba(255,255,255,.18), transparent 26%),
        radial-gradient(circle at 70% 110%, rgba(210,193,182,.18), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 50%, #456882 100%) !important;
    border: 1.5px solid rgba(255,255,255,.24) !important;
    box-shadow:
        0 20px 50px rgba(27,60,83,.20),
        inset 0 1px 0 rgba(255,255,255,.18) !important;
    overflow: hidden !important;
}

/* Reduce giant decorative bubbles */
.hero-banner > .absolute {
    opacity: .10 !important;
    filter: blur(1px) !important;
    transform: scale(.70) !important;
}

/* Hero text same line feel */
.hero-banner .relative.flex.flex-col.lg\:flex-row {
    align-items: center !important;
}

.hero-banner h2 {
    font-size: 1.75rem !important;
    line-height: 1.15 !important;
    margin-bottom: 6px !important;
}

.hero-banner p {
    font-size: .95rem !important;
    line-height: 1.45 !important;
}

.hero-banner h2,
.hero-banner p,
.hero-banner span,
.hero-banner i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

/* Top right chips clean */
.hero-banner .pulse,
.glow-chip {
    background: rgba(255,255,255,.16) !important;
    border: 1.25px solid rgba(255,255,255,.34) !important;
    color: #ffffff !important;
    box-shadow: 0 10px 22px rgba(15,23,42,.12) !important;
    backdrop-filter: blur(10px) !important;
}

/* Stats inside hero: compact row, not oversized */
.hero-banner .relative.grid.grid-cols-2.md\:grid-cols-4 {
    margin-top: 18px !important;
    gap: 14px !important;
}

.hero-banner .stat-tile {
    border-radius: 17px !important;
    padding: 0 !important;
    border: 1.3px solid rgba(255,255,255,.34) !important;
    box-shadow:
        0 14px 28px rgba(15,23,42,.14),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    overflow: hidden !important;
}

.hero-banner .stat-tile-inner {
    padding: 13px 16px !important;
    min-height: 70px !important;
    background:
        radial-gradient(circle at 92% 8%, rgba(255,255,255,.20), transparent 32%),
        rgba(255,255,255,.08) !important;
    border-radius: 17px !important;
}

.hero-banner .stat-tile-blue {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 55%, #60a5fa 100%) !important;
}

.hero-banner .stat-tile-green {
    background: linear-gradient(135deg, #047857 0%, #059669 55%, #10b981 100%) !important;
}

.hero-banner .stat-tile-purple {
    background: linear-gradient(135deg, #6d28d9 0%, #7c3aed 55%, #a78bfa 100%) !important;
}

.hero-banner .stat-tile-amber {
    background: linear-gradient(135deg, #b45309 0%, #d97706 55%, #f59e0b 100%) !important;
}

.hero-banner .stat-tile .text-2xl {
    font-size: 1.45rem !important;
    line-height: 1.1 !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.hero-banner .stat-tile .text-xs,
.hero-banner .stat-tile .text-gray-500,
.hero-banner .stat-tile .text-gray-800 {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.hero-banner .icon-orb {
    width: 42px !important;
    height: 42px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.42), transparent 34%),
        rgba(255,255,255,.20) !important;
    border: 1.3px solid rgba(255,255,255,.48) !important;
    box-shadow:
        0 10px 20px rgba(0,0,0,.16),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

.hero-banner .stat-tile:hover {
    transform: translateY(-3px) !important;
    filter: brightness(1.05) !important;
}

/* Search bar block tighter and same width */
.glass-card:has(form) {
    width: 100% !important;
    margin-bottom: 24px !important;
    padding: 18px !important;
}

/* Keep all other cards theme-safe */
.glass-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.45px solid rgba(210,193,182,.64) !important;
    border-radius: 22px !important;
    box-shadow:
        0 18px 42px rgba(27,60,83,.10),
        inset 0 1px 0 rgba(255,255,255,.84) !important;
}

/* Inputs readable */
.search-bar,
select {
    background: rgba(255,255,255,.96) !important;
    border: 1.35px solid rgba(69,104,130,.28) !important;
    color: #102A3A !important;
    -webkit-text-fill-color: #102A3A !important;
    font-weight: 750 !important;
}

.search-bar input {
    color: #102A3A !important;
    -webkit-text-fill-color: #102A3A !important;
    font-weight: 750 !important;
}

.search-bar input::placeholder {
    color: #456882 !important;
    -webkit-text-fill-color: #456882 !important;
    opacity: .75 !important;
}

button[type="submit"],
.official-gradient,
a.official-gradient,
span.official-gradient {
    background:
        radial-gradient(circle at 90% 10%, rgba(255,255,255,.15), transparent 35%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border: 1.2px solid rgba(255,255,255,.26) !important;
    box-shadow: 0 12px 24px rgba(27,60,83,.18) !important;
}

/* Hide demo confetti. Company code does not need birthday party debris. */
.confetti {
    display: none !important;
}

/* Mobile */
@media (max-width: 768px) {
    .hero-banner {
        padding: 20px !important;
        border-radius: 20px !important;
    }

    .hero-banner .relative.flex.flex-col.lg\:flex-row {
        align-items: flex-start !important;
    }

    .hero-banner h2 {
        font-size: 1.45rem !important;
    }

    .hero-banner .stat-tile-inner {
        min-height: 66px !important;
        padding: 12px !important;
    }
}

</style>
</head>
<body class="relative overflow-x-hidden">
    <!-- Floating Background Elements -->
    <div class="fixed top-20 left-10 w-64 h-64 bg-[#456882]/30 rounded-full mix-blend-multiply filter blur-3xl opacity-20 floating-element"></div>
    <div class="fixed bottom-20 right-10 w-80 h-80 bg-blue-300 rounded-full mix-blend-multiply filter blur-3xl opacity-20 floating-element" style="animation-delay: 2s;"></div>
    <div class="fixed top-1/2 left-1/3 w-72 h-72 bg-[#D2C1B6]/40 rounded-full mix-blend-multiply filter blur-3xl opacity-15 floating-element" style="animation-delay: 4s;"></div>
    
    <!-- Include Sidebar -->
    <?php include '../t_sidebar.php'; ?>
    
    <div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Mobile Header -->
        <header class="official-gradient shadow-lg px-4 py-3 flex justify-between items-center sticky top-0 z-30 lg:hidden" style="background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;">
            <button class="text-xl text-white hover:text-[#EEF3F6] transition-colors" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            
            <h1 class="text-lg font-bold text-white flex items-center space-x-2">
                <div class="bg-white/20 backdrop-blur-sm p-2 rounded-lg">
                    <i class="fas fa-file-alt text-white text-sm"></i>
                </div>
                <span>Tests & Exams</span>
            </h1>
            
            <div class="w-8 h-8 bg-white/20 backdrop-blur-sm rounded-full flex items-center justify-center">
                <i class="fas fa-chalkboard-teacher text-white"></i>
            </div>
        </header>

        <!-- Desktop Header -->
        <header class="hidden lg:flex official-gradient shadow-lg px-6 py-4 justify-between items-center sticky top-0 z-30" style="background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;">
            <div class="flex-1"></div>
            
            <h1 class="text-2xl font-bold text-white flex items-center space-x-2">
                <div class="bg-white/20 backdrop-blur-sm p-2 rounded-lg">
                    <i class="fas fa-file-alt text-white text-xl"></i>
                </div>
                <span>Tests & Exams Dashboard</span>
            </h1>
            
            <div class="flex-1 flex justify-end items-center">
                <div class="animate-pulse bg-white/20 backdrop-blur-sm rounded-full p-2">
                    <i class="fas fa-chalkboard-teacher text-white"></i>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <div class="p-4 lg:p-8 bg-gray-50 min-h-screen">
            <div class="max-w-7xl mx-auto">
                
                <!-- Welcome Section -->
                <div class="hero-banner p-6 lg:p-8 mb-8 card-enter">
                    <div class="absolute -top-10 -right-10 w-56 h-56 bg-white opacity-10 rounded-full floating-element"></div>
                    <div class="absolute bottom-0 left-1/4 w-32 h-32 bg-yellow-300 opacity-15 rounded-full floating-element" style="animation-delay: 3s;"></div>
                    
                    <div class="relative flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4">
                        <div>
                            <h2 class="text-2xl lg:text-3xl font-bold text-white mb-2 drop-shadow-sm">
                                Test Results & Analytics
                            </h2>
                            <p class="text-[#EEF3F6]">
                                Monitor student performance, track progress, and analyze test results across your batches
                            </p>
                        </div>
                        
                        <!-- Quick Stats -->
                        <div class="flex flex-wrap gap-3">
                            <div class="px-4 py-2 bg-white text-[#234C6A] rounded-xl text-sm font-bold shadow-lg pulse">
                                <i class="fas fa-chart-line mr-2"></i>
                                Pass Rate: <?= $pass_rate ?>%
                            </div>
                            <div class="glow-chip px-4 py-2 text-white rounded-xl text-sm font-semibold">
                                <i class="fas fa-users mr-2 text-yellow-300"></i>
                                <?= $overall_stats['unique_students_attempted'] ?? 0 ?> Students
                            </div>
                        </div>
                    </div>
                    
                    <!-- Stats Grid -->
                    <div class="relative grid grid-cols-2 md:grid-cols-4 gap-4 mt-6">
                        <div class="stat-tile stat-tile-blue hover-lift">
                            <div class="stat-tile-inner p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-2xl font-bold text-gray-800"><?= $overall_stats['total_tests'] ?? 0 ?></div>
                                        <div class="text-xs text-gray-500">Total Tests</div>
                                    </div>
                                    <div class="w-11 h-11 rounded-xl icon-orb bg-gradient-to-br from-blue-400 to-indigo-600 flex items-center justify-center">
                                        <i class="fas fa-file-alt text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-tile stat-tile-green hover-lift">
                            <div class="stat-tile-inner p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-2xl font-bold text-gray-800"><?= $overall_stats['active_tests'] ?? 0 ?></div>
                                        <div class="text-xs text-gray-500">Active Tests</div>
                                    </div>
                                    <div class="w-11 h-11 rounded-xl icon-orb bg-gradient-to-br from-emerald-400 to-green-600 flex items-center justify-center">
                                        <i class="fas fa-play-circle text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-tile stat-tile-purple hover-lift">
                            <div class="stat-tile-inner p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-2xl font-bold text-gray-800"><?= $overall_stats['total_attempts'] ?? 0 ?></div>
                                        <div class="text-xs text-gray-500">Total Attempts</div>
                                    </div>
                                    <div class="w-11 h-11 rounded-xl icon-orb official-gradient flex items-center justify-center">
                                        <i class="fas fa-users text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stat-tile stat-tile-amber hover-lift">
                            <div class="stat-tile-inner p-4">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="text-2xl font-bold text-gray-800"><?= $overall_stats['overall_avg_score'] ?? 0 ?>%</div>
                                        <div class="text-xs text-gray-500">Avg Score</div>
                                    </div>
                                    <div class="w-11 h-11 rounded-xl icon-orb bg-gradient-to-br from-amber-400 to-orange-600 flex items-center justify-center">
                                        <i class="fas fa-star text-white"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters and Search -->
                <div class="glass-card p-6 mb-8 card-enter" style="animation-delay: 0.1s;">
                    <form method="GET" class="flex flex-col lg:flex-row gap-4">
                        <div class="flex-1">
                            <div class="search-bar rounded-full px-4 py-2 border-2 border-gray-200 flex items-center">
                                <i class="fas fa-search text-[#456882] mr-2"></i>
                                <input type="text" 
                                       name="search" 
                                       value="<?= htmlspecialchars($search_term) ?>" 
                                       placeholder="Search tests by title, subject..." 
                                       class="w-full outline-none text-gray-700 bg-transparent">
                                <?php if (!empty($search_term)): ?>
                                <a href="?batch=<?= $selected_batch ?>" class="text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="w-full lg:w-64">
                            <select name="batch" 
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-full focus:ring-2 focus:ring-[#456882] focus:border-transparent transition-all">
                                <option value="all">All Batches</option>
                                <?php foreach ($all_batches as $batch): ?>
                                    <option value="<?= $batch['batch_id'] ?>" <?= $selected_batch == $batch['batch_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($batch['batch_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" 
                                class="px-6 py-2 official-gradient text-white rounded-full hover:shadow-xl hover:scale-105 transition-all duration-300 font-semibold shadow-lg">
                            <i class="fas fa-filter mr-2"></i>
                            Apply Filters
                        </button>
                    </form>
                </div>
                
                <!-- Test Type Distribution -->
                <?php if (!empty($test_type_stats)): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8 card-enter" style="animation-delay: 0.15s;">
                    <?php foreach ($test_type_stats as $stat): 
                        $bgColor = $stat['test_status_group'] == 'Active' ? 'from-green-400 to-emerald-600' : 
                                  ($stat['test_status_group'] == 'Upcoming' ? 'from-blue-400 to-cyan-600' : 'from-orange-400 to-amber-600');
                        $glowColor = $stat['test_status_group'] == 'Active' ? 'rgba(16,185,129,0.25)' : 
                                  ($stat['test_status_group'] == 'Upcoming' ? 'rgba(35,76,106,0.25)' : 'rgba(234,88,12,0.25)');
                    ?>
                    <div class="bg-white rounded-xl p-4 shadow-md hover:shadow-2xl hover:-translate-y-1 transition-all duration-300" style="box-shadow: 0 4px 16px <?= $glowColor ?>;">
                        <div class="flex justify-between items-center">
                            <div>
                                <div class="text-sm text-gray-500"><?= $stat['test_status_group'] ?> Tests</div>
                                <div class="text-2xl font-bold text-gray-800"><?= $stat['test_count'] ?></div>
                                <?php if ($stat['avg_score']): ?>
                                <div class="text-xs text-gray-600 mt-1">Avg Score: <?= $stat['avg_score'] ?>%</div>
                                <?php endif; ?>
                            </div>
                            <div class="w-12 h-12 rounded-xl icon-orb bg-gradient-to-br <?= $bgColor ?> flex items-center justify-center text-white text-xl">
                                <i class="fas fa-<?= $stat['test_status_group'] == 'Active' ? 'play' : ($stat['test_status_group'] == 'Upcoming' ? 'clock' : 'check-circle') ?>"></i>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Tests Grid -->
                <div class="mb-8">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <span class="w-9 h-9 rounded-lg official-gradient flex items-center justify-center mr-2 shadow-md">
                                <i class="fas fa-list-alt text-white text-sm"></i>
                            </span>
                            All Tests
                            <span class="ml-3 official-gradient text-white text-xs font-semibold px-3 py-1 rounded-full shadow-md">
                                <?= count($tests) ?> found
                            </span>
                        </h2>
                    </div>
                    
                    <?php if (empty($tests)): ?>
                    <div class="glass-card p-12 text-center">
                        <div class="official-gradient p-5 rounded-full inline-block mb-4 shadow-lg">
                            <i class="fas fa-file-alt text-4xl text-white"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-700 mb-2">No tests found</h3>
                        <p class="text-gray-500">No tests have been created for your batches yet</p>
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php foreach ($tests as $index => $test): 
                            $test_status = '';
                            $status_class = '';
                            $status_icon = '';
                            
                            if (!$test['is_active']) {
                                $test_status = 'Inactive';
                                $status_class = 'status-inactive';
                                $status_icon = 'fa-ban';
                            } elseif (strtotime($test['start_date']) > time()) {
                                $test_status = 'Upcoming';
                                $status_class = 'status-upcoming';
                                $status_icon = 'fa-clock';
                            } elseif (strtotime($test['end_date']) < time()) {
                                $test_status = 'Completed';
                                $status_class = 'status-completed';
                                $status_icon = 'fa-check-circle';
                            } else {
                                $test_status = 'Active';
                                $status_class = 'status-active';
                                $status_icon = 'fa-play-circle';
                            }
                            
                            $pass_percentage = $test['total_attempts'] > 0 ? 
                                round(($test['passed_count'] / max($test['completed_attempts'], 1)) * 100, 1) : 0;
                        ?>
                        <div class="test-card glass-card p-6 card-enter" style="animation-delay: <?= $index * 0.1 ?>s;">
                            <!-- Test Header -->
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex items-center">
                                    <div class="w-12 h-12 rounded-xl official-gradient flex items-center justify-center text-white font-bold text-lg mr-3 shadow-md icon-orb">
                                        <?= strtoupper(substr($test['title'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <h3 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($test['title']) ?></h3>
                                        <div class="flex items-center mt-1">
                                            <span class="text-xs text-gray-500 mr-2">
                                                <i class="fas fa-layer-group mr-1"></i><?= htmlspecialchars($test['batch_name']) ?>
                                            </span>
                                            <?php if ($test['subject']): ?>
                                            <span class="text-xs text-gray-500">
                                                <i class="fas fa-book mr-1"></i><?= htmlspecialchars($test['subject']) ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="status-badge <?= $status_class ?>">
                                    <i class="fas <?= $status_icon ?> mr-1"></i>
                                    <?= $test_status ?>
                                </span>
                            </div>
                            
                            <!-- Test Stats -->
                            <div class="grid grid-cols-3 gap-3 mb-4">
                                <div class="text-center p-2 bg-gradient-to-br from-blue-50 to-indigo-100 rounded-lg border border-blue-100">
                                    <div class="text-lg font-bold text-[#234C6A]"><?= $test['question_count'] ?? 0 ?></div>
                                    <div class="text-xs text-gray-500">Questions</div>
                                </div>
                                <div class="text-center p-2 bg-gradient-to-br from-emerald-50 to-green-100 rounded-lg border border-emerald-100">
                                    <div class="text-lg font-bold text-green-600"><?= $test['total_attempts'] ?? 0 ?></div>
                                    <div class="text-xs text-gray-500">Attempts</div>
                                </div>
                                <div class="text-center p-2 bg-gradient-to-br from-[#F6F1ED] to-[#EEF3F6] rounded-lg border border-[#D2C1B6]/50">
                                    <div class="text-lg font-bold text-[#234C6A]"><?= $test['avg_score'] ?? '0' ?>%</div>
                                    <div class="text-xs text-gray-500">Avg Score</div>
                                </div>
                            </div>
                            
                            <!-- Progress and Performance -->
                            <div class="space-y-3 mb-4">
                                <div>
                                    <div class="flex justify-between text-xs mb-1">
                                        <span class="text-gray-600">Pass Rate</span>
                                        <span class="font-semibold text-green-600"><?= $pass_percentage ?>%</span>
                                    </div>
                                    <div class="h-2.5 bg-gray-200 rounded-full overflow-hidden shadow-inner">
                                        <div class="h-full bg-gradient-to-r from-emerald-400 via-green-500 to-teal-500 rounded-full" style="width: <?= $pass_percentage ?>%"></div>
                                    </div>
                                </div>
                                
                                <div class="flex justify-between text-xs">
                                    <span class="text-gray-600">
                                        <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                        Passed: <?= $test['passed_count'] ?? 0 ?>
                                    </span>
                                    <span class="text-gray-600">
                                        <i class="fas fa-times-circle text-red-500 mr-1"></i>
                                        Failed: <?= $test['failed_count'] ?? 0 ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Score Range -->
                            <?php if ($test['highest_score']): ?>
                            <div class="flex items-center justify-between text-xs text-gray-600 mb-4 p-3 bg-gradient-to-r from-[#F8FBFF] to-[#EEF3F6] rounded-lg border border-indigo-100">
                                <div>
                                    <i class="fas fa-arrow-up text-green-500 mr-1"></i>
                                    Highest: <span class="font-bold text-green-600"><?= $test['highest_score'] ?>%</span>
                                </div>
                                <div>
                                    <i class="fas fa-arrow-down text-red-500 mr-1"></i>
                                    Lowest: <span class="font-bold text-red-600"><?= $test['lowest_score'] ?>%</span>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Test Details -->
                            <div class="flex flex-wrap gap-2 text-xs text-gray-600 mb-4">
                                <?php if ($test['duration_minutes']): ?>
                                <span class="px-2 py-1 bg-gradient-to-r from-[#EEF3F6] to-[#D2C1B6]/30 text-blue-700 rounded-full font-medium">
                                    <i class="fas fa-clock mr-1"></i><?= $test['duration_minutes'] ?> mins
                                </span>
                                <?php endif; ?>
                                <?php if ($test['total_marks']): ?>
                                <span class="px-2 py-1 bg-gradient-to-r from-amber-100 to-yellow-100 text-amber-700 rounded-full font-medium">
                                    <i class="fas fa-star mr-1"></i><?= $test['total_marks'] ?> marks
                                </span>
                                <?php endif; ?>
                                <?php if ($test['passing_marks']): ?>
                                <span class="px-2 py-1 bg-gradient-to-r from-emerald-100 to-green-100 text-emerald-700 rounded-full font-medium">
                                    <i class="fas fa-check-circle mr-1"></i>Pass: <?= $test['passing_marks'] ?> marks
                                </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex gap-2 mt-4 pt-4 border-t border-gray-100">
                                <a href="test_details.php?test_id=<?= $test['id'] ?>" 
                                   class="flex-1 px-4 py-2 official-gradient text-white rounded-lg text-sm font-semibold hover:shadow-lg hover:scale-[1.02] transition-all duration-300 text-center">
                                    <i class="fas fa-chart-bar mr-2"></i>
                                    View Results
                                </a>
                                <a href="test_attempts.php?test_id=<?= $test['id'] ?>" 
                                   class="px-4 py-2 bg-gradient-to-r from-gray-100 to-gray-200 text-gray-700 rounded-lg text-sm font-semibold hover:from-purple-100 hover:to-pink-100 hover:text-[#234C6A] transition-all duration-300">
                                    <i class="fas fa-users"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Recent Attempts and Top Performers -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Recent Attempts -->
                    <div class="lg:col-span-2 glass-card p-6 card-enter" style="animation-delay: 0.2s;">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold text-gray-800 flex items-center">
                                <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-[#234C6A] to-[#456882] flex items-center justify-center mr-2 shadow-md">
                                    <i class="fas fa-history text-white text-xs"></i>
                                </span>
                                Recent Test Attempts
                            </h3>
                        </div>
                        
                        <?php if (empty($recent_attempts)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-3 text-gray-300"></i>
                            <p>No recent attempts</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-3 max-h-[500px] overflow-y-auto pr-2 scrollbar-thin">
                            <?php foreach ($recent_attempts as $attempt): 
                                $performance_color = '';
                                switch($attempt['performance_level']) {
                                    case 'Outstanding': $performance_color = 'performance-outstanding'; break;
                                    case 'Excellent': $performance_color = 'performance-excellent'; break;
                                    case 'Good': $performance_color = 'performance-good'; break;
                                    case 'Satisfactory': $performance_color = 'performance-satisfactory'; break;
                                    default: $performance_color = 'performance-needs-improvement';
                                }
                            ?>
                            <div class="table-row-hover p-4 bg-gray-50 rounded-xl flex items-center justify-between hover:bg-white transition-all">
                                <div class="flex items-center space-x-3 flex-1">
                                    <!-- Student Avatar -->
                                    <?php if (!empty($attempt['profile_picture']) && file_exists($attempt['profile_picture'])): ?>
                                    <img src="<?= htmlspecialchars($attempt['profile_picture']) ?>" 
                                         alt="<?= $attempt['first_name'] ?>" 
                                         class="w-10 h-10 rounded-lg object-cover ring-2 ring-[#D2C1B6]/70">
                                    <?php else: ?>
                                    <div class="student-avatar official-gradient shadow-md">
                                        <?= strtoupper(substr($attempt['first_name'], 0, 1) . substr($attempt['last_name'], 0, 1)) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <span class="font-semibold text-gray-800">
                                                <?= htmlspecialchars($attempt['first_name'] . ' ' . $attempt['last_name']) ?>
                                            </span>
                                            <?php if ($attempt['test_rank'] == 1): ?>
                                            <span class="ml-2 px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded-full text-xs font-bold">
                                                #1
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center text-xs text-gray-500 mt-1">
                                            <span class="truncate max-w-[150px]"><?= htmlspecialchars($attempt['test_title']) ?></span>
                                            <span class="mx-2">•</span>
                                            <span><?= date('M d, H:i', strtotime($attempt['submitted_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-right ml-4">
                                    <div class="flex items-center space-x-3">
                                        <div>
                                            <div class="text-lg font-bold <?= $attempt['result_status'] == 'Passed' ? 'text-green-600' : 'text-red-600' ?>">
                                                <?= $attempt['percentage'] ?>%
                                            </div>
                                            <span class="text-xs px-2 py-0.5 rounded-full <?= $performance_color ?> text-white">
                                                <?= $attempt['performance_level'] ?>
                                            </span>
                                        </div>
                                        <span class="badge <?= $attempt['result_status'] == 'Passed' ? 'badge-pass' : 'badge-fail' ?> text-xs px-2 py-1">
                                            <?= $attempt['result_status'] ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Top Performers -->
                    <div class="glass-card p-6 card-enter" style="animation-delay: 0.25s;">
                        <h3 class="text-lg font-bold text-gray-800 flex items-center mb-4">
                            <span class="w-8 h-8 rounded-lg bg-gradient-to-br from-yellow-400 to-orange-500 flex items-center justify-center mr-2 shadow-md">
                                <i class="fas fa-trophy text-white text-xs"></i>
                            </span>
                            Top Performers
                        </h3>
                        
                        <?php if (empty($top_performers)): ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-user-graduate text-4xl mb-3 text-gray-300"></i>
                            <p>No performers yet</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($top_performers as $index => $performer): ?>
                            <div class="flex items-center p-3 <?= $index < 3 ? 'bg-gradient-to-r from-yellow-50 via-amber-50 to-orange-50 rounded-xl border border-amber-100 shadow-sm' : 'hover:bg-gray-50 rounded-xl transition-colors' ?>">
                                <!-- Rank Badge -->
                                <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-white mr-3 shadow-md <?= $index == 0 ? 'rank-1' : ($index == 1 ? 'rank-2' : ($index == 2 ? 'rank-3' : 'bg-gray-300 text-gray-700')) ?>">
                                    <?= $index + 1 ?>
                                </div>
                                
                                <!-- Student Info -->
                                <div class="flex-1 flex items-center">
                                    <?php if (!empty($performer['profile_picture']) && file_exists($performer['profile_picture'])): ?>
                                    <img src="<?= htmlspecialchars($performer['profile_picture']) ?>" 
                                         alt="<?= $performer['first_name'] ?>" 
                                         class="w-10 h-10 rounded-lg object-cover mr-3 ring-2 ring-[#D2C1B6]/70">
                                    <?php else: ?>
                                    <div class="w-10 h-10 rounded-lg official-gradient flex items-center justify-center text-white font-bold mr-3 shadow-md">
                                        <?= strtoupper(substr($performer['first_name'], 0, 1) . substr($performer['last_name'], 0, 1)) ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-1">
                                        <div class="font-semibold text-gray-800">
                                            <?= htmlspecialchars($performer['first_name'] . ' ' . $performer['last_name']) ?>
                                        </div>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($performer['batch_name']) ?></div>
                                    </div>
                                </div>
                                
                                <!-- Score -->
                                <div class="text-right">
                                    <div class="font-bold gradient-text"><?= $performer['avg_score'] ?>%</div>
                                    <div class="text-xs text-gray-500"><?= $performer['tests_taken'] ?> tests</div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Summary Stats -->
                        <div class="mt-4 pt-4 border-t border-gray-100 grid grid-cols-2 gap-2 text-center">
                            <div class="p-2 bg-gradient-to-br from-blue-50 to-indigo-100 rounded-lg border border-blue-100">
                                <div class="text-lg font-bold text-[#234C6A]"><?= round(array_sum(array_column($top_performers, 'avg_score')) / count($top_performers), 1) ?>%</div>
                                <div class="text-xs text-gray-600">Avg Top Score</div>
                            </div>
                            <div class="p-2 bg-gradient-to-br from-emerald-50 to-green-100 rounded-lg border border-emerald-100">
                                <div class="text-lg font-bold text-green-600"><?= array_sum(array_column($top_performers, 'tests_passed')) ?></div>
                                <div class="text-xs text-gray-600">Tests Passed</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Footer -->
                <footer class="mt-8 text-center text-sm text-gray-500">
                    <p>© <?= date('Y'); ?> ASD Academy. All rights reserved.</p>
                </footer>
            </div>
        </div>
    </div>
    
    <!-- Mobile Menu Script (same as dashboard.php) -->
    <script>
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
        
        // Initialize animations
        document.addEventListener('DOMContentLoaded', function() {
            // Animate progress bars
            document.querySelectorAll('.progress-ring').forEach((ring, index) => {
                setTimeout(() => {
                    const value = ring.getAttribute('data-value');
                    if (value) {
                        ring.style.strokeDashoffset = 264 - (264 * parseInt(value) / 100);
                    }
                }, index * 100);
            });
            
            // Add ripple effect to buttons
            document.querySelectorAll('button, a').forEach(element => {
                element.addEventListener('click', function(e) {
                    if (this.classList.contains('no-ripple')) return;
                    
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
            
            // Show welcome toast
            setTimeout(() => {
                showToast('Test results loaded successfully!', 'success');
            }, 1000);
        });
        
        // Toast notification
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 px-4 py-3 rounded-lg shadow-xl text-white font-semibold z-50 transform translate-x-full opacity-0 transition-all duration-300 ${type === 'success' ? 'bg-gradient-to-r from-green-500 to-emerald-500' : 'bg-gradient-to-r from-[#234C6A] to-[#456882]'}`;
            toast.textContent = message;
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
        
        // Create confetti effect on page load
        function createConfetti() {
            const colors = ['#1B3C53', '#234C6A', '#fbbf24', '#34d399', '#f87171', '#60a5fa'];
            
            for (let i = 0; i < 30; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.left = Math.random() * 100 + 'vw';
                confetti.style.top = '-10px';
                confetti.style.background = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.transform = `rotate(${Math.random() * 360}deg)`;
                
                document.body.appendChild(confetti);
                
                const animation = confetti.animate([
                    { transform: `translateY(0) rotate(0deg)`, opacity: 0.7 },
                    { transform: `translateY(${window.innerHeight}px) rotate(${Math.random() * 360}deg)`, opacity: 0 }
                ], {
                    duration: Math.random() * 2000 + 1500,
                    easing: 'cubic-bezier(0.215, 0.61, 0.355, 1)'
                });
                
                animation.onfinish = () => confetti.remove();
            }
        }
        
        setTimeout(createConfetti, 500);
    </script>
</body>
</html>