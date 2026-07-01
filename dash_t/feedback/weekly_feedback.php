<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// Check if user is logged in as trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit;
}

require_once '../db_connection.php';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $trainer_user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("
        SELECT t.*, u.name 
        FROM trainers t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.user_id = ?
    ");
    $stmt->execute([$trainer_user_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        header("Location: ../../log2.php");
        exit;
    }

    // Initialize variables
    $current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $week_start = date('Y-m-d', strtotime('monday this week', strtotime($current_date)));
    $week_end = date('Y-m-d', strtotime('sunday this week', strtotime($current_date)));
    $week_number = date('W', strtotime($current_date));
    $year = date('Y', strtotime($current_date));
    
    $selected_batch = isset($_GET['batch_id']) ? $_GET['batch_id'] : null;
    $batches = [];
    $batch_students = [];
    $stats = null;
    $success_message = '';
    $error_message = '';
    $feedback_lookup = [];

    // Robust trainer matching
    $trainer_match_ids = array_values(array_unique(array_filter([
        (int)$trainer['id'],
        (int)$trainer_user_id
    ])));
    $trainer_placeholders = implode(',', array_fill(0, count($trainer_match_ids), '?'));

    // Get batches assigned to this trainer
    $batches_stmt = $db->prepare("
        SELECT DISTINCT b.batch_id, b.batch_name, b.status, b.time_slot
        FROM batches b 
        LEFT JOIN batch_courses bc ON b.batch_id = bc.batch_id
        WHERE (b.batch_mentor_id IN ($trainer_placeholders) OR bc.trainer_id IN ($trainer_placeholders))
        AND b.status IN ('ongoing', 'upcoming')
        ORDER BY b.batch_name
    ");
    $batches_stmt->execute(array_merge($trainer_match_ids, $trainer_match_ids));
    $batches = $batches_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_feedback'])) {
        $student_id = $_POST['student_id'];
        $rating = (int)$_POST['rating'];
        $remarks = trim($_POST['remarks']);
        $batch_id = $_POST['batch_id'] ?? $selected_batch;
        
        if (empty($batch_id)) {
            $error_message = "Batch selection is required.";
        } elseif (empty($student_id)) {
            $error_message = "Student selection is required.";
        } elseif ($rating < 1 || $rating > 5) {
            $error_message = "Please select a valid rating (1-5 stars).";
        } else {
            $check_stmt = $db->prepare("
                SELECT id FROM weekly_feedback 
                WHERE batch_id = ? AND student_id = ? AND trainer_id = ? AND week_start_date = ?
            ");
            $check_stmt->execute([$batch_id, $student_id, $trainer['id'], $week_start]);
            
            if ($check_stmt->rowCount() > 0) {
                $error_message = "Feedback already submitted for this student this week.";
            } else {
                $insert_stmt = $db->prepare("
                    INSERT INTO weekly_feedback 
                    (batch_id, student_id, trainer_id, week_start_date, week_end_date, rating, remarks)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $result = $insert_stmt->execute([
                    $batch_id,
                    $student_id,
                    $trainer['id'],
                    $week_start,
                    $week_end,
                    $rating,
                    $remarks
                ]);
                
                if ($result) {
                    $success_message = "Feedback submitted successfully!";
                    $selected_batch = $batch_id;
                    echo '<script>
                        setTimeout(function() {
                            window.location.href = "weekly_feedback.php?batch_id=' . $batch_id . '&date=' . $current_date . '&success=1";
                        }, 100);
                    </script>';
                } else {
                    $error_message = "Failed to submit feedback. Please try again.";
                }
            }
        }
    }

    if (isset($_GET['success']) && $_GET['success'] == 1) {
        $success_message = "Feedback submitted successfully!";
    }

    // Load data for selected batch
    if ($selected_batch && !empty($batches)) {
        $batch_ids = array_column($batches, 'batch_id');
        if (in_array($selected_batch, $batch_ids)) {
            $students_stmt = $db->prepare("
                SELECT DISTINCT s.student_id, s.first_name, s.last_name, s.current_status,
                       s.email, s.phone_number, s.enrollment_date,
                       CASE 
                           WHEN s.batch_name = ? THEN 'Primary'
                           WHEN s.batch_name_2 = ? THEN 'Secondary'
                           WHEN s.batch_name_3 = ? THEN 'Tertiary'
                           WHEN s.batch_name_4 = ? THEN 'Quaternary'
                       END as batch_role
                FROM students s
                WHERE s.current_status = 'active'
                AND (
                    s.batch_name = ? 
                    OR s.batch_name_2 = ? 
                    OR s.batch_name_3 = ? 
                    OR s.batch_name_4 = ?
                )
                ORDER BY s.first_name, s.last_name
            ");
            $students_stmt->execute([
                $selected_batch, $selected_batch, $selected_batch, $selected_batch,
                $selected_batch, $selected_batch, $selected_batch, $selected_batch
            ]);
            $batch_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

            $feedback_stmt = $db->prepare("
                SELECT wf.student_id, wf.rating, wf.remarks, wf.submitted_at
                FROM weekly_feedback wf
                WHERE wf.batch_id = ? 
                AND wf.trainer_id = ?
                AND wf.week_start_date = ?
                ORDER BY wf.submitted_at DESC
            ");
            $feedback_stmt->execute([$selected_batch, $trainer['id'], $week_start]);
            $existing_feedback = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

            $feedback_lookup = [];
            foreach ($existing_feedback as $feedback) {
                $feedback_lookup[$feedback['student_id']] = $feedback;
            }

            $stats_stmt = $db->prepare("
                SELECT 
                    COUNT(DISTINCT s.student_id) as total_students,
                    COUNT(DISTINCT wf.id) as feedback_given,
                    AVG(wf.rating) as avg_rating
                FROM students s
                LEFT JOIN weekly_feedback wf ON s.student_id = wf.student_id 
                    AND wf.batch_id = ? 
                    AND wf.trainer_id = ?
                    AND wf.week_start_date = ?
                WHERE s.current_status = 'active'
                AND (
                    s.batch_name = ? 
                    OR s.batch_name_2 = ? 
                    OR s.batch_name_3 = ? 
                    OR s.batch_name_4 = ?
                )
            ");
            $stats_stmt->execute([
                $selected_batch, $trainer['id'], $week_start,
                $selected_batch, $selected_batch, $selected_batch, $selected_batch
            ]);
            $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    // Get feedback history
    $history_stmt = $db->prepare("
        SELECT 
            wf.week_start_date,
            wf.week_end_date,
            b.batch_name,
            COUNT(wf.id) as feedback_count,
            AVG(wf.rating) as avg_rating
        FROM weekly_feedback wf
        JOIN batches b ON wf.batch_id = b.batch_id
        WHERE wf.trainer_id = ?
        AND wf.week_start_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
        GROUP BY wf.week_start_date, wf.batch_id
        ORDER BY wf.week_start_date DESC
    ");
    $history_stmt->execute([$trainer['id']]);
    $feedback_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Feedback | Trainer Dashboard | ASD Academy</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --warning-gradient: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            --nb-800: #1B3C53;
            --nb-700: #234C6A;
            --nb-500: #456882;
            --nb-200: #c8d9e6;
            --nb-50: #f3f7fb;
            --grey-skin: #f0eff0;
            --accent-amber: #f59e0b;
            --accent-cyan: #00bcd4;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            overflow-x: hidden;
        }
        
        .sidebar-open main {
            margin-left: 16rem !important;
        }
        
        @media (max-width: 1023px) {
            body.sidebar-open { overflow: hidden; }
            .sidebar-open main { margin-left: 0 !important; }
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(229, 231, 235, 0.5);
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }
        
        .gradient-text {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: bold;
        }
        
        .star-rating {
            direction: rtl;
            display: inline-flex;
            gap: 4px;
        }
        
        .star-rating input[type="radio"] { display: none; }
        
        .star-rating label {
            color: #ddd;
            font-size: 24px;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        
        @media (min-width: 768px) {
            .star-rating label { font-size: 28px; }
        }
        
        .star-rating label:hover,
        .star-rating label:hover ~ label,
        .star-rating input[type="radio"]:checked ~ label {
            color: #f59e0b;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        .status-pending {
            background: rgba(245, 158, 11, 0.15);
            color: #d97706;
        }
        
        .status-completed {
            background: rgba(16, 185, 129, 0.15);
            color: #059669;
        }
        
        .batch-role-badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            margin-left: 0.5rem;
            text-transform: uppercase;
        }
        
        .batch-primary {
            background: rgba(79, 70, 229, 0.1);
            color: #1B3C53;
            border: 1px solid rgba(79, 70, 229, 0.2);
        }
        
        .batch-secondary {
            background: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .batch-tertiary {
            background: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .batch-quaternary {
            background: rgba(139, 92, 246, 0.1);
            color: #234C6A;
            border: 1px solid rgba(139, 92, 246, 0.2);
        }
        
        .week-navigation {
            background: var(--primary-gradient);
            border-radius: 10px;
            padding: 2px;
        }
        
        .week-display {
            background: white;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
        }
        
        @media (min-width: 768px) {
            .week-display { padding: 0.75rem 1rem; border-radius: 10px; }
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }
        
        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #1B3C53;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .select2-container--default .select2-selection--single {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            height: 44px;
            transition: border-color 0.3s ease;
        }
        
        .select2-container--default .select2-selection--single:focus {
            border-color: #1B3C53;
            outline: none;
            box-shadow: 0 0 0 3px rgba(27,60,83, 0.1);
        }
        
        .weekly-feedback-hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.2rem, 2.5vw, 1.9rem);
            margin-bottom: 1.5rem;
            color: white;
            background: var(--primary-gradient);
            box-shadow: 0 24px 58px rgba(27,60,83,.25);
        }
        
        .weekly-feedback-hero h1 {
            color: white !important;
            font-weight: 900;
            letter-spacing: -.03em;
        }
        
        .weekly-feedback-hero p {
            color: rgba(255,255,255,.84) !important;
            font-weight: 600;
        }
        
        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .48rem .76rem;
            border-radius: 999px;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.25);
            color: white;
            font-size: .75rem;
            font-weight: 900;
            backdrop-filter: blur(12px);
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
        }
        
        .stats-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            transition: transform 0.2s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .modal-overlay {
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }
        
        .modal-content {
            transform: scale(0.9);
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .modal-content.active {
            transform: scale(1);
            opacity: 1;
        }
        
        .student-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            transition: box-shadow 0.2s ease;
        }
        
        .student-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.08);
        }
        
        /* Mobile responsive */
        @media (max-width: 640px) {
            .weekly-feedback-hero { border-radius: 20px; }
            .glass-card { border-radius: 12px; }
            .stats-card { padding: 0.75rem; }
            .student-card { padding: 0.75rem; }
            .star-rating label { font-size: 20px; }
        }
        
        @media (min-width: 641px) and (max-width: 1023px) {
            .weekly-period-mini-grid {
                grid-template-columns: repeat(2, 1fr) !important;
            }
        }
        
        @media (max-width: 1024px) {
            button, .star-rating label, a[href] {
                min-height: 44px;
                min-width: 44px;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="loadingOverlay" class="loading-overlay hidden">
        <div class="spinner"></div>
    </div>
    
    <!-- Sidebar -->
    <?php include '../t_sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 transition-all duration-300 min-h-screen" id="main-content">
        <!-- Mobile Header -->
        <div class="lg:hidden sticky top-0 z-40 bg-white shadow-md">
            <div class="px-4 py-3 flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <button id="mobileSidebarToggle" class="p-2 text-gray-700">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div>
                        <h1 class="text-lg font-bold gradient-text">Weekly Feedback</h1>
                        <p class="text-xs text-gray-600 truncate">Trainer Dashboard</p>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="relative">
                        <i class="fas fa-bell text-gray-600 text-lg"></i>
                        <span class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">3</span>
                    </div>
                    <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold">
                        <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Desktop Header -->
        <header class="hidden lg:block bg-white shadow-lg sticky top-0 z-40">
            <div class="px-6 py-4 flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold gradient-text">Weekly Feedback</h1>
                    <p class="text-gray-600">Provide weekly feedback for your students across all their batches</p>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <i class="fas fa-bell text-gray-600 text-xl hover:text-purple-600 cursor-pointer transition-colors"></i>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">3</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold">
                            <?php echo strtoupper(substr($trainer['name'], 0, 2)); ?>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($trainer['name']); ?></p>
                            <p class="text-sm text-gray-500">Trainer</p>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <main class="p-3 sm:p-4 md:p-6">
            <!-- Hero Section -->
            <section class="weekly-feedback-hero">
                <h1 class="text-2xl md:text-3xl mb-2">
                    <i class="fas fa-calendar-week mr-2"></i>Weekly Feedback
                </h1>
                <p class="mb-4">Provide weekly performance feedback for your assigned batch students.</p>
                <div class="flex flex-wrap gap-2">
                    <span class="hero-chip"><i class="fas fa-user-tie"></i><?php echo htmlspecialchars($trainer['name']); ?></span>
                    <span class="hero-chip"><i class="fas fa-calendar"></i>Week <?php echo $week_number; ?>, <?php echo $year; ?></span>
                    <span class="hero-chip"><i class="fas fa-layer-group"></i><?php echo count($batches); ?> assigned batches</span>
                </div>
            </section>
            
            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-green-50 border border-green-200 rounded-lg" id="successMessage">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-green-500 text-lg sm:text-xl mr-2 sm:mr-3"></i>
                        <div>
                            <p class="font-medium text-green-800 text-sm sm:text-base">Success!</p>
                            <p class="text-green-700 text-xs sm:text-sm"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="mb-4 sm:mb-6 p-3 sm:p-4 bg-red-50 border border-red-200 rounded-lg" id="errorMessage">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle text-red-500 text-lg sm:text-xl mr-2 sm:mr-3"></i>
                        <div>
                            <p class="font-medium text-red-800 text-sm sm:text-base">Error!</p>
                            <p class="text-red-700 text-xs sm:text-sm"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Main Content -->
            <div class="glass-card p-3 sm:p-4 md:p-6 mb-4 sm:mb-6">
                <div class="section-kicker"><i class="fas fa-calendar-alt"></i> Feedback Period</div>
                
                <!-- Week Navigation -->
                <div class="mb-4 sm:mb-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between mb-4 sm:mb-6">
                        <div class="mb-4 md:mb-0">
                            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-1 sm:mb-2">Weekly Feedback Period</h2>
                            <p class="text-gray-600 text-xs sm:text-sm">Provide feedback for each student's weekly performance</p>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-3">
                                <div class="flex items-center gap-3 p-3 rounded-xl" style="background:linear-gradient(135deg,#1B3C53,#234C6A);color:#fff;">
                                    <i class="fas fa-calendar-week text-xl"></i>
                                    <div>
                                        <strong class="block text-sm">Week <?php echo $week_number; ?></strong>
                                        <small class="text-xs opacity-80"><?php echo date('M j', strtotime($week_start)); ?> - <?php echo date('M j', strtotime($week_end)); ?></small>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 p-3 rounded-xl" style="background:linear-gradient(135deg,#047857,#10b981);color:#fff;">
                                    <i class="fas fa-layer-group text-xl"></i>
                                    <div>
                                        <strong class="block text-sm"><?php echo count($batches); ?> Batches</strong>
                                        <small class="text-xs opacity-80">Available for review</small>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 p-3 rounded-xl" style="background:linear-gradient(135deg,#b45309,#f59e0b);color:#fff;">
                                    <i class="fas fa-star-half-alt text-xl"></i>
                                    <div>
                                        <strong class="block text-sm">Weekly Score</strong>
                                        <small class="text-xs opacity-80">Rating + remarks</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col xs:flex-row items-stretch xs:items-center space-y-2 xs:space-y-0 xs:space-x-3 mt-4 md:mt-0">
                            <a href="?date=<?php echo date('Y-m-d', strtotime($current_date . ' -7 days')); ?><?php echo $selected_batch ? '&batch_id=' . $selected_batch : ''; ?>" 
                               class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors text-center text-xs sm:text-sm flex items-center justify-center">
                                <i class="fas fa-chevron-left mr-1 sm:mr-2"></i> Previous Week
                            </a>
                            
                            <div class="week-navigation">
                                <div class="week-display flex items-center space-x-2 sm:space-x-4">
                                    <div class="text-center flex-shrink-0">
                                        <div class="text-xs font-semibold text-gray-500">Week</div>
                                        <div class="text-lg sm:text-xl font-bold text-purple-600"><?php echo $week_number; ?></div>
                                    </div>
                                    <div class="h-6 sm:h-8 w-px bg-gray-200"></div>
                                    <div class="min-w-0">
                                        <div class="font-bold text-gray-800 text-sm sm:text-base">
                                            <?php echo date('M j', strtotime($week_start)); ?> - <?php echo date('M j, Y', strtotime($week_end)); ?>
                                        </div>
                                        <div class="text-xs text-gray-500">Week <?php echo $week_number; ?>, <?php echo $year; ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <a href="?date=<?php echo date('Y-m-d', strtotime($current_date . ' +7 days')); ?><?php echo $selected_batch ? '&batch_id=' . $selected_batch : ''; ?>" 
                               class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors text-center text-xs sm:text-sm flex items-center justify-center">
                                Next Week <i class="fas fa-chevron-right ml-1 sm:ml-2"></i>
                            </a>
                            
                            <a href="?date=<?php echo date('Y-m-d'); ?><?php echo $selected_batch ? '&batch_id=' . $selected_batch : ''; ?>" 
                               class="px-3 py-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg font-medium hover:opacity-90 transition-opacity text-center text-xs sm:text-sm">
                                Current Week
                            </a>
                        </div>
                    </div>
                    
                    <!-- Batch Selection -->
                    <div class="mb-4 sm:mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Select Batch</label>
                        <select id="batchSelect" class="w-full max-w-md">
                            <option value="">Select a batch...</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?php echo $batch['batch_id']; ?>" 
                                    <?php echo $selected_batch == $batch['batch_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($batch['batch_name']); ?> 
                                    (<?php echo ucfirst($batch['status']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($selected_batch && !empty($batch_students)): 
                        $batch_name = '';
                        foreach ($batches as $batch) {
                            if ($batch['batch_id'] == $selected_batch) {
                                $batch_name = $batch['batch_name'];
                                break;
                            }
                        }
                    ?>
                        <div class="section-kicker"><i class="fas fa-chart-pie"></i> Weekly Stats</div>
                        
                        <!-- Stats -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4 mb-4 sm:mb-6">
                            <div class="stats-card">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-r from-blue-500 to-indigo-500 rounded-lg flex items-center justify-center text-white mr-3 sm:mr-4 flex-shrink-0">
                                        <i class="fas fa-users text-sm sm:text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs sm:text-sm text-gray-600">Total Students</p>
                                        <p class="text-lg sm:text-2xl font-bold text-gray-800"><?php echo $stats['total_students'] ?? 0; ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="stats-card">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-r from-green-500 to-emerald-500 rounded-lg flex items-center justify-center text-white mr-3 sm:mr-4 flex-shrink-0">
                                        <i class="fas fa-check-circle text-sm sm:text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs sm:text-sm text-gray-600">Feedback Given</p>
                                        <p class="text-lg sm:text-2xl font-bold text-gray-800"><?php echo $stats['feedback_given'] ?? 0; ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo round((($stats['feedback_given'] ?? 0) / max(1, ($stats['total_students'] ?? 1))) * 100); ?>% completed
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="stats-card">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-r from-amber-500 to-orange-500 rounded-lg flex items-center justify-center text-white mr-3 sm:mr-4 flex-shrink-0">
                                        <i class="fas fa-star text-sm sm:text-lg"></i>
                                    </div>
                                    <div>
                                        <p class="text-xs sm:text-sm text-gray-600">Average Rating</p>
                                        <p class="text-lg sm:text-2xl font-bold text-gray-800">
                                            <?php echo number_format($stats['avg_rating'] ?? 0, 1); ?>/5.0
                                        </p>
                                        <div class="flex">
                                            <?php 
                                            $avg_rating = $stats['avg_rating'] ?? 0;
                                            for ($i = 1; $i <= 5; $i++): 
                                                if ($i <= floor($avg_rating)): ?>
                                                    <i class="fas fa-star text-amber-400 text-xs"></i>
                                                <?php elseif ($i == ceil($avg_rating) && fmod($avg_rating, 1) > 0): ?>
                                                    <i class="fas fa-star-half-alt text-amber-400 text-xs"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star text-gray-300 text-xs"></i>
                                                <?php endif; 
                                            endfor; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="section-kicker"><i class="fas fa-users"></i> Student Feedback List</div>
                        
                        <!-- Students List -->
                        <div class="mb-4 sm:mb-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-3 sm:mb-4 flex items-center">
                                <i class="fas fa-user-graduate mr-2 text-purple-500"></i>
                                Students in <?php echo htmlspecialchars($batch_name); ?>
                                <span class="ml-2 text-sm font-normal text-gray-600">(All batch assignments)</span>
                            </h3>
                            
                            <?php if (empty($batch_students)): ?>
                                <div class="text-center py-8 sm:py-10 bg-gray-50 rounded-xl">
                                    <i class="fas fa-user-graduate text-3xl sm:text-4xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-500 text-sm sm:text-base">No active students in this batch.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3 sm:space-y-4">
                                    <?php foreach ($batch_students as $student): 
                                        $has_feedback = isset($feedback_lookup[$student['student_id']]);
                                        $existing_feedback = $has_feedback ? $feedback_lookup[$student['student_id']] : null;
                                    ?>
                                        <div class="student-card">
                                            <div class="flex flex-col md:flex-row md:items-start justify-between gap-3 sm:gap-4">
                                                <!-- Student Info -->
                                                <div class="flex items-center mb-3 md:mb-0">
                                                    <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold text-sm sm:text-lg mr-3 sm:mr-4 flex-shrink-0">
                                                        <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <div class="flex items-center flex-wrap">
                                                            <h4 class="font-bold text-gray-800 text-sm sm:text-base">
                                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                                            </h4>
                                                            <?php if (!empty($student['batch_role'])): 
                                                                $role_class = '';
                                                                switch($student['batch_role']) {
                                                                    case 'Primary': $role_class = 'batch-primary'; break;
                                                                    case 'Secondary': $role_class = 'batch-secondary'; break;
                                                                    case 'Tertiary': $role_class = 'batch-tertiary'; break;
                                                                    case 'Quaternary': $role_class = 'batch-quaternary'; break;
                                                                    default: $role_class = 'batch-primary';
                                                                }
                                                            ?>
                                                                <span class="batch-role-badge <?php echo $role_class; ?>">
                                                                    <?php echo $student['batch_role']; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-xs text-gray-600">ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                                                        <p class="text-xs text-gray-500">Enrolled: <?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?></p>
                                                    </div>
                                                </div>
                                                
                                                <!-- Feedback Status -->
                                                <div class="flex items-center mb-3 md:mb-0">
                                                    <?php if ($has_feedback): ?>
                                                        <div class="text-center mr-3 sm:mr-6">
                                                            <span class="status-badge status-completed">Feedback Given</span>
                                                            <p class="text-xs text-gray-500 mt-1">
                                                                <?php echo date('M j, g:i A', strtotime($existing_feedback['submitted_at'])); ?>
                                                            </p>
                                                        </div>
                                                        <div>
                                                            <div class="flex mb-1">
                                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                                    <?php if ($i <= $existing_feedback['rating']): ?>
                                                                        <i class="fas fa-star text-amber-400 text-lg sm:text-xl"></i>
                                                                    <?php else: ?>
                                                                        <i class="far fa-star text-gray-300 text-lg sm:text-xl"></i>
                                                                    <?php endif; ?>
                                                                <?php endfor; ?>
                                                            </div>
                                                            <p class="text-sm font-semibold text-gray-700"><?php echo $existing_feedback['rating']; ?>/5</p>
                                                            <?php if (!empty($existing_feedback['remarks'])): ?>
                                                                <p class="text-xs text-gray-600 mt-2 max-w-xs truncate">
                                                                    <i class="fas fa-comment mr-1"></i>
                                                                    <?php echo htmlspecialchars(substr($existing_feedback['remarks'], 0, 50)); ?>
                                                                    <?php if (strlen($existing_feedback['remarks']) > 50): ?>...<?php endif; ?>
                                                                </p>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="status-badge status-pending">Pending Feedback</span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <!-- Feedback Button -->
                                                <div class="flex-shrink-0">
                                                    <?php if (!$has_feedback): ?>
                                                        <button onclick="openFeedbackModal(
                                                            '<?php echo $student['student_id']; ?>',
                                                            '<?php echo htmlspecialchars(addslashes($student['first_name'] . ' ' . $student['last_name'])); ?>',
                                                            '<?php echo htmlspecialchars(addslashes($student['batch_role'] ?? 'Primary')); ?>'
                                                        )" class="px-3 py-2 sm:px-4 sm:py-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg font-medium hover:opacity-90 transition-opacity text-xs sm:text-sm w-full md:w-auto">
                                                            <i class="fas fa-edit mr-1 sm:mr-2"></i> Give Feedback
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="px-3 py-2 sm:px-4 sm:py-2 bg-gray-100 text-gray-600 rounded-lg font-medium cursor-not-allowed text-xs sm:text-sm w-full md:w-auto" disabled>
                                                            <i class="fas fa-check mr-1 sm:mr-2"></i> Feedback Submitted
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="section-kicker"><i class="fas fa-history"></i> Recent History</div>
                        
                        <!-- Feedback History -->
                        <?php if (!empty($feedback_history)): ?>
                            <div class="glass-card p-3 sm:p-4 md:p-6">
                                <h3 class="text-lg font-bold text-gray-800 mb-3 sm:mb-4 flex items-center">
                                    <i class="fas fa-history mr-2 text-blue-500"></i>
                                    Recent Feedback History
                                </h3>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Week</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Batch</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Feedback</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Avg Rating</th>
                                                <th class="px-3 py-2 text-left text-xs font-semibold text-gray-700 uppercase">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($feedback_history as $history): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        <div class="font-medium text-gray-900 text-xs sm:text-sm">
                                                            Week <?php echo date('W', strtotime($history['week_start_date'])); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            <?php echo date('M j', strtotime($history['week_start_date'])); ?> - 
                                                            <?php echo date('M j', strtotime($history['week_end_date'])); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-2 whitespace-nowrap text-xs sm:text-sm text-gray-900">
                                                        <?php echo htmlspecialchars($history['batch_name']); ?>
                                                    </td>
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        <span class="font-semibold text-purple-600"><?php echo $history['feedback_count']; ?></span>
                                                        <span class="text-xs text-gray-500">students</span>
                                                    </td>
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <div class="flex mr-2">
                                                                <?php 
                                                                $avg = $history['avg_rating'];
                                                                for ($i = 1; $i <= 5; $i++): 
                                                                    if ($i <= floor($avg)): ?>
                                                                        <i class="fas fa-star text-amber-400 text-xs"></i>
                                                                    <?php elseif ($i == ceil($avg) && fmod($avg, 1) > 0): ?>
                                                                        <i class="fas fa-star-half-alt text-amber-400 text-xs"></i>
                                                                    <?php else: ?>
                                                                        <i class="far fa-star text-gray-300 text-xs"></i>
                                                                    <?php endif; 
                                                                endfor; ?>
                                                            </div>
                                                            <span class="font-semibold text-gray-800 text-xs sm:text-sm">
                                                                <?php echo number_format($avg, 1); ?>
                                                            </span>
                                                        </div>
                                                    </td>
                                                    <td class="px-3 py-2 whitespace-nowrap">
                                                        <a href="?batch_id=<?php echo $selected_batch; ?>&date=<?php echo $history['week_start_date']; ?>" 
                                                           class="px-2 py-1 sm:px-3 sm:py-1 bg-blue-100 text-blue-700 rounded-lg text-xs font-medium hover:bg-blue-200 transition-colors inline-block">
                                                            View
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                    <?php elseif ($selected_batch && empty($batch_students)): ?>
                        <div class="text-center py-8 sm:py-10 bg-gray-50 rounded-xl">
                            <i class="fas fa-user-slash text-4xl sm:text-5xl text-gray-300 mb-4"></i>
                            <p class="text-gray-600 mb-2 text-sm sm:text-base">No active students found in this batch.</p>
                            <p class="text-sm text-gray-500">All students might be inactive or transferred.</p>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 sm:py-10 bg-gray-50 rounded-xl">
                            <i class="fas fa-hand-pointer text-4xl sm:text-5xl text-gray-300 mb-4"></i>
                            <p class="text-gray-600 mb-2 text-sm sm:text-base">Select a batch to view students</p>
                            <p class="text-sm text-gray-500">Choose a batch from the dropdown above to start giving feedback</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="mt-4 sm:mt-8 py-3 sm:py-4 text-center text-gray-500 text-xs sm:text-sm border-t border-gray-200">
            <p>ASD Academy Trainer Portal © <?php echo date('Y'); ?>. All rights reserved.</p>
        </footer>
    </div>

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-overlay fixed inset-0" onclick="closeFeedbackModal()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-3 sm:p-4">
            <div class="modal-content bg-white rounded-xl sm:rounded-2xl shadow-2xl w-full max-w-sm sm:max-w-md max-h-[90vh] overflow-y-auto">
                <!-- Modal Header -->
                <div class="p-4 sm:p-6 border-b border-gray-200" style="background:linear-gradient(135deg,#1B3C53,#234C6A);border-radius:12px 12px 0 0;">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center">
                                <i class="fas fa-edit text-white text-lg"></i>
                            </div>
                            <div>
                                <div class="text-white/70 text-xs font-bold uppercase tracking-wider">Trainer Feedback</div>
                                <div class="text-white text-base font-bold">Give Weekly Feedback</div>
                            </div>
                        </div>
                        <button onclick="closeFeedbackModal()" class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center text-white hover:bg-white/30 transition-colors">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="p-4 sm:p-6">
                    <form id="feedbackForm" method="POST" action="">
                        <input type="hidden" name="student_id" id="modalStudentId">
                        <input type="hidden" name="batch_id" value="<?php echo $selected_batch; ?>">
                        <input type="hidden" name="submit_feedback" value="1">
                        
                        <div class="mb-4 sm:mb-6">
                            <div class="flex items-center mb-4">
                                <div id="studentAvatar" class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold text-sm sm:text-base mr-3 flex-shrink-0"></div>
                                <div>
                                    <div class="flex items-center flex-wrap">
                                        <h4 id="studentName" class="font-bold text-gray-800 text-sm sm:text-base"></h4>
                                        <span id="studentBatchRole" class="batch-role-badge ml-2"></span>
                                    </div>
                                    <p id="studentId" class="text-xs sm:text-sm text-gray-600"></p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-star mr-1 text-amber-500"></i>
                                    Weekly Performance Rating <span class="text-red-500">*</span>
                                </label>
                                <div class="star-rating">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <input type="radio" id="star<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>" required>
                                        <label for="star<?php echo $i; ?>" title="<?php echo $i; ?> stars">
                                            <i class="fas fa-star"></i>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                                <div class="flex justify-between text-xs text-gray-500 mt-2">
                                    <span>Poor</span>
                                    <span>Excellent</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4 sm:mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-comment-alt mr-1 text-blue-600"></i>
                                Remarks <span class="text-gray-400 font-normal">(Optional)</span>
                            </label>
                            <textarea name="remarks" id="remarks" rows="3" 
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base" 
                                      placeholder="Add any additional comments about this student's weekly performance..."></textarea>
                            <div class="text-right">
                                <span id="charCount" class="text-xs text-gray-500">500 characters remaining</span>
                            </div>
                        </div>
                        
                        <div class="bg-blue-50 p-3 sm:p-4 rounded-lg mb-4 sm:mb-6">
                            <div class="flex items-start">
                                <i class="fas fa-info-circle text-blue-500 mt-1 mr-2 sm:mr-3 flex-shrink-0"></i>
                                <div>
                                    <p class="text-xs sm:text-sm text-blue-800">
                                        <strong>Note:</strong> This feedback is for the week of 
                                        <?php echo date('M j', strtotime($week_start)); ?> - <?php echo date('M j, Y', strtotime($week_end)); ?>.
                                        Once submitted, feedback cannot be edited.
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex flex-col xs:flex-row xs:space-x-3 space-y-2 xs:space-y-0">
                            <button type="button" onclick="closeFeedbackModal()" 
                                    class="flex-1 px-3 py-2 sm:px-4 sm:py-3 border border-gray-300 text-gray-700 rounded-lg font-medium hover:bg-gray-50 transition-colors text-sm sm:text-base">
                                Cancel
                            </button>
                            <button type="submit" name="submit_feedback" 
                                    class="flex-1 px-3 py-2 sm:px-4 sm:py-3 bg-gradient-to-r from-purple-500 to-pink-500 text-white rounded-lg font-medium hover:opacity-90 transition-opacity text-sm sm:text-base">
                                <i class="fas fa-paper-plane mr-1 sm:mr-2"></i> Submit Feedback
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile sidebar toggle
            const mobileToggle = document.getElementById('mobileSidebarToggle');
            const sidebar = document.querySelector('aside');
            const body = document.body;
            
            if (mobileToggle) {
                mobileToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('-translate-x-full');
                    body.classList.toggle('sidebar-open');
                });
            }
            
            // Close sidebar on link click (mobile)
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
            
            // Initialize Select2
            $('#batchSelect').select2({
                placeholder: "Select a batch...",
                allowClear: false,
                width: '100%',
                dropdownParent: $('#main-content')
            }).on('change', function() {
                const batchId = $(this).val();
                if (batchId) {
                    const currentDate = '<?php echo $current_date; ?>';
                    window.location.href = `weekly_feedback.php?batch_id=${batchId}&date=${currentDate}`;
                }
            });
            
            // Character counter
            const remarksField = document.getElementById('remarks');
            const charCount = document.getElementById('charCount');
            
            if (remarksField && charCount) {
                remarksField.addEventListener('input', function() {
                    const remaining = 500 - this.value.length;
                    charCount.textContent = `${remaining} characters remaining`;
                    charCount.style.color = remaining < 50 ? '#ef4444' : remaining < 100 ? '#f59e0b' : '#6b7280';
                });
            }
        });
        
        // Modal functions
        function openFeedbackModal(studentId, studentName, batchRole) {
            document.getElementById('modalStudentId').value = studentId;
            document.getElementById('studentName').textContent = studentName;
            document.getElementById('studentId').textContent = 'ID: ' + studentId;
            
            const roleBadge = document.getElementById('studentBatchRole');
            roleBadge.textContent = batchRole;
            roleBadge.className = 'batch-role-badge ml-2 ';
            switch(batchRole) {
                case 'Primary': roleBadge.className += 'batch-primary'; break;
                case 'Secondary': roleBadge.className += 'batch-secondary'; break;
                case 'Tertiary': roleBadge.className += 'batch-tertiary'; break;
                case 'Quaternary': roleBadge.className += 'batch-quaternary'; break;
                default: roleBadge.className += 'batch-primary';
            }
            
            const names = studentName.split(' ');
            const initials = (names[0].charAt(0) + (names[1] ? names[1].charAt(0) : names[0].charAt(1))).toUpperCase();
            document.getElementById('studentAvatar').textContent = initials;
            
            document.getElementById('feedbackForm').reset();
            document.querySelectorAll('.star-rating input[type="radio"]').forEach(radio => radio.checked = false);
            
            const modal = document.getElementById('feedbackModal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            setTimeout(() => {
                modal.querySelector('.modal-content').classList.add('active');
            }, 10);
        }
        
        function closeFeedbackModal() {
            const modal = document.getElementById('feedbackModal');
            modal.querySelector('.modal-content').classList.remove('active');
            
            setTimeout(() => {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }, 300);
        }
        
        // Close modal on outside click
        document.getElementById('feedbackModal').addEventListener('click', function(e) {
            if (e.target === this || e.target.classList.contains('modal-overlay')) {
                closeFeedbackModal();
            }
        });
        
        // Form submission
        document.getElementById('feedbackForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const rating = document.querySelector('input[name="rating"]:checked');
            const submitBtn = this.querySelector('button[type="submit"]');
            
            if (!rating) {
                showError('Please select a rating (1-5 stars)');
                return;
            }
            
            document.getElementById('loadingOverlay').classList.remove('hidden');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Submitting...';
            
            this.submit();
        });
        
        function showError(message) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg';
            errorDiv.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            const form = document.getElementById('feedbackForm');
            form.insertBefore(errorDiv, form.firstChild);
            
            setTimeout(() => {
                if (errorDiv.parentNode) errorDiv.remove();
            }, 5000);
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeFeedbackModal();
            
            if (!document.getElementById('feedbackModal').classList.contains('hidden')) {
                if (e.key >= '1' && e.key <= '5') {
                    e.preventDefault();
                    const rating = parseInt(e.key);
                    const starInput = document.getElementById(`star${rating}`);
                    if (starInput) starInput.checked = true;
                }
            }
        });
        
        // Auto-hide messages
        setTimeout(() => {
            ['successMessage', 'errorMessage'].forEach(id => {
                const el = document.getElementById(id);
                if (el) {
                    el.style.opacity = '0';
                    el.style.transition = 'opacity 1s ease';
                    setTimeout(() => { if (el.parentNode) el.remove(); }, 1000);
                }
            });
        }, 5000);
    </script>
</body>
</html>