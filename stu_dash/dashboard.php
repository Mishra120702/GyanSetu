<?php
session_start();
require_once '../db_connection.php';
date_default_timezone_set('Asia/Kolkata');

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
                             suggestions, feedback_text, is_regular, show_to_trainer) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

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
            header("Location: dashboard.php");
            exit();
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
    'total_topics' => 0,
    'covered_topics' => 0,
    'total_sub_topics' => 0,
    'completed_sub_topics' => 0,
    'theory_completed_total' => 0,
    'practical_completed_total' => 0,
    'topic_progress' => 0,
    'sub_topic_progress' => 0,
    'theory_progress' => 0,
    'practical_progress' => 0,
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
        LEFT JOIN courses c ON mt.course_id = c.id
        LEFT JOIN batch_courses bc ON bc.batch_id = mt.batch_name AND bc.course_id = mt.course_id
        WHERE mt.batch_name = ? AND (mt.course_id IS NULL OR bc.id IS NOT NULL)
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
            if ($topic['covered_by_trainer'])
                $covered_topics++;
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

// Get allocation date for the selected batch
$allocation_date = $student['enrollment_date'] ?? null;
if ($selected_batch_id) {
    $alloc_query = $db->prepare("SELECT transfer_date FROM student_batch_history WHERE student_id = ? AND to_batch_id = ? ORDER BY transfer_date ASC LIMIT 1");
    $alloc_query->execute([$student_id_value, $selected_batch_id]);
    $alloc_data = $alloc_query->fetch(PDO::FETCH_ASSOC);
    if ($alloc_data && !empty($alloc_data['transfer_date'])) {
        $allocation_date = $alloc_data['transfer_date'];
    }
}

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

