<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Get current day of week (0 = Sunday, 6 = Saturday)
$currentDayOfWeek = date('w');
$isSaturday = ($currentDayOfWeek == 6); // 6 = Saturday

// Get student information
$student_user_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT 
        s.*,
        c.name as course_name,
        u.email as user_email,
        s.batch_name_2 as current_batch
    FROM students s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN courses c ON s.course = c.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Check if terms need to be accepted
if ($student['terms_accepted'] == 0) {
    // Check if batch requires terms
    $batch_id = $student['current_batch'];
    if ($batch_id) {
        $batch_terms_query = $db->prepare("
            SELECT require_terms_acceptance 
            FROM batch_terms_settings 
            WHERE batch_id = :batch_id
        ");
        $batch_terms_query->execute([':batch_id' => $batch_id]);
        $batch_terms = $batch_terms_query->fetch(PDO::FETCH_ASSOC);
        
        $require_terms = $batch_terms ? $batch_terms['require_terms_acceptance'] : 1;
        
        if ($require_terms) {
            header("Location: terms_conditions.php");
            exit();
        }
    }
}

// Get current batches (batch_name, batch_name_2, batch_name_3, batch_name_4)
$current_batches = [];
$batch_names = ['batch_name', 'batch_name_2', 'batch_name_3', 'batch_name_4'];
$selected_batch_index = isset($_GET['batch_index']) ? intval($_GET['batch_index']) : 0;

foreach ($batch_names as $batch_field) {
    if (!empty($student[$batch_field])) {
        $batch_query = $db->prepare("
            SELECT * 
            FROM batches 
            WHERE batch_id = :batch_id
        ");
        $batch_query->execute([':batch_id' => $student[$batch_field]]);
        $batch_data = $batch_query->fetch(PDO::FETCH_ASSOC);
        
        if ($batch_data) {
            $current_batches[] = [
                'field_name' => $batch_field,
                'batch_name' => $student[$batch_field],
                'batch_data' => $batch_data
            ];
        }
    }
}

// Validate selected batch index
if ($selected_batch_index >= count($current_batches)) {
    $selected_batch_index = 0;
}

$selected_batch = null;
$selected_batch_id = null;

// Get selected batch data
if (!empty($current_batches) && isset($current_batches[$selected_batch_index])) {
    $selected_batch = $current_batches[$selected_batch_index]['batch_data'];
    $selected_batch_id = $selected_batch['batch_id'];
}

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    // Validate required fields
    $required_fields = ['class_rating', 'assignment_understanding', 'practical_understanding', 'satisfied', 'regular_in_class', 'feedback_batch_id'];
    $valid = true;
    
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $valid = false;
            break;
        }
    }
    
    if ($valid) {
        // Convert 'Yes'/'No' to 1/0 for satisfied field
        $satisfied_value = ($_POST['satisfied'] === 'Yes') ? 1 : 0;
        
        // Get batch name for the selected feedback batch
        $feedback_batch_id = $_POST['feedback_batch_id'];
        $feedback_batch_name = '';
        foreach ($current_batches as $batch) {
            if ($batch['batch_name'] == $feedback_batch_id) {
                $feedback_batch_name = $batch['batch_data']['batch_name'];
                break;
            }
        }
        
        $stmt = $db->prepare("INSERT INTO feedback (date, student_name, email, batch_id, course_name, 
                             class_rating, assignment_understanding, practical_understanding, satisfied, 
                             suggestions, feedback_text, is_regular) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $result = $stmt->execute([
            date('Y-m-d'),
            $student['first_name'] . ' ' . $student['last_name'],
            $student['user_email'],
            $feedback_batch_id,
            $student['course_name'] ?? 'Unknown Course',
            intval($_POST['class_rating']),
            intval($_POST['assignment_understanding']),
            intval($_POST['practical_understanding']),
            $satisfied_value,
            $_POST['suggestions'] ?? '',
            $_POST['feedback_text'] ?? '',
            $_POST['regular_in_class']
        ]);
        
        if ($result) {
            $_SESSION['feedback_submitted'] = true;
            $_SESSION['modal_closed'] = true;
            $_SESSION['saturday_feedback_completed'] = true;
        }
    }
}

$student_id_value = $student['student_id'];
$student_name = $student['first_name'] . ' ' . $student['last_name'];

// Get all batch IDs associated with the student (current + historical)
$batch_ids = [];

// Add current batches
foreach ($current_batches as $batch) {
    if (!in_array($batch['batch_name'], $batch_ids)) {
        $batch_ids[] = $batch['batch_name'];
    }
}

