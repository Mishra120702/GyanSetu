<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => $_SERVER['HTTP_HOST'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit;
}

require_once '../db_connection.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $trainer_id = $_SESSION['user_id'];
    
    // Get trainer details
    $trainer_stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $trainer_stmt->execute([$trainer_id]);
    $trainer = $trainer_stmt->fetch(PDO::FETCH_ASSOC);

    if ($trainer === false) {
        $trainer = [
            'id' => $trainer_id,
            'user_id' => $trainer_id,
            'name' => ''
        ];
    }
    
    // Robust trainer matching:
    $trainer_match_ids = array_values(array_unique(array_filter([
        (int)$trainer['id'],
        (int)$trainer_id
    ])));
    $trainer_placeholders = implode(',', array_fill(0, count($trainer_match_ids), '?'));

    // Get batches assigned to this trainer
    $batch_stmt = $db->prepare("
        SELECT DISTINCT b.batch_id, b.batch_name, b.start_date, b.end_date, b.status, 
               b.time_slot, b.mode, b.max_students, b.current_enrollment,
               COUNT(DISTINCT s.student_id) as student_count
        FROM batches b 
        LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
        LEFT JOIN students s ON b.batch_id = s.batch_name 
        WHERE b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders)
        GROUP BY b.batch_id
        ORDER BY b.created_at DESC
    ");
    $batch_stmt->execute(array_merge($trainer_match_ids, $trainer_match_ids));
    $batches = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get selected batch or default to first batch
    $selected_batch_id = isset($_GET['batch_id']) ? $_GET['batch_id'] : ($batches[0]['batch_id'] ?? null);
    
    // Get month from URL or use current month
    $current_month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $current_year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    
    // Validate month and year
    if ($current_month < 1 || $current_month > 12) {
        $current_month = date('n');
    }
    if ($current_year < 2020 || $current_year > 2100) {
        $current_year = date('Y');
    }
    
    // Calculate previous and next month
    $prev_month = $current_month - 1;
    $prev_year = $current_year;
    if ($prev_month < 1) {
        $prev_month = 12;
        $prev_year--;
    }
    
    $next_month = $current_month + 1;
    $next_year = $current_year;
    if ($next_month > 12) {
        $next_month = 1;
        $next_year++;
    }
    
    if ($selected_batch_id) {
        // Get batch details
        $batch_stmt = $db->prepare("
            SELECT DISTINCT b.*, 
                   COUNT(DISTINCT s.student_id) as student_count
            FROM batches b 
            LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
            LEFT JOIN students s ON b.batch_id = s.batch_name 
            WHERE b.batch_id = ? AND (b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders))
            GROUP BY b.batch_id
        ");
        $params = array_merge([$selected_batch_id], $trainer_match_ids, $trainer_match_ids);
        $batch_stmt->execute($params);
        $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($batch) {
            // Get schedule for selected month range
            $first_day_of_month = date('Y-m-01', strtotime("$current_year-$current_month-01"));
            $last_day_of_month = date('Y-m-t', strtotime("$current_year-$current_month-01"));
            
            // Get classes for current month
            $schedule_stmt = $db->prepare("SELECT * FROM schedule WHERE batch_id = ? AND schedule_date BETWEEN ? AND ? ORDER BY schedule_date, start_time");
            $schedule_stmt->execute([$selected_batch_id, $first_day_of_month, $last_day_of_month]);
            $month_schedule = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get all schedule for stats
            $all_schedule_stmt = $db->prepare("SELECT * FROM schedule WHERE batch_id = ? ORDER BY schedule_date, start_time");
            $all_schedule_stmt->execute([$selected_batch_id]);
            $all_schedule = $all_schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get upcoming classes (next 7 days)
            $upcoming_start = date('Y-m-d');
            $upcoming_end = date('Y-m-d', strtotime('+7 days'));
            
            $upcoming_stmt = $db->prepare("SELECT * FROM schedule WHERE batch_id = ? AND schedule_date BETWEEN ? AND ? ORDER BY schedule_date, start_time");
            $upcoming_stmt->execute([$selected_batch_id, $upcoming_start, $upcoming_end]);
            $upcoming_classes = $upcoming_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate stats
            $total_classes = count($all_schedule);
            $upcoming_count = count(array_filter($all_schedule, function($c) {
                return $c['schedule_date'] >= date('Y-m-d') && !$c['is_cancelled'];
            }));
            $completed_count = count(array_filter($all_schedule, function($c) {
                return $c['schedule_date'] < date('Y-m-d') && !$c['is_cancelled'];
            }));
            $cancelled_count = count(array_filter($all_schedule, function($c) {
                return $c['is_cancelled'];
            }));
        }
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Generate calendar days for current month
$days_in_month = date('t', mktime(0, 0, 0, $current_month, 1, $current_year));
$first_day_of_week = date('N', mktime(0, 0, 0, $current_month, 1, $current_year));

// Group schedule by date for current month
$schedule_by_date = [];
if (isset($month_schedule)) {
    foreach ($month_schedule as $class) {
        $class_date = $class['schedule_date'];
        if (!isset($schedule_by_date[$class_date])) {
            $schedule_by_date[$class_date] = [];
        }
        $schedule_by_date[$class_date][] = $class;
    }
}

// Prepare calendar days array
$calendar_days = [];
$empty_days = $first_day_of_week - 1; // Monday is 1, Sunday is 7

// Add empty days for first week
for ($i = 0; $i < $empty_days; $i++) {
    $calendar_days[] = ['day' => '', 'date' => null, 'events' => [], 'is_today' => false, 'has_events' => false, 
                       'is_sunday' => false, 'is_saturday' => false, 'has_cancelled' => false];
}

// Add days of the month
for ($day = 1; $day <= $days_in_month; $day++) {
    $date = sprintf('%04d-%02d-%02d', $current_year, $current_month, $day);
    $is_today = ($date == date('Y-m-d') && $current_month == date('n') && $current_year == date('Y'));
    $has_events = isset($schedule_by_date[$date]) && !empty($schedule_by_date[$date]);
    $day_of_week = date('N', strtotime($date));
    $is_sunday = ($day_of_week == 7);
    $is_saturday = ($day_of_week == 6);
    
    $day_events = $has_events ? $schedule_by_date[$date] : [];
    
    // Check if any event is cancelled
    $has_cancelled = false;
    foreach ($day_events as $event) {
        if ($event['is_cancelled']) {
            $has_cancelled = true;
            break;
        }
    }
    
    $calendar_days[] = [
        'day' => $day,
        'date' => $date,
        'events' => $day_events,
        'is_today' => $is_today,
        'has_events' => $has_events,
        'is_sunday' => $is_sunday,
        'is_saturday' => $is_saturday,
        'has_cancelled' => $has_cancelled
    ];
}

// Calculate total empty days at the end to make full weeks
$total_cells = 42; // 6 rows * 7 days
$remaining_empty_days = $total_cells - count($calendar_days);
for ($i = 0; $i < $remaining_empty_days; $i++) {
    $calendar_days[] = ['day' => '', 'date' => null, 'events' => [], 'is_today' => false, 'has_events' => false, 
                       'is_sunday' => false, 'is_saturday' => false, 'has_cancelled' => false];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Schedule Management | Trainer Panel | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary: #1B3C53;
            --primary-light: #1B3C53;
            --primary-dark: #4338ca;
            --secondary: #10b981;
            --accent: #f59e0b;
        }
        
        *, *::before, *::after {
            box-sizing: border-box;
        }

        .glass-effect {
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.18);
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
        }
        
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .schedule-card {
            border-left: 4px solid;
            transition: all 0.3s ease;
        }
        
        .schedule-card.class {
            border-left-color: #234C6A;
        }
        
        .schedule-card.today {
            border-left-color: #10b981;
        }
        
        .schedule-card.completed {
            border-left-color: #10b981;
        }
        
        .schedule-card.cancelled {
            border-left-color: #ef4444;
        }
        
        .schedule-card.upcoming {
            border-left-color: #234C6A;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.class {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge.today {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.completed {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.cancelled {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-badge.upcoming {
            background-color: #f3e8ff;
            color: #6b21a8;
        }
        
        .status-badge.sunday {
            background-color: #fce7f3;
            color: #9d174d;
        }
        
        .ripple {
            position: relative;
            overflow: hidden;
        }
        
        .ripple-effect {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.4);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .slide-in {
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        /* Calendar Styles */
        .calendar-day {
            transition: all 0.2s ease;
            min-height: 120px;
            position: relative;
            overflow: hidden;
        }
        
        @media (min-width: 768px) {
            .calendar-day {
                min-height: 140px;
            }
        }
        
        .calendar-day:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .calendar-day.has-events {
            background-color: #f8fafc;
        }
        
        .calendar-day.today {
            background-color: rgba(59, 130, 246, 0.05);
            border: 2px solid rgba(59, 130, 246, 0.3);
            font-weight: bold;
        }
        
        .calendar-day.has-class {
            border-left: 3px solid #234C6A;
        }
        
        .calendar-day.completed {
            background-color: rgba(16, 185, 129, 0.05);
            border-left: 3px solid #10b981;
        }
        
        .calendar-day.cancelled {
            background-color: rgba(239, 68, 68, 0.05);
            border-left: 3px solid #ef4444;
        }
        
        .calendar-day.sunday {
            background-color: rgba(69,104,130, 0.05);
        }
        
        .calendar-day.saturday {
            background-color: rgba(139, 92, 246, 0.05);
        }
        
        .event-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 4px;
        }
        
        .dot-class {
            background-color: #234C6A;
        }
        
        .dot-completed {
            background-color: #10b981;
        }
        
        .dot-cancelled {
            background-color: #ef4444;
        }
        
        .dot-today {
            background-color: #10b981;
        }
        
        .dot-upcoming {
            background-color: #234C6A;
        }
        
        .dot-sunday {
            background-color: #456882;
        }
        
        .calendar-event-item {
            font-size: 0.65rem;
            padding: 3px 5px;
            margin-bottom: 3px;
            border-radius: 4px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        @media (min-width: 768px) {
            .calendar-event-item {
                font-size: 0.7rem;
            }
        }
        
        .calendar-event-item:hover {
            transform: scale(1.02);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .event-class {
            background-color: #dbeafe;
            color: #1e40af;
            border-left: 2px solid #234C6A;
        }
        
        .event-completed {
            background-color: #d1fae5;
            color: #065f46;
            border-left: 2px solid #10b981;
        }
        
        .event-cancelled {
            background-color: #fee2e2;
            color: #991b1b;
            border-left: 2px solid #ef4444;
        }
        
        .event-upcoming {
            background-color: #f3e8ff;
            color: #6b21a8;
            border-left: 2px solid #234C6A;
        }
        
        .event-sunday {
            background-color: #fce7f3;
            color: #9d174d;
            border-left: 2px solid #456882;
        }
        
        .month-navigation {
            transition: all 0.3s ease;
        }
        
        .month-navigation:hover {
            background-color: #f3f4f6;
            transform: scale(1.05);
        }
        
        .event-popup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            animation: popupFadeIn 0.3s ease;
            width: 90%;
            max-width: 28rem;
        }
        
        @keyframes popupFadeIn {
            from {
                opacity: 0;
                transform: translate(-50%, -60%);
            }
            to {
                opacity: 1;
                transform: translate(-50%, -50%);
            }
        }
        
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        
        .scrollbar-thin::-webkit-scrollbar {
            width: 4px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 4px;
        }
        
        .scrollbar-thin::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        .tab-content {
            animation: tabFade 0.3s ease;
        }
        
        @keyframes tabFade {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .floating-button {
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .glow-effect {
            box-shadow: 0 0 20px rgba(59, 130, 246, 0.3);
        }
        
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .smooth-transition {
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.27, 1.55);
        }
        
        .rotate-on-hover:hover {
            transform: rotate(5deg);
        }
        
        .bounce-in {
            animation: bounceIn 0.6s cubic-bezier(0.68, -0.55, 0.27, 1.55);
        }
        
        @keyframes bounceIn {
            0% { transform: scale(0.5); opacity: 0; }
            60% { transform: scale(1.1); opacity: 1; }
            100% { transform: scale(1); }
        }
        
        .card-hover {
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        
        .card-hover:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }
        
        .text-gradient {
            background: linear-gradient(90deg, #1B3C53 0%, #234C6A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .shimmer {
            position: relative;
            overflow: hidden;
        }
        
        .shimmer::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(
                to right,
                rgba(255, 255, 255, 0) 0%,
                rgba(255, 255, 255, 0.3) 50%,
                rgba(255, 255, 255, 0) 100%
            );
            transform: rotate(30deg);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% { transform: translateX(-100%) rotate(30deg); }
            100% { transform: translateX(100%) rotate(30deg); }
        }
        
        /* Responsive Styles */
        @media (max-width: 640px) {
            h1 { font-size: 1.5rem !important; }
            h2 { font-size: 1.25rem !important; }
            h3 { font-size: 1.125rem !important; }
            
            .text-2xl { font-size: 1.25rem !important; }
            .text-xl { font-size: 1.125rem !important; }
            
            .px-6 { padding-left: 1rem !important; padding-right: 1rem !important; }
            .py-4 { padding-top: 1rem !important; padding-bottom: 1rem !important; }
            
            .space-y-6 > * + * { margin-top: 1rem !important; }
            .space-y-4 > * + * { margin-top: 0.75rem !important; }
            
            .flex-col-mobile {
                flex-direction: column !important;
            }
            
            .flex-wrap-mobile {
                flex-wrap: wrap !important;
            }
            
            .text-center-mobile {
                text-align: center !important;
            }
            
            .w-full-mobile {
                width: 100% !important;
            }
            
            .stack-on-mobile {
                display: block !important;
            }
            
            .stack-on-mobile > * {
                margin-bottom: 0.75rem !important;
            }
            
            .stack-on-mobile > *:last-child {
                margin-bottom: 0 !important;
            }
            
            .calendar-day {
                min-height: 90px;
                padding: 0.5rem !important;
            }
            
            .calendar-event-item {
                font-size: 0.6rem;
                padding: 2px 3px;
            }
            
            .event-dot {
                width: 6px;
                height: 6px;
                margin-right: 2px;
            }
        }
        
        /* Tablet-specific styles */
        @media (min-width: 641px) and (max-width: 1023px) {
            .lg\:col-span-1, .lg\:col-span-2 {
                grid-column: span 2 !important;
            }
            
            .grid-cols-1 {
                grid-template-columns: 1fr !important;
            }
            
            .calendar-day {
                min-height: 110px;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 1024px) {
            /* Scoped to nav/action elements only — avoids distorting small icon buttons */
            nav button,
            nav a[href],
            .month-navigation,
            .tab-btn,
            #mobileSidebarToggle {
                min-height: 44px;
            }

            .calendar-event-item {
                min-height: 30px;
                display: flex;
                align-items: center;
            }
        }
        
        /* Optimize animations for mobile */
        @media (prefers-reduced-motion: reduce) {
            *, ::before, ::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        /* Zoom-safe layout: prevent content from spilling outside its container */
        #main-content {
            min-width: 0;
            overflow-x: hidden;
        }

        /* Prevent flex children from overflowing at high zoom */
        .flex > * {
            min-width: 0;
        }

        /* Ensure calendar grid cells never overflow their column */
        .calendar-day {
            min-width: 0;
            word-break: break-word;
        }

        /* Popup: always within viewport at any zoom level */
        .event-popup {
            max-height: 90vh;
            overflow-y: auto;
        }

        .event-popup > div {
            max-height: 85vh;
            overflow-y: auto;
        }

        /* Sidebar: Mobile sidebar overlay */
        .sidebar-open main {
            margin-left: 16rem !important;
        }
        
        @media (max-width: 1023px) {
            body.sidebar-open {
                overflow: hidden;
            }
            .sidebar-open main {
                margin-left: 0 !important;
            }
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(229, 231, 235, 0.5);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        @media (max-width: 640px) {
            .glass-card {
                border-radius: 12px;
                padding: 1rem !important;
            }
        }
    
        /* ===== Same dashboard/student-card theme: visual-only enhancement ===== */
        :root {
            --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 46%, #456882 100%);
            --dash-blue: linear-gradient(135deg, #234C6A 0%, #456882 100%);
            --dash-green: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --dash-orange: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            --dash-red: linear-gradient(135deg, #ef4444 0%, #f43f5e 100%);
            --dash-ink: #101827;
        }

        body {
            background:
                radial-gradient(circle at 14% 10%, rgba(27,60,83,.15), transparent 28%),
                radial-gradient(circle at 86% 8%, rgba(69,104,130,.15), transparent 30%),
                linear-gradient(135deg, #eef4ff 0%, #f7f3ff 48%, #f8fbff 100%) !important;
            color: var(--dash-ink);
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83,.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(69,104,130,.045) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.82), transparent 84%);
            z-index: -2;
        }

        .glass-card, .stat-card, .schedule-card, .calendar-day {
            background: rgba(255,255,255,.90) !important;
            border: 1px solid rgba(226,232,240,.82) !important;
            border-radius: 24px !important;
            box-shadow: 0 18px 42px rgba(15,23,42,.075) !important;
            backdrop-filter: blur(16px);
        }

        .hero-shell {
            position: relative;
            overflow: hidden;
            border-radius: 28px !important;
            padding: clamp(1.15rem, 2.5vw, 1.8rem) !important;
            color: white;
            background: var(--dash-main) !important;
            box-shadow: 0 24px 58px rgba(27,60,83,.25) !important;
            border: 1px solid rgba(255,255,255,.25);
        }

        .hero-shell::before {
            content: "";
            position: absolute;
            width: 430px;
            height: 430px;
            right: -135px;
            top: -145px;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            filter: blur(2px);
        }

        .hero-shell::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            left: 42%;
            bottom: -165px;
            border-radius: 999px;
            background: rgba(34,211,238,.16);
        }

        .hero-shell > * { position: relative; z-index: 1; }

        .hero-shell .status-badge {
            background: rgba(255,255,255,.18) !important;
            color: #fff !important;
            border: 1px solid rgba(255,255,255,.24);
            backdrop-filter: blur(12px);
        }

        .feature-shell {
            position: relative;
            overflow: hidden;
            border-radius: 26px !important;
        }

        .feature-shell::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--feature-accent, var(--dash-main));
        }

        .feature-shell::after {
            content: "";
            position: absolute;
            width: 190px;
            height: 190px;
            right: -60px;
            top: -60px;
            border-radius: 999px;
            background: var(--feature-glow, rgba(139,92,246,.10));
            filter: blur(8px);
            opacity: .75;
            pointer-events: none;
        }

        .feature-shell > * { position: relative; z-index: 1; }

        .feature-selector {
            --feature-accent: linear-gradient(90deg, #234C6A, #456882, #234C6A);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-calendar {
            --feature-accent: linear-gradient(90deg, #234C6A, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(35,76,106,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-records {
            --feature-accent: linear-gradient(90deg, #10b981, #22c55e, #456882);
            --feature-glow: radial-gradient(circle, rgba(16,185,129,.14), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .feature-detail {
            --feature-accent: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
            --feature-glow: radial-gradient(circle, rgba(79,70,229,.13), rgba(69,104,130,.05) 60%, transparent 72%);
        }

        .section-kicker {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .36rem .72rem;
            border-radius: 999px;
            margin-bottom: .85rem;
            background: rgba(255,255,255,.84);
            border: 1px solid rgba(226,232,240,.9);
            color: #1B3C53;
            font-size: .72rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
            box-shadow: 0 6px 18px rgba(15,23,42,.05);
        }

        .stat-card {
            position: relative;
            overflow: hidden;
            transition: all .22s ease !important;
            border-left: 0 !important;
        }

        .stat-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 4px;
            background: var(--dash-main);
        }

        .stat-card.present::before { background: var(--dash-green); }
        .stat-card.absent::before { background: var(--dash-red); }
        .stat-card.attendance::before { background: var(--dash-blue); }
        .stat-card.camera::before { background: var(--dash-main); }

        .stat-card:hover, .schedule-card:hover, .calendar-day:hover {
            transform: translateY(-3px) !important;
            box-shadow: 0 22px 48px rgba(15,23,42,.11) !important;
        }

        .schedule-card {
            position: relative;
            overflow: hidden;
            border-left: 0 !important;
        }

        .schedule-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: #234C6A;
        }

        .schedule-card.today::before { background: #10b981; }
        .schedule-card.completed::before { background: #10b981; }
        .schedule-card.cancelled::before { background: #ef4444; }
        .schedule-card.upcoming::before { background: #234C6A; }
        .schedule-card.class::before { background: #234C6A; }

        .schedule-card > * { position: relative; z-index: 1; }

        .calendar-day {
            border-radius: 18px !important;
            background: linear-gradient(135deg, rgba(255,255,255,.94), rgba(248,250,255,.92)) !important;
        }

        .calendar-day.today {
            background: linear-gradient(135deg, rgba(239,246,255,.96), rgba(245,243,255,.94)) !important;
            border: 2px solid rgba(27,60,83,.40) !important;
        }

        .calendar-event-item {
            border-radius: 10px !important;
            font-weight: 800;
            box-shadow: 0 6px 14px rgba(15,23,42,.05);
        }

        .month-navigation {
            border-radius: 14px !important;
            font-weight: 800 !important;
            box-shadow: 0 8px 20px rgba(15,23,42,.06);
        }

        .tab-btn {
            border-radius: 14px 14px 0 0;
        }

        table thead tr {
            background: linear-gradient(90deg, #EEF3F6, #F6F1ED) !important;
        }

        table tbody tr:hover {
            background: linear-gradient(90deg, rgba(245,243,255,.88), rgba(255,241,248,.78)) !important;
        }

        .status-badge {
            border: 1px solid currentColor;
            font-weight: 900 !important;
        }

        @media (max-width: 768px) {
            .hero-shell { border-radius: 22px !important; }
            .glass-card, .stat-card, .schedule-card, .calendar-day { border-radius: 20px !important; }
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

/* ===== Company Source Safe UI Patch: Schedule page approved theme ===== */
/* CSS-only patch. PHP queries, session, links, month navigation, event popup, tab JS, GET params and DB logic untouched. */

/* Main page background like the approved pages */
body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.10), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
    color: #1B3C53 !important;
}

/* Desktop/mobile top headers clean */
header.sticky,
.lg\:hidden.sticky {
    background: rgba(255,253,250,.86) !important;
    backdrop-filter: blur(18px) !important;
    border-bottom: 1px solid rgba(210,193,182,.56) !important;
    box-shadow: 0 12px 34px rgba(27,60,83,.08) !important;
}

header.sticky h1,
header.sticky span,
.lg\:hidden.sticky h1 {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Shared cards: skin/cream shade, not broken white */
.glass-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.09), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.55px solid rgba(210,193,182,.66) !important;
    border-radius: 24px !important;
    box-shadow:
        0 18px 44px rgba(27,60,83,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
    backdrop-filter: blur(16px) !important;
}

/* Feature shells: only top border accent, theme style */
.feature-shell {
    position: relative !important;
    overflow: hidden !important;
    border-radius: 26px !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.feature-shell::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 0 auto 0 !important;
    height: 5px !important;
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882) !important;
    z-index: 1 !important;
}

.feature-shell::after {
    content: "" !important;
    position: absolute !important;
    width: 190px !important;
    height: 190px !important;
    right: -60px !important;
    top: -60px !important;
    border-radius: 999px !important;
    background: radial-gradient(circle, rgba(69,104,130,.13), rgba(210,193,182,.08) 58%, transparent 72%) !important;
    filter: blur(7px) !important;
    pointer-events: none !important;
}

.feature-shell > * {
    position: relative !important;
    z-index: 2 !important;
}

.feature-shell:hover {
    border-color: rgba(35,76,106,.42) !important;
    box-shadow:
        0 26px 58px rgba(27,60,83,.16),
        inset 0 1px 0 rgba(255,255,255,.90) !important;
}

/* Section pills */
.section-kicker {
    background:
        linear-gradient(135deg, rgba(255,253,250,.96), rgba(238,243,246,.90)) !important;
    border: 1.3px solid rgba(210,193,182,.72) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    box-shadow: 0 8px 20px rgba(27,60,83,.08) !important;
}

/* Batch selector buttons */
.feature-selector a {
    border-radius: 14px !important;
    border-color: rgba(210,193,182,.78) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    background: rgba(255,253,250,.92) !important;
    box-shadow: 0 8px 18px rgba(27,60,83,.055) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background .22s ease !important;
}

.feature-selector a:hover {
    transform: translateY(-2px) !important;
    border-color: #234C6A !important;
    background: rgba(238,243,246,.96) !important;
    box-shadow: 0 14px 28px rgba(27,60,83,.12) !important;
}

.feature-selector a.bg-blue-50,
.feature-selector a.border-blue-500 {
    background:
        radial-gradient(circle at 94% 8%, rgba(255,255,255,.18), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    border-color: rgba(255,255,255,.48) !important;
}

/* Hero / batch header, keep same approved navy banner */
.hero-shell {
    background:
        radial-gradient(circle at 92% 20%, rgba(255,255,255,.18), transparent 26%),
        radial-gradient(circle at 70% 110%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    border: 1.6px solid rgba(255,255,255,.24) !important;
    border-radius: 28px !important;
    box-shadow:
        0 24px 64px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
}

.hero-shell h2,
.hero-shell p,
.hero-shell span,
.hero-shell i,
.hero-shell .status-badge {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 7px rgba(0,0,0,.16) !important;
}

.hero-shell .status-badge {
    background: rgba(255,255,255,.18) !important;
    border: 1.4px solid rgba(255,255,255,.34) !important;
    box-shadow: 0 10px 22px rgba(15,23,42,.12) !important;
}

/* Schedule Snapshot cards: exact requested colors */
.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card {
    position: relative !important;
    overflow: hidden !important;
    min-height: 132px !important;
    border-radius: 24px !important;
    color: #ffffff !important;
    border: 1.6px solid rgba(255,255,255,.38) !important;
    box-shadow:
        0 20px 42px rgba(27,60,83,.18),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.20), transparent 34%),
        radial-gradient(circle at 4% 100%, rgba(255,255,255,.10), transparent 32%) !important;
    pointer-events: none !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card::after {
    content: "" !important;
    position: absolute !important;
    right: -38px !important;
    top: -42px !important;
    width: 124px !important;
    height: 124px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.14) !important;
    pointer-events: none !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card > * {
    position: relative !important;
    z-index: 2 !important;
}

/* Total = navy, Upcoming = orange, Completed = green, Cancelled = red */
.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card:nth-child(1) {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 54%, #456882 100%) !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card:nth-child(2) {
    background: linear-gradient(135deg, #b45309 0%, #d97706 54%, #f59e0b 100%) !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card:nth-child(3) {
    background: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%) !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card:nth-child(4) {
    background: linear-gradient(135deg, #b91c1c 0%, #dc2626 54%, #ef4444 100%) !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card:hover {
    transform: translateY(-5px) !important;
    filter: brightness(1.06) !important;
    box-shadow:
        0 28px 62px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card h3,
.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card p,
.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.16) !important;
}

/* Snapshot icons like the reference image */
.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card .rounded-full {
    width: 54px !important;
    height: 54px !important;
    min-width: 54px !important;
    min-height: 54px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.45px solid rgba(255,255,255,.46) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow:
        0 12px 26px rgba(0,0,0,.20),
        inset 0 1px 0 rgba(255,255,255,.26) !important;
    transition: transform .22s ease, box-shadow .22s ease !important;
}

.section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card:hover .rounded-full {
    transform: translateY(-2px) scale(1.10) rotate(3deg) !important;
    box-shadow:
        0 17px 36px rgba(0,0,0,.25),
        0 0 0 8px rgba(255,255,255,.14),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}

/* Calendar board */
.feature-calendar {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
}

.feature-calendar h3,
.feature-calendar .text-gray-800,
.feature-calendar .font-semibold {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
}

/* Weekday headers highlighted */
.feature-calendar .grid.grid-cols-7.gap-1.sm\:gap-2.mb-1 > div,
.feature-calendar .grid.grid-cols-7.gap-1.sm\:gap-2.mb-2 > div {
    background:
        linear-gradient(135deg, rgba(255,253,250,.96), rgba(238,243,246,.90)) !important;
    border: 1.2px solid rgba(210,193,182,.58) !important;
    border-radius: 14px !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    box-shadow: 0 8px 18px rgba(27,60,83,.045) !important;
}

/* Calendar days */
.calendar-day {
    background:
        radial-gradient(circle at 92% 6%, rgba(69,104,130,.05), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(238,243,246,.86)) !important;
    border: 1.35px solid rgba(210,193,182,.62) !important;
    border-radius: 18px !important;
    box-shadow:
        0 9px 22px rgba(27,60,83,.055),
        inset 0 1px 0 rgba(255,255,255,.76) !important;
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.calendar-day:hover {
    transform: translateY(-3px) !important;
    border-color: rgba(35,76,106,.38) !important;
    box-shadow:
        0 18px 34px rgba(27,60,83,.12),
        inset 0 1px 0 rgba(255,255,255,.88) !important;
}

.calendar-day.today {
    background:
        linear-gradient(135deg, rgba(238,243,246,.98), rgba(210,193,182,.28)) !important;
    border: 2px solid rgba(35,76,106,.44) !important;
}

.calendar-day.saturday {
    background:
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(234,244,255,.72)) !important;
}

.calendar-day.sunday {
    background:
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(255,236,244,.62)) !important;
}

/* Date chips */
.calendar-day span.bg-pink-100,
.calendar-day span.bg-purple-100,
.calendar-day span.bg-blue-100 {
    border-radius: 999px !important;
    font-weight: 900 !important;
    border: 1px solid rgba(210,193,182,.55) !important;
}

/* Month navigation buttons */
.month-navigation {
    background: rgba(255,253,250,.96) !important;
    border: 1.3px solid rgba(210,193,182,.70) !important;
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    box-shadow: 0 8px 20px rgba(27,60,83,.06) !important;
}

.month-navigation:hover {
    background:
        linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    transform: translateY(-2px) scale(1.03) !important;
}

/* Event items and badges */
.calendar-event-item,
.status-badge {
    border-radius: 10px !important;
    font-weight: 900 !important;
    box-shadow: 0 6px 14px rgba(15,23,42,.06) !important;
}

.event-class,
.event-upcoming {
    background: rgba(35,76,106,.12) !important;
    color: #1B3C53 !important;
    border-left-color: #234C6A !important;
}

.event-completed {
    background: rgba(16,185,129,.14) !important;
    color: #047857 !important;
    border-left-color: #059669 !important;
}

.event-cancelled {
    background: rgba(239,68,68,.14) !important;
    color: #b91c1c !important;
    border-left-color: #dc2626 !important;
}

/* List tabs / cards below calendar */
.tab-btn {
    border-radius: 14px 14px 0 0 !important;
    transition: all .22s ease !important;
}

.tab-btn:hover {
    transform: translateY(-2px) !important;
}

.schedule-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.06), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.35px solid rgba(210,193,182,.66) !important;
    border-left: 0 !important;
    box-shadow: 0 12px 28px rgba(27,60,83,.075) !important;
}

.schedule-card:hover {
    border-color: rgba(35,76,106,.40) !important;
    box-shadow: 0 20px 40px rgba(27,60,83,.13) !important;
}

.schedule-card::before {
    width: 5px !important;
}

.schedule-card.completed::before,
.schedule-card.today::before {
    background: #059669 !important;
}

.schedule-card.cancelled::before {
    background: #dc2626 !important;
}

.schedule-card.upcoming::before,
.schedule-card.class::before {
    background: #234C6A !important;
}

/* Tables */
table thead tr {
    background: linear-gradient(90deg, #EEF3F6, #F6F1ED) !important;
}

table thead th {
    color: #1B3C53 !important;
    -webkit-text-fill-color: #1B3C53 !important;
    font-weight: 1000 !important;
}

table tbody tr:hover {
    background: linear-gradient(90deg, rgba(238,243,246,.92), rgba(246,241,237,.82)) !important;
}

/* Popup theme */
.event-popup .gradient-bg {
    background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
}

@media (max-width: 768px) {
    .hero-shell,
    .feature-shell,
    .glass-card {
        border-radius: 20px !important;
    }

    .section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card {
        min-height: 116px !important;
    }

    .section-kicker + .grid.grid-cols-2.sm\:grid-cols-4 > .glass-card .rounded-full {
        width: 46px !important;
        height: 46px !important;
        min-width: 46px !important;
        min-height: 46px !important;
    }
}

</style>
</head>
<body class="min-h-screen relative overflow-x-hidden">
    <!-- Sidebar -->
    <?php include '../t_sidebar.php'; ?>
    
    <!-- Mobile Header -->
    <div class="lg:hidden sticky top-0 z-40 bg-white shadow-md">
        <div class="px-4 py-3 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <button id="mobileSidebarToggle" class="p-2 text-gray-700">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div>
                    <h1 class="text-lg font-bold text-gradient">Schedule Management</h1>
                    <p class="text-xs text-gray-600 truncate">Trainer Panel</p>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <a href="../dashboard/dashboard.php" class="text-sm text-blue-600 hover:underline flex items-center space-x-1 transition-colors">
                    <i class="fas fa-tachometer-alt"></i>
                </a>
                <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold">
                    <?php echo isset($trainer['name']) ? strtoupper(substr($trainer['name'], 0, 1)) : 'T'; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="ml-0 lg:ml-64 min-h-screen transition-all duration-300" id="main-content">
        <!-- Desktop Header -->
        <header class="hidden lg:block bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center space-x-4">
                <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                    <i class="fas fa-calendar-alt text-blue-500"></i>
                    <span>Schedule Management</span>
                </h1>
            </div>
            <div class="flex items-center space-x-4">
                <a href="../dashboard/dashboard.php" class="text-sm text-blue-600 hover:underline flex items-center space-x-1 transition-colors">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </div>
        </header>

        <div class="p-3 sm:p-4 md:p-6">
            <!-- Batch Selector -->
            <div class="glass-card feature-shell feature-selector p-4 sm:p-6 mb-4 sm:mb-6 fade-in">
                <div class="section-kicker"><i class="fas fa-layer-group"></i> Batch Selector</div>
                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                    <h2 class="text-lg font-semibold text-gray-800">Select Batch</h2>
                    <div class="mt-2 md:mt-0">
                        <span class="text-sm text-gray-600">Total Batches: <?php echo count($batches); ?></span>
                    </div>
                </div>
                
                <div class="flex flex-wrap gap-2 sm:gap-3">
                    <?php foreach($batches as $batch_item): ?>
                        <a href="schedule.php?batch_id=<?php echo $batch_item['batch_id']; ?>&month=<?php echo $current_month; ?>&year=<?php echo $current_year; ?>" 
                           class="px-3 py-2 sm:px-4 sm:py-2 rounded-lg border-2 transition-all duration-300 ripple smooth-transition text-sm sm:text-base <?php echo $selected_batch_id == $batch_item['batch_id'] ? 'bg-blue-50 border-blue-500 text-blue-700 font-medium glow-effect' : 'bg-white border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600'; ?>">
                            <i class="fas fa-users mr-1 sm:mr-2"></i>
                            <?php echo htmlspecialchars($batch_item['batch_name']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if($selected_batch_id && isset($batch) && $batch): ?>
                <!-- Batch Header -->
                <div class="hero-shell mb-4 sm:mb-6 fade-in bounce-in">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div class="flex-1 min-w-0">
                            <h2 class="text-xl sm:text-2xl font-bold truncate"><?php echo htmlspecialchars($batch['batch_name']); ?></h2>
                            <p class="text-blue-100 mt-1 text-sm sm:text-base truncate"><?php echo htmlspecialchars($batch['batch_id']); ?></p>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <span class="px-2 py-1 sm:px-3 sm:py-1 bg-white/20 rounded-full text-xs sm:text-sm backdrop-blur-sm truncate">
                                    <i class="far fa-calendar mr-1"></i>
                                    <?php echo date('M j, Y', strtotime($batch['start_date'])) . ' - ' . date('M j, Y', strtotime($batch['end_date'])); ?>
                                </span>
                                <span class="px-2 py-1 sm:px-3 sm:py-1 bg-white/20 rounded-full text-xs sm:text-sm backdrop-blur-sm">
                                    <i class="far fa-clock mr-1"></i>
                                    <?php echo htmlspecialchars($batch['time_slot']); ?>
                                </span>
                                <span class="px-2 py-1 sm:px-3 sm:py-1 bg-white/20 rounded-full text-xs sm:text-sm backdrop-blur-sm">
                                    <i class="fas fa-users mr-1"></i>
                                    <?php echo htmlspecialchars($batch['student_count']) . '/' . htmlspecialchars($batch['max_students']); ?> Students
                                </span>
                                <span class="px-2 py-1 sm:px-3 sm:py-1 bg-white/20 rounded-full text-xs sm:text-sm backdrop-blur-sm">
                                    <i class="fas fa-laptop mr-1"></i>
                                    <?php echo ucfirst($batch['mode']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <span class="status-badge <?php echo $batch['status']; ?> text-xs sm:text-sm">
                                <?php echo ucfirst($batch['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <div class="section-kicker"><i class="fas fa-chart-pie"></i> Schedule Snapshot</div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-6 mb-4 sm:mb-6">
                    <div class="glass-card p-3 sm:p-6 text-center hover-lift">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-2 sm:mb-3 pulse-animation">
                            <i class="fas fa-calendar-day text-blue-600 text-lg sm:text-xl"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-800"><?php echo $total_classes ?? 0; ?></h3>
                        <p class="text-gray-600 text-xs sm:text-sm">Total Classes</p>
                    </div>
                    
                    <div class="glass-card p-3 sm:p-6 text-center hover-lift">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-2 sm:mb-3 pulse-animation">
                            <i class="fas fa-calendar-plus text-purple-600 text-lg sm:text-xl"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-800"><?php echo $upcoming_count ?? 0; ?></h3>
                        <p class="text-gray-600 text-xs sm:text-sm">Upcoming Classes</p>
                    </div>
                    
                    <div class="glass-card p-3 sm:p-6 text-center hover-lift">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-2 sm:mb-3 pulse-animation">
                            <i class="fas fa-check-circle text-green-600 text-lg sm:text-xl"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-800"><?php echo $completed_count ?? 0; ?></h3>
                        <p class="text-gray-600 text-xs sm:text-sm">Completed Classes</p>
                    </div>
                    
                    <div class="glass-card p-3 sm:p-6 text-center hover-lift">
                        <div class="w-10 h-10 sm:w-12 sm:h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-2 sm:mb-3 pulse-animation">
                            <i class="fas fa-times-circle text-red-600 text-lg sm:text-xl"></i>
                        </div>
                        <h3 class="text-xl sm:text-2xl font-bold text-gray-800"><?php echo $cancelled_count ?? 0; ?></h3>
                        <p class="text-gray-600 text-xs sm:text-sm">Cancelled Classes</p>
                    </div>
                </div>

                <!-- Enhanced Calendar View -->
                <div class="glass-card feature-shell feature-calendar mb-4 sm:mb-6 fade-in">
                    <div class="section-kicker m-4 sm:m-6 mb-0"><i class="fas fa-calendar"></i> Calendar Board</div>
                    <div class="p-4 sm:p-6 border-b border-gray-200">
                        <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">
                            <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                <i class="fas fa-calendar text-purple-500 mr-2"></i>
                                Calendar View - <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?>
                            </h3>
                            <div class="flex space-x-2">
                                <a href="?batch_id=<?php echo $selected_batch_id; ?>&month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" 
                                   class="month-navigation px-2 py-1 sm:px-3 sm:py-1 border border-gray-300 rounded-lg text-xs sm:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 smooth-transition">
                                    <i class="fas fa-chevron-left"></i> <span class="hidden xs:inline">Prev</span>
                                </a>
                                <a href="?batch_id=<?php echo $selected_batch_id; ?>&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" 
                                   class="month-navigation px-2 py-1 sm:px-3 sm:py-1 border border-gray-300 rounded-lg text-xs sm:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 smooth-transition">
                                    Today
                                </a>
                                <a href="?batch_id=<?php echo $selected_batch_id; ?>&month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" 
                                   class="month-navigation px-2 py-1 sm:px-3 sm:py-1 border border-gray-300 rounded-lg text-xs sm:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 smooth-transition">
                                    <span class="hidden xs:inline">Next</span> <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 sm:p-6">
                        <!-- Weekday headers -->
                        <div class="grid grid-cols-7 gap-1 sm:gap-2 mb-1 sm:mb-2">
                            <?php 
                            $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                            foreach($weekdays as $day): 
                            ?>
                                <div class="text-center font-semibold text-gray-600 py-2 text-xs sm:text-sm uppercase smooth-transition">
                                    <?php echo $day; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Calendar grid -->
                        <div class="grid grid-cols-7 gap-1 sm:gap-2">
                            <?php foreach($calendar_days as $index => $day): ?>
                                <div class="calendar-day p-1 sm:p-3 border border-gray-200 rounded-lg smooth-transition
                                            <?php echo $day['is_today'] ? 'today' : ''; ?>
                                            <?php echo $day['has_events'] ? 'has-events' : ''; ?>
                                            <?php echo $day['is_sunday'] ? 'sunday' : ''; ?>
                                            <?php echo $day['is_saturday'] ? 'saturday' : ''; ?>
                                            <?php echo $day['has_cancelled'] ? 'cancelled' : ''; ?>
                                            <?php echo $day['has_events'] ? 'has-class' : ''; ?>">
                                    <?php if($day['day'] !== ''): ?>
                                        <div class="flex justify-between items-start mb-1 sm:mb-2">
                                            <div class="flex items-center">
                                                <div class="text-xs sm:text-sm font-medium <?php echo $day['is_today'] ? 'text-blue-600' : ($day['is_sunday'] ? 'text-pink-600' : ($day['is_saturday'] ? 'text-purple-600' : 'text-gray-700')); ?>">
                                                    <?php echo $day['day']; ?>
                                                </div>
                                                <?php if($day['is_sunday']): ?>
                                                    <span class="ml-1 px-1 py-0.5 sm:ml-2 sm:px-2 sm:py-0.5 bg-pink-100 text-pink-800 text-xs font-medium rounded-full smooth-transition hidden sm:inline">Sun</span>
                                                <?php elseif($day['is_saturday']): ?>
                                                    <span class="ml-1 px-1 py-0.5 sm:ml-2 sm:px-2 sm:py-0.5 bg-purple-100 text-purple-800 text-xs font-medium rounded-full smooth-transition hidden sm:inline">Sat</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if($day['is_today']): ?>
                                                <span class="px-1 py-0.5 sm:px-2 sm:py-0.5 bg-blue-100 text-blue-800 text-xs font-medium rounded-full smooth-transition">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Events for this day -->
                                        <?php if(!empty($day['events'])): ?>
                                            <div class="space-y-1 max-h-16 sm:max-h-20 overflow-y-auto scrollbar-thin">
                                                <?php foreach($day['events'] as $event): 
                                                    $status = '';
                                                    if ($event['is_cancelled']) {
                                                        $status = 'cancelled';
                                                    } elseif ($event['schedule_date'] < date('Y-m-d')) {
                                                        $status = 'completed';
                                                    } elseif ($event['schedule_date'] == date('Y-m-d')) {
                                                        $status = 'today';
                                                    } else {
                                                        $status = 'upcoming';
                                                    }
                                                ?>
                                                    <div class="calendar-event-item smooth-transition
                                                        <?php echo 'event-' . $status; ?>
                                                        <?php echo $day['is_sunday'] ? 'event-sunday' : ''; ?>" 
                                                         onclick="showEventDetails(<?php echo htmlspecialchars(json_encode($event)); ?>)">
                                                        <div class="flex items-center">
                                                            <span class="event-dot 
                                                                <?php echo 'dot-' . $status; ?>
                                                                <?php echo $day['is_sunday'] ? 'dot-sunday' : ''; ?>"></span>
                                                            <span class="truncate" title="<?php echo htmlspecialchars($event['topic']); ?>">
                                                                <?php echo htmlspecialchars($event['topic']); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Calendar Legend -->
                        <div class="flex flex-wrap items-center justify-center mt-4 sm:mt-6 gap-2 sm:gap-4 text-xs sm:text-sm text-gray-600">
                            <div class="flex items-center smooth-transition">
                                <div class="w-2 h-2 sm:w-3 sm:h-3 bg-blue-500 rounded-full mr-1 sm:mr-2"></div>
                                <span>Class</span>
                            </div>
                            <div class="flex items-center smooth-transition">
                                <div class="w-2 h-2 sm:w-3 sm:h-3 bg-green-500 rounded-full mr-1 sm:mr-2"></div>
                                <span>Completed</span>
                            </div>
                            <div class="flex items-center smooth-transition">
                                <div class="w-2 h-2 sm:w-3 sm:h-3 bg-red-500 rounded-full mr-1 sm:mr-2"></div>
                                <span>Cancelled</span>
                            </div>
                            <div class="flex items-center smooth-transition">
                                <div class="w-2 h-2 sm:w-3 sm:h-3 bg-purple-500 rounded-full mr-1 sm:mr-2"></div>
                                <span>Upcoming</span>
                            </div>
                            <div class="flex items-center smooth-transition">
                                <div class="w-2 h-2 sm:w-3 sm:h-3 bg-pink-500 rounded-full mr-1 sm:mr-2"></div>
                                <span>Sunday</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabs for Different Views -->
                <div class="glass-card feature-shell feature-detail mb-4 sm:mb-6 p-4 sm:p-5">
                    <div class="section-kicker"><i class="fas fa-table-list"></i> Schedule Lists</div>
                    <div class="border-b border-gray-200 overflow-x-auto">
                        <nav class="-mb-px flex space-x-4 sm:space-x-8 min-w-max sm:min-w-0">
                            <button id="tab-upcoming" class="tab-btn py-2 sm:py-3 px-1 border-b-2 font-medium text-xs sm:text-sm border-blue-500 text-blue-600 smooth-transition whitespace-nowrap" onclick="switchTab('upcoming')">
                                <i class="fas fa-clock mr-1 sm:mr-2"></i>Upcoming
                                <span class="ml-1 sm:ml-2 px-1 py-0.5 sm:px-2 sm:py-0.5 text-xs bg-blue-100 text-blue-800 rounded-full"><?php echo count($upcoming_classes); ?></span>
                            </button>
                            <button id="tab-month" class="tab-btn py-2 sm:py-3 px-1 border-b-2 font-medium text-xs sm:text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 smooth-transition whitespace-nowrap" onclick="switchTab('month')">
                                <i class="fas fa-calendar-alt mr-1 sm:mr-2"></i>This Month
                                <span class="ml-1 sm:ml-2 px-1 py-0.5 sm:px-2 sm:py-0.5 text-xs bg-purple-100 text-purple-800 rounded-full"><?php echo count($month_schedule); ?></span>
                            </button>
                            <button id="tab-all" class="tab-btn py-2 sm:py-3 px-1 border-b-2 font-medium text-xs sm:text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 smooth-transition whitespace-nowrap" onclick="switchTab('all')">
                                <i class="fas fa-list-alt mr-1 sm:mr-2"></i>All Schedule
                                <span class="ml-1 sm:ml-2 px-1 py-0.5 sm:px-2 sm:py-0.5 text-xs bg-green-100 text-green-800 rounded-full"><?php echo $total_classes ?? 0; ?></span>
                            </button>
                        </nav>
                    </div>
                </div>

                <!-- Upcoming Classes View -->
                <div id="view-upcoming" class="tab-content fade-in">
                    <div class="glass-card feature-shell feature-records">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">
                                <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                    <i class="fas fa-clock text-blue-500 mr-2"></i>
                                    Upcoming Classes (Next 7 Days)
                                </h3>
                                <span class="px-2 py-1 sm:px-3 sm:py-1 bg-blue-100 text-blue-800 rounded-full text-xs sm:text-sm font-medium">
                                    <?php echo count($upcoming_classes); ?> classes
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-4 sm:p-6">
                            <?php if(count($upcoming_classes) > 0): ?>
                                <div class="space-y-3 sm:space-y-4">
                                    <?php foreach($upcoming_classes as $class): 
                                        $status = '';
                                        if ($class['is_cancelled']) {
                                            $status = 'cancelled';
                                        } elseif ($class['schedule_date'] == date('Y-m-d')) {
                                            $status = 'today';
                                        } else {
                                            $status = 'upcoming';
                                        }
                                    ?>
                                        <div class="schedule-card <?php echo $status; ?> bg-white p-3 sm:p-4 rounded-lg shadow-sm hover-lift smooth-transition">
                                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 sm:gap-0">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex flex-wrap items-center mb-2 gap-2">
                                                        <h4 class="font-medium text-gray-900 truncate text-sm sm:text-base"><?php echo htmlspecialchars($class['topic']); ?></h4>
                                                        <?php if($class['is_cancelled']): ?>
                                                            <span class="status-badge cancelled text-xs">Cancelled</span>
                                                        <?php elseif($class['schedule_date'] == date('Y-m-d')): ?>
                                                            <span class="status-badge today text-xs">Today</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <div class="flex flex-col xs:flex-row xs:flex-wrap gap-1 sm:gap-4 text-xs sm:text-sm text-gray-600">
                                                        <span class="flex items-center">
                                                            <i class="far fa-calendar mr-1 sm:mr-2"></i>
                                                            <?php echo date('D, M j, Y', strtotime($class['schedule_date'])); ?>
                                                        </span>
                                                        <span class="flex items-center">
                                                            <i class="far fa-clock mr-1 sm:mr-2"></i>
                                                            <?php echo date('g:i A', strtotime($class['start_time'])) . ' - ' . date('g:i A', strtotime($class['end_time'])); ?>
                                                        </span>
                                                    </div>
                                                    
                                                    <?php if(!empty($class['description'])): ?>
                                                        <p class="text-gray-500 text-xs sm:text-sm mt-2 line-clamp-2"><?php echo htmlspecialchars($class['description']); ?></p>
                                                    <?php endif; ?>
                                                    
                                                    <?php if(!empty($class['cancellation_reason'])): ?>
                                                        <p class="text-red-500 text-xs sm:text-sm mt-2">
                                                            <i class="fas fa-exclamation-circle mr-1"></i>
                                                            <?php echo htmlspecialchars($class['cancellation_reason']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="mt-3 md:mt-0 flex space-x-2">
                                                    <button onclick="showEventDetails(<?php echo htmlspecialchars(json_encode($class)); ?>)" 
                                                           class="px-2 py-1 sm:px-3 sm:py-2 bg-blue-100 text-blue-700 rounded-lg text-xs sm:text-sm font-medium hover:bg-blue-200 transition-colors ripple flex items-center smooth-transition whitespace-nowrap">
                                                        <i class="fas fa-eye mr-1"></i> <span class="hidden xs:inline">View</span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-6 sm:py-8">
                                    <i class="fas fa-calendar-times text-gray-400 text-3xl sm:text-4xl mb-3"></i>
                                    <p class="text-gray-500 text-sm sm:text-base">No upcoming classes in the next 7 days</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- This Month View -->
                <div id="view-month" class="tab-content hidden">
                    <div class="glass-card feature-shell feature-records">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">
                                <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                    <i class="fas fa-calendar-alt text-purple-500 mr-2"></i>
                                    This Month's Schedule - <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?>
                                </h3>
                                <span class="px-2 py-1 sm:px-3 sm:py-1 bg-purple-100 text-purple-800 rounded-full text-xs sm:text-sm font-medium">
                                    <?php echo count($month_schedule); ?> classes
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-4 sm:p-6">
                            <?php if(count($month_schedule) > 0): ?>
                                <?php 
                                $current_date = null;
                                $has_classes = false;
                                
                                // Sort by date
                                usort($month_schedule, function($a, $b) {
                                    return strtotime($a['schedule_date']) - strtotime($b['schedule_date']);
                                });
                                
                                foreach($month_schedule as $class): 
                                    $class_date = $class['schedule_date'];
                                    $display_date = date('l, F j, Y', strtotime($class_date));
                                    
                                    if ($class_date !== $current_date):
                                        $current_date = $class_date;
                                        if ($has_classes): ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="border-l-4 <?php echo $class['is_cancelled'] ? 'border-red-400' : 'border-purple-400'; ?> pl-2 sm:pl-4 mb-3 sm:mb-4">
                                            <h4 class="text-base sm:text-lg font-semibold text-gray-800 mb-1 sm:mb-2">
                                                <?php echo $display_date; ?>
                                                <?php if($class_date == date('Y-m-d')): ?>
                                                    <span class="status-badge today ml-2 text-xs">Today</span>
                                                <?php endif; ?>
                                            </h4>
                                        </div>
                                        <div class="mb-3 sm:mb-4 ml-2 sm:ml-4">
                                    <?php 
                                    $has_classes = true;
                                    endif; 
                                    
                                    $status = '';
                                    if ($class['is_cancelled']) {
                                        $status = 'cancelled';
                                    } elseif ($class['schedule_date'] < date('Y-m-d')) {
                                        $status = 'completed';
                                    } elseif ($class['schedule_date'] == date('Y-m-d')) {
                                        $status = 'today';
                                    } else {
                                        $status = 'upcoming';
                                    }
                                ?>
                                
                                <div class="schedule-card <?php echo $status; ?> bg-white p-3 sm:p-4 rounded-lg shadow-sm hover-lift mb-2 sm:mb-3 smooth-transition">
                                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 sm:gap-0">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex flex-wrap items-center mb-2 gap-2">
                                                <h4 class="font-medium text-gray-900 truncate text-sm sm:text-base"><?php echo htmlspecialchars($class['topic']); ?></h4>
                                                <span class="status-badge <?php echo $status; ?> text-xs">
                                                    <?php echo ucfirst($status); ?>
                                                </span>
                                            </div>
                                            
                                            <div class="flex flex-wrap gap-2 sm:gap-4 text-xs sm:text-sm text-gray-600">
                                                <span class="flex items-center">
                                                    <i class="far fa-clock mr-1 sm:mr-2"></i>
                                                    <?php echo date('g:i A', strtotime($class['start_time'])) . ' - ' . date('g:i A', strtotime($class['end_time'])); ?>
                                                </span>
                                            </div>
                                            
                                            <?php if(!empty($class['description'])): ?>
                                                <p class="text-gray-500 text-xs sm:text-sm mt-2 line-clamp-2"><?php echo htmlspecialchars($class['description']); ?></p>
                                            <?php endif; ?>
                                            
                                            <?php if(!empty($class['cancellation_reason'])): ?>
                                                <p class="text-red-500 text-xs sm:text-sm mt-2">
                                                    <i class="fas fa-exclamation-circle mr-1"></i>
                                                    <?php echo htmlspecialchars($class['cancellation_reason']); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="mt-3 md:mt-0 flex space-x-2">
                                            <button onclick="showEventDetails(<?php echo htmlspecialchars(json_encode($class)); ?>)" 
                                                   class="px-2 py-1 sm:px-3 sm:py-2 bg-blue-100 text-blue-700 rounded-lg text-xs sm:text-sm font-medium hover:bg-blue-200 transition-colors ripple flex items-center smooth-transition whitespace-nowrap">
                                                <i class="fas fa-eye mr-1"></i> <span class="hidden xs:inline">View</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if($has_classes): ?>
                                    </div>
                                <?php endif; ?>
                                
                            <?php else: ?>
                                <div class="text-center py-6 sm:py-8">
                                    <i class="fas fa-calendar-plus text-gray-400 text-3xl sm:text-4xl mb-3"></i>
                                    <p class="text-gray-500 text-sm sm:text-base">No classes scheduled for this month</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- All Schedule View -->
                <div id="view-all" class="tab-content hidden">
                    <div class="glass-card feature-shell feature-records">
                        <div class="p-4 sm:p-6 border-b border-gray-200">
                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2">
                                <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                                    <i class="fas fa-list-alt text-green-500 mr-2"></i>
                                    Complete Schedule
                                </h3>
                                <span class="px-2 py-1 sm:px-3 sm:py-1 bg-green-100 text-green-800 rounded-full text-xs sm:text-sm font-medium">
                                    <?php echo $total_classes ?? 0; ?> total classes
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-4 sm:p-6">
                            <?php if(isset($all_schedule) && count($all_schedule) > 0): ?>
                                <div class="overflow-x-auto -mx-4 sm:mx-0">
                                    <table class="w-full min-w-max">
                                        <thead>
                                            <tr class="bg-gray-50">
                                                <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                                <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                                <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Topic</th>
                                                <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-200">
                                            <?php foreach($all_schedule as $class): 
                                                $status = '';
                                                if ($class['is_cancelled']) {
                                                    $status = 'cancelled';
                                                } elseif ($class['schedule_date'] < date('Y-m-d')) {
                                                    $status = 'completed';
                                                } elseif ($class['schedule_date'] == date('Y-m-d')) {
                                                    $status = 'today';
                                                } else {
                                                    $status = 'upcoming';
                                                }
                                            ?>
                                                <tr class="hover:bg-gray-50 transition-colors smooth-transition">
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap">
                                                        <span class="text-xs sm:text-sm font-medium text-gray-900">
                                                            <?php echo date('M j, Y', strtotime($class['schedule_date'])); ?>
                                                        </span>
                                                        <br>
                                                        <span class="text-xs text-gray-500">
                                                            <?php echo date('D', strtotime($class['schedule_date'])); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap text-xs sm:text-sm text-gray-500">
                                                        <?php echo date('g:i A', strtotime($class['start_time'])) . ' - ' . date('g:i A', strtotime($class['end_time'])); ?>
                                                    </td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3">
                                                        <div class="text-xs sm:text-sm font-medium text-gray-900 truncate max-w-[150px] sm:max-w-xs"><?php echo htmlspecialchars($class['topic']); ?></div>
                                                        <?php if(!empty($class['description'])): ?>
                                                            <div class="text-xs text-gray-500 truncate max-w-[150px] sm:max-w-xs"><?php echo htmlspecialchars($class['description']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap">
                                                        <span class="status-badge <?php echo $status; ?> text-xs">
                                                            <?php echo ucfirst($status); ?>
                                                        </span>
                                                    </td>
                                                    <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap text-xs sm:text-sm font-medium">
                                                        <div class="flex space-x-1 sm:space-x-2">
                                                            <button onclick="showEventDetails(<?php echo htmlspecialchars(json_encode($class)); ?>)" 
                                                                   class="text-blue-600 hover:text-blue-900 transition-colors ripple p-1 sm:p-2 rounded smooth-transition"
                                                                   title="View Details">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-6 sm:py-8">
                                    <i class="fas fa-calendar-plus text-gray-400 text-3xl sm:text-4xl mb-3"></i>
                                    <p class="text-gray-500 text-sm sm:text-base">No classes scheduled for this batch</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            <?php elseif($selected_batch_id && (!isset($batch) || !$batch)): ?>
                <div class="glass-card p-6 sm:p-8 text-center fade-in">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-3xl sm:text-4xl mb-3 sm:mb-4"></i>
                    <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-2">Batch Not Found</h3>
                    <p class="text-gray-600 text-sm sm:text-base mb-3 sm:mb-4">The selected batch doesn't exist or you don't have access to it.</p>
                    <a href="schedule.php" class="px-3 sm:px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors ripple smooth-transition text-sm sm:text-base">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Batches
                    </a>
                </div>
            <?php else: ?>
                <div class="glass-card p-6 sm:p-8 text-center fade-in">
                    <i class="fas fa-users-slash text-gray-400 text-3xl sm:text-4xl mb-3 sm:mb-4"></i>
                    <h3 class="text-lg sm:text-xl font-semibold text-gray-800 mb-2">No Batches Assigned</h3>
                    <p class="text-gray-600 text-sm sm:text-base">You don't have any batches assigned to you yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Event Details Popup -->
    <div id="eventPopup" class="event-popup hidden">
        <div class="bg-white rounded-xl shadow-2xl p-4 sm:p-6">
            <div class="flex justify-between items-center mb-3 sm:mb-4">
                <h3 id="popupTitle" class="text-lg sm:text-xl font-semibold text-gray-800 truncate"></h3>
            </div>
            <div id="popupContent" class="space-y-2 sm:space-y-3 text-sm sm:text-base"></div>
            <div class="mt-4 sm:mt-6 flex justify-end">
                <button onclick="closeEventPopup()" class="px-3 sm:px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors smooth-transition text-sm sm:text-base">
                    Close
                </button>
            </div>
        </div>
    </div>

    <div id="overlay" class="overlay hidden"></div>

    <script>
        // Initialize sidebar functionality
        document.addEventListener('DOMContentLoaded', function() {
            const mobileToggle = document.getElementById('mobileSidebarToggle');
            const sidebar = document.querySelector('aside');
            const mainContent = document.getElementById('main-content');
            const body = document.body;
            
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    body.classList.toggle('sidebar-open');
                });
            }
            
            // Close sidebar when clicking on a link (mobile)
            document.querySelectorAll('nav a').forEach(link => {
                link.addEventListener('click', function() {
                    if (window.innerWidth < 1024) {
                        sidebar.classList.add('-translate-x-full');
                        body.classList.remove('sidebar-open');
                    }
                });
            });
            
            // Handle window resize
            window.addEventListener('resize', function() {
                if (window.innerWidth >= 1024) {
                    sidebar.classList.remove('-translate-x-full');
                    body.classList.remove('sidebar-open');
                }
            });

            // Ripple effect
            const rippleButtons = document.querySelectorAll('.ripple');
            rippleButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const rect = this.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    
                    const ripple = document.createElement('span');
                    ripple.className = 'ripple-effect';
                    ripple.style.left = `${x}px`;
                    ripple.style.top = `${y}px`;
                    
                    this.appendChild(ripple);
                    
                    setTimeout(() => {
                        ripple.remove();
                    }, 600);
                });
            });
            
            // Add animation to calendar days
            const calendarDays = document.querySelectorAll('.calendar-day');
            calendarDays.forEach((day, index) => {
                if (day.querySelector('.calendar-event-item')) {
                    day.style.animationDelay = `${index * 0.05}s`;
                    day.classList.add('fade-in');
                }
            });
            
            // Initialize with upcoming tab
            switchTab('upcoming');
        });

        // Tab switching functionality
        function switchTab(tab) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
                content.classList.remove('fade-in');
            });
            
            // Remove active styles from all tabs
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('border-blue-500', 'text-blue-600');
                btn.classList.add('border-transparent', 'text-gray-500');
            });
            
            // Show selected tab content
            const selectedContent = document.getElementById('view-' + tab);
            selectedContent.classList.remove('hidden');
            selectedContent.classList.add('fade-in');
            
            // Style active tab
            const selectedTab = document.getElementById('tab-' + tab);
            selectedTab.classList.remove('border-transparent', 'text-gray-500');
            selectedTab.classList.add('border-blue-500', 'text-blue-600');
        }

        // Show event details in popup
        function showEventDetails(eventData) {
            const popup = document.getElementById('eventPopup');
            const overlay = document.getElementById('overlay');
            const title = document.getElementById('popupTitle');
            const content = document.getElementById('popupContent');
            
            // Set title
            title.textContent = eventData.topic;
            
            // Determine status
            let status = '';
            let statusHtml = '';
            if (eventData.is_cancelled) {
                status = 'cancelled';
                statusHtml = `<div class="p-2 bg-red-50 border-l-4 border-red-500 rounded-r mb-3">
                    <div class="flex items-center">
                        <i class="fas fa-times-circle text-red-500 mr-2"></i>
                        <span class="font-medium text-red-700">Class Cancelled</span>
                    </div>
                </div>`;
            } else if (eventData.schedule_date < new Date().toISOString().split('T')[0]) {
                status = 'completed';
                statusHtml = `<div class="p-2 bg-green-50 border-l-4 border-green-500 rounded-r mb-3">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                        <span class="font-medium text-green-700">Class Completed</span>
                    </div>
                </div>`;
            } else if (eventData.schedule_date === new Date().toISOString().split('T')[0]) {
                status = 'today';
                statusHtml = `<div class="p-2 bg-blue-50 border-l-4 border-blue-500 rounded-r mb-3">
                    <div class="flex items-center">
                        <i class="fas fa-calendar-day text-blue-500 mr-2"></i>
                        <span class="font-medium text-blue-700">Today's Class</span>
                    </div>
                </div>`;
            } else {
                status = 'upcoming';
                statusHtml = `<div class="p-2 bg-purple-50 border-l-4 border-purple-500 rounded-r mb-3">
                    <div class="flex items-center">
                        <i class="fas fa-calendar-plus text-purple-500 mr-2"></i>
                        <span class="font-medium text-purple-700">Upcoming Class</span>
                    </div>
                </div>`;
            }
            
            // Check if it's Sunday
            const dayOfWeek = new Date(eventData.schedule_date).getDay(); // 0=Sunday, 6=Saturday
            let dayTypeHtml = '';
            if (dayOfWeek === 0) {
                dayTypeHtml = `<div class="p-2 bg-pink-50 border-l-4 border-pink-500 rounded-r mb-3">
                    <div class="flex items-center">
                        <i class="fas fa-umbrella-beach text-pink-500 mr-2"></i>
                        <span class="font-medium text-pink-700">Sunday</span>
                    </div>
                </div>`;
            }
            
            content.innerHTML = statusHtml + dayTypeHtml + `
                <div class="space-y-2">
                    <div class="flex items-center text-sm text-gray-600">
                        <i class="far fa-calendar mr-2 w-5"></i>
                        <span>${new Date(eventData.schedule_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
                    </div>
                    <div class="flex items-center text-sm text-gray-600">
                        <i class="far fa-clock mr-2 w-5"></i>
                        <span>${formatTime(eventData.start_time)} - ${formatTime(eventData.end_time)}</span>
                    </div>
                    ${eventData.description ? `
                    <div class="mt-3">
                        <h4 class="font-medium text-gray-700 mb-1">Description:</h4>
                        <p class="text-sm text-gray-600">${eventData.description}</p>
                    </div>` : ''}
                    ${eventData.cancellation_reason ? `
                    <div class="mt-3">
                        <h4 class="font-medium text-red-700 mb-1">Cancellation Reason:</h4>
                        <p class="text-sm text-red-600">${eventData.cancellation_reason}</p>
                    </div>` : ''}
                    ${eventData.is_back_schedule ? `
                    <div class="mt-3">
                        <h4 class="font-medium text-amber-700 mb-1">Special Schedule:</h4>
                        <p class="text-sm text-amber-600">This is a back-scheduled class</p>
                    </div>` : ''}
                </div>
            `;
            
            // Show popup and overlay
            popup.classList.remove('hidden');
            overlay.classList.remove('hidden');
            
            // Add animation to popup
            popup.style.animation = 'popupFadeIn 0.3s ease';
        }

        // Close event popup
        function closeEventPopup() {
            const popup = document.getElementById('eventPopup');
            const overlay = document.getElementById('overlay');
            
            // Add fade out animation
            popup.style.animation = 'none';
            void popup.offsetWidth; // Trigger reflow
            popup.style.animation = 'popupFadeIn 0.3s ease reverse';
            
            setTimeout(() => {
                popup.classList.add('hidden');
                overlay.classList.add('hidden');
            }, 300);
        }

        // Format time function
        function formatTime(timeString) {
            const time = new Date(`2000-01-01T${timeString}`);
            return time.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        }

        // Go to today in calendar
        function goToToday() {
            const todayElement = document.querySelector('.calendar-day.today');
            if (todayElement) {
                todayElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                // Add highlight animation
                todayElement.classList.add('glow-effect');
                setTimeout(() => todayElement.classList.remove('glow-effect'), 2000);
            }
        }
        
        // Touch-friendly interactions
        function initializeTouchInteractions() {
            // Add touch feedback to buttons
            document.querySelectorAll('button, .calendar-event-item').forEach(element => {
                element.addEventListener('touchstart', function() {
                    this.style.opacity = '0.7';
                });
                
                element.addEventListener('touchend', function() {
                    this.style.opacity = '';
                });
            });
            
            // Prevent zoom on double tap
            let lastTouchEnd = 0;
            document.addEventListener('touchend', function(event) {
                const now = (new Date()).getTime();
                if (now - lastTouchEnd <= 300) {
                    event.preventDefault();
                }
                lastTouchEnd = now;
            }, false);
        }
        
        // Initialize touch interactions
        initializeTouchInteractions();
    </script>
</body>
</html>