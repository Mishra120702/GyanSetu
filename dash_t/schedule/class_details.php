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
    header("Location: ../logout_t.php");
    exit;
}

require_once '../db_connection.php';

$class_id = isset($_GET['id']) ? $_GET['id'] : null;

if (!$class_id) {
    header("Location: schedule.php");
    exit();
}

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $trainer_id = $_SESSION['user_id'];
    
    // Fetch trainer details (consistent with schedule.php)
    $trainer_stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $trainer_stmt->execute([$trainer_id]);
    $trainer = $trainer_stmt->fetch(PDO::FETCH_ASSOC);

    // If no trainer row found, provide a safe fallback
    if ($trainer === false) {
        $trainer = [
            'id' => $trainer_id,
            'user_id' => $trainer_id,
            'name' => ''
        ];
    }
    
    // Get class details (consistent with schedule.php query structure)
    $stmt = $db->prepare("SELECT s.*, b.batch_id, b.batch_name, b.batch_mentor_id 
                         FROM schedule s 
                         JOIN batches b ON s.batch_id = b.batch_id 
                         WHERE s.id = ? AND b.batch_mentor_id = ?");
    $stmt->execute([$class_id, $trainer['id']]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$class) {
        header("Location: schedule.php");
        exit();
    }
    
    // Get batch details for additional info (consistent with schedule.php)
    $batch_stmt = $db->prepare("
        SELECT b.*, 
               COUNT(DISTINCT s.student_id) as student_count
        FROM batches b 
        LEFT JOIN students s ON b.batch_id = s.batch_name 
        WHERE b.batch_id = ? AND b.batch_mentor_id = ? 
        GROUP BY b.batch_id
    ");
    $batch_stmt->execute([$class['batch_id'], $trainer['id']]);
    $batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get attendance for this class
    $attendance_stmt = $db->prepare("
        SELECT a.*, CONCAT(s.first_name, ' ', s.last_name) as student_name
        FROM attendance a 
        JOIN students s ON a.student_id = s.student_id 
        WHERE a.batch_id = ? AND a.date = ?
        ORDER BY s.first_name, s.last_name
    ");
    $attendance_stmt->execute([
        $class['batch_id'], 
        $class['schedule_date']
    ]);
    $attendance = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate attendance stats
    $total_students = count($attendance);
    $present_count = 0;
    $absent_count = 0;
    $camera_on_count = 0;
    
    foreach($attendance as $record) {
        if ($record['status'] == 'Present') {
            $present_count++;
            if ($record['camera_status'] == 'On') {
                $camera_on_count++;
            }
        } else {
            $absent_count++;
        }
    }
    
    $attendance_rate = $total_students > 0 ? round(($present_count / $total_students) * 100) : 0;
    $camera_rate = $present_count > 0 ? round(($camera_on_count / $present_count) * 100) : 0;
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Class Details | Trainer Panel | ASD Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.upcoming {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .status-badge.ongoing {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .status-badge.completed {
            background-color: #f3f4f6;
            color: #374151;
        }
        
        .status-badge.cancelled {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .attendance-present {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .attendance-absent {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .camera-on {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .camera-off {
            background-color: #f3f4f6;
            color: #6b7280;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 1rem;
                margin-bottom: 1.5rem;
            }
        }
        
        .stat-card {
            background: white;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            text-align: center;
            border-left: 4px solid;
        }
        
        @media (min-width: 640px) {
            .stat-card {
                padding: 1.5rem;
            }
        }
        
        .stat-card.present {
            border-left-color: #10b981;
        }
        
        .stat-card.absent {
            border-left-color: #ef4444;
        }
        
        .stat-card.attendance {
            border-left-color: #234C6A;
        }
        
        .stat-card.camera {
            border-left-color: #234C6A;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        @media (min-width: 640px) {
            .stat-number {
                font-size: 2rem;
            }
        }
        
        .stat-label {
            font-size: 0.75rem;
            color: #6b7280;
        }
        
        @media (min-width: 640px) {
            .stat-label {
                font-size: 0.875rem;
            }
        }
        
        .hover-lift {
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        .hover-lift:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .gradient-bg {
            background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
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
        }
        
        /* Tablet-specific styles */
        @media (min-width: 641px) and (max-width: 1023px) {
            .lg\:col-span-1, .lg\:col-span-2 {
                grid-column: span 2 !important;
            }
            
            .grid-cols-1 {
                grid-template-columns: 1fr !important;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 1024px) {
            button, 
            a[href] {
                min-height: 44px;
                min-width: 44px;
            }
        }
        
        /* Mobile sidebar spacing */
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
        
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .text-gradient {
            background: linear-gradient(90deg, #1B3C53 0%, #234C6A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
                    <h1 class="text-lg font-bold text-gradient">Class Details</h1>
                    <p class="text-xs text-gray-600 truncate">Trainer Panel</p>
                </div>
            </div>
            <div class="flex items-center space-x-2">
                <a href="schedule.php?batch_id=<?php echo $class['batch_id']; ?>" class="text-sm text-blue-600 hover:underline flex items-center space-x-1 transition-colors">
                    <i class="fas fa-arrow-left"></i>
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
                    <i class="fas fa-chalkboard-teacher text-blue-500"></i>
                    <span>Class Details</span>
                </h1>
            </div>
            <div class="flex items-center space-x-4">
                <a href="schedule.php?batch_id=<?php echo $class['batch_id']; ?>" class="text-sm text-blue-600 hover:underline flex items-center space-x-1 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                    <span>Back to Schedule</span>
                </a>
            </div>
        </header>

        <div class="p-3 sm:p-4 md:p-6">
            <!-- Batch Header -->
            <?php if($batch): ?>
            <div class="hero-shell mb-4 sm:mb-6 fade-in">
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
            <?php endif; ?>

            <!-- Class Header -->
            <div class="glass-card feature-shell feature-detail p-4 sm:p-6 mb-4 sm:mb-6 fade-in">
                <div class="section-kicker"><i class="fas fa-chalkboard-teacher"></i> Class Overview</div>
                <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 sm:gap-0">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center mb-3 sm:mb-4 gap-2">
                            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 truncate"><?php echo htmlspecialchars($class['topic']); ?></h2>
                            <?php
                            $status = '';
                            $current_date = date('Y-m-d');
                            $current_time = date('H:i:s');
                            
                            if ($class['is_cancelled']) {
                                $status = 'cancelled';
                            } elseif ($class['schedule_date'] < $current_date) {
                                $status = 'completed';
                            } elseif ($class['schedule_date'] == $current_date) {
                                // Check if current time is within class time
                                if ($current_time >= $class['start_time'] && $current_time <= $class['end_time']) {
                                    $status = 'ongoing';
                                } elseif ($current_time < $class['start_time']) {
                                    $status = 'upcoming';
                                } else {
                                    $status = 'completed';
                                }
                            } else {
                                $status = 'upcoming';
                            }
                            ?>
                            <span class="status-badge <?php echo $status; ?> text-xs sm:text-sm">
                                <?php echo ucfirst($status); ?>
                            </span>
                        </div>
                        
                        <div class="grid grid-cols-1 xs:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-3 sm:mb-4">
                            <div class="flex items-center">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                    <i class="fas fa-calendar-day text-blue-600 text-sm sm:text-base"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500">Date</p>
                                    <p class="font-medium text-gray-900 text-sm sm:text-base truncate"><?php echo date('D, M j, Y', strtotime($class['schedule_date'])); ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 bg-green-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                    <i class="fas fa-clock text-green-600 text-sm sm:text-base"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500">Time</p>
                                    <p class="font-medium text-gray-900 text-sm sm:text-base truncate"><?php echo date('g:i A', strtotime($class['start_time'])) . ' - ' . date('g:i A', strtotime($class['end_time'])); ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                    <i class="fas fa-users text-purple-600 text-sm sm:text-base"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500">Batch</p>
                                    <p class="font-medium text-gray-900 text-sm sm:text-base truncate"><?php echo htmlspecialchars($class['batch_name']); ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 bg-orange-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                    <i class="fas fa-chart-bar text-orange-600 text-sm sm:text-base"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-xs text-gray-500">Attendance</p>
                                    <p class="font-medium text-gray-900 text-sm sm:text-base"><?php echo $attendance_rate; ?>%</p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if(!empty($class['description'])): ?>
                            <div class="mb-3 sm:mb-4">
                                <h3 class="text-xs sm:text-sm font-medium text-gray-500 mb-1">Description</h3>
                                <p class="text-gray-700 text-sm sm:text-base"><?php echo htmlspecialchars($class['description']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if($class['is_cancelled'] && !empty($class['cancellation_reason'])): ?>
                            <div class="mb-3 sm:mb-4">
                                <h3 class="text-xs sm:text-sm font-medium text-red-500 mb-1">Cancellation Reason</h3>
                                <p class="text-red-600 text-sm sm:text-base"><?php echo htmlspecialchars($class['cancellation_reason']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Attendance Section -->
            <div class="glass-card feature-shell feature-records mb-4 sm:mb-6 fade-in">
                <div class="section-kicker m-4 sm:m-6 mb-0"><i class="fas fa-clipboard-check"></i> Attendance Snapshot</div>
                <div class="p-4 sm:p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-clipboard-check text-green-500 mr-2"></i>
                        Attendance Records
                    </h3>
                </div>
                
                <div class="p-4 sm:p-6">
                    <?php if($class['schedule_date'] <= date('Y-m-d')): ?>
                        <!-- Attendance Stats -->
                        <div class="stats-grid">
                            <div class="stat-card present">
                                <div class="stat-number text-green-600"><?php echo $present_count; ?></div>
                                <div class="stat-label">Present Students</div>
                            </div>
                            
                            <div class="stat-card absent">
                                <div class="stat-number text-red-600"><?php echo $absent_count; ?></div>
                                <div class="stat-label">Absent Students</div>
                            </div>
                            
                            <div class="stat-card attendance">
                                <div class="stat-number text-blue-600"><?php echo $attendance_rate; ?>%</div>
                                <div class="stat-label">Attendance Rate</div>
                            </div>
                            
                            <div class="stat-card camera">
                                <div class="stat-number text-purple-600"><?php echo $camera_rate; ?>%</div>
                                <div class="stat-label">Camera On Rate</div>
                            </div>
                        </div>
                        
                        <!-- Attendance List -->
                        <?php if(count($attendance) > 0): ?>
                            <div class="overflow-x-auto -mx-4 sm:mx-0 rounded-2xl border border-slate-200 bg-white/70">
                                <table class="w-full min-w-max">
                                    <thead>
                                        <tr class="bg-gray-50">
                                            <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                            <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Name</th>
                                            <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                            <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Camera</th>
                                            <th class="px-2 sm:px-4 py-2 sm:py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200">
                                        <?php foreach($attendance as $record): ?>
                                            <tr class="hover:bg-gray-50 transition-colors">
                                                <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap text-xs sm:text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($record['student_id']); ?>
                                                </td>
                                                <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap">
                                                    <div class="text-xs sm:text-sm font-medium text-gray-900 truncate max-w-[120px] sm:max-w-none"><?php echo htmlspecialchars($record['student_name']); ?></div>
                                                </td>
                                                <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap">
                                                    <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $record['status'] == 'Present' ? 'attendance-present' : 'attendance-absent'; ?>">
                                                        <?php echo $record['status']; ?>
                                                    </span>
                                                </td>
                                                <td class="px-2 sm:px-4 py-2 sm:py-3 whitespace-nowrap">
                                                    <?php if($record['status'] == 'Present'): ?>
                                                        <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $record['camera_status'] == 'On' ? 'camera-on' : 'camera-off'; ?>">
                                                            <?php echo $record['camera_status']; ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="px-2 py-1 rounded-full text-xs font-medium text-gray-400">
                                                            N/A
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-2 sm:px-4 py-2 sm:py-3 text-xs sm:text-sm text-gray-500 truncate max-w-[100px] sm:max-w-xs">
                                                    <?php echo !empty($record['remarks']) ? htmlspecialchars($record['remarks']) : '-'; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-6 sm:py-8">
                                <i class="fas fa-clipboard-list text-gray-400 text-3xl sm:text-4xl mb-3"></i>
                                <p class="text-gray-500 text-sm sm:text-base">No attendance records found for this class</p>
                                <p class="text-xs sm:text-sm text-gray-400 mt-2">Attendance records will be created when you mark attendance for this class</p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-6 sm:py-8">
                            <i class="fas fa-clock text-gray-400 text-3xl sm:text-4xl mb-3"></i>
                            <p class="text-gray-500 text-sm sm:text-base">Attendance will be available after the class date</p>
                            <p class="text-xs sm:text-sm text-gray-400 mt-2">You can mark attendance on or after <?php echo date('M j, Y', strtotime($class['schedule_date'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col xs:flex-row justify-between gap-3 sm:gap-0">
                <a href="schedule.php?batch_id=<?php echo $class['batch_id']; ?>" 
                   class="px-3 sm:px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors ripple flex items-center justify-center text-sm sm:text-base">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Schedule
                </a>
                
                <?php if($class['schedule_date'] <= date('Y-m-d') && !$class['is_cancelled']): ?>
                    <a href="../attendance/trainer_attendance.php?batch_id=<?php echo $class['batch_id']; ?>&date=<?php echo $class['schedule_date']; ?>" 
                       class="px-3 sm:px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors ripple flex items-center justify-center text-sm sm:text-base">
                        <i class="fas fa-edit mr-2"></i> Mark/Edit Attendance
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

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
            
            // Touch-friendly interactions
            function initializeTouchInteractions() {
                // Add touch feedback to buttons
                document.querySelectorAll('button, a[href]').forEach(element => {
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
        });
    </script>
</body>
</html>