// Get historical batches from student_batch_history
$history_query = $db->prepare("
    SELECT DISTINCT from_batch_id, to_batch_id 
    FROM student_batch_history 
    WHERE student_id = :student_id
");
$history_query->execute([':student_id' => $student_id_value]);
$history_batches = $history_query->fetchAll(PDO::FETCH_ASSOC);

foreach ($history_batches as $batch) {
    if ($batch['from_batch_id'] && !in_array($batch['from_batch_id'], $batch_ids)) {
        $batch_ids[] = $batch['from_batch_id'];
    }
    if ($batch['to_batch_id'] && !in_array($batch['to_batch_id'], $batch_ids)) {
        $batch_ids[] = $batch['to_batch_id'];
    }
}

// Get progress data for selected batch
$batch_progress = [
    'total_topics' => 0, 'covered_topics' => 0, 
    'total_sub_topics' => 0, 'completed_sub_topics' => 0, 
    'theory_completed_total' => 0, 'practical_completed_total' => 0,
    'topic_progress' => 0, 'sub_topic_progress' => 0,
    'theory_progress' => 0, 'practical_progress' => 0,
    'overall_progress' => 0
];

$batch_topics = [];

if ($selected_batch_id) {
    // Get progress data for selected batch
    $progress_stmt = $db->prepare("
        SELECT 
            mt.id,
            mt.chapter,
            mt.topic_name,
            mt.topic_type,
            mt.covered_by_trainer,
            mt.covered_date,
            COUNT(st.id) as total_sub_topics,
            SUM(CASE WHEN st.theory_completed = 1 AND st.practical_completed = 1 THEN 1 ELSE 0 END) as fully_completed_sub_topics,
            SUM(st.theory_completed) as theory_completed_count,
            SUM(st.practical_completed) as practical_completed_count,
            GROUP_CONCAT(DISTINCT CONCAT(st.id, ':', st.sub_topic_name, ':', st.theory_completed, ':', st.practical_completed) SEPARATOR '||') as sub_topic_details
        FROM main_topics mt
        LEFT JOIN sub_topics st ON mt.id = st.main_topic_id
        WHERE mt.batch_name = ?
        GROUP BY mt.id
        ORDER BY mt.chapter
    ");
    $progress_stmt->execute([$selected_batch_id]);
    $batch_topics = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate overall progress
    $total_topics = count($batch_topics);
    $covered_topics = 0;
    $total_sub_topics = 0;
    $completed_sub_topics = 0;
    $theory_completed_total = 0;
    $practical_completed_total = 0;

    foreach ($batch_topics as $topic) {
        if ($topic['sub_topic_details'] !== null) {
            if ($topic['covered_by_trainer']) $covered_topics++;
            $total_sub_topics += $topic['total_sub_topics'] ?? 0;
            $completed_sub_topics += $topic['fully_completed_sub_topics'] ?? 0;
            $theory_completed_total += $topic['theory_completed_count'] ?? 0;
            $practical_completed_total += $topic['practical_completed_count'] ?? 0;
        }
    }

    $batch_progress = [
        'total_topics' => $total_topics,
        'covered_topics' => $covered_topics,
        'total_sub_topics' => $total_sub_topics,
        'completed_sub_topics' => $completed_sub_topics,
        'theory_completed_total' => $theory_completed_total,
        'practical_completed_total' => $practical_completed_total,
        'topic_progress' => $total_topics > 0 ? round(($covered_topics / $total_topics) * 100) : 0,
        'sub_topic_progress' => $total_sub_topics > 0 ? round(($completed_sub_topics / $total_sub_topics) * 100) : 0,
        'theory_progress' => $total_sub_topics > 0 ? round(($theory_completed_total / $total_sub_topics) * 100) : 0,
        'practical_progress' => $total_sub_topics > 0 ? round(($practical_completed_total / $total_sub_topics) * 100) : 0
    ];
    
    // Calculate overall progress
    $overall_progress = 0;
    if ($total_sub_topics > 0) {
        $overall_progress = round((($theory_completed_total + $practical_completed_total) / ($total_sub_topics * 2)) * 100, 2);
    }
    $batch_progress['overall_progress'] = $overall_progress;
}

// Get attendance data for selected batch
$attendance_data = null;
if ($selected_batch_id) {
    $attendance_query = $db->prepare("
        SELECT 
            COUNT(*) as total_classes,
            SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
            SUM(CASE WHEN camera_status = 'On' THEN 1 ELSE 0 END) as camera_on_count
        FROM attendance 
        WHERE student_id = :student_id AND batch_id = :batch_id
    ");
    $attendance_query->execute([
        ':student_id' => $student_id_value,
        ':batch_id' => $selected_batch_id
    ]);
    $attendance_data = $attendance_query->fetch(PDO::FETCH_ASSOC);

    // If no attendance found with student_id and batch_id, try with student_name as fallback
    if (!$attendance_data || $attendance_data['total_classes'] == 0) {
        $student_name = $student['first_name'] . ' ' . $student['last_name'];
        $attendance_query_fallback = $db->prepare("
            SELECT 
                COUNT(*) as total_classes,
                SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_count,
                SUM(CASE WHEN camera_status = 'On' THEN 1 ELSE 0 END) as camera_on_count
            FROM attendance 
            WHERE student_name = :student_name AND batch_id = :batch_id
        ");
        $attendance_query_fallback->execute([
            ':student_name' => $student_name,
            ':batch_id' => $selected_batch_id
        ]);
        $attendance_data = $attendance_query_fallback->fetch(PDO::FETCH_ASSOC);
    }
}

$attendance_percentage = $attendance_data && $attendance_data['total_classes'] > 0 ? 
    round(($attendance_data['present_count'] / $attendance_data['total_classes']) * 100, 2) : 0;
$camera_usage_percentage = $attendance_data && $attendance_data['total_classes'] > 0 ? 
    round(($attendance_data['camera_on_count'] / $attendance_data['total_classes']) * 100, 2) : 0;

// Get exam performance data for selected batch
$exam_percentage = 0;
$pass_rate = 0;
$exam_data = null;

if ($selected_batch_id) {
    $exam_query = $db->prepare("
        SELECT 
            COUNT(*) as total_exams,
            SUM(CASE WHEN er.obtained_marks >= e.passing_marks THEN 1 ELSE 0 END) as passed_exams,
            AVG(er.obtained_marks) as avg_marks,
            AVG((er.obtained_marks / e.total_marks) * 100) as avg_percentage
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        WHERE er.student_id = :student_id AND e.batch_id = :batch_id
    ");
    $exam_query->execute([
        ':student_id' => $student_id_value,
        ':batch_id' => $selected_batch_id
    ]);
    $exam_data = $exam_query->fetch(PDO::FETCH_ASSOC);

    $exam_percentage = $exam_data && $exam_data['total_exams'] > 0 ? 
        round($exam_data['avg_percentage'], 2) : 0;
    $pass_rate = $exam_data && $exam_data['total_exams'] > 0 ? 
        round(($exam_data['passed_exams'] / $exam_data['total_exams']) * 100, 2) : 0;
}

// Get recent attendance records for display (from all batches)
$start_date = date('Y-m-01');
$end_date = date('Y-m-t');
$recent_attendance = [];

if (!empty($batch_ids)) {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $recent_attendance_query = $db->prepare("
        SELECT 
            *,
            DAYNAME(date) as day_name
        FROM attendance 
        WHERE (student_id = ? OR student_name = ?)
        AND batch_id IN ($placeholders)
        ORDER BY date DESC
        LIMIT 5
    ");
    $params = [$student_id_value, $student_name];
    $params = array_merge($params, $batch_ids);
    $recent_attendance_query->execute($params);
    $recent_attendance = $recent_attendance_query->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent exam results (from all batches the student belongs to)
$recent_exams = [];
if (!empty($batch_ids)) {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $exam_results_query = $db->prepare("
        SELECT 
            e.exam_name,
            e.exam_date,
            e.total_marks,
            er.obtained_marks,
            er.grade,
            e.batch_id
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        WHERE er.student_id = ?
        AND e.batch_id IN ($placeholders)
        ORDER BY e.exam_date DESC
        LIMIT 5
    ");
    $params = [$student_id_value];
    $params = array_merge($params, $batch_ids);
    $exam_results_query->execute($params);
    $recent_exams = $exam_results_query->fetchAll(PDO::FETCH_ASSOC);
}

// Get recent content (from all batches)
$recent_content = [];
if (!empty($batch_ids)) {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $content_query = $db->prepare("
        SELECT DISTINCT u.*, 
               b.batch_id, 
               b.batch_name,
               b.status as batch_status,
               CASE 
                   WHEN b.batch_id = ? THEN 'Current'
                   ELSE 'Previous'
               END as batch_type
        FROM uploads u
        JOIN batch_uploads bu ON u.id = bu.upload_id
        JOIN batches b ON bu.batch_id = b.batch_id
        WHERE bu.batch_id IN ($placeholders)
        ORDER BY u.uploaded_at DESC
        LIMIT 5
    ");
    $params = [$selected_batch_id];
    $params = array_merge($params, $batch_ids);
    $content_query->execute($params);
    $recent_content = $content_query->fetchAll(PDO::FETCH_ASSOC);
}

// Get upcoming classes (next 30 days) from all batches
$upcoming_classes = [];
if (!empty($batch_ids)) {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $upcoming_query = $db->prepare("
        SELECT s.*, b.batch_name, b.mode, b.meeting_link, b.platform
        FROM schedule s
        JOIN batches b ON s.batch_id = b.batch_id
        WHERE s.batch_id IN ($placeholders) 
        AND s.schedule_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        AND s.is_cancelled = 0
        ORDER BY s.schedule_date ASC, s.start_time ASC
        LIMIT 5
    ");
    $upcoming_query->execute($batch_ids);
    $upcoming_classes = $upcoming_query->fetchAll(PDO::FETCH_ASSOC);
}

// Add join class information to upcoming classes
foreach ($upcoming_classes as &$class) {
    $class['can_join'] = false;
    $class['join_message'] = '';
    $class['join_action'] = '';
    
    if ($class['mode'] === 'online' && !empty($class['meeting_link'])) {
        $class_date = $class['schedule_date'];
        $current_date = date('Y-m-d');
        
        if ($class_date == $current_date) {
            $current_time = date('H:i:s');
            $class_start = $class['start_time'];
            $class_end = $class['end_time'];
            
            $join_start = date('H:i:s', strtotime('-15 minutes', strtotime($class_start)));
            
            if ($current_time >= $join_start && $current_time <= $class_end) {
                $class['can_join'] = true;
                $class['join_message'] = 'Join Now';
                $class['join_action'] = $class['meeting_link'];
            } elseif ($current_time < $join_start) {
                $class['can_join'] = false;
                $time_until = strtotime($class_start) - strtotime($current_time);
                $minutes_until = ceil($time_until / 60);
                $class['join_message'] = "Join in {$minutes_until} min";
            } else {
                $class['join_message'] = 'Class Ended';
            }
        } elseif ($class_date > $current_date) {
            $class['join_message'] = 'Upcoming';
        } else {
            $class['join_message'] = 'Completed';
        }
    } else {
        $class['join_message'] = $class['mode'] === 'offline' ? 'Offline Class' : 'No Link';
    }
}
unset($class);

// Calculate overall performance from all batches
$overall_performance = 0;
if (!empty($batch_ids)) {
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $performance_query = $db->prepare("
        SELECT 
            AVG(er.obtained_marks / e.total_marks * 100) as avg_percentage,
            COUNT(*) as exam_count
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.exam_id
        WHERE er.student_id = ?
        AND e.batch_id IN ($placeholders)
    ");
    $params = [$student_id_value];
    $params = array_merge($params, $batch_ids);
    $performance_query->execute($params);
    $performance = $performance_query->fetch(PDO::FETCH_ASSOC);
    $overall_performance = $performance['avg_percentage'] ? round($performance['avg_percentage']) : 0;
}

// Get leave applications for the student
$leave_applications_query = $db->prepare("
    SELECT * FROM leave_applications 
    WHERE student_id = :student_id 
    ORDER BY created_at DESC
    LIMIT 5
");
$leave_applications_query->execute([':student_id' => $student_id_value]);
$recent_leaves = $leave_applications_query->fetchAll(PDO::FETCH_ASSOC);

// Get pending leave count
$pending_leaves = 0;
$approved_leaves = 0;
$rejected_leaves = 0;

foreach ($recent_leaves as $leave) {
    if ($leave['status'] === 'pending') $pending_leaves++;
    if ($leave['status'] === 'approved') $approved_leaves++;
    if ($leave['status'] === 'rejected') $rejected_leaves++;
}

// Check if modal should be shown
$show_modal = !isset($_SESSION['modal_closed']);

// Check if Saturday feedback is required and not completed
$require_mandatory_feedback = $isSaturday && !isset($_SESSION['saturday_feedback_completed']) && $show_modal;

// Reset Saturday feedback session at midnight (new day)
if (isset($_SESSION['saturday_feedback_last_reset'])) {
    $last_reset = new DateTime($_SESSION['saturday_feedback_last_reset']);
    $now = new DateTime();
    if ($last_reset->format('Y-m-d') !== $now->format('Y-m-d')) {
        unset($_SESSION['saturday_feedback_completed']);
        $_SESSION['saturday_feedback_last_reset'] = $now->format('Y-m-d H:i:s');
    }
} else {
    $_SESSION['saturday_feedback_last_reset'] = date('Y-m-d H:i:s');
}
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out <?= $require_mandatory_feedback ? 'pointer-events-none opacity-50' : '' ?>">
    <!-- Background Blocker Overlay (Only on Saturday when feedback is required) -->
    <?php if ($require_mandatory_feedback): ?>
    <div id="dashboardBlocker" class="fixed inset-0 bg-black bg-opacity-70 z-40 flex items-center justify-center">
        <div class="bg-white p-8 rounded-2xl shadow-2xl max-w-md text-center">
            <div class="text-5xl mb-4 text-yellow-500">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 mb-3">Feedback Required</h2>
            <p class="text-gray-600 mb-4">Saturday feedback is mandatory before accessing the dashboard.</p>
            <p class="text-sm text-gray-500 mb-6">Please complete the feedback form to continue.</p>
            <button onclick="showFeedbackModal()" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                <i class="fas fa-comment-dots mr-2"></i> Go to Feedback Form
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Mobile Header -->
    <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden <?= $require_mandatory_feedback ? 'pointer-events-none' : '' ?>">
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
            <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                <i class="fas fa-user-graduate text-indigo-600"></i>
            </div>
        </div>
    </header>

    <!-- Desktop Header -->
    <header class="hidden md:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30 <?= $require_mandatory_feedback ? 'pointer-events-none' : '' ?>">
        <div class="flex-1"></div>
        
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-tachometer-alt text-indigo-600 text-xl"></i>
            </div>
            <span>Student Dashboard</span>
        </h1>
        
        <div class="flex-1 flex justify-end items-center space-x-4">
            <div class="animate-pulse bg-indigo-100 rounded-full p-2">
                <i class="fas fa-user-graduate text-indigo-600"></i>
            </div>
        </div>
    </header>

    <!-- Mobile Navigation Menu -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden">
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
                        <?= strtoupper(substr($student['first_name'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800"><?= htmlspecialchars($student['first_name'] ?? 'Student') ?> <?= htmlspecialchars($student['last_name'] ?? '') ?></p>
                        <p class="text-xs text-gray-600">Student</p>
                    </div>
                </div>
            </div>
            
            <!-- Mobile Navigation Links -->
            <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                
                <a href="../stu_dash/dashboard.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'bg-white shadow-md text-blue-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-tachometer-alt <?= $current_page == 'dashboard.php' ? 'text-blue-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Dashboard</span>
                </a>

                <a href="../stu_dash/my_batches.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_batches.php' ? 'bg-white shadow-md text-green-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-users <?= $current_page == 'my_batches.php' ? 'text-green-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Batches</span>
                </a>

                <a href="../stu_dash/upcoming.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'upcoming.php' ? 'bg-white shadow-md text-purple-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-calendar-alt <?= $current_page == 'upcoming.php' ? 'text-purple-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Upcoming Schedule</span>
                </a>

                <a href="../stu_dash/my_content.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_content.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-book <?= $current_page == 'my_content.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Content</span>
                </a>
                
                <a href="../student_test/student_dashboard.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_dashboard.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-vial <?= $current_page == 'student_dashboard.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Test</span>
                </a>

                <a href="../stu_dash/my_performance.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_performance.php' ? 'bg-white shadow-md text-red-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-chart-line <?= $current_page == 'my_performance.php' ? 'text-red-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Performance</span>
                </a>

                <a href="../stu_dash/student_feedback.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_feedback.php' ? 'bg-white shadow-md text-indigo-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-comment-dots <?= $current_page == 'student_feedback.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Feedback</span>
                </a>

                <a href="../stu_dash/my_leaves.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_leaves.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-calendar-alt <?= $current_page == 'my_leaves.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Leave Applications</span>
                </a>

                <a href="../stu_dash/student_profile.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_profile.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-user-circle <?= $current_page == 'student_profile.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Profile</span>
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

    <div class="p-4 md:p-6 bg-gray-50 min-h-screen <?= $require_mandatory_feedback ? 'pointer-events-none' : '' ?>">
        <!-- Success Messages -->
        <?php if (isset($_SESSION['feedback_submitted']) && $_SESSION['feedback_submitted']): ?>
            <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span>Feedback submitted successfully!</span>
                </div>
            </div>
            <?php unset($_SESSION['feedback_submitted']); ?>
        <?php endif; ?>

        <!-- Welcome Section -->
        <div class="bg-gradient-to-r from-blue-500 to-purple-600 text-white p-6 rounded-2xl shadow-lg mb-6 transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h2 class="text-2xl font-bold mb-2">Welcome back, <?= htmlspecialchars($student['first_name']) ?>!</h2>
                    <p class="text-blue-100">Here's your learning progress and upcoming activities</p>
                </div>
                <div class="mt-4 md:mt-0 bg-white bg-opacity-20 p-3 rounded-lg backdrop-blur-sm">
                    <p class="text-sm">Student ID: <span class="font-semibold"><?= htmlspecialchars($student['student_id']) ?></span></p>
                    <p class="text-sm">Total Batches: <span class="font-semibold"><?= count($current_batches) ?></span></p>
                    <p class="text-sm">Course: <span class="font-semibold"><?= htmlspecialchars($student['course'] ?? 'Not assigned') ?></span></p>
                </div>
            </div>
        </div>

        <!-- Batch Selection Tabs (Only show if multiple batches) -->
        <?php if (count($current_batches) > 1): ?>
        <div class="mb-6 bg-white p-4 rounded-2xl shadow-md">
            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-exchange-alt text-indigo-600 mr-2"></i>
                Select Batch to View
            </h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($current_batches as $index => $batch_info): ?>
                    <a href="?batch_index=<?= $index ?>" 
                       class="px-4 py-2 rounded-lg transition-all duration-300 <?= $selected_batch_index == $index ? 'bg-indigo-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <div class="flex items-center">
                            <i class="fas fa-layer-group mr-2"></i>
                            <?php 
                            $batch_label = "Batch ";
                            if ($batch_info['field_name'] == 'batch_name') $batch_label .= "1";
                            elseif ($batch_info['field_name'] == 'batch_name_2') $batch_label .= "2";
                            elseif ($batch_info['field_name'] == 'batch_name_3') $batch_label .= "3";
                            elseif ($batch_info['field_name'] == 'batch_name_4') $batch_label .= "4";
                            ?>
                            <span><?= $batch_label ?>: <?= htmlspecialchars($batch_info['batch_data']['batch_name']) ?></span>
                            <?php if ($selected_batch_index == $index): ?>
                                <i class="fas fa-check ml-2"></i>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php if ($selected_batch): ?>
                <p class="text-sm text-gray-500 mt-2">
                    Currently viewing: <span class="font-semibold text-indigo-600">
                        <?php 
                        $current_label = "Batch ";
                        if ($current_batches[$selected_batch_index]['field_name'] == 'batch_name') $current_label .= "1";
                        elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_2') $current_label .= "2";
                        elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_3') $current_label .= "3";
                        elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_4') $current_label .= "4";
                        echo $current_label . " - " . htmlspecialchars($selected_batch['batch_name']);
                        ?>
                    </span>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Batch Information with Join Class Button -->
        <div class="bg-white p-6 rounded-2xl shadow-lg mb-6 transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-info-circle text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                    <?php if (count($current_batches) > 1): ?>
                        Selected Batch Information
                    <?php else: ?>
                        Batch Information
                    <?php endif; ?>
                </h2>
                <div class="flex space-x-2">
                    <!-- Join Class Button (only if online and meeting link exists) -->
                    <?php if ($selected_batch && $selected_batch['mode'] === 'online' && !empty($selected_batch['meeting_link'])): ?>
                        <a href="<?= htmlspecialchars($selected_batch['meeting_link']) ?>" 
                           target="_blank"
                           class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center space-x-2">
                            <i class="fas fa-video"></i>
                            <span>Join Class</span>
                        </a>
                    <?php endif; ?>
                    
                    <a href="my_batches.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center space-x-2">
                        <i class="fas fa-layer-group"></i>
                        <span>View Batch</span>
                    </a>
                </div>
            </div>
            
            <?php if ($selected_batch): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                        <div class="flex items-center mb-2">
                            <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                <i class="fas fa-hashtag text-blue-600"></i>
                            </div>
                            <p class="text-sm text-gray-500">Batch ID</p>
                        </div>
                        <p class="font-medium text-lg text-blue-800"><?= htmlspecialchars($selected_batch['batch_id']) ?></p>
                    </div>
                    
                    <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                        <div class="flex items-center mb-2">
                            <div class="bg-green-100 p-2 rounded-lg mr-3">
                                <i class="fas fa-clock text-green-600"></i>
                            </div>
                            <p class="text-sm text-gray-500">Schedule</p>
                        </div>
                        <p class="font-medium text-lg text-green-800"><?= htmlspecialchars($selected_batch['time_slot'] ?? 'Not scheduled') ?></p>
                    </div>
                    
                    <div class="bg-amber-50 p-4 rounded-lg border border-amber-100">
                        <div class="flex items-center mb-2">
                            <div class="bg-amber-100 p-2 rounded-lg mr-3">
                                <i class="fas fa-laptop-house text-amber-600"></i>
                            </div>
                            <p class="text-sm text-gray-500">Mode</p>
                        </div>
                        <p class="font-medium text-lg text-amber-800"><?= ucfirst($selected_batch['mode'] ?? 'Not specified') ?></p>
                    </div>
                    
                    <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                        <div class="flex items-center mb-2">
                            <div class="bg-purple-100 p-2 rounded-lg mr-3">
                                <i class="fas fa-tasks text-purple-600"></i>
                            </div>
                            <p class="text-sm text-gray-500">Status</p>
                        </div>
                        <p class="font-medium text-lg text-purple-800"><?= ucfirst($selected_batch['status'] ?? 'Unknown') ?></p>
                    </div>
                </div>
                
                <!-- Online Class Access -->
                <?php if ($selected_batch['mode'] === 'online' && !empty($selected_batch['meeting_link'])): ?>
                <div class="mt-6 p-4 bg-gradient-to-r from-green-50 to-blue-50 rounded-lg border border-green-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="bg-green-100 p-3 rounded-lg mr-4">
                                <i class="fas fa-video text-green-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800">Online Class Access</h3>
                                <p class="text-sm text-gray-600">Join your online classes using the meeting link</p>
                                <p class="text-xs text-gray-500 mt-1">
                                    Platform: <?= htmlspecialchars($selected_batch['platform'] ?? 'Not specified') ?> | 
                                    Schedule: <?= htmlspecialchars($selected_batch['time_slot'] ?? 'Not scheduled') ?>
                                </p>
                            </div>
                        </div>
                        <a href="<?= htmlspecialchars($selected_batch['meeting_link']) ?>" 
                           target="_blank"
                           class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center space-x-2">
                            <i class="fas fa-external-link-alt"></i>
                            <span>Join Class</span>
                        </a>
                    </div>
                    <div class="mt-3 text-sm text-green-700 flex items-center">
                        <i class="fas fa-info-circle mr-2"></i>
                        You can join 15 minutes before scheduled class time
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <div class="bg-yellow-50 inline-block p-4 rounded-full mb-4">
                        <i class="fas fa-exclamation-circle text-yellow-500 text-3xl"></i>
                    </div>
                    <p class="text-gray-600">No batch assigned</p>
                    <p class="text-sm text-gray-500 mt-2">You are not currently assigned to any batch. Please contact administration.</p>
                    <a href="batch_enquiry.php" class="mt-4 inline-block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-question-circle mr-2"></i>Enquire About Batches
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Stats Overview Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
            <div class="bg-white p-5 rounded-2xl shadow-md hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1 border-l-4 border-blue-500">
                <div class="flex items-center">
                    <div class="rounded-full bg-blue-100 p-3 mr-4">
                        <i class="fas fa-chart-line text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Batch Progress</p>
                        <h3 class="text-2xl font-bold text-gray-800"><?= $batch_progress['overall_progress'] ?>%</h3>
                        <p class="text-xs text-gray-500">Selected batch</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-5 rounded-2xl shadow-md hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1 border-l-4 border-green-500">
                <div class="flex items-center">
                    <div class="rounded-full bg-green-100 p-3 mr-4">
                        <i class="fas fa-user-check text-green-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Attendance</p>
                        <h3 class="text-2xl font-bold text-gray-800"><?= number_format($attendance_percentage, 1) ?>%</h3>
                        <p class="text-xs text-gray-500">
                            <?= $attendance_data['present_count'] ?? 0 ?>/<?= $attendance_data['total_classes'] ?? 0 ?> classes
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-5 rounded-2xl shadow-md hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1 border-l-4 border-amber-500">
                <div class="flex items-center">
                    <div class="rounded-full bg-amber-100 p-3 mr-4">
                        <i class="fas fa-video text-amber-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Camera Usage</p>
                        <h3 class="text-2xl font-bold text-gray-800"><?= number_format($camera_usage_percentage, 1) ?>%</h3>
                        <p class="text-xs text-gray-500">
                            On in <?= $attendance_data['camera_on_count'] ?? 0 ?>/<?= $attendance_data['total_classes'] ?? 0 ?> classes
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-5 rounded-2xl shadow-md hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1 border-l-4 border-purple-500">
                <div class="flex items-center">
                    <div class="rounded-full bg-purple-100 p-3 mr-4">
                        <i class="fas fa-chart-bar text-purple-600 text-xl"></i>
                    </div>
                    <div>
                        <p class="text-gray-500 text-sm">Exam Performance</p>
                        <h3 class="text-2xl font-bold text-gray-800"><?= $exam_percentage ?>%</h3>
                        <p class="text-xs text-gray-500">
                            <?= $exam_data['passed_exams'] ?? 0 ?>/<?= $exam_data['total_exams'] ?? 0 ?> passed
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left Column -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Recent Attendance -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-calendar-check text-green-500 mr-2 bg-green-100 p-2 rounded-lg"></i>
                            Recent Attendance
                        </h2>
                        <a href="my_performance.php?view=attendance" class="text-blue-500 hover:text-blue-700 text-sm font-medium flex items-center">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <?php if (count($recent_attendance) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_attendance as $record): ?>
                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                                    <div class="flex items-center">
                                        <div class="bg-green-100 text-green-600 p-3 rounded-lg mr-4">
                                            <i class="fas fa-calendar-day"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-medium text-gray-800">
                                                <?= date('M j, Y', strtotime($record['date'])) ?>
                                                <span class="text-sm font-normal text-gray-500 ml-2">(<?= $record['day_name'] ?>)</span>
                                            </h3>
                                            <p class="text-sm text-gray-500">
                                                Batch: <?= htmlspecialchars($record['batch_id']) ?>
                                                <?php if ($record['batch_id'] !== $selected_batch_id): ?>
                                                    <span class="ml-2 text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">Other Batch</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span class="inline-block px-3 py-1 rounded-full text-sm font-medium 
                                            <?= $record['status'] === 'Present' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <?= $record['status'] ?>
                                        </span>
                                        <p class="text-xs text-gray-500 mt-1">
                                            Camera: 
                                            <span class="<?= $record['camera_status'] === 'On' ? 'text-green-600' : 'text-gray-500' ?>">
                                                <?= $record['camera_status'] ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-calendar-times text-3xl mb-2 text-gray-300"></i>
                            <p>No attendance records found for this month</p>
                            <p class="text-sm mt-1">Attendance data will appear here when recorded</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Exam Performance -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-chart-bar text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                            Recent Exam Performance
                        </h2>
                        <a href="my_performance.php" class="text-blue-500 hover:text-blue-700 text-sm font-medium flex items-center">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <?php if (count($recent_exams) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_exams as $exam): ?>
                                <?php
                                $percentage = ($exam['obtained_marks'] / $exam['total_marks']) * 100;
                                $status_class = $percentage >= 60 ? 'bg-green-100 text-green-800' : ($percentage >= 40 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
                                ?>
                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                                    <div class="flex items-center">
                                        <div class="bg-blue-100 text-blue-600 p-3 rounded-lg mr-4">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                        <div>
                                            <h3 class="font-medium text-gray-800"><?= htmlspecialchars($exam['exam_name']) ?></h3>
                                            <p class="text-sm text-gray-500">
                                                <?= date('M j, Y', strtotime($exam['exam_date'])) ?>
                                                <?php if ($exam['batch_id'] !== $selected_batch_id): ?>
                                                    <span class="ml-2 text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">Other Batch</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold text-gray-800"><?= $exam['obtained_marks'] ?>/<?= $exam['total_marks'] ?></p>
                                        <span class="text-xs px-2 py-1 rounded-full <?= $status_class ?>">
                                            <?= round($percentage) ?>%
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-clipboard-list text-3xl mb-2 text-gray-300"></i>
                            <p>No exam results available yet</p>
                            <p class="text-sm mt-1">Your exam results will appear here once published</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Column -->
            <div class="space-y-6">
                <!-- Quick Actions -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-rocket text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                        Quick Actions
                    </h2>
                    
                    <div class="grid grid-cols-2 gap-4 mt-4">
                        <a href="my_performance.php" class="bg-blue-50 hover:bg-blue-100 p-4 rounded-lg text-center transition-all duration-300 transform hover:scale-105 border border-blue-100">
                            <div class="bg-blue-100 text-blue-600 p-2 rounded-lg inline-block mb-2">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <p class="font-medium text-blue-700">Performance</p>
                        </a>
                        
                        <a href="my_content.php" class="bg-green-50 hover:bg-green-100 p-4 rounded-lg text-center transition-all duration-300 transform hover:scale-105 border border-green-100">
                            <div class="bg-green-100 text-green-600 p-2 rounded-lg inline-block mb-2">
                                <i class="fas fa-book"></i>
                            </div>
                            <p class="font-medium text-green-700">Content</p>
                        </a>
                        
                        <a href="student_feedback.php" class="bg-amber-50 hover:bg-amber-100 p-4 rounded-lg text-center transition-all duration-300 transform hover:scale-105 border border-amber-100">
                            <div class="bg-amber-100 text-amber-600 p-2 rounded-lg inline-block mb-2">
                                <i class="fas fa-comment-dots"></i>
                            </div>
                            <p class="font-medium text-amber-700">Feedback</p>
                        </a>
                        
                        <a href="my_leaves.php" class="bg-purple-50 hover:bg-purple-100 p-4 rounded-lg text-center transition-all duration-300 transform hover:scale-105 border border-purple-100">
                            <div class="bg-purple-100 text-purple-600 p-2 rounded-lg inline-block mb-2">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <p class="font-medium text-purple-700">Leave</p>
                        </a>
                    </div>
                </div>

                <!-- Leave Applications Summary -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-calendar-alt text-purple-500 mr-2 bg-purple-100 p-2 rounded-lg"></i>
                            Leave Applications
                        </h2>
                        <a href="my_leaves.php" class="text-blue-500 hover:text-blue-700 text-sm font-medium flex items-center">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <?php if (count($recent_leaves) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($recent_leaves as $leave): ?>
                                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                                    <div class="flex items-center">
                                        <div class="bg-purple-100 text-purple-600 p-2 rounded-lg mr-3">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($leave['application_no']) ?></p>
                                            <p class="text-xs text-gray-500">
                                                <?= date('d M Y', strtotime($leave['start_date'])) ?> - <?= date('d M Y', strtotime($leave['end_date'])) ?>
                                                (<?= $leave['total_days'] ?> days)
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span class="inline-block px-2 py-1 rounded-full text-xs font-medium
                                            <?= $leave['status'] === 'approved' ? 'bg-green-100 text-green-800' : 
                                               ($leave['status'] === 'rejected' ? 'bg-red-100 text-red-800' : 
                                               ($leave['status'] === 'cancelled' ? 'bg-gray-100 text-gray-800' : 'bg-yellow-100 text-yellow-800')) ?>">
                                            <?= ucfirst($leave['status']) ?>
                                        </span>
                                        <?php if ($leave['status'] === 'rejected' && !empty($leave['rejection_reason'])): ?>
                                            <p class="text-xs text-red-600 mt-1">Rejected</p>
                                        <?php elseif ($leave['status'] === 'approved'): ?>
                                            <p class="text-xs text-green-600 mt-1">Approved</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Leave Stats -->
                        <div class="grid grid-cols-3 gap-2 mt-4 pt-4 border-t border-gray-200">
                            <div class="text-center">
                                <div class="text-2xl font-bold text-yellow-600"><?= $pending_leaves ?></div>
                                <div class="text-xs text-gray-500">Pending</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-green-600"><?= $approved_leaves ?></div>
                                <div class="text-xs text-gray-500">Approved</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-red-600"><?= $rejected_leaves ?></div>
                                <div class="text-xs text-gray-500">Rejected</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-6 text-gray-500">
                            <i class="fas fa-calendar-times text-3xl mb-2 text-gray-300"></i>
                            <p>No leave applications yet</p>
                            <a href="apply_leave.php" class="mt-3 inline-block text-blue-600 hover:text-blue-800 text-sm">
                                Apply for Leave <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Upcoming Classes with Join Option -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center">
                            <i class="fas fa-calendar-day text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                            Upcoming Classes
                        </h2>
                        <a href="upcoming.php" class="text-blue-500 hover:text-blue-700 text-sm font-medium flex items-center">
                            View All <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <?php if (count($upcoming_classes) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($upcoming_classes as $class): ?>
                                <div class="flex items-start justify-between p-3 rounded-lg border border-gray-100 hover:bg-blue-50 transition-all duration-300">
                                    <div class="flex-1">
                                        <div class="flex items-start">
                                            <div class="bg-blue-100 text-blue-600 p-2 rounded-lg mr-3">
                                                <i class="fas fa-chalkboard-teacher"></i>
                                            </div>
                                            <div class="flex-1">
                                                <p class="font-medium text-gray-800"><?= htmlspecialchars($class['topic']) ?></p>
                                                <?php if (!empty($class['description'])): ?>
                                                    <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($class['description']) ?></p>
                                                <?php endif; ?>
                                                <div class="flex justify-between text-sm text-gray-500 mt-2">
                                                    <span class="flex items-center">
                                                        <i class="far fa-calendar mr-1"></i>
                                                        <?= date('M j, Y', strtotime($class['schedule_date'])) ?>
                                                    </span> 
                                                    <span class="flex items-center">
                                                        <i class="far fa-clock mr-1"></i>
                                                        <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                                    </span>
                                                </div>
                                                <?php if ($class['batch_id'] !== $selected_batch_id): ?>
                                                    <span class="mt-1 inline-block text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">Other Batch</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="ml-4 flex flex-col items-end space-y-2">
                                        <?php if ($class['can_join']): ?>
                                            <a href="<?= htmlspecialchars($class['join_action']) ?>" 
                                               target="_blank"
                                               class="px-3 py-1 bg-green-600 text-white text-sm rounded-lg hover:bg-green-700 transition-colors flex items-center space-x-1">
                                                <i class="fas fa-video"></i>
                                                <span>Join Now</span>
                                            </a>
                                        <?php endif; ?>
                                        <span class="px-3 py-1 <?= $class['can_join'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' ?> text-sm rounded-lg flex items-center space-x-1">
                                            <i class="fas fa-clock"></i>
                                            <span><?= $class['join_message'] ?></span>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($selected_batch && $selected_batch['mode'] === 'online' && !empty($selected_batch['meeting_link'])): ?>
                            <div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
                                <p class="text-sm text-blue-700 flex items-center">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    Online classes available via meeting link. Join 15 minutes before start time.
                                </p>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-calendar-times text-3xl mb-2 text-gray-300"></i>
                            <p>No upcoming classes scheduled</p>
                            <p class="text-sm mt-1">Check back later for your class schedule</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Content -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1 border border-gray-100">
                    <h2 class="text-xl font-bold text-gray-800 flex items-center">
                        <i class="fas fa-book text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                        Recent Content
                    </h2>
                    
                    <?php if (count($recent_content) > 0): ?>
                        <div class="space-y-3 mt-4">
                            <?php foreach ($recent_content as $content): ?>
                                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors duration-200">
                                    <div class="flex items-center">
                                        <div class="bg-blue-100 text-blue-600 p-2 rounded-lg mr-3">
                                            <i class="fas 
                                                <?= $content['file_type'] === 'Test' ? 'fa-file-signature' : 
                                                   ($content['file_type'] === 'Assignment' ? 'fa-tasks' : 
                                                   ($content['file_type'] === 'Notes' ? 'fa-sticky-note' : 'fa-file')) ?>">
                                            </i>
                                        </div>
                                        <div>
                                            <h3 class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($content['title']) ?></h3>
                                            <p class="text-xs text-gray-500">
                                                <?= date('M j', strtotime($content['uploaded_at'])) ?>
                                                <?php if ($content['batch_id'] !== $selected_batch_id): ?>
                                                    <span class="ml-1 text-xs bg-gray-100 text-gray-600 px-1 py-0.5 rounded">Other Batch</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="text-xs px-2 py-1 rounded-full 
                                        <?= $content['file_type'] === 'Test' ? 'bg-purple-100 text-purple-800' : 
                                           ($content['file_type'] === 'Assignment' ? 'bg-blue-100 text-blue-800' : 
                                           ($content['file_type'] === 'Notes' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')) ?>">
                                        <?= htmlspecialchars($content['file_type']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-4 text-center">
                            <a href="my_content.php" class="text-blue-500 hover:text-blue-700 text-sm font-medium">
                                View All Content <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-500">
                            <i class="fas fa-book-open text-3xl mb-2 text-gray-300"></i>
                            <p>No course content available yet</p>
                            <p class="text-sm mt-1">New content will appear here when uploaded</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Auto-Modal for Feedback Only - IMPROVED SCROLLABLE VERSION WITH BATCH SELECTION -->
<div id="feedbackModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto <?= $show_modal ? '' : 'hidden' ?>" style="padding: 20px 0;">
    <div class="relative my-auto mx-auto w-full max-w-2xl">
        <div class="bg-white rounded-2xl shadow-2xl mx-4 max-h-[85vh] flex flex-col">
            <!-- Modal Header - Fixed -->
            <div class="bg-gradient-to-r from-blue-500 to-purple-600 text-white p-4 md:p-6 rounded-t-2xl flex-shrink-0">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl md:text-2xl font-bold flex items-center">
                        <i class="fas fa-comment-dots mr-3"></i>
                        Class Feedback
                        <?php if ($isSaturday): ?>
                            <span class="ml-3 text-sm bg-yellow-500 text-white px-2 py-1 rounded-full">Mandatory (Saturday)</span>
                        <?php endif; ?>
                    </h2>
                    <?php if (!$isSaturday): ?>
                        <button id="closeModal" class="text-white hover:text-gray-200 text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <p class="text-blue-100 mt-2 text-sm md:text-base">Share your feedback to help us improve the learning experience</p>
            </div>

            <!-- Modal Body - Scrollable -->
            <div class="flex-1 overflow-y-auto p-4 md:p-6" style="max-height: calc(85vh - 200px);">
                <form method="POST" id="feedbackFormModal">
                    <div class="space-y-3 md:space-y-4">
                        <!-- Batch Selection Dropdown -->
                        <div>
                            <label for="feedback_batch_id" class="block text-gray-700 mb-1 md:mb-2 font-medium">
                                Select Batch for Feedback *
                            </label>
                            <select name="feedback_batch_id" id="feedback_batch_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <option value="">Select a batch</option>
                                <?php foreach ($current_batches as $batch): ?>
                                    <option value="<?= htmlspecialchars($batch['batch_name']) ?>">
                                        <?php 
                                        $batch_label = "Batch ";
                                        if ($batch['field_name'] == 'batch_name') $batch_label .= "1";
                                        elseif ($batch['field_name'] == 'batch_name_2') $batch_label .= "2";
                                        elseif ($batch['field_name'] == 'batch_name_3') $batch_label .= "3";
                                        elseif ($batch['field_name'] == 'batch_name_4') $batch_label .= "4";
                                        echo $batch_label . ": " . htmlspecialchars($batch['batch_data']['batch_name']);
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Select the batch you are providing feedback for</p>
                        </div>

                        <!-- Regularity -->
                        <div>
                            <label for="regular_in_class" class="block text-gray-700 mb-1 md:mb-2 font-medium">
                                Are you regular in class? *
                            </label>
                            <select name="regular_in_class" id="regular_in_class" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <option value="">Select an option</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                                <option value="Sometimes">Sometimes</option>
                            </select>
                        </div>

                        <!-- Ratings -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-4">
                            <div class="text-center">
                                <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">Class Rating *</label>
                                <div class="star-rating" data-target="class_rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span data-value="<?= $i ?>" class="text-xl md:text-2xl cursor-pointer text-gray-300 hover:text-yellow-400">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="class_rating" id="class_rating" required>
                            </div>
                            
                            <div class="text-center">
                                <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">Assignment Understanding *</label>
                                <div class="star-rating" data-target="assignment_understanding">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span data-value="<?= $i ?>" class="text-xl md:text-2xl cursor-pointer text-gray-300 hover:text-yellow-400">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="assignment_understanding" id="assignment_understanding" required>
                            </div>
                            
                            <div class="text-center">
                                <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">Practical Understanding *</label>
                                <div class="star-rating" data-target="practical_understanding">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span data-value="<?= $i ?>" class="text-xl md:text-2xl cursor-pointer text-gray-300 hover:text-yellow-400">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="practical_understanding" id="practical_understanding" required>
                            </div>
                        </div>

                        <!-- Satisfaction -->
                        <div>
                            <label for="satisfied" class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                                Are you satisfied with the course? *
                            </label>
                            <select name="satisfied" id="satisfied" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base">
                                <option value="">Select an option</option>
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>

                        <!-- Suggestions -->
                        <div>
                            <label for="suggestions" class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                                Your suggestions or issues
                            </label>
                            <textarea id="suggestions" name="suggestions" rows="2" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base" 
                                placeholder="Share your suggestions or any issues you faced..."
                                maxlength="500"></textarea>
                        </div>

                        <!-- Additional Feedback -->
                        <div>
                            <label for="feedback_text" class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                                Additional Feedback
                            </label>
                            <textarea id="feedback_text" name="feedback_text" rows="2" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base" 
                                placeholder="Share your thoughts about the course, instructor, or any suggestions for improvement..."
                                maxlength="1000"></textarea>
                        </div>
                    </div>

                    <!-- Action Buttons - Fixed at bottom -->
                    <div class="flex flex-col sm:flex-row justify-end gap-2 sm:space-x-4 mt-4 md:mt-6 pt-4 border-t sticky bottom-0 bg-white">
                        <?php if (!$isSaturday): ?>
                            <button type="button" id="skipButton" class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-sm md:text-base order-2 sm:order-1">
                                Skip for Now
                            </button>
                        <?php endif; ?>
                        <button type="submit" name="submit_feedback" 
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center space-x-2 text-sm md:text-base <?= $isSaturday ? 'w-full' : '' ?> order-1 sm:order-2 mb-2 sm:mb-0">
                            <i class="fas fa-paper-plane"></i>
                            <span>Submit Feedback</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    @keyframes slideIn {
        from { transform: translateX(-100%); }
        to { transform: translateX(0); }
    }
    
    .animate-fade-in {
        animation: fadeIn 0.6s ease-out forwards;
    }
    
    .animate-slide-in {
        animation: slideIn 0.3s ease-out forwards;
    }
    
    .delay-100 { animation-delay: 0.1s; }
    .delay-200 { animation-delay: 0.2s; }
    .delay-300 { animation-delay: 0.3s; }
    
    .star-rating span.active {
        color: #f39c12;
    }
    
    /* Mobile menu styles */
    .mobile-nav-link.active {
        background-color: #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        font-weight: 600;
    }
    
    #mobileMenu {
        transition: opacity 0.3s ease-in-out;
    }
    
    .pointer-events-none {
        pointer-events: none;
    }
    
    .opacity-50 {
        opacity: 0.5;
    }
    
    /* Scrollbar styling */
    .overflow-y-auto::-webkit-scrollbar {
        width: 8px;
    }
    
    .overflow-y-auto::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    
    .overflow-y-auto::-webkit-scrollbar-thumb {
        background: #cbd5e0;
        border-radius: 10px;
    }
    
    .overflow-y-auto::-webkit-scrollbar-thumb:hover {
        background: #a0aec0;
    }
</style>

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

// Function to show feedback modal
function showFeedbackModal() {
    const modal = document.getElementById('feedbackModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

// Star rating functionality
document.querySelectorAll('.star-rating').forEach(rating => {
    const target = rating.dataset.target;
    const hiddenInput = document.getElementById(target);
    
    rating.querySelectorAll('span').forEach(star => {
        star.addEventListener('click', function() {
            const value = this.dataset.value;
            hiddenInput.value = value;
            
            // Update stars appearance
            this.parentElement.querySelectorAll('span').forEach(s => {
                s.classList.remove('active');
                s.style.color = '';
            });
            
            // Color all stars up to the clicked one
            this.parentElement.querySelectorAll('span').forEach((s, index) => {
                if (index < value) {
                    s.classList.add('active');
                    s.style.color = '#f39c12';
                }
            });
        });
        
        star.addEventListener('mouseover', function() {
            const value = this.dataset.value;
            this.parentElement.querySelectorAll('span').forEach((s, index) => {
                if (index < value) {
                    s.style.color = '#f39c12';
                }
            });
        });
        
        star.addEventListener('mouseout', function() {
            this.parentElement.querySelectorAll('span').forEach(s => {
                if (!s.classList.contains('active')) {
                    s.style.color = '#d1d5db';
                }
            });
        });
    });
});

// Form validation for modal
document.getElementById('feedbackFormModal').addEventListener('submit', function(e) {
    const requiredFields = ['class_rating', 'assignment_understanding', 'practical_understanding', 'satisfied', 'regular_in_class', 'feedback_batch_id'];
    let isValid = true;
    
    requiredFields.forEach(field => {
        const value = document.getElementById(field).value;
        if (!value) {
            isValid = false;
            document.getElementById(field).classList.add('border-red-500');
        } else {
            document.getElementById(field).classList.remove('border-red-500');
        }
    });
    
    if (!isValid) {
        e.preventDefault();
        showToast('Please fill in all required fields marked with *', 'error');
    }
});

// Toast notification function
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg text-white z-50 transform transition-all duration-300 translate-x-full opacity-0 ${type === 'success' ? 'bg-green-500' : type === 'error' ? 'bg-red-500' : 'bg-blue-500'}`;
    toast.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
            <span>${message}</span>
        </div>
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('translate-x-0', 'opacity-100');
    }, 10);
    
    setTimeout(() => {
        toast.classList.remove('translate-x-0', 'opacity-100');
        toast.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => {
            document.body.removeChild(toast);
        }, 300);
    }, 3000);
}

// Modal functionality
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('feedbackModal');
    const closeModalBtn = document.getElementById('closeModal');
    const skipButton = document.getElementById('skipButton');
    const isSaturday = <?= $isSaturday ? 'true' : 'false' ?>;
    const requireMandatoryFeedback = <?= $require_mandatory_feedback ? 'true' : 'false' ?>;
    
    <?php if ($show_modal): ?>
    setTimeout(() => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        
        if (isSaturday) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    showToast('Feedback is mandatory on Saturday. Please submit your feedback.', 'error');
                    e.stopPropagation();
                }
            });
        }
    }, 1000);
    <?php endif; ?>
    
    if (requireMandatoryFeedback && modal.classList.contains('hidden')) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    
    if (closeModalBtn) {
        closeModalBtn.addEventListener('click', function() {
            if (!isSaturday) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'close_modal=1'
                });
            }
        });
    }
    
    if (skipButton) {
        skipButton.addEventListener('click', function() {
            if (!isSaturday) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'close_modal=1'
                });
            }
        });
    }
    
    if (isSaturday) {
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('flex')) {
                showToast('Feedback is mandatory on Saturday. Please submit your feedback.', 'error');
                e.preventDefault();
                e.stopPropagation();
            }
        });
    }
    
    // Animate cards on page load
    const cards = document.querySelectorAll('.bg-white');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
        card.classList.add('animate-fade-in');
    });
});

// Handle modal close request
<?php 
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_modal'])) {
    $currentDayOfWeek = date('w');
    $isSaturday = ($currentDayOfWeek == 6);
    
    if (!$isSaturday) {
        $_SESSION['modal_closed'] = true;
    }
}
?>
</script>

<?php include '../footer.php'; ?>