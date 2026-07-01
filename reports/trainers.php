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
$selected_trainer_id = $_GET['trainer_id'] ?? '';
$view_type = $_GET['view'] ?? 'profile';
$time_period = $_GET['period'] ?? 'all';

// Get all trainers (active and inactive)
$trainers_query = $db->query("
    SELECT t.id, t.name, t.email, t.is_active, t.specialization, 
           t.years_of_experience, t.profile_picture,
           COUNT(DISTINCT b.batch_id) as batch_count,
           AVG(f.rating) as avg_rating,
           (SELECT COUNT(*) FROM weekly_feedback wf WHERE wf.trainer_id = t.id) as weekly_feedback_count
    FROM trainers t
    LEFT JOIN batches b ON t.id = b.batch_mentor_id
    LEFT JOIN feedback f ON b.batch_id = f.batch_id
    GROUP BY t.id, t.name, t.email, t.is_active, t.specialization, 
             t.years_of_experience, t.profile_picture
    ORDER BY t.is_active DESC, t.name ASC
");
$trainers = $trainers_query->fetchAll(PDO::FETCH_ASSOC);

// Initialize trainer data
$trainer_data = [];
$batch_data = [];
$class_data = [];
$feedback_data = [];
$workshop_data = [];
$workshop_reviews = [];

// Get selected trainer data
if (!empty($selected_trainer_id)) {
    // Basic trainer info
    $trainer_stmt = $db->prepare("
        SELECT t.*, u.email, u.created_at as user_created_at
        FROM trainers t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.id = ?
    ");
    $trainer_stmt->execute([$selected_trainer_id]);
    $trainer_data = $trainer_stmt->fetch(PDO::FETCH_ASSOC);

    if ($trainer_data) {
        // Get batches assigned to trainer
        $batch_stmt = $db->prepare("
            SELECT b.*, 
                   COUNT(DISTINCT s.student_id) as student_count
            FROM batches b
            LEFT JOIN students s ON s.batch_name = b.batch_id AND s.current_status = 'active'
            WHERE b.batch_mentor_id = ?
            GROUP BY b.batch_id, b.batch_name, b.start_date, b.end_date, b.status
            ORDER BY 
                CASE 
                    WHEN b.status = 'ongoing' THEN 1
                    WHEN b.status = 'upcoming' THEN 2
                    WHEN b.status = 'completed' THEN 3
                    ELSE 4
                END,
                b.start_date DESC
        ");
        $batch_stmt->execute([$selected_trainer_id]);
        $batch_data = $batch_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get classes from attendance table for batches allotted to trainer with detailed day-wise attendance
        if (!empty($batch_data)) {
            $batch_ids = array_column($batch_data, 'batch_id');
            $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
            $class_stmt = $db->prepare("
                SELECT 
                    a.id,
                    a.batch_id,
                    a.date as class_date,
                    a.date as schedule_date,
                    a.status as attendance_status,
                    a.camera_status,
                    a.remarks,
                    b.batch_name,
                    COUNT(DISTINCT a.student_id) as total_students,
                    COUNT(DISTINCT CASE WHEN a.status = 'Present' THEN a.student_id END) as present_count,
                    COUNT(DISTINCT CASE WHEN a.status = 'Absent' THEN a.student_id END) as absent_count,
                    GROUP_CONCAT(
                        DISTINCT CONCAT(
                            COALESCE(a.student_id, ''), '|',
                            COALESCE(a.status, 'Not Marked'), '|',
                            COALESCE(a.camera_status, 'Off'), '|',
                            COALESCE(a.remarks, '')
                        ) SEPARATOR ';;'
                    ) as attendance_details
                FROM attendance a
                JOIN batches b ON a.batch_id = b.batch_id
                WHERE b.batch_mentor_id = ? AND a.batch_id IN ($placeholders)
                GROUP BY a.id, a.date, a.batch_id, b.batch_name
                ORDER BY a.date DESC
                LIMIT 50
            ");
            $params = array_merge([$selected_trainer_id], $batch_ids);
            $class_stmt->execute($params);
            $class_data = $class_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process class data to extract attendance details properly
            foreach ($class_data as &$class) {
                $attendance_list = [];
                if (!empty($class['attendance_details'])) {
                    $details = explode(';;', $class['attendance_details']);
                    foreach ($details as $detail) {
                        if (!empty($detail)) {
                            $parts = explode('|', $detail);
                            if (count($parts) >= 3) {
                                $attendance_list[] = [
                                    'student_id' => $parts[0],
                                    'status' => $parts[1],
                                    'camera_status' => $parts[2],
                                    'remarks' => $parts[3] ?? ''
                                ];
                            }
                        }
                    }
                }
                $class['parsed_attendance'] = $attendance_list;
            }
        }

        // Get ALL feedback for trainer's batches and weekly feedback
        $feedback_stmt = $db->prepare("
            SELECT 
                f.id,
                f.date,
                f.student_name,
                f.batch_id,
                f.is_regular,
                f.class_rating,
                f.assignment_understanding,
                f.practical_understanding,
                f.satisfied,
                f.suggestions,
                f.feedback_text,
                s.first_name,
                s.last_name,
                b.batch_name,
                CONCAT(s.first_name, ' ', s.last_name) as student_full_name,
                'batch' as feedback_type,
                CASE 
                    WHEN f.class_rating >= 4 THEN 'Very Satisfied'
                    WHEN f.class_rating = 3 THEN 'Satisfied'
                    WHEN f.class_rating = 2 THEN 'Needs Improvement'
                    WHEN f.class_rating = 1 THEN 'Dissatisfied'
                    ELSE 'Not Rated'
                END as satisfaction_status,
                f.class_rating as rating_value
            FROM feedback f
            JOIN students s ON f.student_name = CONCAT(s.first_name, ' ', s.last_name)
            JOIN batches b ON f.batch_id = b.batch_id
            WHERE b.batch_mentor_id = ?

            UNION ALL

            SELECT 
                wf.id,
                wf.submitted_at as date,
                NULL as student_name,
                wf.batch_id,
                NULL as is_regular,
                wf.rating as class_rating,
                NULL as assignment_understanding,
                NULL as practical_understanding,
                CASE WHEN wf.rating >= 4 THEN 1 ELSE 0 END as satisfied,
                wf.remarks as suggestions,
                wf.remarks as feedback_text,
                s.first_name,
                s.last_name,
                b.batch_name,
                CONCAT(s.first_name, ' ', s.last_name) as student_full_name,
                'weekly' as feedback_type,
                CASE 
                    WHEN wf.rating >= 4 THEN 'Very Satisfied'
                    WHEN wf.rating = 3 THEN 'Satisfied'
                    WHEN wf.rating = 2 THEN 'Needs Improvement'
                    WHEN wf.rating = 1 THEN 'Dissatisfied'
                    ELSE 'Not Rated'
                END as satisfaction_status,
                wf.rating as rating_value
            FROM weekly_feedback wf
            JOIN students s ON wf.student_id = s.student_id
            JOIN batches b ON wf.batch_id = b.batch_id
            WHERE wf.trainer_id = ?

            ORDER BY date DESC
        ");
        $feedback_stmt->execute([$selected_trainer_id, $selected_trainer_id]);
        $feedback_data = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get workshops conducted by trainer
        $workshop_stmt = $db->prepare("
            SELECT w.*, 
                   COUNT(wr.id) as total_registrations,
                   COUNT(wa.id) as attendance_count
            FROM workshops w
            LEFT JOIN workshop_registrations wr ON w.workshop_id = wr.workshop_id
            LEFT JOIN workshop_attendance wa ON w.workshop_id = wa.workshop_id AND wa.attendance_status = 'present'
            WHERE w.trainer_id = ?
            GROUP BY w.workshop_id, w.title, w.start_datetime, w.end_datetime, w.status
            ORDER BY w.start_datetime DESC
        ");
        $workshop_stmt->execute([$selected_trainer_id]);
        $workshop_data = $workshop_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get workshop reviews
        $review_stmt = $db->prepare("
            SELECT wf.*, w.title as workshop_title, 
                   CONCAT(s.first_name, ' ', s.last_name) as student_name
            FROM workshop_feedback wf
            JOIN workshops w ON wf.workshop_id = w.workshop_id
            JOIN students s ON wf.student_id = s.student_id
            WHERE w.trainer_id = ?
            ORDER BY wf.submitted_at DESC
        ");
        $review_stmt->execute([$selected_trainer_id]);
        $workshop_reviews = $review_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Prepare chart data for feedback
$feedback_chart_data = [];
if (!empty($feedback_data)) {
    $satisfaction_counts = [
        'Very Satisfied' => 0,
        'Satisfied' => 0,
        'Needs Improvement' => 0,
        'Dissatisfied' => 0
    ];
    $monthly_ratings = [];
    
    foreach ($feedback_data as $feedback) {
        if ($feedback['rating_value']) {
            if ($feedback['rating_value'] >= 4) {
                $satisfaction_counts['Very Satisfied']++;
            } elseif ($feedback['rating_value'] == 3) {
                $satisfaction_counts['Satisfied']++;
            } elseif ($feedback['rating_value'] == 2) {
                $satisfaction_counts['Needs Improvement']++;
            } elseif ($feedback['rating_value'] == 1) {
                $satisfaction_counts['Dissatisfied']++;
            }
            
            $month = date('Y-m', strtotime($feedback['date']));
            if (!isset($monthly_ratings[$month])) {
                $monthly_ratings[$month] = [];
            }
            $monthly_ratings[$month][] = $feedback['rating_value'];
        }
    }
    
    $feedback_chart_data['satisfaction_distribution'] = [
        'labels' => ['Very Satisfied', 'Satisfied', 'Needs Improvement', 'Dissatisfied'],
        'data' => array_values($satisfaction_counts),
        'colors' => ['#22c55e', '#84cc16', '#eab308', '#ef4444']
    ];
    
    // Monthly average ratings
    $monthly_avg_labels = [];
    $monthly_avg_data = [];
    
    foreach ($monthly_ratings as $month => $ratings) {
        $monthly_avg_labels[] = date('M Y', strtotime($month));
        $monthly_avg_data[] = round(array_sum($ratings) / count($ratings), 1);
    }
    
    if (!empty($monthly_avg_data)) {
        $feedback_chart_data['monthly_trend'] = [
            'labels' => $monthly_avg_labels,
            'data' => $monthly_avg_data,
            'color' => '#3b82f6'
        ];
    }
}
?>
<style>
/* ===== TRAINERS PAGE UPGRADE ===== */
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
.scg-orange { background:linear-gradient(135deg,#f97316 0%,#ea580c 100%); box-shadow:0 8px 24px rgba(249,115,22,.4); }

.scg-blue:hover   { box-shadow:0 20px 40px rgba(59,130,246,.55); }
.scg-violet:hover { box-shadow:0 20px 40px rgba(99,102,241,.55); }
.scg-pink:hover   { box-shadow:0 20px 40px rgba(236,72,153,.55); }
.scg-teal:hover   { box-shadow:0 20px 40px rgba(20,184,166,.55); }
.scg-orange:hover { box-shadow:0 20px 40px rgba(249,115,22,.55); }

.scg-number { font-size:2.4rem; font-weight:900; line-height:1; color:white; text-shadow:0 2px 8px rgba(0,0,0,.2); }
.scg-label  { font-size:.75rem; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,.82); }
.scg-icon   { background:rgba(255,255,255,.2); border-radius:14px; padding:12px; }
</style>

<!-- Main Content with Sidebar Offset -->
<div class="ml-64 p-8 transition-all duration-300 min-h-screen" style="background: linear-gradient(145deg,#eef2ff 0%,#e0e7ff 30%,#f0f4ff 60%,#ede9fe 100%); position:relative; overflow-x:hidden;">
<div class="rpt-orb1"></div>
<div class="rpt-orb2"></div>
<div style="position:relative;z-index:1;">
    <!-- Main Navigation Tabs -->
    <div class="mb-8">
        <?php include 'navbar.php'; ?>
    </div>
    
    <div>
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-gray-800">Trainer Performance Reports</h1>
        <div class="flex space-x-4">
            <button onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-print mr-2"></i> Print Report
            </button>
            <button onclick="exportToPDF()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors transform hover:scale-105">
                <i class="fas fa-file-pdf mr-2"></i> Export PDF
            </button>
        </div>
    </div>

    <!-- Trainer Selection Section - Grid View with Photos -->
    <div class="glass-panel p-6 mb-8 transition-all">
        <h2 class="text-xl font-semibold mb-4 text-gray-700">Select Trainer</h2>
        
        <!-- Active Trainers Grid -->
        <div class="mb-6">
            <h3 class="text-lg font-semibold text-green-800 mb-3 flex items-center">
                <i class="fas fa-user-check mr-2"></i> Active Trainers
                <span class="ml-2 text-sm font-normal text-gray-500">(<?= count(array_filter($trainers, fn($t) => $t['is_active'])) ?> trainers)</span>
            </h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                <?php 
                $active_trainers = array_filter($trainers, fn($t) => $t['is_active']);
                if (empty($active_trainers)): ?>
                    <p class="text-gray-500 text-center py-4 col-span-full">No active trainers found</p>
                <?php else: ?>
                    <?php foreach ($active_trainers as $trainer): ?>
                        <a href="?trainer_id=<?= $trainer['id'] ?>&view=profile" 
                           class="group block glass-panel border-2 transition-all duration-300 transform hover:scale-105 hover:shadow-lg <?= $selected_trainer_id == $trainer['id'] ? 'border-indigo-500 shadow-md bg-indigo-50' : 'border-transparent hover:border-indigo-300' ?>" style="border-radius:16px; background:rgba(255,255,255,.6);">
                            <div class="p-4 text-center">
                                <div class="relative">
                                    <img src="<?= $trainer['profile_picture'] ?: '/assets/images/default-avatar.png' ?>" 
                                         class="w-20 h-20 rounded-full object-cover mx-auto mb-3 border-4 <?= $selected_trainer_id == $trainer['id'] ? 'border-indigo-500' : 'border-white group-hover:border-indigo-400' ?> shadow-md">
                                    <?php if ($trainer['avg_rating']): ?>
                                        <div class="absolute -top-1 -right-1 bg-yellow-400 rounded-full px-1.5 py-0.5 text-xs font-bold text-white shadow">
                                            <?= number_format($trainer['avg_rating'], 1) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <h4 class="font-semibold text-gray-800 text-sm truncate"><?= htmlspecialchars($trainer['name']) ?></h4>
                                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($trainer['specialization'] ?: 'General') ?></p>
                                <div class="flex items-center justify-center mt-2 space-x-1 text-xs text-gray-500">
                                    <span class="flex items-center">
                                        <i class="fas fa-users mr-1 text-green-500"></i> <?= $trainer['batch_count'] ?>
                                    </span>
                                    <?php if ($trainer['avg_rating']): ?>
                                        <span class="mx-1">•</span>
                                        <span class="flex items-center">
                                            <i class="fas fa-star text-yellow-500 mr-1"></i> <?= number_format($trainer['avg_rating'], 1) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Inactive Trainers Grid -->
        <div>
            <h3 class="text-lg font-semibold text-gray-700 mb-3 flex items-center">
                <i class="fas fa-user-slash mr-2"></i> Inactive Trainers
                <span class="ml-2 text-sm font-normal text-gray-500">(<?= count(array_filter($trainers, fn($t) => !$t['is_active'])) ?> trainers)</span>
            </h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                <?php 
                $inactive_trainers = array_filter($trainers, fn($t) => !$t['is_active']);
                if (empty($inactive_trainers)): ?>
                    <p class="text-gray-500 text-center py-4 col-span-full">No inactive trainers found</p>
                <?php else: ?>
                    <?php foreach ($inactive_trainers as $trainer): ?>
                        <a href="?trainer_id=<?= $trainer['id'] ?>&view=profile" 
                           class="group block glass-panel border-2 transition-all duration-300 transform hover:scale-105 hover:shadow-lg <?= $selected_trainer_id == $trainer['id'] ? 'border-gray-500 shadow-md bg-gray-50' : 'border-transparent hover:border-gray-300' ?> opacity-80 hover:opacity-100" style="border-radius:16px; background:rgba(255,255,255,.4);">
                            <div class="p-4 text-center">
                                <img src="<?= $trainer['profile_picture'] ?: '/assets/images/default-avatar.png' ?>" 
                                     class="w-20 h-20 rounded-full object-cover mx-auto mb-3 border-2 border-gray-300 grayscale">
                                <h4 class="font-semibold text-gray-600 text-sm truncate"><?= htmlspecialchars($trainer['name']) ?></h4>
                                <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($trainer['specialization'] ?: 'General') ?></p>
                                <div class="flex items-center justify-center mt-2 space-x-1 text-xs text-gray-500">
                                    <span class="flex items-center">
                                        <i class="fas fa-users mr-1"></i> <?= $trainer['batch_count'] ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($selected_trainer_id) && !empty($trainer_data)): ?>
        <!-- View Tabs with Modern Design -->
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="?trainer_id=<?= $selected_trainer_id ?>&view=profile" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $view_type === 'profile' ? 'bg-blue-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-user mr-2"></i> Profile
            </a>
            <a href="?trainer_id=<?= $selected_trainer_id ?>&view=batches" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $view_type === 'batches' ? 'bg-teal-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-users mr-2"></i> Batches
            </a>
            <a href="?trainer_id=<?= $selected_trainer_id ?>&view=classes" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $view_type === 'classes' ? 'bg-green-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-calendar-alt mr-2"></i> Attendance Records
            </a>
            <a href="?trainer_id=<?= $selected_trainer_id ?>&view=feedback" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $view_type === 'feedback' ? 'bg-pink-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-comments mr-2"></i> Feedback
            </a>
            <a href="?trainer_id=<?= $selected_trainer_id ?>&view=workshops" 
               class="px-5 py-2.5 rounded-xl font-medium text-sm transition-all duration-300 flex items-center <?= $view_type === 'workshops' ? 'bg-orange-600 text-white shadow-lg' : 'bg-white text-gray-700 hover:bg-gray-100 shadow-sm' ?>">
                <i class="fas fa-chalkboard-teacher mr-2"></i> Workshops
            </a>
        </div>

        <!-- Profile View -->
        <?php if ($view_type === 'profile'): ?>
            <div class="glass-panel overflow-hidden mb-8">
                <div style="background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(139,92,246,.1));" class="p-6 border-b border-indigo-100">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <img src="<?= $trainer_data['profile_picture'] ?: '/assets/images/default-avatar.png' ?>" 
                                 class="w-20 h-20 rounded-full object-cover border-4 border-white shadow-lg">
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($trainer_data['name']) ?></h2>
                                <p class="text-gray-600"><?= htmlspecialchars($trainer_data['specialization'] ?: 'No specialization') ?></p>
                                <div class="flex items-center space-x-4 mt-2">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium <?= $trainer_data['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                        <i class="fas fa-circle mr-1 text-<?= $trainer_data['is_active'] ? 'green' : 'gray' ?>-500" style="font-size: 8px;"></i>
                                        <?= $trainer_data['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                    <span class="text-gray-500 text-sm">
                                        <i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($trainer_data['email']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-bold text-blue-600"><?= count($batch_data) ?></div>
                            <div class="text-gray-600">Total Batches</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 p-6">
                    <!-- Trainer Stats -->
                    <div class="md:col-span-2 space-y-6">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="stat-card-gradient scg-blue animate-fade-in" onclick="openTrnModal('trnBatchesModal')" style="cursor:pointer;">
                                <p class="scg-label mb-2"><i class="fas fa-users mr-2"></i>Total Batches</p>
                                <h3 class="scg-number"><?= count($batch_data) ?></h3>
                            </div>
                            <div class="stat-card-gradient scg-teal animate-fade-in" onclick="openTrnModal('trnClassesModal')" style="animation-delay:.1s; cursor:pointer;">
                                <p class="scg-label mb-2"><i class="fas fa-calendar-check mr-2"></i>Attendance Days</p>
                                <h3 class="scg-number"><?= count($class_data) ?></h3>
                            </div>
                            <div class="stat-card-gradient scg-pink animate-fade-in" onclick="openTrnModal('trnFeedbackModal')" style="animation-delay:.2s; cursor:pointer;">
                                <p class="scg-label mb-2"><i class="fas fa-comments mr-2"></i>Feedback</p>
                                <h3 class="scg-number"><?= count($feedback_data) ?></h3>
                            </div>
                            <div class="stat-card-gradient scg-orange animate-fade-in" onclick="openTrnModal('trnWorkshopsModal')" style="animation-delay:.3s; cursor:pointer;">
                                <p class="scg-label mb-2"><i class="fas fa-chalkboard-teacher mr-2"></i>Workshops</p>
                                <h3 class="scg-number"><?= count($workshop_data) ?></h3>
                            </div>
                        </div>

                        <!-- Quick Overview Cards -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="glass-panel p-4" style="background:rgba(239,246,255,0.7); border-color:rgba(59,130,246,0.2);">
                                <h4 class="font-semibold text-blue-800 mb-2 flex items-center">
                                    <i class="fas fa-users mr-2"></i> Current Batches
                                </h4>
                                <?php 
                                $current_batches = array_filter($batch_data, fn($b) => $b['status'] === 'ongoing');
                                if (empty($current_batches)): ?>
                                    <p class="text-blue-600 text-sm">No ongoing batches</p>
                                <?php else: ?>
                                    <ul class="space-y-1">
                                        <?php foreach (array_slice($current_batches, 0, 3) as $batch): ?>
                                            <li class="text-sm text-blue-700 flex justify-between">
                                                <span><?= htmlspecialchars($batch['batch_name']) ?></span>
                                                <span class="bg-blue-100 text-blue-800 py-0.5 px-2 rounded-full text-xs font-semibold"><?= $batch['student_count'] ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>

                            <div class="glass-panel p-4" style="background:rgba(240,253,244,0.7); border-color:rgba(34,197,94,0.2);">
                                <h4 class="font-semibold text-green-800 mb-2 flex items-center">
                                    <i class="fas fa-star mr-2 text-yellow-500"></i> Recent Feedback
                                </h4>
                                <?php if (empty($feedback_data)): ?>
                                    <p class="text-green-600 text-sm">No feedback yet</p>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach (array_slice($feedback_data, 0, 2) as $feedback): ?>
                                            <div class="flex items-center justify-between text-sm bg-white bg-opacity-60 rounded p-2">
                                                <span class="text-green-800 font-medium"><?= htmlspecialchars($feedback['student_name']) ?></span>
                                                <span class="px-2 py-1 text-xs rounded-full font-bold <?= 
                                                    $feedback['satisfaction_status'] === 'Very Satisfied' ? 'bg-green-100 text-green-700' :
                                                    ($feedback['satisfaction_status'] === 'Satisfied' ? 'bg-blue-100 text-blue-700' :
                                                    ($feedback['satisfaction_status'] === 'Needs Improvement' ? 'bg-yellow-100 text-yellow-700' :
                                                    'bg-red-100 text-red-700')) ?>">
                                                    <?= $feedback['satisfaction_status'] ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Trainer Details -->
                    <div class="space-y-4">
                        <div class="glass-panel p-4" style="background:rgba(255,255,255,0.7);">
                            <h4 class="font-semibold text-gray-800 mb-3 border-b border-gray-200 pb-2">Trainer Information</h4>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between items-center bg-white bg-opacity-50 p-2 rounded">
                                    <span class="text-gray-600"><i class="fas fa-briefcase text-indigo-400 mr-2"></i>Experience</span>
                                    <span class="font-bold text-gray-800"><?= $trainer_data['years_of_experience'] ?> years</span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-50 p-2 rounded">
                                    <span class="text-gray-600"><i class="fas fa-phone-alt text-teal-500 mr-2"></i>Phone</span>
                                    <span class="font-bold text-gray-800"><?= htmlspecialchars($trainer_data['phone'] ?: 'N/A') ?></span>
                                </div>
                                <div class="flex justify-between items-center bg-white bg-opacity-50 p-2 rounded">
                                    <span class="text-gray-600"><i class="fas fa-envelope text-pink-500 mr-2"></i>Email</span>
                                    <span class="font-bold text-sm text-gray-800"><?= htmlspecialchars($trainer_data['email']) ?></span>
                                </div>
                            </div>
                        </div>

                        <?php if ($trainer_data['qualifications']): ?>
                        <div class="glass-panel p-4" style="background:rgba(255,255,255,0.7);">
                            <h4 class="font-semibold text-gray-800 mb-2 border-b border-gray-200 pb-2"><i class="fas fa-graduation-cap text-violet-500 mr-2"></i>Qualifications</h4>
                            <p class="text-sm text-gray-700 mt-2"><?= nl2br(htmlspecialchars($trainer_data['qualifications'])) ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if ($trainer_data['bio']): ?>
                        <div class="glass-panel p-4" style="background:rgba(255,255,255,0.7);">
                            <h4 class="font-semibold text-gray-800 mb-2 border-b border-gray-200 pb-2"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Bio</h4>
                            <p class="text-sm text-gray-700 mt-2"><?= nl2br(htmlspecialchars($trainer_data['bio'])) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Batches View -->
        <?php if ($view_type === 'batches'): ?>
            <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800">Batches Assigned to <?= htmlspecialchars($trainer_data['name']) ?></h2>
                </div>

                <div class="p-6">
                    <!-- Batch Status Tabs -->
                    <div class="flex space-x-4 mb-6">
                        <button class="batch-filter px-4 py-2 rounded-lg font-medium transition-all <?= $time_period === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-status="all">
                            All Batches (<?= count($batch_data) ?>)
                        </button>
                        <button class="batch-filter px-4 py-2 rounded-lg font-medium transition-all <?= $time_period === 'ongoing' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-status="ongoing">
                            Ongoing (<?= count(array_filter($batch_data, fn($b) => $b['status'] === 'ongoing')) ?>)
                        </button>
                        <button class="batch-filter px-4 py-2 rounded-lg font-medium transition-all <?= $time_period === 'completed' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-status="completed">
                            Completed (<?= count(array_filter($batch_data, fn($b) => $b['status'] === 'completed')) ?>)
                        </button>
                        <button class="batch-filter px-4 py-2 rounded-lg font-medium transition-all <?= $time_period === 'upcoming' ? 'bg-blue-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>" data-status="upcoming">
                            Upcoming (<?= count(array_filter($batch_data, fn($b) => $b['status'] === 'upcoming')) ?>)
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" id="batches-container">
                        <?php foreach ($batch_data as $batch): ?>
                            <div class="batch-card border border-gray-200 rounded-lg p-4 hover:shadow-lg transition-all duration-300" data-status="<?= $batch['status'] ?>">
                                <div class="flex justify-between items-start mb-3">
                                    <h3 class="font-semibold text-gray-800 text-lg"><?= htmlspecialchars($batch['batch_name']) ?></h3>
                                    <span class="px-2 py-1 text-xs rounded-full font-medium 
                                        <?= $batch['status'] === 'ongoing' ? 'bg-green-100 text-green-800' : 
                                           ($batch['status'] === 'upcoming' ? 'bg-blue-100 text-blue-800' : 
                                           'bg-gray-100 text-gray-800') ?>">
                                        <?= ucfirst($batch['status']) ?>
                                    </span>
                                </div>
                                
                                <div class="space-y-2 text-sm text-gray-600 mb-4">
                                    <div class="flex justify-between">
                                        <span>Batch ID:</span>
                                        <span class="font-medium"><?= $batch['batch_id'] ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>Start Date:</span>
                                        <span class="font-medium"><?= date('M d, Y', strtotime($batch['start_date'])) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>End Date:</span>
                                        <span class="font-medium"><?= date('M d, Y', strtotime($batch['end_date'])) ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span>Students:</span>
                                        <span class="font-medium"><?= $batch['student_count'] ?></span>
                                    </div>
                                </div>

                                <div class="flex space-x-2">
                                    <a href="../batch/batch_view.php?id=<?= $batch['batch_id'] ?>" 
                                       class="flex-1 text-center bg-blue-600 text-white py-2 px-3 rounded-lg text-sm font-medium hover:bg-blue-700 transition-colors">
                                        View Details
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($batch_data)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500">No batches assigned to this trainer</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Classes/Attendance View - Detailed Day Wise Attendance from attendance table -->
        <?php if ($view_type === 'classes'): ?>
            <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800">Attendance Records for <?= htmlspecialchars($trainer_data['name']) ?></h2>
                    <p class="text-sm text-gray-500 mt-1">Detailed day-wise attendance records from attendance table</p>
                </div>

                <div class="p-6">
                    <?php if (empty($class_data)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-calendar-times text-4xl text-gray-300 mb-4"></i>
                            <p class="text-gray-500">No attendance records found for this trainer's batches</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-6">
                            <?php foreach ($class_data as $class): 
                                $attendance_rate = $class['total_students'] > 0 ? 
                                    round(($class['present_count'] / $class['total_students']) * 100, 1) : 0;
                                
                                // Use parsed attendance list
                                $attendance_list = $class['parsed_attendance'] ?? [];
                            ?>
                                <div class="border border-gray-200 rounded-lg overflow-hidden hover:shadow-md transition-all">
                                    <!-- Class Header -->
                                    <div class="bg-gradient-to-r from-gray-50 to-gray-100 p-4 border-b border-gray-200">
                                        <div class="flex flex-wrap justify-between items-start gap-4">
                                            <div>
                                                <div class="flex items-center space-x-3 mb-2">
                                                    <h3 class="text-lg font-semibold text-gray-800">Attendance Record</h3>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                        Batch: <?= htmlspecialchars($class['batch_name']) ?>
                                                    </span>
                                                </div>
                                                <div class="flex flex-wrap gap-4 text-sm text-gray-600">
                                                    <span><i class="far fa-calendar-alt mr-1"></i> <?= date('l, M d, Y', strtotime($class['class_date'])) ?></span>
                                                    <span><i class="fas fa-users mr-1"></i> Total Students: <?= $class['total_students'] ?></span>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-2xl font-bold <?= $attendance_rate >= 80 ? 'text-green-600' : ($attendance_rate >= 60 ? 'text-yellow-600' : 'text-red-600') ?>">
                                                    <?= $attendance_rate ?>%
                                                </div>
                                                <div class="text-xs text-gray-500">Attendance Rate</div>
                                                <div class="text-sm text-gray-600 mt-1">
                                                    <?= $class['present_count'] ?> / <?= $class['total_students'] ?> present
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($class['remarks']): ?>
                                            <p class="text-gray-600 text-sm mt-3 pt-2 border-t border-gray-200"><i class="fas fa-info-circle mr-1"></i> <?= htmlspecialchars($class['remarks']) ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Attendance Table -->
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Name</th>
                                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance Status</th>
                                                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Camera Status</th>
                                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php if (!empty($attendance_list)): ?>
                                                    <?php 
                                                    // Get student names for the attendance list
                                                    $student_ids = array_column($attendance_list, 'student_id');
                                                    if (!empty($student_ids)) {
                                                        $student_ids = array_filter($student_ids);
                                                        if (!empty($student_ids)) {
                                                            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
                                                            $student_stmt = $db->prepare("
                                                                SELECT student_id, CONCAT(first_name, ' ', last_name) as student_name 
                                                                FROM students 
                                                                WHERE student_id IN ($placeholders)
                                                            ");
                                                            $student_stmt->execute($student_ids);
                                                            $student_names = [];
                                                            while ($row = $student_stmt->fetch(PDO::FETCH_ASSOC)) {
                                                                $student_names[$row['student_id']] = $row['student_name'];
                                                            }
                                                        }
                                                    }
                                                    ?>
                                                    <?php foreach ($attendance_list as $attendance): 
                                                        if (empty($attendance['student_id'])) continue;
                                                    ?>
                                                        <tr class="hover:bg-gray-50">
                                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                                                <?= htmlspecialchars($attendance['student_id']) ?>
                                                            </td>
                                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                                                <?= htmlspecialchars($student_names[$attendance['student_id']] ?? $attendance['student_id']) ?>
                                                            </td>
                                                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                                    <?= $attendance['status'] === 'Present' ? 'bg-green-100 text-green-800' : 
                                                                       ($attendance['status'] === 'Absent' ? 'bg-red-100 text-red-800' : 
                                                                       'bg-gray-100 text-gray-600') ?>">
                                                                    <?= $attendance['status'] ?>
                                                                </span>
                                                            </td>
                                                            <td class="px-4 py-3 whitespace-nowrap text-center">
                                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                                    <?= $attendance['camera_status'] === 'On' ? 'bg-blue-100 text-blue-800' : 
                                                                       ($attendance['camera_status'] === 'Off' ? 'bg-gray-100 text-gray-600' : 
                                                                       'bg-gray-100 text-gray-400') ?>">
                                                                    <i class="fas fa-video mr-1"></i> <?= $attendance['camera_status'] ?>
                                                                </span>
                                                            </td>
                                                            <td class="px-4 py-3 text-sm text-gray-500">
                                                                <?= htmlspecialchars($attendance['remarks'] ?? ($attendance['status'] === 'Absent' ? 'Student was absent for this session' : 'Attended class')) ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                                            <i class="fas fa-info-circle mr-2"></i> No attendance records found for this date
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <!-- Summary Footer -->
                                    <div class="bg-gray-50 px-4 py-3 border-t border-gray-200">
                                        <div class="flex flex-wrap justify-between items-center text-sm">
                                            <div class="flex space-x-4">
                                                <div class="flex items-center">
                                                    <div class="w-3 h-3 bg-green-500 rounded-full mr-1"></div>
                                                    <span class="text-gray-600">Present: <?= $class['present_count'] ?></span>
                                                </div>
                                                <div class="flex items-center">
                                                    <div class="w-3 h-3 bg-red-500 rounded-full mr-1"></div>
                                                    <span class="text-gray-600">Absent: <?= $class['absent_count'] ?? 0 ?></span>
                                                </div>
                                                <div class="flex items-center">
                                                    <div class="w-3 h-3 bg-gray-300 rounded-full mr-1"></div>
                                                    <span class="text-gray-600">Total Students: <?= $class['total_students'] ?></span>
                                                </div>
                                            </div>
                                            <div class="text-gray-500 text-xs">
                                                Last updated: <?= date('M d, Y h:i A') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Feedback View -->
        <?php if ($view_type === 'feedback'): ?>
            <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800">Feedback for <?= htmlspecialchars($trainer_data['name']) ?></h2>
                    <p class="text-sm text-gray-500 mt-1">Includes batch feedback and weekly feedback from students</p>
                </div>

                <div class="p-6">
                    <!-- Feedback Summary -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-blue-600"><?= count($feedback_data) ?></div>
                            <div class="text-sm text-gray-600">Total Feedback</div>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <?php 
                            $avg_rating = $feedback_data ? 
                                round(array_sum(array_column($feedback_data, 'rating_value')) / count($feedback_data), 1) : 0;
                            ?>
                            <div class="text-2xl font-bold text-yellow-600"><?= $avg_rating ?></div>
                            <div class="text-sm text-gray-600">Average Rating</div>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-green-600">
                                <?= $feedback_data ? count(array_filter($feedback_data, fn($f) => $f['rating_value'] >= 4)) : 0 ?>
                            </div>
                            <div class="text-sm text-gray-600">Very Satisfied (4+ stars)</div>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-red-600">
                                <?= $feedback_data ? count(array_filter($feedback_data, fn($f) => $f['rating_value'] <= 2)) : 0 ?>
                            </div>
                            <div class="text-sm text-gray-600">Needs Improvement</div>
                        </div>
                    </div>

                    <!-- Feedback Charts - Satisfaction Distribution -->
                    <?php if (!empty($feedback_chart_data)): ?>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                            <!-- Satisfaction Distribution -->
                            <div class="bg-white border border-gray-200 rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Student Satisfaction Distribution</h3>
                                <div class="space-y-3">
                                    <?php foreach ($feedback_chart_data['satisfaction_distribution']['labels'] as $index => $label): ?>
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-2">
                                                <div class="w-3 h-3 rounded-full" style="background-color: <?= $feedback_chart_data['satisfaction_distribution']['colors'][$index] ?>"></div>
                                                <span class="text-sm text-gray-600"><?= $label ?></span>
                                            </div>
                                            <div class="flex items-center space-x-3">
                                                <div class="w-32 bg-gray-200 rounded-full h-3">
                                                    <?php 
                                                    $percentage = count($feedback_data) > 0 ? 
                                                        ($feedback_chart_data['satisfaction_distribution']['data'][$index] / count($feedback_data)) * 100 : 0;
                                                    ?>
                                                    <div class="h-3 rounded-full" 
                                                         style="width: <?= $percentage ?>%; background-color: <?= $feedback_chart_data['satisfaction_distribution']['colors'][$index] ?>">
                                                    </div>
                                                </div>
                                                <span class="text-sm font-medium text-gray-700 w-8">
                                                    <?= $feedback_chart_data['satisfaction_distribution']['data'][$index] ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Monthly Trend -->
                            <?php if (isset($feedback_chart_data['monthly_trend'])): ?>
                                <div class="bg-white border border-gray-200 rounded-lg p-6">
                                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Monthly Rating Trend</h3>
                                    <div class="flex items-end space-x-2 h-32">
                                        <?php foreach ($feedback_chart_data['monthly_trend']['data'] as $index => $rating): ?>
                                            <div class="flex-1 flex flex-col items-center">
                                                <div class="text-xs text-gray-500 mb-1"><?= $feedback_chart_data['monthly_trend']['labels'][$index] ?></div>
                                                <div class="w-full bg-blue-200 rounded-t-lg" style="height: <?= ($rating / 5) * 80 ?>%"></div>
                                                <div class="text-xs font-medium mt-1"><?= $rating ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Feedback List with Satisfaction Status -->
                    <div class="space-y-4">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">All Feedback</h3>
                        <?php if (empty($feedback_data)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-comment-slash text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-500">No feedback received yet</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($feedback_data as $feedback): ?>
                                <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-3 mb-1">
                                                <h4 class="font-medium text-gray-800"><?= htmlspecialchars($feedback['student_name']) ?></h4>
                                                <span class="px-2 py-0.5 text-xs rounded-full 
                                                    <?= $feedback['feedback_type'] === 'weekly' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' ?>">
                                                    <?= $feedback['feedback_type'] === 'weekly' ? 'Weekly Feedback' : 'Batch Feedback' ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-500"><?= htmlspecialchars($feedback['batch_name']) ?></p>
                                        </div>
                                        <div class="text-right">
                                            <div class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium mb-2
                                                <?= $feedback['satisfaction_status'] === 'Very Satisfied' ? 'bg-green-100 text-green-700' :
                                                   ($feedback['satisfaction_status'] === 'Satisfied' ? 'bg-blue-100 text-blue-700' :
                                                   ($feedback['satisfaction_status'] === 'Needs Improvement' ? 'bg-yellow-100 text-yellow-700' :
                                                   'bg-red-100 text-red-700')) ?>">
                                                <i class="fas 
                                                    <?= $feedback['satisfaction_status'] === 'Very Satisfied' ? 'fa-smile' :
                                                       ($feedback['satisfaction_status'] === 'Satisfied' ? 'fa-smile' :
                                                       ($feedback['satisfaction_status'] === 'Needs Improvement' ? 'fa-meh' : 'fa-frown')) ?> mr-1">
                                                </i>
                                                <?= $feedback['satisfaction_status'] ?>
                                            </div>
                                            <div class="flex items-center space-x-1">
                                                <div class="flex items-center text-yellow-500">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star <?= $i <= ($feedback['rating_value'] ?? 0) ? 'text-yellow-500' : 'text-gray-300' ?>" style="font-size: 12px;"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <span class="text-xs text-gray-400 ml-2"><?= date('M d, Y', strtotime($feedback['date'])) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($feedback['feedback_text']): ?>
                                        <p class="text-gray-700 mt-2 text-sm"><?= nl2br(htmlspecialchars($feedback['feedback_text'])) ?></p>
                                    <?php endif; ?>
                                    <?php if ($feedback['suggestions']): ?>
                                        <div class="mt-2 text-sm text-gray-600 bg-gray-50 p-2 rounded">
                                            <strong class="text-gray-700">Suggestions:</strong> <?= nl2br(htmlspecialchars($feedback['suggestions'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Workshops View -->
        <?php if ($view_type === 'workshops'): ?>
            <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800">Workshops by <?= htmlspecialchars($trainer_data['name']) ?></h2>
                </div>

                <div class="p-6">
                    <!-- Workshop Statistics -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-blue-600"><?= count($workshop_data) ?></div>
                            <div class="text-sm text-gray-600">Total Workshops</div>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-green-600">
                                <?= count(array_filter($workshop_data, fn($w) => $w['status'] === 'completed')) ?>
                            </div>
                            <div class="text-sm text-gray-600">Completed</div>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-orange-600">
                                <?= count(array_filter($workshop_data, fn($w) => $w['status'] === 'upcoming')) ?>
                            </div>
                            <div class="text-sm text-gray-600">Upcoming</div>
                        </div>
                        <div class="bg-white border border-gray-200 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-purple-600">
                                <?= array_sum(array_column($workshop_data, 'total_registrations')) ?>
                            </div>
                            <div class="text-sm text-gray-600">Total Registrations</div>
                        </div>
                    </div>

                    <!-- Workshop List -->
                    <div class="space-y-6">
                        <?php if (empty($workshop_data)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-chalkboard-teacher text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-500">No workshops conducted by this trainer</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($workshop_data as $workshop): ?>
                                <div class="border border-gray-200 rounded-lg p-6 hover:shadow-md transition-all">
                                    <div class="flex justify-between items-start mb-4">
                                        <div>
                                            <h3 class="text-xl font-semibold text-gray-800"><?= htmlspecialchars($workshop['title']) ?></h3>
                                            <p class="text-gray-600 mt-1"><?= htmlspecialchars($workshop['description']) ?></p>
                                        </div>
                                        <span class="px-3 py-1 rounded-full text-sm font-medium 
                                            <?= $workshop['status'] === 'completed' ? 'bg-green-100 text-green-800' : 
                                               ($workshop['status'] === 'upcoming' ? 'bg-blue-100 text-blue-800' : 
                                               'bg-gray-100 text-gray-800') ?>">
                                            <?= ucfirst($workshop['status']) ?>
                                        </span>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 text-sm">
                                        <div>
                                            <span class="font-medium text-gray-700">Date & Time:</span>
                                            <p class="text-gray-600">
                                                <?= date('M d, Y', strtotime($workshop['start_datetime'])) ?><br>
                                                <?= date('h:i A', strtotime($workshop['start_datetime'])) ?> - 
                                                <?= date('h:i A', strtotime($workshop['end_datetime'])) ?>
                                            </p>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700">Registrations:</span>
                                            <p class="text-gray-600"><?= $workshop['total_registrations'] ?> registered</p>
                                            <p class="text-gray-600"><?= $workshop['attendance_count'] ?> attended</p>
                                        </div>
                                        <div>
                                            <span class="font-medium text-gray-700">Location:</span>
                                            <p class="text-gray-600"><?= htmlspecialchars($workshop['location'] ?: 'Online') ?></p>
                                        </div>
                                    </div>

                                    <!-- Workshop Reviews -->
                                    <?php 
                                    $workshop_reviews_list = array_filter($workshop_reviews, fn($r) => $r['workshop_id'] == $workshop['workshop_id']);
                                    if (!empty($workshop_reviews_list)): ?>
                                        <div class="border-t border-gray-200 pt-4 mt-4">
                                            <h4 class="font-semibold text-gray-800 mb-3">Workshop Reviews</h4>
                                            <div class="space-y-3">
                                                <?php foreach (array_slice($workshop_reviews_list, 0, 3) as $review): ?>
                                                    <div class="bg-gray-50 rounded-lg p-3">
                                                        <div class="flex justify-between items-start mb-1">
                                                            <span class="font-medium text-gray-800"><?= htmlspecialchars($review['student_name']) ?></span>
                                                            <span class="text-sm text-gray-500"><?= date('M d, Y', strtotime($review['submitted_at'])) ?></span>
                                                        </div>
                                                        <div class="flex items-center mb-2">
                                                            <div class="flex items-center text-yellow-500">
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <i class="fas fa-star <?= $i <= $review['rating'] ? 'text-yellow-500' : 'text-gray-300' ?>" style="font-size: 12px;"></i>
                                                                <?php endfor; ?>
                                                            </div>
                                                        </div>
                                                        <?php if ($review['feedback_text']): ?>
                                                            <p class="text-gray-700 text-sm"><?= nl2br(htmlspecialchars($review['feedback_text'])) ?></p>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php elseif (!empty($selected_trainer_id)): ?>
        <div class="bg-white rounded-xl shadow-md p-8 text-center">
            <i class="fas fa-user-slash text-4xl text-gray-300 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">Trainer Not Found</h3>
            <p class="text-gray-500">The selected trainer could not be found in the system.</p>
        </div>
    <?php else: ?>
        <div class="bg-white rounded-xl shadow-md p-8 text-center">
            <i class="fas fa-user-graduate text-4xl text-gray-300 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">Select a Trainer</h3>
            <p class="text-gray-500">Please select a trainer from the grid above to view their performance report.</p>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($selected_trainer_id) && !empty($trainer_data)): ?>
<!-- ===== TRAINER DETAIL MODALS ===== -->
<style>
.trn-modal-backdrop {
    position:fixed; inset:0;
    background:rgba(0,0,0,.5);
    backdrop-filter:blur(4px);
    z-index:1050;
    display:none;
    align-items:center;
    justify-content:center;
    animation:trnFadeIn .2s ease;
}
.trn-modal-backdrop.active { display:flex; }
@keyframes trnFadeIn { from{opacity:0} to{opacity:1} }
.trn-modal-box {
    background:white;
    border-radius:24px;
    overflow:hidden;
    width:90%; max-width:880px;
    max-height:85vh;
    display:flex; flex-direction:column;
    box-shadow:0 30px 60px rgba(0,0,0,.25);
    animation:trnSlideUp .3s cubic-bezier(.4,0,.2,1);
}
@keyframes trnSlideUp { from{transform:translateY(40px);opacity:0} to{transform:translateY(0);opacity:1} }
.trn-modal-header {
    padding:22px 28px;
    color:white;
    display:flex; align-items:center; justify-content:space-between;
    flex-shrink:0;
}
.trn-modal-hero { font-size:3rem; font-weight:900; line-height:1; text-shadow:0 2px 8px rgba(0,0,0,.2); }
.trn-modal-body {
    padding:22px 28px;
    overflow-y:auto;
    background:#f8fafc;
    flex:1;
}
.trn-modal-body::-webkit-scrollbar { width:6px; }
.trn-modal-body::-webkit-scrollbar-thumb { background:#cbd5e0; border-radius:3px; }
.trn-tbl { width:100%; border-collapse:collapse; font-size:.87rem; }
.trn-tbl thead th {
    background:rgba(99,102,241,.08);
    font-weight:700; font-size:.75rem;
    text-transform:uppercase; letter-spacing:.8px;
    color:#4a5568; padding:11px 14px; border:none;
}
.trn-tbl tbody tr { border-bottom:1px solid #edf2f7; transition:background .2s; }
.trn-tbl tbody tr:hover { background:#eef2ff; }
.trn-tbl td { padding:10px 14px; vertical-align:middle; border:none; }
.trn-search {
    width:100%; border:2px solid #e2e8f0;
    border-radius:12px; padding:8px 14px;
    font-size:.9rem; margin-bottom:14px;
    transition:border-color .2s; outline:none;
}
.trn-search:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.15); }
.trn-close-btn {
    background:rgba(255,255,255,.2);
    border:none; color:white;
    width:36px; height:36px;
    border-radius:50%; font-size:1.2rem;
    cursor:pointer; display:flex;
    align-items:center; justify-content:center;
    transition:background .2s;
}
.trn-close-btn:hover { background:rgba(255,255,255,.35); }
</style>

<!-- Batches Detail Modal -->
<div class="trn-modal-backdrop" id="trnBatchesModal" onclick="closeTrnModal('trnBatchesModal',event)">
  <div class="trn-modal-box">
    <div class="trn-modal-header" style="background:linear-gradient(135deg,#3b82f6,#4f46e5)">
      <div class="d-flex align-items-center gap-3">
        <div class="trn-modal-hero"><?= count($batch_data) ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-users me-2"></i>Total Batches</h4>
          <small style="opacity:.8">All assigned batches</small>
        </div>
      </div>
      <button class="trn-close-btn" onclick="closeTrnModal('trnBatchesModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="trn-modal-body">
      <input class="trn-search" placeholder="🔍 Search by batch name, status..." onkeyup="trnFilter(this,'trnBatchTbl')">
      <?php if(empty($batch_data)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-users-slash fa-3x mb-3"></i><p>No batches found.</p></div>
      <?php else: ?>
      <table class="trn-tbl" id="trnBatchTbl">
        <thead><tr><th>#</th><th>Batch ID</th><th>Batch Name</th><th>Status</th><th>Students</th><th>Start Date</th><th>End Date</th></tr></thead>
        <tbody>
        <?php foreach($batch_data as $i=>$b): 
            $sc = $b['status']==='ongoing'?'#16a34a':($b['status']==='upcoming'?'#2563eb':'#64748b');
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= htmlspecialchars($b['batch_id']) ?></strong></td>
          <td><?= htmlspecialchars($b['batch_name']) ?></td>
          <td><span style="background:<?= $sc ?>22;color:<?= $sc ?>;padding:2px 10px;border-radius:20px;font-weight:600;font-size:.8rem;"><?= ucfirst($b['status']) ?></span></td>
          <td><?= $b['student_count'] ?></td>
          <td><?= $b['start_date'] ? date('d M Y',strtotime($b['start_date'])) : '-' ?></td>
          <td><?= $b['end_date'] ? date('d M Y',strtotime($b['end_date'])) : '-' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Attendance Days Modal -->
<div class="trn-modal-backdrop" id="trnClassesModal" onclick="closeTrnModal('trnClassesModal',event)">
  <div class="trn-modal-box">
    <div class="trn-modal-header" style="background:linear-gradient(135deg,#14b8a6,#3b82f6)">
      <div class="d-flex align-items-center gap-3">
        <div class="trn-modal-hero"><?= count($class_data) ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-calendar-check me-2"></i>Attendance Days</h4>
          <small style="opacity:.8">Class days conducted by trainer</small>
        </div>
      </div>
      <button class="trn-close-btn" onclick="closeTrnModal('trnClassesModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="trn-modal-body">
      <input class="trn-search" placeholder="🔍 Search by date or batch..." onkeyup="trnFilter(this,'trnClassTbl')">
      <?php if(empty($class_data)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-calendar-times fa-3x mb-3"></i><p>No class records found.</p></div>
      <?php else: ?>
      <table class="trn-tbl" id="trnClassTbl">
        <thead><tr><th>#</th><th>Date</th><th>Batch</th><th>Students Present</th><th>Attendance Rate</th></tr></thead>
        <tbody>
        <?php foreach($class_data as $i=>$c): 
            $rate = $c['total_students'] > 0 ? round(($c['present_count'] / $c['total_students']) * 100) : 0;
            $rc = $rate >= 80 ? '#16a34a' : ($rate >= 50 ? '#d97706' : '#dc2626');
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= date('d M Y', strtotime($c['class_date'])) ?></strong></td>
          <td><?= htmlspecialchars($c['batch_name']) ?></td>
          <td><?= $c['present_count'] ?> / <?= $c['total_students'] ?></td>
          <td><span style="font-weight:bold; color:<?= $rc ?>"><?= $rate ?>%</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Feedback Modal -->
<div class="trn-modal-backdrop" id="trnFeedbackModal" onclick="closeTrnModal('trnFeedbackModal',event)">
  <div class="trn-modal-box">
    <div class="trn-modal-header" style="background:linear-gradient(135deg,#ec4899,#a855f7)">
      <div class="d-flex align-items-center gap-3">
        <div class="trn-modal-hero"><?= count($feedback_data) ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-comments me-2"></i>Feedback Received</h4>
          <small style="opacity:.8">Batch and weekly feedback ratings</small>
        </div>
      </div>
      <button class="trn-close-btn" onclick="closeTrnModal('trnFeedbackModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="trn-modal-body">
      <input class="trn-search" placeholder="🔍 Search by student name or batch..." onkeyup="trnFilter(this,'trnFbTbl')">
      <?php if(empty($feedback_data)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-comment-slash fa-3x mb-3"></i><p>No feedback found.</p></div>
      <?php else: ?>
      <table class="trn-tbl" id="trnFbTbl">
        <thead><tr><th>#</th><th>Date</th><th>Type</th><th>Student</th><th>Batch</th><th>Rating</th></tr></thead>
        <tbody>
        <?php foreach($feedback_data as $i=>$f): 
            $stars = str_repeat('★',(int)round($f['rating_value'] ?? 0)).str_repeat('☆',5-(int)round($f['rating_value'] ?? 0));
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><?= date('d M Y', strtotime($f['date'])) ?></td>
          <td><span style="background:#eef2ff;color:#4f46e5;padding:2px 8px;border-radius:20px;font-size:.7rem;text-transform:uppercase;"><?= htmlspecialchars($f['feedback_type']) ?></span></td>
          <td><?= htmlspecialchars($f['student_full_name'] ?? 'Anonymous') ?></td>
          <td><?= htmlspecialchars($f['batch_name']) ?></td>
          <td><strong style="color:#a855f7"><?= round($f['rating_value'] ?? 0, 1) ?></strong> <span style="color:#f59e0b;font-size:.8rem"><?= $stars ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Workshops Modal -->
<div class="trn-modal-backdrop" id="trnWorkshopsModal" onclick="closeTrnModal('trnWorkshopsModal',event)">
  <div class="trn-modal-box">
    <div class="trn-modal-header" style="background:linear-gradient(135deg,#f97316,#ea580c)">
      <div class="d-flex align-items-center gap-3">
        <div class="trn-modal-hero"><?= count($workshop_data) ?></div>
        <div>
          <h4 style="margin:0;font-weight:800;"><i class="fas fa-chalkboard-teacher me-2"></i>Workshops</h4>
          <small style="opacity:.8">Workshops conducted</small>
        </div>
      </div>
      <button class="trn-close-btn" onclick="closeTrnModal('trnWorkshopsModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="trn-modal-body">
      <input class="trn-search" placeholder="🔍 Search by workshop title..." onkeyup="trnFilter(this,'trnWsTbl')">
      <?php if(empty($workshop_data)): ?>
        <div style="text-align:center;padding:40px;color:#94a3b8;"><i class="fas fa-microphone-slash fa-3x mb-3"></i><p>No workshops found.</p></div>
      <?php else: ?>
      <table class="trn-tbl" id="trnWsTbl">
        <thead><tr><th>#</th><th>Title</th><th>Status</th><th>Start Date</th><th>Registrations</th><th>Attended</th></tr></thead>
        <tbody>
        <?php foreach($workshop_data as $i=>$w): 
            $sc = $w['status']==='completed'?'#16a34a':($w['status']==='scheduled'?'#2563eb':'#64748b');
        ?>
        <tr>
          <td><?= $i+1 ?></td>
          <td><strong><?= htmlspecialchars($w['title']) ?></strong></td>
          <td><span style="background:<?= $sc ?>22;color:<?= $sc ?>;padding:2px 10px;border-radius:20px;font-weight:600;font-size:.8rem;"><?= ucfirst($w['status']) ?></span></td>
          <td><?= date('d M Y H:i', strtotime($w['start_datetime'])) ?></td>
          <td><?= $w['total_registrations'] ?></td>
          <td><?= $w['attendance_count'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
function openTrnModal(id){
    document.getElementById(id).classList.add('active');
    document.body.style.overflow='hidden';
}
function closeTrnModal(id,e){
    if(e && e.target!==document.getElementById(id)) return;
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow='';
}
function trnFilter(inp,tblId){
    const q=inp.value.toLowerCase();
    document.querySelectorAll('#'+tblId+' tbody tr').forEach(tr=>{
        tr.style.display=tr.textContent.toLowerCase().includes(q)?'':'none';
    });
}
document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
        document.querySelectorAll('.trn-modal-backdrop.active').forEach(m=>{
            m.classList.remove('active');
            document.body.style.overflow='';
        });
    }
});
</script>
<?php endif; ?>

<script>
// Batch filtering functionality
document.querySelectorAll('.batch-filter').forEach(button => {
    button.addEventListener('click', function() {
        const status = this.dataset.status;
        document.querySelectorAll('.batch-card').forEach(card => {
            if (status === 'all' || card.dataset.status === status) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
        
        // Update active button
        document.querySelectorAll('.batch-filter').forEach(btn => {
            btn.classList.remove('bg-blue-600', 'text-white');
            btn.classList.add('bg-gray-100', 'text-gray-700');
        });
        this.classList.remove('bg-gray-100', 'text-gray-700');
        this.classList.add('bg-blue-600', 'text-white');
    });
});

// Export to PDF functionality
function exportToPDF() {
    alert('PDF export functionality would be implemented here with a library like jsPDF');
}

// Print functionality enhancement
window.onbeforeprint = function() {
    document.querySelector('.ml-64').classList.add('mx-auto');
};

window.onafterprint = function() {
    document.querySelector('.ml-64').classList.remove('mx-auto');
};
</script>

<?php require_once '../footer.php'; ?>