// Fetch overall leaderboard data for weekly tests in the selected batch
$overall_leaderboard = [];
if ($selected_batch_id) {
    $overall_query = $db->prepare("
        SELECT s.first_name, s.last_name, s.student_id,
               SUM(ta.obtained_marks) as total_obtained, 
               SUM(ta.total_marks) as max_marks,
               (SUM(ta.obtained_marks) / SUM(ta.total_marks) * 100) as percentage
        FROM test_attempts ta
        JOIN tests t ON ta.test_id = t.id
        JOIN students s ON ta.student_id = s.student_id
        WHERE (t.batch_id = ? OR FIND_IN_SET(?, IFNULL(t.additional_batches, '')))
        AND t.test_category = 'weekly'
        GROUP BY s.student_id
        HAVING SUM(ta.total_marks) > 0
        ORDER BY percentage DESC
        LIMIT 5
    ");
    $overall_query->execute([$selected_batch_id, $selected_batch_id]);
    $overall_leaderboard = $overall_query->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch leaderboard data for specific tests in the selected batch
$weekly_leaderboard_data = [];
$chapter_leaderboard_data = [];

if ($selected_batch_id) {
    // Get all tests for this batch
    $tests_query = $db->prepare("
        SELECT id, title, total_marks, test_category, start_date 
        FROM tests 
        WHERE batch_id = ? OR FIND_IN_SET(?, IFNULL(additional_batches, ''))
        ORDER BY start_date DESC 
        LIMIT 15
    ");
    $tests_query->execute([$selected_batch_id, $selected_batch_id]);
    $batch_tests = $tests_query->fetchAll(PDO::FETCH_ASSOC);

    foreach ($batch_tests as $test) {
        $top_students_query = $db->prepare("
            SELECT s.first_name, s.last_name, s.student_id, ta.obtained_marks, ta.percentage
            FROM test_attempts ta
            JOIN students s ON ta.student_id = s.student_id
            WHERE ta.test_id = ?
            ORDER BY ta.percentage DESC, ta.obtained_marks DESC
            LIMIT 5
        ");
        $top_students_query->execute([$test['id']]);
        $top_students = $top_students_query->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($top_students)) {
            $test_data = [
                'test_name' => $test['title'],
                'total_marks' => $test['total_marks'],
                'test_id' => $test['id'],
                'top_students' => $top_students
            ];

            if ($test['test_category'] === 'weekly') {
                $weekly_leaderboard_data[] = $test_data;
            } elseif ($test['test_category'] === 'chapter_wise') {
                $chapter_leaderboard_data[] = $test_data;
            }
        }
    }
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
        LEFT JOIN batch_courses bc ON bc.batch_id = bu.batch_id AND bc.course_id = bu.course_id
        WHERE bu.batch_id IN ($placeholders) AND (bu.course_id IS NULL OR bc.id IS NOT NULL)
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
        AND STR_TO_DATE(CONCAT(s.schedule_date, ' ', s.start_time), '%Y-%m-%d %H:%i:%s') >= DATE_SUB(STR_TO_DATE(?, '%Y-%m-%d %H:%i:%s'), INTERVAL 30 MINUTE)
        ORDER BY s.schedule_date ASC, s.start_time ASC
        LIMIT 1
    ");
    $params = $batch_ids;
    $params[] = date('Y-m-d H:i:s');
    $upcoming_query->execute($params);
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
    if ($leave['status'] === 'pending')
        $pending_leaves++;
    if ($leave['status'] === 'approved')
        $approved_leaves++;
    if ($leave['status'] === 'rejected')
        $rejected_leaves++;
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

<div
    class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out <?= $require_mandatory_feedback ? 'pointer-events-none opacity-50' : '' ?>">
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
                <button onclick="showFeedbackModal()"
                    class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium">
                    <i class="fas fa-comment-dots mr-2"></i> Go to Feedback Form
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Mobile Header -->
    <header
        class="shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden <?= $require_mandatory_feedback ? 'pointer-events-none' : '' ?>"
        style="background: rgba(247, 245, 243, 0.75); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">
        <button class="text-xl transition-colors" style="color:#1B3C53;" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>

        <h1 class="text-lg font-bold flex items-center space-x-2" style="color:#1B3C53;">
            <div class="p-2 rounded-lg" style="background:#234C6A;">
                <i class="fas fa-tachometer-alt text-sm" style="color:#D2C1B6;"></i>
            </div>
            <span>Dashboard</span>
        </h1>

        <div class="flex items-center space-x-3">
            <?php include 'student_notification_bell.php'; ?>
            <div class="w-8 h-8 rounded-full flex items-center justify-center" style="background:#234C6A;">
                <i class="fas fa-user-graduate" style="color:#D2C1B6;"></i>
            </div>
        </div>
    </header>

    <!-- Desktop Header -->
    <header
        class="hidden md:flex shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30 <?= $require_mandatory_feedback ? 'pointer-events-none' : '' ?>"
        style="background: linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);">

        <div class="flex-1"></div>

        <h1 class="text-2xl font-bold flex items-center space-x-2" style="color:#1B3C53;">
            <div class="p-2 rounded-lg" style="background:#234C6A;">
                <i class="fas fa-tachometer-alt text-xl" style="color:#D2C1B6;"></i>
            </div>
            <span style="color: white;">Student Dashboard</span>

        </h1>

        <div class="flex-1 flex justify-end items-center space-x-4">
            <?php include 'student_notification_bell.php'; ?>
            <div class="animate-pulse rounded-full p-2" style="background:#234C6A;">
                <i class="fas fa-user-graduate" style="color:#D2C1B6;"></i>
            </div>
        </div>
    </header>

    <!-- Mobile Navigation Menu -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden">
        <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs shadow-xl transform transition-transform duration-300 -translate-x-full"
            style="background:#F7F5F3;">
            <!-- Mobile Menu Header -->
            <div class="p-4 border-b" style="border-color:#456882; background:linear-gradient(90deg,#1B3C53,#234C6A);">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                    </div>
                    <button onclick="toggleSidebar()" class="text-gray-500 hover:text-indigo-600 text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- User Info -->
                <div class="mt-4 flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold"
                        style="background:#456882;">
                        <?= strtoupper(substr($student['first_name'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div>
                        <p class="font-medium" style="color:#D2C1B6;">
                            <?= htmlspecialchars($student['first_name'] ?? 'Student') ?>
                            <?= htmlspecialchars($student['last_name'] ?? '') ?>
                        </p>
                        <p class="text-xs" style="color:rgba(210,193,182,0.7);">Student</p>
                    </div>
                </div>
            </div>

            <!-- Mobile Navigation Links -->
            <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>

                <a href="../stu_dash/dashboard.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'bg-white shadow-md text-blue-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-tachometer-alt <?= $current_page == 'dashboard.php' ? 'text-blue-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Dashboard</span>
                </a>

                <a href="../stu_dash/my_batches.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_batches.php' ? 'bg-white shadow-md text-green-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-users <?= $current_page == 'my_batches.php' ? 'text-green-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Batches</span>
                </a>

                <a href="../stu_dash/upcoming.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'upcoming.php' ? 'bg-white shadow-md text-purple-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-calendar-alt <?= $current_page == 'upcoming.php' ? 'text-purple-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Upcoming Schedule</span>
                </a>

                <a href="../stu_dash/my_content.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_content.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-book <?= $current_page == 'my_content.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Content</span>
                </a>

                <a href="../student_test/student_dashboard.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= ($current_page == 'student_dashboard.php' && !isset($_GET['category'])) ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-vial <?= ($current_page == 'student_dashboard.php' && !isset($_GET['category'])) ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Test</span>
                </a>


                <a href="../stu_dash/my_performance.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_performance.php' ? 'bg-white shadow-md text-red-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-chart-line <?= $current_page == 'my_performance.php' ? 'text-red-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Performance</span>
                </a>

                <a href="../stu_dash/student_feedback.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_feedback.php' ? 'bg-white shadow-md text-indigo-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-comment-dots <?= $current_page == 'student_feedback.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Feedback</span>
                </a>

                <a href="../stu_dash/my_leaves.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_leaves.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-calendar-alt <?= $current_page == 'my_leaves.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Leave Applications</span>
                </a>

                <a href="../stu_dash/mark_attendance.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'mark_attendance.php' ? 'bg-white shadow-md text-green-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-clipboard-user <?= $current_page == 'mark_attendance.php' ? 'text-green-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Mark Attendance</span>
                </a>

                <a href="../stu_dash/view_attendance.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'view_attendance.php' ? 'bg-white shadow-md text-blue-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-list-alt <?= $current_page == 'view_attendance.php' ? 'text-blue-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">View Attendance</span>
                </a>

                <a href="../stu_dash/student_profile.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_profile.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i
                            class="fas fa-user-circle <?= $current_page == 'student_profile.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Profile</span>
                </a>

                <!-- Logout Button -->
                <a href="../logout.php"
                    class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-50 hover:text-red-600 text-gray-700 mt-4 border-t pt-4"
                    onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-sign-out-alt text-red-500"></i>
                    </div>
                    <span class="font-medium">Logout</span>
                </a>
            </nav>
        </div>
    </div>

    <div class="p-4 md:p-6 min-h-screen <?= $require_mandatory_feedback ? 'pointer-events-none' : '' ?>"
        style="background:#F7F5F3;">
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
        <div class="text-white p-6 rounded-2xl shadow-lg mb-6 transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1"
            style="background:linear-gradient(90deg,#1B3C53,#234C6A);">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div>
                    <h2 class="text-2xl font-bold mb-2">Welcome back, <?= htmlspecialchars($student['first_name']) ?>!
                    </h2>
                    <p style="color:rgba(210,193,182,0.85);">Here's your learning progress and upcoming activities</p>
                </div>
                <div class="mt-4 md:mt-0 bg-white bg-opacity-10 p-3 rounded-lg backdrop-blur-sm">
                    <p class="text-sm">Student ID: <span
                            class="font-semibold"><?= htmlspecialchars($student['student_id']) ?></span></p>
                    <p class="text-sm">Total Batches: <span class="font-semibold"><?= count($current_batches) ?></span>
                    </p>
                    <p class="text-sm">Course: <span
                            class="font-semibold"><?= htmlspecialchars($student['course'] ?? 'Not assigned') ?></span>
                    </p>
                </div>
            </div>
        </div>

        <!-- Batch Selection Tabs (Only show if multiple batches) -->
        <?php if (count($current_batches) > 1): ?>
            <div class="mb-6 p-4 rounded-2xl shadow-md" style="background: #fdf8f3; border:1px solid #456882;">
                <h3 class="text-lg font-bold mb-3 flex items-center" style="color:#1B3C53;">
                    <i class="fas fa-exchange-alt mr-2" style="color:#234C6A;"></i>
                    Select Batch to View
                </h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($current_batches as $index => $batch_info): ?>
                        <a href="?batch_index=<?= $index ?>" class="px-4 py-2 rounded-lg transition-all duration-300"
                            style="<?= $selected_batch_index == $index ? 'background:#1B3C53; color:white; box-shadow:0 2px 8px rgba(0,0,0,0.2);' : 'background:rgba(69,104,130,0.15); color:#1B3C53;' ?>">
                            <div class="flex items-center">
                                <i class="fas fa-layer-group mr-2"></i>
                                <?php
                                $batch_label = "Batch ";
                                if ($batch_info['field_name'] == 'batch_name')
                                    $batch_label .= "1";
                                elseif ($batch_info['field_name'] == 'batch_name_2')
                                    $batch_label .= "2";
                                elseif ($batch_info['field_name'] == 'batch_name_3')
                                    $batch_label .= "3";
                                elseif ($batch_info['field_name'] == 'batch_name_4')
                                    $batch_label .= "4";
                                ?>
                                <span><?= $batch_label ?>:
                                    <?= htmlspecialchars($batch_info['batch_data']['batch_name']) ?></span>
                                <?php if ($selected_batch_index == $index): ?>
                                    <i class="fas fa-check ml-2"></i>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($selected_batch): ?>
                    <p class="text-sm mt-2" style="color:#1B3C53;">
                        Currently viewing: <span class="font-semibold" style="color:#234C6A;">
                            <?php
                            $current_label = "Batch ";
                            if ($current_batches[$selected_batch_index]['field_name'] == 'batch_name')
                                $current_label .= "1";
                            elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_2')
                                $current_label .= "2";
                            elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_3')
                                $current_label .= "3";
                            elseif ($current_batches[$selected_batch_index]['field_name'] == 'batch_name_4')
                                $current_label .= "4";
                            echo $current_label . " - " . htmlspecialchars($selected_batch['batch_name']);
                            ?>
                        </span>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Overall Weekly Leaderboard Card -->
        <div class="mb-8 p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl"
            style="background: #fdf8f3; border:1px solid #456882;">
            <div class="flex justify-between items-center mb-6 border-b pb-4" style="border-color:#456882;">
                <h2 class="text-xl font-bold flex items-center" style="color:#1B3C53;">
                    <i class="fas fa-trophy mr-2 p-2 rounded-lg" style="color:#D2C1B6; background:#456882;"></i>
                    Overall Weekly Leaderboard
                </h2>
            </div>

            <?php if (!empty($overall_leaderboard)): ?>
                <div class="space-y-3 block animate-fade-in">
                    <?php foreach ($overall_leaderboard as $rank => $student_board): ?>
                        <?php
                        $lb_bg = $rank === 0 ? '#456882' : ($rank === 1 ? '#5a7a96' : ($rank === 2 ? '#6b8fa8' : 'rgba(69,104,130,0.12)'));
                        $lb_txt = $rank <= 2 ? 'white' : '#1B3C53';
                        $lb_border = $rank <= 2 ? 'transparent' : 'rgba(69,104,130,0.3)';
                        ?>
                        <div class="flex items-center justify-between p-3 rounded-lg transition-all duration-300 hover:-translate-y-1"
                            style="background:<?= $lb_bg ?>; color:<?= $lb_txt ?>; border:1px solid <?= $lb_border ?>;">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center mr-3 font-bold shadow-sm"
                                    style="background:rgba(255,255,255,0.25); color:<?= $lb_txt ?>;">
                                    #<?= $rank + 1 ?>
                                </div>
                                <div>
                                    <p class="font-bold flex items-center">
                                        <?= htmlspecialchars($student_board['first_name'] . ' ' . $student_board['last_name']) ?>
                                        <?php if (strtolower($student_board['student_id']) === strtolower($student['student_id'])): ?>
                                            <span class="ml-2 text-[10px] px-1.5 py-0.5 rounded font-bold uppercase tracking-wider"
                                                style="background:#D2C1B6; color:#1B3C53;">You</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold"><?= round($student_board['percentage'], 1) ?>%</p>
                                <p class="text-[10px] font-medium" style="opacity:0.75;">
                                    <?= floatval($student_board['total_obtained']) ?> /
                                    <?= floatval($student_board['max_marks']) ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-10" style="color:#1B3C53;">
                    <i class="fas fa-medal text-4xl mb-3" style="color:#456882;"></i>
                    <p>No overall test leaderboard data available yet</p>
                    <p class="text-sm mt-1" style="color:#234C6A;">Check back after your first test!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Batch Information with Join Class Button -->
        <div class="p-6 rounded-2xl shadow-md mb-8 transform transition-all duration-500 hover:shadow-xl hover:-translate-y-1"
            style="background: #fdf8f3; border:1px solid #456882;">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold flex items-center" style="color:#1B3C53;">
                    <i class="fas fa-info-circle mr-2 p-2 rounded-lg" style="color:#D2C1B6; background:#234C6A;"></i>
                    <?php if (count($current_batches) > 1): ?>
                        Selected Batch Information
                    <?php else: ?>
                        Batch Information
                    <?php endif; ?>
                </h2>
                <div class="flex space-x-2">
                    <a href="my_batches.php"
                        class="px-4 py-2 text-white rounded-lg transition-colors flex items-center space-x-2"
                        style="background:#1B3C53;" onmouseenter="this.style.background='#234C6A'"
                        onmouseleave="this.style.background='#1B3C53'">
                        <i class="fas fa-layer-group"></i>
                        <span>View Batch</span>
                    </a>
                </div>
            </div>

            <?php if ($selected_batch): ?>

                <!-- Compact Chips Row -->

                <div class="batch-chips-row">

                    <!-- Batch ID -->
                    <div class="batch-chip bchip-id">
                        <i class="fas fa-hashtag" style="font-size:12px;"></i>
                        <?= htmlspecialchars($selected_batch['batch_id']) ?>
                        <div class="bchip-tooltip">
                            <strong>Batch ID</strong>
                            <?= htmlspecialchars($selected_batch['batch_id']) ?>
                        </div>
                    </div>

                    <!-- Next Class -->
                    <?php if (count($upcoming_classes) > 0):
                        $latest_class = $upcoming_classes[0]; ?>
                        <div class="batch-chip bchip-next">
                            <?php if ($latest_class['can_join']): ?>
                                <span class="chip-live-dot"></span>
                            <?php else: ?>
                                <i class="fas fa-calendar-day" style="font-size:12px;"></i>
                            <?php endif; ?>
                            <?= htmlspecialchars(mb_strimwidth($latest_class['topic'], 0, 18, '…')) ?>
                            &nbsp;·&nbsp;
                            <?= date('M j, g:i A', strtotime($latest_class['schedule_date'] . ' ' . $latest_class['start_time'])) ?>
                            <div class="bchip-tooltip">
                                <strong>Next Class</strong>
                                <?= htmlspecialchars($latest_class['topic']) ?><br>
                                <span style="color:#6b7280;">
                                    <?= date('M j, Y · g:i A', strtotime($latest_class['schedule_date'] . ' ' . $latest_class['start_time'])) ?>
                                </span>
                                <?php if ($latest_class['can_join']): ?>
                                    <br>
                                    <a href="<?= htmlspecialchars($latest_class['join_action']) ?>" target="_blank"
                                        style="color:#16A34A;font-weight:700;margin-top:5px;display:inline-block;">
                                        Join now →
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="batch-chip bchip-next">
                            <i class="fas fa-calendar-day" style="font-size:12px;"></i>
                            No upcoming classes
                            <div class="bchip-tooltip">
                                <strong>Next Class</strong>
                                No upcoming classes scheduled
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Mode -->
                    <div class="batch-chip bchip-mode">
                        <i class="fas fa-laptop-house" style="font-size:12px;"></i>
                        <?= ucfirst($selected_batch['mode'] ?? 'Not specified') ?>
                        <div class="bchip-tooltip">
                            <strong>Mode</strong>
                            <?= ucfirst($selected_batch['mode'] ?? 'Not specified') ?>
                        </div>
                    </div>

                    <!-- Status -->
                    <div class="batch-chip bchip-status">
                        <i class="fas fa-tasks" style="font-size:12px;"></i>
                        <?= ucfirst($selected_batch['status'] ?? 'Unknown') ?>
                        <div class="bchip-tooltip">
                            <strong>Status</strong>
                            <?= ucfirst($selected_batch['status'] ?? 'Unknown') ?>
                        </div>
                    </div>

                    <!-- Assigned On -->
                    <div class="batch-chip bchip-date">
                        <i class="fas fa-calendar-check" style="font-size:12px;"></i>
                        <?= $allocation_date ? date('M j, Y', strtotime($allocation_date)) : 'N/A' ?>
                        <div class="bchip-tooltip">
                            <strong>Assigned On</strong>
                            <?= $allocation_date ? date('M j, Y', strtotime($allocation_date)) : 'Not assigned' ?>
                        </div>
                    </div>

                </div>

                <!-- Online Class Access (untouched) -->
                <?php if ($selected_batch['mode'] === 'online' && !empty($selected_batch['meeting_link'])): ?>
                    <div class="mt-2 p-4 rounded-lg" style="background:rgba(69,104,130,0.12); border:1px solid #456882;">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <div class="p-3 rounded-lg mr-4" style="background:#234C6A;">
                                    <i class="fas fa-video text-xl" style="color:#D2C1B6;"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold" style="color:#1B3C53;">Online Class Access</h3>
                                    <p class="text-sm" style="color:#234C6A;">Join your online classes using the meeting link
                                    </p>
                                    <p class="text-xs mt-1" style="color:#456882;">
                                        Platform: <?= htmlspecialchars($selected_batch['platform'] ?? 'Not specified') ?> |
                                        Schedule: <?= htmlspecialchars($selected_batch['time_slot'] ?? 'Not scheduled') ?>
                                    </p>
                                </div>
                            </div>
                            <a href="<?= htmlspecialchars($selected_batch['meeting_link']) ?>" target="_blank"
                                class="px-4 py-2 text-white rounded-lg transition-colors flex items-center space-x-2"
                                style="background:#1B3C53;" onmouseenter="this.style.background='#234C6A'"
                                onmouseleave="this.style.background='#1B3C53'">
                                <i class="fas fa-external-link-alt"></i>
                                <span>Join Class</span>
                            </a>
                        </div>
                        <div class="mt-3 text-sm flex items-center" style="color:#234C6A;">
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
                    <p class="text-sm text-gray-500 mt-2">You are not currently assigned to any batch. Please contact
                        administration.</p>
                    <a href="batch_enquiry.php"
                        class="mt-4 inline-block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                        <i class="fas fa-question-circle mr-2"></i>Enquire About Batches
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Stats Overview Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-5 mb-8">

            <?php
            function stat_theme($value)
            {
                if ($value >= 75)
                    return [
                        'bg' => '#456882',
                        'border' => '#1B3C53',
                        'icon_bg' => '#234C6A',
                        'icon_text' => '#D2C1B6',
                        'label' => '#D2C1B6',
                        'value_text' => '#ffffff',
                        'badge_bg' => '#1B3C53',
                        'badge_text' => '#D2C1B6',
                        'badge_label' => 'Good',
                        'dot' => 'rgba(210,193,182,0.4)'
                    ];
                if ($value >= 50)
                    return [
                        'bg' => '#5a7a96',
                        'border' => '#1B3C53',
                        'icon_bg' => '#234C6A',
                        'icon_text' => '#D2C1B6',
                        'label' => '#D2C1B6',
                        'value_text' => '#ffffff',
                        'badge_bg' => '#1B3C53',
                        'badge_text' => '#D2C1B6',
                        'badge_label' => 'Average',
                        'dot' => 'rgba(210,193,182,0.4)'
                    ];
                return [
                    'bg' => '#6b8fa8',
                    'border' => '#1B3C53',
                    'icon_bg' => '#234C6A',
                    'icon_text' => '#D2C1B6',
                    'label' => '#D2C1B6',
                    'value_text' => '#ffffff',
                    'badge_bg' => '#1B3C53',
                    'badge_text' => '#D2C1B6',
                    'badge_label' => 'Low',
                    'dot' => 'rgba(210,193,182,0.4)'
                ];
            }

            $progress_val = (float) $batch_progress['overall_progress'];
            $attendance_val = (float) $attendance_percentage;
            $camera_val = (float) $camera_usage_percentage;
            $exam_val = (float) $exam_percentage;
            $at = [
                'bg' => '#427197',
                'border' => '#1e3a8a',
                'icon_bg' => 'rgba(255, 255, 255, 0.2)',
                'icon_text' => '#ffffff',
                'label' => 'rgba(255, 255, 255, 0.85)',
                'value_text' => '#ffffff',
                'badge_bg' => 'rgba(255, 255, 255, 0.25)',
                'badge_text' => '#ffffff',
                'badge_label' => ($attendance_val >= 75 ? 'Good' : ($attendance_val >= 50 ? 'Average' : 'Low')),
            ];
            $et = [
                'bg' => '#759ebd',
                'border' => '#2563eb',
                'icon_bg' => 'rgba(255, 255, 255, 0.2)',
                'icon_text' => '#ffffff',
                'label' => 'rgba(255, 255, 255, 0.85)',
                'value_text' => '#ffffff',
                'badge_bg' => 'rgba(255, 255, 255, 0.25)',
                'badge_text' => '#ffffff',
                'badge_label' => ($exam_val >= 75 ? 'Good' : ($exam_val >= 50 ? 'Average' : 'Low')),
            ];
            $pt = [
                'bg' => '#a5c1d6',
                'border' => '#60a5fa',
                'icon_bg' => 'rgba(30, 58, 138, 0.1)',
                'icon_text' => '#1e3a8a',
                'label' => '#1e3a8a',
                'value_text' => '#1e3a8a',
                'badge_bg' => 'rgba(30, 58, 138, 0.12)',
                'badge_text' => '#1e3a8a',
                'badge_label' => ($progress_val >= 75 ? 'Good' : ($progress_val >= 50 ? 'Average' : 'Low')),
            ];
            ?>

            <?php
            // Reusable card renderer for conditional cards
            function render_stat_card($theme, $icon, $label, $value, $formatted_value)
            {
                echo '
                <div style="
                    background: ' . $theme['bg'] . ';
                    border-left: 5px solid ' . $theme['border'] . ';
                    border-radius: 1rem;
                    padding: 1.1rem 1.2rem;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.07);
                    transition: all 0.3s;
                    position: relative;
                    overflow: hidden;
                " onmouseenter="this.style.transform=\'translateY(-3px)\';this.style.boxShadow=\'0 8px 24px rgba(0,0,0,0.13)\'" onmouseleave="this.style.transform=\'\';this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.07)\'">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                        <div style="background:' . $theme['icon_bg'] . '; color:' . $theme['icon_text'] . '; width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; flex-shrink:0;">
                            <i class="fas fa-' . $icon . '"></i>
                        </div>
                        <span style="background:' . $theme['badge_bg'] . '; color:' . $theme['badge_text'] . '; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:0.03em;">
                            ' . $theme['badge_label'] . '
                        </span>
                    </div>
                    <p style="color:' . $theme['label'] . '; font-size:12px; font-weight:600; margin:0 0 2px;">' . $label . '</p>
                    <h3 style="color:' . $theme['value_text'] . '; font-size:1.6rem; font-weight:800; margin:0;">' . $formatted_value . '</h3>
                </div>';
            }

            render_stat_card($at, 'user-check', 'Attendance', $attendance_val, number_format($attendance_val, 1) . '%');
            render_stat_card($et, 'file-alt', 'Test Scores', $exam_val, $exam_val . '%');
            render_stat_card($pt, 'chart-line', 'Batch Progress', $progress_val, $progress_val . '%');
            ?>

            <!-- Camera On — palette themed (Lightest) -->
            <div style="
                background: #d2e0ed;
                border-left: 5px solid #93c5fd;
                border-radius: 1rem;
                padding: 1.1rem 1.2rem;
                box-shadow: 0 2px 8px rgba(0,0,0,0.07);
                transition: all 0.3s;
                overflow: hidden;
            " onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,0.13)'"
                onmouseleave="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.07)'">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                    <div
                        style="background:rgba(30, 58, 138, 0.1); color:#1e3a8a; width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px;">
                        <i class="fas fa-video"></i>
                    </div>
                    <span
                        style="background:rgba(30, 58, 138, 0.12); color:#1e3a8a; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px;">
                        Camera
                    </span>
                </div>
                <p style="color:#1e3a8a; font-size:12px; font-weight:600; margin:0 0 2px;">Camera On</p>
                <h3 style="color:#1e3a8a; font-size:1.6rem; font-weight:800; margin:0;">
                    <?= number_format($camera_val, 1) ?>%
                </h3>
            </div>


            <!-- Detailed Report -->
            <a href="my_performance.php" style="
                    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
                    border-left: 5px solid #0d2233;
                    border-radius: 1rem;
                    padding: 1.1rem 1.2rem;
                    box-shadow: 0 2px 8px rgba(27,60,83,0.25);
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    text-decoration: none;
                    position: relative;
                    overflow: hidden;
                    transition: all 0.3s;
                "
                onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(27,60,83,0.4)'"
                onmouseleave="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(27,60,83,0.25)'">

                <!-- decorative circles -->
                <div
                    style="position:absolute; width:80px; height:80px; border-radius:50%; background:rgba(255,255,255,0.08); top:-20px; right:-20px;">
                </div>
                <div
                    style="position:absolute; width:50px; height:50px; border-radius:50%; background:rgba(255,255,255,0.06); bottom:10px; right:10px;">
                </div>

                <div style="display:flex; align-items:center; gap:10px; margin-bottom:10px; position:relative;">
                    <div
                        style="background:rgba(255,255,255,0.2); width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <span
                        style="background:rgba(255,255,255,0.18); color:#fff; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:0.03em;">
                        Report
                    </span>
                </div>

                <div style="position:relative;">
                    <h3 style="color:#fff; font-size:1rem; font-weight:800; margin:0 0 6px; line-height:1.2;">Detailed
                        Report</h3>
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <p style="color:rgba(255,255,255,0.75); font-size:11px; margin:0;">View performance</p>
                        <div
                            style="background:rgba(255,255,255,0.2); width:26px; height:26px; border-radius:8px; display:flex; align-items:center; justify-content:center;">
                            <i class="fas fa-arrow-right" style="color:#fff; font-size:11px;"></i>
                        </div>
                    </div>
                </div>
            </a>

        </div>

        <!-- Stats Overview Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-8">

            <!-- Recent Content -->
            <div style="background: #fdf8f3; border-radius:1rem; padding:1.4rem; box-shadow:0 2px 8px rgba(0,0,0,0.07); border:1.5px solid #456882; transition:all 0.3s; display:flex; flex-direction:column; height:100%;"
                onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,0.13)'"
                onmouseleave="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.07)'">

                <h2
                    style="font-size:1rem; font-weight:800; color:#1B3C53; display:flex; align-items:center; gap:8px; margin-bottom:1rem; flex-shrink:0;">
                    <span
                        style="background:#234C6A; color:#D2C1B6; padding:6px 8px; border-radius:8px; font-size:13px;">
                        <i class="fas fa-book"></i>
                    </span>
                    Recent Content
                </h2>

                <?php if (count($recent_content) > 0): ?>
                    <div
                        style="display:flex; flex-direction:column; gap:8px; overflow-y:auto; min-height:0; padding-right:4px; flex:1; max-height:320px;">
                        <?php foreach ($recent_content as $content):
                            $ft = $content['file_type'];
                            $icon = $ft === 'Test' ? 'fa-file-signature' : ($ft === 'Assignment' ? 'fa-tasks' : ($ft === 'Notes' ? 'fa-sticky-note' : 'fa-file'));
                            ?>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; background:rgba(69,104,130,0.1); border:1.5px solid #456882; border-radius:10px; transition:background 0.2s;"
                                onmouseenter="this.style.background='rgba(69,104,130,0.2)'"
                                onmouseleave="this.style.background='rgba(69,104,130,0.1)'">
                                <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                                    <div
                                        style="background:#234C6A; color:#D2C1B6; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0;">
                                        <i class="fas <?= $icon ?>"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <h3
                                            style="font-weight:700; color:#1B3C53; font-size:13px; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:140px;">
                                            <?= htmlspecialchars($content['title']) ?>
                                        </h3>
                                        <p style="font-size:11px; color:#456882; margin:0;">
                                            <?= date('M j', strtotime($content['uploaded_at'])) ?>
                                            <?php if ($content['batch_id'] !== $selected_batch_id): ?>
                                                <span
                                                    style="background:#456882; color:white; font-size:10px; padding:1px 6px; border-radius:999px; margin-left:4px;">Other
                                                    Batch</span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                                <span
                                    style="background:#1B3C53; color:#D2C1B6; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap; margin-left:8px; flex-shrink:0;">
                                    <?= htmlspecialchars($ft) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="margin-top:12px; text-align:center; flex-shrink:0;">
                        <a href="my_content.php"
                            style="color:#1B3C53; font-weight:700; font-size:13px; text-decoration:none;">
                            View All Content <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>

                <?php else: ?>
                    <div style="text-align:center; padding:2rem 0; color:#456882;">
                        <i class="fas fa-book-open"
                            style="font-size:2rem; color:#234C6A; display:block; margin-bottom:8px;"></i>
                        No course content available yet
                        <p style="font-size:12px; margin-top:4px;">New content will appear here when uploaded</p>
                    </div>
                <?php endif; ?>
            </div>


            <!-- Leave Applications -->
            <div style="background: #fdf8f3; border-radius:1rem; padding:1.4rem; box-shadow:0 2px 8px rgba(0,0,0,0.07); border:1.5px solid #456882; transition:all 0.3s; display:flex; flex-direction:column; height:100%;"
                onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,0.13)'"
                onmouseleave="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.07)'">

                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem;">
                    <h2
                        style="font-size:1rem; font-weight:800; color:#1B3C53; display:flex; align-items:center; gap:8px; margin:0;">
                        <span
                            style="background:#234C6A; color:#D2C1B6; padding:6px 8px; border-radius:8px; font-size:13px;">
                            <i class="fas fa-calendar-alt"></i>
                        </span>
                        Leave Applications
                    </h2>
                    <a href="my_leaves.php"
                        style="color:#234C6A; font-size:12px; font-weight:700; text-decoration:none; display:flex; align-items:center; gap:4px;">
                        View All <i class="fas fa-arrow-right"></i>
                    </a>
                </div>

                <?php if (count($recent_leaves) > 0): ?>
                    <div
                        style="display:flex; flex-direction:column; gap:8px; overflow-y:auto; min-height:0; padding-right:4px; flex:1; max-height:260px;">
                        <?php foreach ($recent_leaves as $leave):
                            $s = $leave['status'];
                            $badge_bg = $s === 'approved' ? '#16a34a' : ($s === 'rejected' ? '#dc2626' : ($s === 'cancelled' ? '#6b7280' : '#d97706'));
                            $badge_color = '#fff';
                            ?>
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; background:rgba(69,104,130,0.1); border:1.5px solid #456882; border-radius:10px; transition:background 0.2s;"
                                onmouseenter="this.style.background='rgba(69,104,130,0.2)'"
                                onmouseleave="this.style.background='rgba(69,104,130,0.1)'">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div
                                        style="background:#234C6A; color:#D2C1B6; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0;">
                                        <i class="fas fa-file-alt"></i>
                                    </div>
                                    <div>
                                        <p style="font-weight:700; color:#1B3C53; font-size:13px; margin:0;">
                                            <?= htmlspecialchars($leave['application_no']) ?>
                                        </p>
                                        <p style="font-size:11px; color:#456882; margin:0;">
                                            <?= date('d M Y', strtotime($leave['start_date'])) ?> –
                                            <?= date('d M Y', strtotime($leave['end_date'])) ?>
                                            (<?= $leave['total_days'] ?> days)
                                        </p>
                                    </div>
                                </div>
                                <span
                                    style="background:<?= $badge_bg ?>; color:<?= $badge_color ?>; font-size:11px; font-weight:700; padding:3px 10px; border-radius:999px; white-space:nowrap;">
                                    <?= ucfirst($s) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div
                        style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin-top:14px; padding-top:14px; border-top:1.5px solid #456882; flex-shrink:0;">
                        <div
                            style="text-align:center; background:rgba(69,104,130,0.2); border-radius:8px; padding:8px 4px;">
                            <div style="font-size:1.4rem; font-weight:800; color:#1B3C53;"><?= $pending_leaves ?></div>
                            <div style="font-size:11px; font-weight:700; color:#234C6A;">Pending</div>
                        </div>
                        <div
                            style="text-align:center; background:rgba(69,104,130,0.2); border-radius:8px; padding:8px 4px;">
                            <div style="font-size:1.4rem; font-weight:800; color:#1B3C53;"><?= $approved_leaves ?></div>
                            <div style="font-size:11px; font-weight:700; color:#234C6A;">Approved</div>
                        </div>
                        <div
                            style="text-align:center; background:rgba(69,104,130,0.2); border-radius:8px; padding:8px 4px;">
                            <div style="font-size:1.4rem; font-weight:800; color:#1B3C53;"><?= $rejected_leaves ?></div>
                            <div style="font-size:11px; font-weight:700; color:#234C6A;">Rejected</div>
                        </div>
                    </div>

                <?php else: ?>
                    <div
                        style="text-align:center; color:#6b7280; flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center;">

                        <i class="fas fa-calendar-times"
                            style="font-size:2rem; color:#fdba74; display:block; margin-bottom:8px;"></i>
                        No leave applications yet
                        <br>
                        <a href="leaves/apply_leave.php"
                            style="color:#ea580c; font-weight:700; font-size:13px; text-decoration:none; margin-top:8px; display:inline-block;">
                            Apply for Leave <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>


            <!-- Quick Actions -->
            <div style="background: #fdf8f3; border-radius:1rem; padding:1.4rem; box-shadow:0 2px 8px rgba(0,0,0,0.07); border:1.5px solid #456882; transition:all 0.3s; display:flex; flex-direction:column; height:100%;"
                onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,0.13)'"
                onmouseleave="this.style.transform='';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.07)'">

                <h2
                    style="font-size:1rem; font-weight:800; color:#1B3C53; display:flex; align-items:center; gap:8px; margin-bottom:1rem;">
                    <span
                        style="background:#234C6A; color:#D2C1B6; padding:6px 8px; border-radius:8px; font-size:13px;">
                        <i class="fas fa-rocket"></i>
                    </span>
                    Quick Actions
                </h2>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; flex:1;">

                    <a href="my_performance.php"
                        style="background:rgba(69,104,130,0.15); border:1.5px solid #456882; border-radius:10px; padding:14px 10px; text-align:center; text-decoration:none; transition:all 0.2s; display:flex; flex-direction:column; align-items:center; justify-content:center;"
                        onmouseenter="this.style.background='rgba(69,104,130,0.3)';this.style.transform='scale(1.04)'"
                        onmouseleave="this.style.background='rgba(69,104,130,0.15)';this.style.transform='scale(1)'">
                        <div
                            style="background:#234C6A; color:#D2C1B6; width:36px; height:36px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:7px; font-size:15px;">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <p style="font-weight:700; color:#1B3C53; font-size:13px; margin:0;">Performance</p>
                    </a>

                    <a href="my_content.php"
                        style="background:rgba(69,104,130,0.15); border:1.5px solid #456882; border-radius:10px; padding:14px 10px; text-align:center; text-decoration:none; transition:all 0.2s; display:flex; flex-direction:column; align-items:center; justify-content:center;"
                        onmouseenter="this.style.background='rgba(69,104,130,0.3)';this.style.transform='scale(1.04)'"
                        onmouseleave="this.style.background='rgba(69,104,130,0.15)';this.style.transform='scale(1)'">
                        <div
                            style="background:#234C6A; color:#D2C1B6; width:36px; height:36px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:7px; font-size:15px;">
                            <i class="fas fa-book"></i>
                        </div>
                        <p style="font-weight:700; color:#1B3C53; font-size:13px; margin:0;">Content</p>
                    </a>

                    <a href="student_feedback.php"
                        style="background:rgba(69,104,130,0.15); border:1.5px solid #456882; border-radius:10px; padding:14px 10px; text-align:center; text-decoration:none; transition:all 0.2s; display:flex; flex-direction:column; align-items:center; justify-content:center;"
                        onmouseenter="this.style.background='rgba(69,104,130,0.3)';this.style.transform='scale(1.04)'"
                        onmouseleave="this.style.background='rgba(69,104,130,0.15)';this.style.transform='scale(1)'">
                        <div
                            style="background:#234C6A; color:#D2C1B6; width:36px; height:36px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:7px; font-size:15px;">
                            <i class="fas fa-comment-dots"></i>
                        </div>
                        <p style="font-weight:700; color:#1B3C53; font-size:13px; margin:0;">Feedback</p>
                    </a>

                    <a href="my_leaves.php"
                        style="background:rgba(69,104,130,0.15); border:1.5px solid #456882; border-radius:10px; padding:14px 10px; text-align:center; text-decoration:none; transition:all 0.2s; display:flex; flex-direction:column; align-items:center; justify-content:center;"
                        onmouseenter="this.style.background='rgba(69,104,130,0.3)';this.style.transform='scale(1.04)'"
                        onmouseleave="this.style.background='rgba(69,104,130,0.15)';this.style.transform='scale(1)'">
                        <div
                            style="background:#234C6A; color:#D2C1B6; width:36px; height:36px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; margin-bottom:7px; font-size:15px;">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <p style="font-weight:700; color:#1B3C53; font-size:13px; margin:0;">Leave</p>
                    </a>

                </div>
            </div>

        </div>


        <!-- RIGHT Column -->
        <div class=" space-y-6 order-2">

            <!-- Weekly Tests Leaderboards Card -->
            <div class="p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl"
                style="background: #fdf8f3; border:1px solid #456882;">
                <div class="flex justify-between items-center mb-6 border-b pb-4" style="border-color:#456882;">
                    <h2 class="text-xl font-bold flex items-center" style="color:#1B3C53;">
                        <i class="fas fa-calendar-check mr-2 p-2 rounded-lg"
                            style="color:#D2C1B6; background:#456882;"></i>
                        Weekly Tests
                    </h2>
                    <?php if (!empty($weekly_leaderboard_data)): ?>
                        <select id="weeklySelect" onchange="showWeeklyLb(this.value)"
                            class="text-sm border rounded-lg px-3 py-1.5 focus:outline-none font-medium cursor-pointer max-w-[200px] truncate"
                            style="border-color:#456882; background:#456882; color:white;">
                            <?php foreach ($weekly_leaderboard_data as $test_board): ?>
                                <option value="weekly_<?= $test_board['test_id'] ?>">
                                    <?= htmlspecialchars($test_board['test_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <?php if (!empty($weekly_leaderboard_data)): ?>
                    <?php foreach ($weekly_leaderboard_data as $test_board): ?>
                        <div id="lb_weekly_<?= $test_board['test_id'] ?>"
                            class="weekly-lb-section space-y-3 hidden animate-fade-in">
                            <?php foreach ($test_board['top_students'] as $rank => $student_board): ?>
                                <?php
                                $wlb_bg = $rank === 0 ? '#456882' : ($rank === 1 ? '#5a7a96' : ($rank === 2 ? '#6b8fa8' : 'rgba(69,104,130,0.12)'));
                                $wlb_txt = $rank <= 2 ? 'white' : '#1B3C53';
                                ?>
                                <div class="flex items-center justify-between p-3 rounded-lg transition-all duration-300 hover:-translate-y-1"
                                    style="background:<?= $wlb_bg ?>; color:<?= $wlb_txt ?>;">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center mr-3 font-bold shadow-sm"
                                            style="background:rgba(255,255,255,0.25); color:<?= $wlb_txt ?>;">
                                            #<?= $rank + 1 ?>
                                        </div>
                                        <div>
                                            <p class="font-bold flex items-center">
                                                <?= htmlspecialchars($student_board['first_name'] . ' ' . $student_board['last_name']) ?>
                                                <?php if (strtolower($student_board['student_id']) === strtolower($student['student_id'])): ?>
                                                    <span
                                                        class="ml-2 text-[10px] px-1.5 py-0.5 rounded font-bold uppercase tracking-wider"
                                                        style="background:#D2C1B6; color:#1B3C53;">You</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold"><?= round($student_board['percentage'], 1) ?>%</p>
                                        <p class="text-[10px] font-medium" style="opacity:0.75;">
                                            <?= floatval($student_board['obtained_marks']) ?> /
                                            <?= floatval($test_board['total_marks']) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-10" style="color:#1B3C53;">
                        <i class="fas fa-medal text-4xl mb-3" style="color:#456882;"></i>
                        <p>No weekly tests data available yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Chapter-wise Tests Leaderboards Card -->
            <div class="p-6 rounded-2xl shadow-lg transform transition-all duration-500 hover:shadow-xl"
                style="background: #fdf8f3; border:1px solid #456882;">
                <div class="flex justify-between items-center mb-6 border-b pb-4" style="border-color:#456882;">
                    <h2 class="text-xl font-bold flex items-center" style="color:#1B3C53;">
                        <i class="fas fa-book-open mr-2 p-2 rounded-lg" style="color:#D2C1B6; background:#456882;"></i>
                        Chapter-wise Tests
                    </h2>
                    <?php if (!empty($chapter_leaderboard_data)): ?>
                        <select id="chapterSelect" onchange="showChapterLb(this.value)"
                            class="text-sm border rounded-lg px-3 py-1.5 focus:outline-none font-medium cursor-pointer max-w-[200px] truncate"
                            style="border-color:#456882; background:#456882; color:white;">
                            <?php foreach ($chapter_leaderboard_data as $test_board): ?>
                                <option value="chapter_<?= $test_board['test_id'] ?>">
                                    <?= htmlspecialchars($test_board['test_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>

                <?php if (!empty($chapter_leaderboard_data)): ?>
                    <?php foreach ($chapter_leaderboard_data as $test_board): ?>
                        <div id="lb_chapter_<?= $test_board['test_id'] ?>"
                            class="chapter-lb-section space-y-3 hidden animate-fade-in">
                            <?php foreach ($test_board['top_students'] as $rank => $student_board): ?>
                                <?php
                                $clb_bg = $rank === 0 ? '#456882' : ($rank === 1 ? '#5a7a96' : ($rank === 2 ? '#6b8fa8' : 'rgba(69,104,130,0.12)'));
                                $clb_txt = $rank <= 2 ? 'white' : '#1B3C53';
                                ?>
                                <div class="flex items-center justify-between p-3 rounded-lg transition-all duration-300 hover:-translate-y-1"
                                    style="background:<?= $clb_bg ?>; color:<?= $clb_txt ?>;">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center mr-3 font-bold shadow-sm"
                                            style="background:rgba(255,255,255,0.25); color:<?= $clb_txt ?>;">
                                            #<?= $rank + 1 ?>
                                        </div>
                                        <div>
                                            <p class="font-bold flex items-center">
                                                <?= htmlspecialchars($student_board['first_name'] . ' ' . $student_board['last_name']) ?>
                                                <?php if (strtolower($student_board['student_id']) === strtolower($student['student_id'])): ?>
                                                    <span
                                                        class="ml-2 text-[10px] px-1.5 py-0.5 rounded font-bold uppercase tracking-wider"
                                                        style="background:#D2C1B6; color:#1B3C53;">You</span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-bold"><?= round($student_board['percentage'], 1) ?>%</p>
                                        <p class="text-[10px] font-medium" style="opacity:0.75;">
                                            <?= floatval($student_board['obtained_marks']) ?> /
                                            <?= floatval($test_board['total_marks']) ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-10" style="color:#1B3C53;">
                        <i class="fas fa-medal text-4xl mb-3" style="color:#456882;"></i>
                        <p>No chapter-wise tests data available yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                function showWeeklyLb(id) {
                    document.querySelectorAll('.weekly-lb-section').forEach(el => {
                        el.classList.add('hidden');
                        el.classList.remove('block');
                    });
                    const target = document.getElementById('lb_' + id);
                    if (target) {
                        target.classList.remove('hidden');
                        target.classList.add('block');
                    }
                }

                function showChapterLb(id) {
                    document.querySelectorAll('.chapter-lb-section').forEach(el => {
                        el.classList.add('hidden');
                        el.classList.remove('block');
                    });
                    const target = document.getElementById('lb_' + id);
                    if (target) {
                        target.classList.remove('hidden');
                        target.classList.add('block');
                    }
                }

                // Initialize to show the first test for weekly and chapter-wise
                document.addEventListener('DOMContentLoaded', function () {
                    const weeklySelect = document.getElementById('weeklySelect');
                    if (weeklySelect) showWeeklyLb(weeklySelect.value);

                    const chapterSelect = document.getElementById('chapterSelect');
                    if (chapterSelect) showChapterLb(chapterSelect.value);
                });
            </script>
        </div>



    </div>
</div>

<!-- Auto-Modal for Feedback Only - IMPROVED SCROLLABLE VERSION WITH BATCH SELECTION -->
<div id="feedbackModal"
    class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 overflow-y-auto <?= $show_modal ? '' : 'hidden' ?>"
    style="padding: 20px 0;">
    <div class="relative my-auto mx-auto w-full max-w-2xl">
        <div class="bg-white rounded-2xl shadow-2xl mx-4 max-h-[85vh] flex flex-col">
            <!-- Modal Header - Fixed -->
            <div class="text-white p-4 md:p-6 rounded-t-2xl flex-shrink-0"
                style="background:linear-gradient(90deg,#1B3C53,#234C6A);">
                <div class="flex justify-between items-center">
                    <h2 class="text-xl md:text-2xl font-bold flex items-center">
                        <i class="fas fa-comment-dots mr-3"></i>
                        Class Feedback
                        <?php if ($isSaturday): ?>
                            <span class="ml-3 text-sm px-2 py-1 rounded-full" style="background:#456882;">Mandatory
                                (Saturday)</span>
                        <?php endif; ?>
                    </h2>
                    <?php if (!$isSaturday): ?>
                        <button id="closeModal" class="text-white hover:text-gray-200 text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    <?php endif; ?>
                </div>
                <p class="mt-2 text-sm md:text-base" style="color:rgba(210,193,182,0.85);">Share your feedback to help
                    us improve the learning
                    experience</p>
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
                                        if ($batch['field_name'] == 'batch_name')
                                            $batch_label .= "1";
                                        elseif ($batch['field_name'] == 'batch_name_2')
                                            $batch_label .= "2";
                                        elseif ($batch['field_name'] == 'batch_name_3')
                                            $batch_label .= "3";
                                        elseif ($batch['field_name'] == 'batch_name_4')
                                            $batch_label .= "4";
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
                                <label class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">Class
                                    Rating *</label>
                                <div class="star-rating" data-target="class_rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span data-value="<?= $i ?>"
                                            class="text-xl md:text-2xl cursor-pointer text-gray-300 hover:text-yellow-400">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="class_rating" id="class_rating" required>
                            </div>

                            <div class="text-center">
                                <label
                                    class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">Assignment
                                    Understanding *</label>
                                <div class="star-rating" data-target="assignment_understanding">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span data-value="<?= $i ?>"
                                            class="text-xl md:text-2xl cursor-pointer text-gray-300 hover:text-yellow-400">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="assignment_understanding" id="assignment_understanding"
                                    required>
                            </div>

                            <div class="text-center">
                                <label
                                    class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">Practical
                                    Understanding *</label>
                                <div class="star-rating" data-target="practical_understanding">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span data-value="<?= $i ?>"
                                            class="text-xl md:text-2xl cursor-pointer text-gray-300 hover:text-yellow-400">★</span>
                                    <?php endfor; ?>
                                </div>
                                <input type="hidden" name="practical_understanding" id="practical_understanding"
                                    required>
                            </div>
                        </div>

                        <!-- Satisfaction -->
                        <div>
                            <label for="satisfied"
                                class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
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
                            <label for="suggestions"
                                class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                                Your suggestions or issues
                            </label>
                            <textarea id="suggestions" name="suggestions" rows="2"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base"
                                placeholder="Share your suggestions or any issues you faced..."
                                maxlength="500"></textarea>
                        </div>

                        <!-- Additional Feedback -->
                        <div>
                            <label for="feedback_text"
                                class="block text-gray-700 mb-1 md:mb-2 font-medium text-sm md:text-base">
                                Additional Feedback
                            </label>
                            <textarea id="feedback_text" name="feedback_text" rows="2"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm md:text-base"
                                placeholder="Share your thoughts about the course, instructor, or any suggestions for improvement..."
                                maxlength="1000"></textarea>
                        </div>
                    </div>

                    <!-- Action Buttons - Fixed at bottom -->
                    <div class="flex flex-col sm:flex-row justify-end gap-2 sm:space-x-4 mt-4 md:mt-6 pt-4 border-t">
                        <?php if (!$isSaturday): ?>
                            <button type="button" id="skipButton"
                                class="px-4 py-2 text-gray-600 border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors text-sm md:text-base order-2 sm:order-1">
                                Skip for Now
                            </button>
                        <?php endif; ?>
                        <button type="submit" name="submit_feedback"
                            class="px-4 py-2 text-white rounded-lg transition-colors flex items-center justify-center space-x-2 text-sm md:text-base <?= $isSaturday ? 'w-full' : '' ?> order-1 sm:order-2 mb-2 sm:mb-0"
                            style="background:#1B3C53;" onmouseenter="this.style.background='#234C6A'"
                            onmouseleave="this.style.background='#1B3C53'">
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
    .batch-chips-row {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .batch-chip {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 9999px;
        font-size: 13px;
        font-weight: 600;
        cursor: default;
        border: 1px solid transparent;
        transition: box-shadow 0.15s, transform 0.15s;
        user-select: none;
    }

    .batch-chip:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10);
    }

    .batch-chip:hover .bchip-tooltip {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
        pointer-events: auto;
    }

    .bchip-tooltip {
        position: absolute;
        top: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%) translateY(6px);
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 10px 14px;
        font-size: 12px;
        color: #111827;
        white-space: nowrap;
        opacity: 0;
        transition: opacity 0.15s, transform 0.15s;
        z-index: 50;
        pointer-events: none;
        min-width: 150px;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.10);
    }

    .bchip-tooltip strong {
        display: block;
        font-size: 10px;
        font-weight: 700;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-bottom: 3px;
    }

    .chip-live-dot {
        display: inline-block;
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #EF4444;
        animation: chipPulse 1.2s infinite;
    }

    @keyframes chipPulse {

        0%,
        100% {
            opacity: 1
        }

        50% {
            opacity: 0.35
        }
    }

    /* Color variants — palette themed */
    .bchip-id {
        background: rgba(69, 104, 130, 0.15);
        color: #1B3C53;
        border-color: #456882;
    }

    .bchip-next {
        background: #234C6A;
        color: #D2C1B6;
        border-color: #1B3C53;
    }

    .bchip-mode {
        background: rgba(210, 193, 182, 0.5);
        color: #1B3C53;
        border-color: #456882;
    }

    .bchip-status {
        background: #456882;
        color: white;
        border-color: #1B3C53;
    }

    .bchip-date {
        background: rgba(69, 104, 130, 0.12);
        color: #234C6A;
        border-color: #456882;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes slideIn {
        from {
            transform: translateX(-100%);
        }

        to {
            transform: translateX(0);
        }
    }

    .animate-fade-in {
        animation: fadeIn 0.6s ease-out forwards;
    }

    .animate-slide-in {
        animation: slideIn 0.3s ease-out forwards;
    }

    .delay-100 {
        animation-delay: 0.1s;
    }

    .delay-200 {
        animation-delay: 0.2s;
    }

    .delay-300 {
        animation-delay: 0.3s;
    }

    .star-rating span.active {
        color: #f39c12;
    }

    /* Mobile menu styles */
    .mobile-nav-link.active {
        background-color: #ffffff;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
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
    document.getElementById('mobileMenu').addEventListener('click', function (e) {
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
            star.addEventListener('click', function () {
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

            star.addEventListener('mouseover', function () {
                const value = this.dataset.value;
                this.parentElement.querySelectorAll('span').forEach((s, index) => {
                    if (index < value) {
                        s.style.color = '#f39c12';
                    }
                });
            });

            star.addEventListener('mouseout', function () {
                this.parentElement.querySelectorAll('span').forEach(s => {
                    if (!s.classList.contains('active')) {
                        s.style.color = '#d1d5db';
                    }
                });
            });
        });
    });

    // Form validation for modal
    document.getElementById('feedbackFormModal').addEventListener('submit', function (e) {
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
    document.addEventListener('DOMContentLoaded', function () {
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
                    modal.addEventListener('click', function (e) {
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
            closeModalBtn.addEventListener('click', function () {
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
            skipButton.addEventListener('click', function () {
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
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('flex')) {
                    showToast('Feedback is mandatory on Saturday. Please submit your feedback.', 'error');
                    e.preventDefault();
                    e.stopPropagation();
                }
            });
        }

        // Animate cards on page load
        const cards = document.querySelectorAll('.rounded-2xl');
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