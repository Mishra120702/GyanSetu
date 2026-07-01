<?php
// latest my_batches.php 
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit;
}

$student_id = $_SESSION['user_id'];
$student_query = $db->prepare("SELECT * FROM students WHERE user_id = :user_id");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get pending topic verifications for this student
$verif_stmt = $db->prepare("
    SELECT tv.id, tv.main_topic_id, tv.batch_id, tv.course_id, tv.created_at,
           mt.chapter, mt.topic_name,
           c.name as course_name,
           b.batch_name
    FROM topic_verifications tv
    JOIN main_topics mt ON tv.main_topic_id = mt.id
    JOIN courses c ON tv.course_id = c.id
    JOIN batches b ON tv.batch_id = b.batch_id
    WHERE tv.student_id = ? AND tv.status = 'pending'
    ORDER BY tv.created_at DESC
");
$verif_stmt->execute([$student['student_id']]);
$pending_verifications = $verif_stmt->fetchAll(PDO::FETCH_ASSOC);
$pending_verif_count = count($pending_verifications);

// Handle verification POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verif_action'])) {
    $verif_id = (int) ($_POST['verif_id'] ?? 0);
    $action = $_POST['verif_action'];
    if (in_array($action, ['verified', 'rejected']) && $verif_id) {
        $upd = $db->prepare("UPDATE topic_verifications SET status = ?, responded_at = NOW() WHERE id = ? AND student_id = ? AND status = 'pending'");
        $upd->execute([$action, $verif_id, $student['student_id']]);
    }
    header("Location: " . $_SERVER['PHP_SELF'] . (isset($_GET['batch_index']) ? '?batch_index=' . (int) $_GET['batch_index'] : ''));
    exit;
}

// Get current batches (batch_name, batch_name_2, batch_name_3, batch_name_4)
$current_batches = [];
$batch_names = ['batch_name', 'batch_name_2', 'batch_name_3', 'batch_name_4'];

foreach ($batch_names as $batch_field) {
    if (!empty($student[$batch_field])) {
        $batch_query = $db->prepare("
            SELECT b.*, t.name as trainer_name, t.phone as trainer_phone, t.email as trainer_email 
            FROM batches b
            LEFT JOIN trainers t ON b.batch_mentor_id = t.id
            WHERE b.batch_id = :batch_id
        ");
        $batch_query->execute([':batch_id' => $student[$batch_field]]);
        $batch_data = $batch_query->fetch(PDO::FETCH_ASSOC);

        if ($batch_data) {
            // Fetch assigned courses with order
            $course_query = $db->prepare("
                SELECT c.id, c.name, bc.status, bc.id as bc_id
                FROM batch_courses bc 
                JOIN courses c ON bc.course_id = c.id 
                WHERE bc.batch_id = :batch_id
                ORDER BY bc.id ASC
            ");
            $course_query->execute([':batch_id' => $student[$batch_field]]);
            $batch_data['assigned_courses'] = $course_query->fetchAll(PDO::FETCH_ASSOC);

            $current_batches[] = [
                'field_name' => $batch_field,
                'batch_name' => $student[$batch_field],
                'batch_data' => $batch_data
            ];
        }
    }
}

// Get selected batch from URL or default to first
$selected_batch_index = isset($_GET['batch_index']) ? intval($_GET['batch_index']) : 0;
if ($selected_batch_index >= count($current_batches)) {
    $selected_batch_index = 0;
}

// Get selected course from URL
$selected_course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : null;

$selected_batch = null;
$batch_progress = [];
$batch_topics = [];
$selected_batch_id = null;

// Get batch history
$batch_history_query = $db->prepare("
    SELECT sbh.*, b.batch_name, b.start_date, b.end_date, b.thumbnail_path
    FROM student_batch_history sbh
    JOIN batches b ON sbh.to_batch_id = b.batch_id
    WHERE sbh.student_id = :student_id
    ORDER BY sbh.transfer_date DESC
");
$batch_history_query->execute([':student_id' => $student['student_id']]);
$batch_history = $batch_history_query->fetchAll(PDO::FETCH_ASSOC);

// --- Initial Progress Calculation ---
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

if (!empty($current_batches) && isset($current_batches[$selected_batch_index])) {
    $selected_batch = $current_batches[$selected_batch_index]['batch_data'];
    $selected_batch_id = $selected_batch['batch_id'];

    // Get progress data for selected batch
    $progress_stmt = $db->prepare("
        SELECT 
            mt.id,
            mt.chapter,
            mt.topic_name,
            mt.topic_type,
            mt.covered_by_trainer,
            mt.covered_date,
            mt.course_id,
            c.name as course_name,
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
        ORDER BY mt.course_id ASC, mt.chapter ASC
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

    $overall_progress = 0;
    if ($total_sub_topics > 0) {
        $overall_progress = round((($theory_completed_total + $practical_completed_total) / ($total_sub_topics * 2)) * 100, 2);
    }
    $batch_progress['overall_progress'] = $overall_progress;

    // Process the sub-topics data for detailed view
    foreach ($batch_topics as &$topic) {
        $sub_topics = [];
        if (!empty($topic['sub_topic_details'])) {
            $sub_topic_items = explode('||', $topic['sub_topic_details']);
            foreach ($sub_topic_items as $item) {
                $parts = explode(':', $item);
                if (count($parts) >= 4) {
                    $sub_topics[] = [
                        'id' => $parts[0],
                        'name' => $parts[1],
                        'theory_completed' => $parts[2],
                        'practical_completed' => $parts[3]
                    ];
                }
            }
        }
        $topic['sub_topics'] = $sub_topics;
        unset($topic['sub_topic_details']);
    }
    unset($topic);
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
        ':student_id' => $student['student_id'],
        ':batch_id' => $selected_batch_id
    ]);
    $attendance_data = $attendance_query->fetch(PDO::FETCH_ASSOC);

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
        ':student_id' => $student['student_id'],
        ':batch_id' => $selected_batch_id
    ]);
    $exam_data = $exam_query->fetch(PDO::FETCH_ASSOC);

    $exam_percentage = $exam_data && $exam_data['total_exams'] > 0 ?
        round($exam_data['avg_percentage'], 2) : 0;
    $pass_rate = $exam_data && $exam_data['total_exams'] > 0 ?
        round(($exam_data['passed_exams'] / $exam_data['total_exams']) * 100, 2) : 0;
}

// === OVERALL BATCH PROGRESS ===
$student_db_id = $student['student_id'];

// Chapter-wise Tests for this batch
$chapter_tests_progress = ['total' => 0, 'attempted' => 0, 'avg_score' => 0];
if ($selected_batch_id) {
    $ct_stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT t.id) as total_tests,
            SUM(CASE WHEN ta.id IS NOT NULL THEN 1 ELSE 0 END) as attempted_tests,
            COALESCE(AVG(ta.percentage), 0) as avg_score
        FROM tests t
        INNER JOIN main_topics mt ON mt.id = t.chapter_id AND mt.batch_name = ?
        LEFT JOIN test_attempts ta ON ta.test_id = t.id AND ta.student_id = ? AND ta.status = 'submitted'
        WHERE t.test_category = 'chapter_wise' AND t.is_active = 1
    ");
    $ct_stmt->execute([$selected_batch_id, $student_db_id]);
    $ct_row = $ct_stmt->fetch(PDO::FETCH_ASSOC);
    if ($ct_row && (int) $ct_row['total_tests'] > 0) {
        $chapter_tests_progress = [
            'total' => (int) $ct_row['total_tests'],
            'attempted' => (int) $ct_row['attempted_tests'],
            'avg_score' => round((float) $ct_row['avg_score'], 1)
        ];
    } else {
        $ct_fb = $db->prepare("
            SELECT COUNT(DISTINCT t.id) as total_tests,
                   COUNT(DISTINCT ta.test_id) as attempted_tests,
                   COALESCE(AVG(ta.percentage), 0) as avg_score
            FROM test_attempts ta
            JOIN tests t ON t.id = ta.test_id
            WHERE ta.student_id = ? AND ta.status = 'submitted'
              AND t.test_category = 'chapter_wise'
        ");
        $ct_fb->execute([$student_db_id]);
        $ct_fb_row = $ct_fb->fetch(PDO::FETCH_ASSOC);
        if ($ct_fb_row) {
            $chapter_tests_progress = [
                'total' => (int) $ct_fb_row['total_tests'],
                'attempted' => (int) $ct_fb_row['attempted_tests'],
                'avg_score' => round((float) $ct_fb_row['avg_score'], 1)
            ];
        }
    }
}

// Weekly Tests for this batch
$weekly_tests_progress = ['total' => 0, 'attempted' => 0, 'avg_score' => 0];
if ($selected_batch_id) {
    $wt_stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT t.id) as total_tests,
            COUNT(DISTINCT ta.test_id) as attempted_tests,
            COALESCE(AVG(ta.percentage), 0) as avg_score
        FROM tests t
        LEFT JOIN test_attempts ta ON ta.test_id = t.id AND ta.student_id = ? AND ta.status = 'submitted'
        WHERE t.test_category = 'weekly' AND t.is_active = 1
        AND (t.batch_id = ? OR t.assigned_to LIKE CONCAT('%', ?, '%'))
    ");
    $wt_stmt->execute([$student_db_id, $selected_batch_id, $selected_batch_id]);
    $wt_row = $wt_stmt->fetch(PDO::FETCH_ASSOC);
    if ($wt_row) {
        $weekly_tests_progress = [
            'total' => (int) $wt_row['total_tests'],
            'attempted' => (int) $wt_row['attempted_tests'],
            'avg_score' => round((float) $wt_row['avg_score'], 1)
        ];
    }
}

// Assignments for this batch
$assignments_progress = ['total' => 0, 'submitted' => 0];
if ($selected_batch_id) {
    $asgn_total_stmt = $db->prepare("
        SELECT COUNT(DISTINCT u.id) as total
        FROM uploads u
        JOIN batch_uploads bu ON bu.upload_id = u.id
        WHERE bu.batch_id = ?
    ");
    $asgn_total_stmt->execute([$selected_batch_id]);
    $asgn_total_row = $asgn_total_stmt->fetch(PDO::FETCH_ASSOC);

    $asgn_sub_stmt = $db->prepare("
        SELECT COUNT(DISTINCT asub.upload_id) as submitted
        FROM assignment_submissions asub
        JOIN uploads u ON u.id = asub.upload_id
        JOIN batch_uploads bu ON bu.upload_id = u.id
        WHERE bu.batch_id = ? AND asub.student_id = ?
    ");
    $asgn_sub_stmt->execute([$selected_batch_id, $student_db_id]);
    $asgn_sub_row = $asgn_sub_stmt->fetch(PDO::FETCH_ASSOC);

    $assignments_progress = [
        'total' => (int) ($asgn_total_row['total'] ?? 0),
        'submitted' => (int) ($asgn_sub_row['submitted'] ?? 0)
    ];
}

// Calculate course progress for the selected batch
$course_progress = [];
$course_checkpoints = [];
$in_progress_course_id = null;
$in_progress_course_name = null;

if ($selected_batch && !empty($selected_batch['assigned_courses'])) {
    $total_courses = count($selected_batch['assigned_courses']);
    $completed_courses = 0;
    $current_course_index = -1;
    
    foreach ($selected_batch['assigned_courses'] as $index => $course) {
        $status = $course['status'];
        $is_completed = ($status === 'completed');
        $is_in_progress = ($status === 'in_progress');
        $is_pending = ($status === 'pending');
        
        if ($is_completed) {
            $completed_courses++;
        }
        if ($is_in_progress && $current_course_index === -1) {
            $current_course_index = $index;
            $in_progress_course_id = $course['id'];
            $in_progress_course_name = $course['name'];
        }
        
        $course_checkpoints[] = [
            'id' => $course['id'],
            'name' => $course['name'],
            'status' => $status,
            'is_completed' => $is_completed,
            'is_in_progress' => $is_in_progress,
            'is_pending' => $is_pending,
            'index' => $index,
            'label' => $is_completed ? 'Completed' : ($is_in_progress ? 'In Progress' : 'Not Started')
        ];
    }
    
    // If no course is in_progress, find the first pending course
    if ($current_course_index === -1) {
        foreach ($selected_batch['assigned_courses'] as $index => $course) {
            if ($course['status'] === 'pending') {
                $current_course_index = $index;
                $in_progress_course_id = $course['id'];
                $in_progress_course_name = $course['name'];
                break;
            }
        }
    }
    
    $course_progress = [
        'total' => $total_courses,
        'completed' => $completed_courses,
        'percentage' => $total_courses > 0 ? round(($completed_courses / $total_courses) * 100) : 0,
        'current_index' => $current_course_index,
        'current_course_id' => $in_progress_course_id,
        'current_course_name' => $in_progress_course_name,
        'checkpoints' => $course_checkpoints
    ];
}

// Auto-select the in-progress course if no course is selected
if (!$selected_course_id && $in_progress_course_id) {
    $selected_course_id = $in_progress_course_id;
}

// Filter topics by selected course
$filtered_topics = [];
$all_course_topics = [];
$has_topics_for_selected_course = false;

if (!empty($batch_topics)) {
    // Group topics by course
    foreach ($batch_topics as $topic) {
        $course_id = $topic['course_id'] ?? 0;
        if (!isset($all_course_topics[$course_id])) {
            $all_course_topics[$course_id] = [];
        }
        $all_course_topics[$course_id][] = $topic;
    }
    
    // If a course is selected, filter topics for that course
    if ($selected_course_id && isset($all_course_topics[$selected_course_id])) {
        $filtered_topics = $all_course_topics[$selected_course_id];
        $has_topics_for_selected_course = true;
    } elseif ($in_progress_course_id && isset($all_course_topics[$in_progress_course_id])) {
        // Default to in-progress course topics
        $filtered_topics = $all_course_topics[$in_progress_course_id];
        $selected_course_id = $in_progress_course_id;
        $has_topics_for_selected_course = true;
    } else {
        // If no match, show all topics
        foreach ($all_course_topics as $topics) {
            $filtered_topics = array_merge($filtered_topics, $topics);
        }
        $has_topics_for_selected_course = !empty($filtered_topics);
    }
}

// Get course names for the course filter
$course_names_map = [];
if ($selected_batch && !empty($selected_batch['assigned_courses'])) {
    foreach ($selected_batch['assigned_courses'] as $c) {
        $course_names_map[$c['id']] = $c['name'];
    }
}
?>
<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<!-- Add Font Awesome and Animation Libraries -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
<link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap"
    rel="stylesheet">

<style>
    /* ─── Brand Palette Variables ─── */
    :root {
        --primary-dark: #1B3C53;
        --primary: #234C6A;
        --primary-light: #456882;
        --neutral: #D2C1B6;
        --accent: #A4C4D4;
        --neutral-bg: #F5F0EB;
        --neutral-light: #EAE4E0;
        --white: #ffffff;
        --shadow: 0 4px 20px rgba(27, 60, 83, .13);
        --shadow-hover: 0 16px 48px rgba(27, 60, 83, .22);
        --radius: 1rem;
        --transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    }

    * {
        box-sizing: border-box;
    }

    body {
        background:
            radial-gradient(1100px 500px at 100% -8%, rgba(69, 104, 130, .22), transparent 55%),
            radial-gradient(900px 450px at -10% 108%, rgba(27, 60, 83, .16), transparent 55%),
            radial-gradient(rgba(27, 60, 83, .045) 1px, transparent 1px) 0 0 / 22px 22px,
            linear-gradient(165deg, #e8e2db 0%, #e4ddd5 44%, #d9e3ec 100%);
        background-attachment: fixed;
        font-family: 'Inter', sans-serif;
        min-height: 100vh;
    }

    ::-webkit-scrollbar { width: 7px; height: 7px; }
    ::-webkit-scrollbar-track { background: var(--neutral); border-radius: 4px; }
    ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #456882, #234C6A); border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: var(--primary-dark); }

    .batches-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
        gap: 24px;
        margin: 20px 0;
    }

    .batch-card {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 20px rgba(27, 60, 83, .13);
        position: relative;
        cursor: pointer;
        animation: fadeInUp 0.6s ease-out forwards;
        opacity: 0;
        border-left: 4px solid #456882;
        border-top: 1px solid rgba(27, 60, 83, .08);
        border-right: 1px solid rgba(27, 60, 83, .08);
        border-bottom: 1px solid rgba(27, 60, 83, .08);
    }

    .batch-card:nth-child(1) { animation-delay: 0.1s; }
    .batch-card:nth-child(2) { animation-delay: 0.2s; }
    .batch-card:nth-child(3) { animation-delay: 0.3s; }
    .batch-card:nth-child(4) { animation-delay: 0.4s; }

    .batch-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 24px 48px rgba(27, 60, 83, 0.18);
        border-left-color: #1B3C53;
    }

    .batch-card.selected {
        border-left: 4px solid var(--primary-dark);
        box-shadow: 0 0 0 3px rgba(27, 60, 83, 0.18), 0 24px 48px rgba(27, 60, 83, 0.2);
        transform: translateY(-4px);
        z-index: 2;
    }

    .batch-card.selected .batch-card-rainbow-bar { display: block; }

    .batch-card-rainbow-bar {
        display: none;
        position: absolute;
        top: 0;
        left: 4px;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6, #A4C4D4, #456882, #1B3C53);
        background-size: 300% 100%;
        z-index: 10;
        animation: rainbowSlide 3s linear infinite;
    }

    @keyframes rainbowSlide {
        0% { background-position: 0% 50%; }
        100% { background-position: 300% 50%; }
    }

    .batch-card.selected .selected-badge { display: flex; }

    .selected-badge {
        position: absolute;
        top: 16px;
        left: 20px;
        background: linear-gradient(135deg, var(--primary-dark), var(--primary-light));
        color: white;
        padding: 5px 14px;
        border-radius: 9999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.4px;
        z-index: 10;
        box-shadow: 0 4px 14px rgba(27, 60, 83, 0.45);
        align-items: center;
        gap: 6px;
        display: none;
        animation: slideInLeft 0.4s ease-out;
    }

    @keyframes slideInLeft {
        from { opacity: 0; transform: translateX(-18px); }
        to { opacity: 1; transform: translateX(0); }
    }

    .batch-card.selected .batch-thumbnail-container::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(135deg, rgba(27, 60, 83, 0.18), rgba(69, 104, 130, 0.18));
        pointer-events: none;
        z-index: 1;
    }

    .batch-thumbnail-container {
        position: relative;
        height: 195px;
        overflow: hidden;
    }

    .batch-thumbnail {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .batch-card:hover .batch-thumbnail { transform: scale(1.08); }

    .thumbnail-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to top, rgba(0, 0, 0, 0.72) 0%, transparent 60%);
        display: flex;
        align-items: flex-end;
        padding: 16px;
        opacity: 0;
        transition: opacity 0.4s;
    }

    .batch-card:hover .thumbnail-overlay { opacity: 1; }

    .thumbnail-placeholder {
        height: 195px;
        background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, var(--primary-light) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 3.5rem;
        position: relative;
        overflow: hidden;
    }

    .placeholder-pattern {
        position: absolute;
        inset: -50%;
        background: repeating-linear-gradient(45deg, transparent, transparent 22px,
                rgba(255, 255, 255, 0.07) 22px, rgba(255, 255, 255, 0.07) 44px);
        animation: patternMove 20s linear infinite;
    }

    @keyframes patternMove {
        0% { transform: translate(0, 0); }
        100% { transform: translate(50px, 50px); }
    }

    .batch-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        z-index: 5;
        backdrop-filter: blur(8px);
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
    }

    .badge-ongoing { background: linear-gradient(135deg, #10b981, #14b8a6); color: #fff; }
    .badge-upcoming { background: linear-gradient(135deg, #f59e0b, #f97316); color: #fff; }
    .badge-completed { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: #fff; }
    .badge-cancelled { background: linear-gradient(135deg, #f43f5e, #ec4899); color: #fff; }

    .batch-content { padding: 20px; }

    .batch-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
    }

    .batch-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 700;
        color: var(--primary-dark);
        font-size: 1.2rem;
        margin-bottom: 5px;
    }

    .batch-id {
        font-size: 0.85rem;
        color: var(--primary);
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .batch-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 12px 0;
        color: #64748b;
        font-size: 0.88rem;
    }

    .meta-item {
        display: flex;
        align-items: center;
        gap: 6px;
        background: var(--neutral-bg);
        padding: 4px 10px;
        border-radius: 8px;
        border: 1px solid rgba(27, 60, 83, 0.1);
    }

    .meta-item i { color: var(--primary); width: 14px; }

    .mode-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.3px;
    }

    .mode-online {
        background: linear-gradient(135deg, rgba(14, 165, 233, .12), rgba(27, 60, 83, .12));
        color: #0ea5e9;
        border: 1px solid rgba(14, 165, 233, .3);
    }
    .mode-offline {
        background: linear-gradient(135deg, rgba(245, 158, 11, .12), rgba(249, 115, 22, .12));
        color: #f97316;
        border: 1px solid rgba(249, 115, 22, .3);
    }

    .course-tag-card {
        background: linear-gradient(135deg, rgba(27, 60, 83, 0.09), rgba(69, 104, 130, 0.09));
        border: 1px solid rgba(27, 60, 83, 0.22);
        color: var(--primary);
        font-size: 0.7rem;
        font-weight: 600;
        padding: 4px 10px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .progress-bar-container {
        height: 9px;
        background: rgba(27, 60, 83, 0.09);
        border-radius: 6px;
        overflow: hidden;
        margin-bottom: 8px;
    }

    .progress-bar-fill {
        height: 100%;
        border-radius: 6px;
        background: linear-gradient(90deg, var(--primary-dark), var(--primary), var(--primary-light));
        background-size: 200% 100%;
        transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .progress-bar-fill::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
        animation: progressShine 2.2s ease-in-out infinite;
    }

    @keyframes progressShine {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }

    .batch-tabs-wrap {
        background: #ffffff;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 20px rgba(27, 60, 83, .13);
        border-left: 4px solid #456882;
        border-top: 1px solid rgba(27, 60, 83, .08);
        border-right: 1px solid rgba(27, 60, 83, .08);
        border-bottom: 1px solid rgba(27, 60, 83, .08);
        margin-bottom: 24px;
    }

    .batch-tab {
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.92rem;
    }

    .batch-tab.active {
        background: linear-gradient(135deg, #eef6fb, #c8e0ef);
        color: var(--primary-dark);
        box-shadow: 0 4px 14px rgba(27, 60, 83, .18);
        transform: translateY(-2px);
    }

    .batch-tab:not(.active) {
        background: linear-gradient(90deg, #2c5f7a, #3a7595, #5a8fa8);
        color: #ffffff;
        text-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
    }

    .batch-tab:not(.active):hover {
        background: linear-gradient(90deg, #3a7595, #5a8fa8, #7aadca);
        color: #ffffff;
        transform: translateY(-2px);
    }

    .viewing-indicator {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: linear-gradient(135deg, rgba(27, 60, 83, 0.1), rgba(69, 104, 130, 0.1));
        padding: 5px 14px;
        border-radius: 40px;
        font-size: 0.78rem;
        color: var(--primary);
        font-weight: 600;
        margin-left: 12px;
        border: 1px solid rgba(27, 60, 83, 0.18);
        animation: gentlePulse 2.5s ease-in-out infinite;
    }

    @keyframes gentlePulse {
        0%, 100% { opacity: 0.7; }
        50% { opacity: 1; }
    }

    .empty-state {
        text-align: center;
        padding: 70px 20px;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(27, 60, 83, .13);
        border-left: 4px solid #456882;
        border-top: 1px solid rgba(27, 60, 83, .08);
        border-right: 1px solid rgba(27, 60, 83, .08);
        border-bottom: 1px solid rgba(27, 60, 83, .08);
    }

    .empty-state i {
        font-size: 4.5rem;
        background: linear-gradient(135deg, var(--primary-dark), var(--primary-light));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 20px;
        display: block;
        animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-12px); }
    }

    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(28px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .detail-hero {
        background: linear-gradient(135deg, #1B3C53 0%, #234C6A 45%, #456882 100%);
        border-radius: 16px;
        padding: 32px 36px;
        color: white;
        position: relative;
        overflow: hidden;
        margin-bottom: 24px;
        box-shadow: 0 16px 48px rgba(27, 60, 83, 0.3);
    }

    .detail-hero::before {
        content: '';
        position: absolute;
        width: 320px;
        height: 320px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
        top: -100px;
        right: -80px;
    }

    .detail-hero::after {
        content: '';
        position: absolute;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.04);
        bottom: -50px;
        left: 10%;
    }

    .info-card {
        border-radius: 16px;
        padding: 24px;
        position: relative;
        overflow: hidden;
        background: #ffffff;
        box-shadow: 0 4px 20px rgba(27, 60, 83, .13);
        border-left: 4px solid #456882;
        border-top: 1px solid rgba(27, 60, 83, .08);
        border-right: 1px solid rgba(27, 60, 83, .08);
        border-bottom: 1px solid rgba(27, 60, 83, .08);
        transition: transform 0.3s, box-shadow 0.3s;
    }

    .info-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 32px rgba(27, 60, 83, .18);
    }

    .info-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 4px;
        right: 0;
        height: 3px;
    }

    .info-card-blue::before { background: linear-gradient(90deg, var(--primary), var(--primary-light)); }
    .info-card-purple::before { background: linear-gradient(90deg, var(--primary-light), var(--neutral)); }

    /* ── COURSE PROGRESS TIMELINE ── */
    .course-timeline-wrapper {
        position: relative;
        padding: 10px 0 5px 0;
        margin: 10px 0;
    }

    .course-timeline-track {
        position: relative;
        height: 4px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 2px;
        margin: 18px 0 0 0;
        overflow: hidden;
    }

    .course-timeline-track .timeline-progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #10b981, #34d399, #6ee7b7);
        background-size: 200% 100%;
        border-radius: 2px;
        transition: width 1.5s cubic-bezier(0.4, 0, 0.2, 1);
        width: <?= $course_progress['percentage'] ?? 0 ?>%;
        position: relative;
        animation: progressPulse 2s ease-in-out infinite;
    }

    .course-timeline-track .timeline-progress-fill::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        animation: progressShine 2.5s ease-in-out infinite;
    }

    @keyframes progressPulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.85; }
    }

    @keyframes progressShine {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }

    .course-checkpoints-container {
        display: flex;
        justify-content: space-between;
        position: relative;
        margin-top: -22px;
    }

    .course-checkpoint {
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
        z-index: 2;
        flex: 1;
        cursor: pointer;
        transition: transform 0.3s ease;
        text-decoration: none;
        min-width: 0;
    }

    .course-checkpoint:hover { transform: translateY(-4px); }

    .course-checkpoint .checkpoint-dot {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 700;
        border: 3px solid rgba(255, 255, 255, 0.3);
        background: rgba(255, 255, 255, 0.15);
        transition: all 0.3s ease;
        color: white;
        flex-shrink: 0;
    }

    .course-checkpoint.completed .checkpoint-dot {
        background: linear-gradient(135deg, #10b981, #34d399);
        border-color: #10b981;
        box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        animation: dotComplete 2s ease-in-out infinite;
    }

    @keyframes dotComplete {
        0%, 100% { box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4); }
        50% { box-shadow: 0 4px 24px rgba(16, 185, 129, 0.6); }
    }

    .course-checkpoint.in-progress .checkpoint-dot {
        background: linear-gradient(135deg, #f59e0b, #fbbf24);
        border-color: #f59e0b;
        box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
        animation: pulse-dot 2s ease-in-out infinite;
    }

    .course-checkpoint.pending .checkpoint-dot {
        background: rgba(255, 255, 255, 0.1);
        border-color: rgba(255, 255, 255, 0.2);
        color: rgba(255, 255, 255, 0.6);
    }

    @keyframes pulse-dot {
        0%, 100% { transform: scale(1); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4); }
        50% { transform: scale(1.15); box-shadow: 0 4px 20px rgba(245, 158, 11, 0.6); }
    }

    .course-checkpoint .checkpoint-label {
        font-size: 0.6rem;
        font-weight: 600;
        text-align: center;
        color: rgba(255, 255, 255, 0.7);
        max-width: 80px;
        line-height: 1.2;
        margin-top: 6px;
        transition: color 0.3s ease;
        word-break: break-word;
    }

    .course-checkpoint.completed .checkpoint-label { color: #a7f3d0; }
    .course-checkpoint.in-progress .checkpoint-label { color: #fde68a; }
    .course-checkpoint.pending .checkpoint-label { color: rgba(255, 255, 255, 0.5); }

    .course-checkpoint .checkpoint-status {
        font-size: 0.5rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 2px;
        padding: 1px 8px;
        border-radius: 9999px;
        color: white;
        white-space: nowrap;
        transition: all 0.3s ease;
    }

    .course-checkpoint.completed .checkpoint-status {
        background: rgba(16, 185, 129, 0.3);
        color: #a7f3d0;
    }

    .course-checkpoint.in-progress .checkpoint-status {
        background: rgba(245, 158, 11, 0.3);
        color: #fde68a;
        animation: pulse-status 2s ease-in-out infinite;
    }

    .course-checkpoint.pending .checkpoint-status {
        background: rgba(255, 255, 255, 0.08);
        color: rgba(255, 255, 255, 0.5);
    }

    @keyframes pulse-status {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.6; }
    }

    .course-checkpoint .checkpoint-icon { font-size: 12px; }

    /* ── COURSE FILTER BUTTONS ── */
    .course-filter-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin: 12px 0 20px 0;
    }

    .course-filter-btn {
        padding: 6px 16px;
        border-radius: 9999px;
        font-size: 0.78rem;
        font-weight: 600;
        border: 2px solid rgba(27, 60, 83, 0.15);
        background: white;
        color: var(--primary);
        cursor: pointer;
        transition: all 0.25s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .course-filter-btn:hover {
        border-color: var(--primary);
        background: var(--neutral-bg);
        transform: translateY(-2px);
    }

    .course-filter-btn.active {
        border-color: var(--primary-dark);
        background: var(--primary-dark);
        color: white;
        box-shadow: 0 4px 12px rgba(27, 60, 83, 0.3);
    }

    .course-filter-btn .status-badge {
        font-size: 0.55rem;
        padding: 1px 8px;
        border-radius: 9999px;
        background: rgba(0,0,0,0.1);
    }

    .course-filter-btn.active .status-badge {
        background: rgba(255,255,255,0.2);
    }

    /* ── NOT UPDATED YET STYLE ── */
    .not-updated-yet {
        background: var(--neutral-bg);
        border: 2px dashed rgba(27, 60, 83, 0.22);
        border-radius: 16px;
        padding: 40px 20px;
        text-align: center;
        animation: fadeInUp 0.6s ease-out;
    }

    .not-updated-yet .icon-wrapper {
        width: 64px;
        height: 64px;
        margin: 0 auto 16px;
        background: linear-gradient(135deg, rgba(27, 60, 83, 0.08), rgba(69, 104, 130, 0.08));
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        color: var(--primary-light);
        animation: pulse-icon 2s ease-in-out infinite;
    }

    @keyframes pulse-icon {
        0%, 100% { transform: scale(1); opacity: 0.7; }
        50% { transform: scale(1.05); opacity: 1; }
    }

    .not-updated-yet h4 {
        font-family: 'Poppins', sans-serif;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--primary-dark);
        margin-bottom: 6px;
    }

    .not-updated-yet p {
        color: #6b7280;
        font-size: 0.9rem;
        margin-bottom: 4px;
    }

    .not-updated-yet .sub-text {
        font-size: 0.78rem;
        color: #9ca3af;
    }

    @media (max-width: 768px) {
        .course-checkpoints-container {
            overflow-x: auto;
            padding: 0 4px;
            gap: 4px;
            -webkit-overflow-scrolling: touch;
        }
        .course-checkpoint { flex: 0 0 auto; min-width: 60px; }
        .course-checkpoint .checkpoint-label { font-size: 0.5rem; max-width: 55px; }
        .course-checkpoint .checkpoint-dot { width: 26px; height: 26px; font-size: 10px; }
        .batches-grid { grid-template-columns: 1fr; }
        .detail-hero { padding: 22px 18px; }
        .course-section-header-v2 { flex-direction: column; align-items: flex-start; }
        .course-action-btns { width: 100%; }
        .tp-stats-grid { gap: 0; }
        .batch-tabs-wrap { padding: 14px; }
        .course-filter-wrap { gap: 4px; }
        .course-filter-btn { font-size: 0.7rem; padding: 4px 12px; }
        .course-timeline-track { margin: 14px 0 0 0; }
        .course-checkpoints-container { margin-top: -18px; }
    }

    h2, h3, .section-heading {
        font-family: 'Inter', 'Poppins', sans-serif;
        color: #234C6A;
    }

    .topic-card-enhanced {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 20px rgba(27, 60, 83, .13);
        display: flex;
        flex-direction: column;
        height: 100%;
        border-left: 4px solid #456882;
        border-top: 1px solid rgba(27, 60, 83, .08);
        border-right: 1px solid rgba(27, 60, 83, .08);
        border-bottom: 1px solid rgba(27, 60, 83, .08);
    }

    .topic-card-enhanced:hover {
        transform: translateY(-6px);
        box-shadow: 0 18px 40px rgba(27, 60, 83, 0.18);
    }

    .topic-card-top {
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: relative;
        overflow: hidden;
    }

    .topic-card-top::after {
        content: '';
        position: absolute;
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.18);
        top: -40px;
        right: -20px;
        pointer-events: none;
    }

    .topic-top-theory { background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%); }
    .topic-top-practical { background: linear-gradient(135deg, #065f46 0%, #10b981 100%); }
    .topic-top-both { background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%); }

    .topic-chapter-badge {
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        padding: 4px 12px;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.22);
        color: white;
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255, 255, 255, 0.25);
    }

    .topic-type-pill {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        padding: 4px 10px;
        border-radius: 20px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: rgba(255, 255, 255, 0.18);
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.28);
        backdrop-filter: blur(4px);
    }

    .topic-card-body {
        padding: 14px 16px 10px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .topic-name {
        font-size: 0.95rem;
        font-weight: 700;
        color: var(--primary-dark);
        line-height: 1.4;
        font-family: 'Poppins', sans-serif;
        margin: 0;
    }

    .tp-stats-grid {
        display: flex;
        align-items: stretch;
        border-radius: 14px;
        overflow: hidden;
        border: 1px solid rgba(27, 60, 83, 0.1);
    }

    .tp-stat {
        flex: 1;
        text-align: center;
        padding: 10px 6px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 2px;
    }

    .tp-stat-theory { background: linear-gradient(135deg, #eff6ff, #dbeafe); }
    .tp-stat-practical { background: linear-gradient(135deg, #f0fdf4, #d1fae5); }

    .tp-divider {
        width: 1px;
        background: linear-gradient(to bottom, transparent 10%, rgba(27, 60, 83, 0.15) 50%, transparent 90%);
        flex-shrink: 0;
    }

    .tp-stat-icon { font-size: 0.8rem; margin-bottom: 1px; }
    .tp-stat-theory .tp-stat-icon { color: var(--primary); }
    .tp-stat-practical .tp-stat-icon { color: #059669; }

    .tp-stat-count {
        font-size: 1.05rem;
        font-weight: 800;
        line-height: 1;
        font-family: 'Poppins', sans-serif;
    }
    .tp-stat-theory .tp-stat-count { color: var(--primary-dark); }
    .tp-stat-practical .tp-stat-count { color: #065f46; }

    .tp-stat-label {
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #6b7280;
    }

    .topic-progress-wrap {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 0 2px;
    }

    .topic-progress-track {
        flex: 1;
        height: 7px;
        background: #f1f5f9;
        border-radius: 4px;
        overflow: hidden;
        position: relative;
    }

    .topic-progress-fill {
        height: 100%;
        border-radius: 4px;
        transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .topic-progress-fill::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
        animation: progressShine 2s ease-in-out infinite;
    }

    .topic-progress-theory { background: linear-gradient(90deg, var(--primary), var(--primary-light)); }
    .topic-progress-practical { background: linear-gradient(90deg, #059669, #14b8a6); }
    .topic-progress-both { background: linear-gradient(90deg, var(--primary-dark), var(--primary-light)); }

    .topic-progress-pct {
        font-size: 0.68rem;
        font-weight: 700;
        color: var(--primary);
        min-width: 30px;
        text-align: right;
        font-family: 'Poppins', sans-serif;
    }

    .covered-indicator {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.65rem;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 20px;
    }

    .covered-yes {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        color: #065f46;
    }
    .covered-no {
        background: #f3f4f6;
        color: #9ca3af;
        border: 1px solid #e5e7eb;
    }

    .topic-toggle-section {
        border-top: 1px solid #f1f5f9;
        flex-shrink: 0;
    }

    .topic-toggle-btn {
        width: 100%;
        padding: 11px 16px;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--primary);
        background: linear-gradient(135deg, #f5f0eb, #eae4e0);
        border: none;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.25s;
        text-align: left;
        font-family: 'Inter', sans-serif;
    }

    .topic-toggle-btn:hover {
        background: linear-gradient(135deg, var(--neutral-light), var(--neutral));
        color: var(--primary-dark);
    }

    .topic-toggle-btn i.chevron-icon { transition: transform 0.3s ease; }

    .topic-subtopics-panel {
        background: #fafafa;
        border-top: 1px solid rgba(27, 60, 83, .08);
        padding: 10px;
        max-height: 260px;
        overflow-y: auto;
    }

    .topic-subtopics-panel::-webkit-scrollbar { width: 4px; }
    .topic-subtopics-panel::-webkit-scrollbar-track { background: var(--neutral); border-radius: 4px; }
    .topic-subtopics-panel::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #456882, #234C6A); border-radius: 4px; }

    .subtopic-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 12px;
        border-radius: 10px;
        background: white;
        border: 1px solid rgba(27, 60, 83, 0.08);
        margin-bottom: 6px;
        transition: all 0.2s;
        gap: 8px;
    }

    .subtopic-row:last-child { margin-bottom: 0; }

    .subtopic-row:hover {
        background: var(--neutral-bg);
        border-color: rgba(27, 60, 83, 0.2);
        transform: translateX(3px);
        box-shadow: 0 2px 8px rgba(27, 60, 83, 0.08);
    }

    .subtopic-name-text {
        font-size: 0.76rem;
        font-weight: 500;
        color: #374151;
        flex: 1;
        line-height: 1.35;
    }

    .subtopic-tp-badges { display: flex; gap: 4px; flex-shrink: 0; }

    .tp-badge {
        font-size: 0.6rem;
        font-weight: 800;
        padding: 3px 8px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 3px;
        letter-spacing: 0.2px;
        text-transform: uppercase;
    }

    .tp-t-done {
        background: linear-gradient(135deg, var(--primary-dark), var(--primary));
        color: white;
        box-shadow: 0 2px 6px rgba(27, 60, 83, 0.35);
    }
    .tp-t-pending {
        background: #f3f4f6;
        color: #9ca3af;
        border: 1px solid #e5e7eb;
    }
    .tp-p-done {
        background: linear-gradient(135deg, #065f46, #10b981);
        color: white;
        box-shadow: 0 2px 6px rgba(16, 185, 129, 0.35);
    }
    .tp-p-pending {
        background: #f3f4f6;
        color: #9ca3af;
        border: 1px solid #e5e7eb;
    }

    .course-section-header-v2 {
        background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
        border-radius: 16px;
        padding: 16px 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        box-shadow: 0 8px 28px rgba(27, 60, 83, 0.28);
        position: relative;
        overflow: hidden;
    }

    .course-section-header-v2::before {
        content: '';
        position: absolute;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
        top: -90px;
        right: -50px;
        pointer-events: none;
    }

    .course-section-header-v2::after {
        content: '';
        position: absolute;
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.04);
        bottom: -40px;
        left: 30%;
        pointer-events: none;
    }

    .course-header-title {
        font-family: 'Poppins', sans-serif;
        font-size: 1.05rem;
        font-weight: 700;
        color: white;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        display: flex;
        align-items: center;
        gap: 10px;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        position: relative;
        z-index: 1;
    }

    .course-icon-wrap {
        width: 32px;
        height: 32px;
        background: rgba(255, 255, 255, 0.18);
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        flex-shrink: 0;
    }

    .course-action-btns {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        position: relative;
        z-index: 1;
    }

    .course-action-btn {
        padding: 7px 14px;
        border-radius: 8px;
        font-size: 0.78rem;
        font-weight: 700;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.22s;
        backdrop-filter: blur(6px);
        border: 1px solid transparent;
        white-space: nowrap;
    }

    .course-btn-teal {
        background: rgba(20, 184, 166, 0.22);
        color: #99f6e4;
        border-color: rgba(20, 184, 166, 0.3);
    }
    .course-btn-teal:hover {
        background: rgba(20, 184, 166, 0.45);
        color: white;
        transform: translateY(-1px);
    }

    .course-btn-blue {
        background: rgba(27, 60, 83, 0.22);
        color: #c7d2fe;
        border-color: rgba(27, 60, 83, 0.3);
    }
    .course-btn-blue:hover {
        background: rgba(27, 60, 83, 0.45);
        color: white;
        transform: translateY(-1px);
    }

    .course-btn-orange {
        background: rgba(249, 115, 22, 0.22);
        color: #fed7aa;
        border-color: rgba(249, 115, 22, 0.3);
    }
    .course-btn-orange:hover {
        background: rgba(249, 115, 22, 0.45);
        color: white;
        transform: translateY(-1px);
    }

    .course-btn-white {
        background: rgba(255, 255, 255, 0.14);
        color: #e0e7ff;
        border-color: rgba(255, 255, 255, 0.2);
    }
    .course-btn-white:hover {
        background: rgba(255, 255, 255, 0.28);
        color: white;
        transform: translateY(-1px);
    }

    .course-no-topics {
        background: var(--neutral-bg);
        border: 2px dashed rgba(27, 60, 83, 0.22);
        border-radius: 16px;
        padding: 36px;
        text-align: center;
    }

    .history-card-wrap {
        background: #ffffff;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 20px rgba(27, 60, 83, .13);
        border-left: 4px solid #456882;
        border-top: 1px solid rgba(27, 60, 83, .08);
        border-right: 1px solid rgba(27, 60, 83, .08);
        border-bottom: 1px solid rgba(27, 60, 83, .08);
    }

    .history-card-header {
        background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
        padding: 18px 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        position: relative;
        overflow: hidden;
    }

    .history-card-header::before {
        content: '';
        position: absolute;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.06);
        top: -70px;
        right: -30px;
    }

    .history-icon-wrap {
        width: 40px;
        height: 40px;
        background: rgba(255, 255, 255, 0.18);
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(4px);
    }

    .history-table-v2 {
        width: 100%;
        border-collapse: collapse;
    }

    .history-table-v2 thead th {
        padding: 11px 20px;
        text-align: left;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        color: #ffffff;
        background: linear-gradient(90deg, #1B3C53, #234C6A, #456882);
        border-bottom: 2px solid rgba(27, 60, 83, 0.1);
    }

    .history-table-v2 tbody tr {
        border-bottom: 1px solid var(--neutral-bg);
        transition: all 0.2s;
    }

    .history-table-v2 tbody tr:last-child { border-bottom: none; }
    .history-table-v2 tbody tr:nth-child(even) { background: #f4ede7; }
    .history-table-v2 tbody tr:hover { background: #e8dfd8; transform: scale(1.005); }
    .history-table-v2 tbody td { padding: 14px 20px; }

    .history-thumb-img {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        object-fit: cover;
        border: 2px solid rgba(27, 60, 83, 0.15);
        margin-right: 12px;
        flex-shrink: 0;
    }

    .history-thumb-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: linear-gradient(135deg, var(--primary), var(--primary-light));
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1rem;
        margin-right: 12px;
        flex-shrink: 0;
    }

    .history-batch-name {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--primary-dark);
    }

    .history-date-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.82rem;
        color: #64748b;
        font-weight: 500;
    }

    .history-date-pill i { color: var(--primary); }

    .btn-primary, button.btn-primary, a.btn-primary {
        background: linear-gradient(135deg, #f59e0b, #C97B50);
        color: #ffffff !important;
        border: none;
        border-radius: 9999px;
        font-weight: 600;
        padding: 8px 20px;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        font-size: 0.85rem;
    }

    .btn-primary:hover {
        opacity: 0.9;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(245, 158, 11, .35);
    }

    .btn-success, button.btn-success, a.btn-success {
        background: linear-gradient(135deg, #456882, #234C6A);
        color: #ffffff !important;
        border: none;
        border-radius: 9999px;
        font-weight: 600;
        padding: 8px 20px;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        font-size: 0.85rem;
    }

    .btn-success:hover {
        opacity: 0.9;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(35, 76, 106, .35);
    }

    .btn-danger, button.btn-danger, a.btn-danger {
        background: linear-gradient(135deg, #ef4444, #C0392B);
        color: #ffffff !important;
        border: none;
        border-radius: 9999px;
        font-weight: 600;
        padding: 8px 20px;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        font-size: 0.85rem;
    }

    .btn-danger:hover {
        opacity: 0.9;
        transform: translateY(-1px);
        box-shadow: 0 6px 18px rgba(192, 57, 43, .35);
    }

    .btn-secondary, button.btn-secondary, a.btn-secondary {
        background: linear-gradient(135deg, #EAE4E0, #D2C1B6);
        color: #1B3C53 !important;
        border: none;
        border-radius: 9999px;
        font-weight: 600;
        padding: 8px 20px;
        cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        font-size: 0.85rem;
    }

    .btn-secondary:hover {
        opacity: 0.9;
        transform: translateY(-1px);
    }

    .alert-success {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        border-left: 4px solid #10b981;
        border-radius: 12px;
        padding: 14px 18px;
        color: #065f46;
        font-weight: 600;
    }

    .alert-error, .alert-danger {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        border-left: 4px solid #ef4444;
        border-radius: 12px;
        padding: 14px 18px;
        color: #991b1b;
        font-weight: 600;
    }

    .section-upload { border-left-color: #7C5CBF !important; }
    .section-create { border-left-color: #C97B50 !important; }
    .section-danger { border-left-color: #C0392B !important; }
    .section-default { border-left-color: #456882 !important; }

    .page-inner-wrap { background: transparent; }

    .stat-card {
        background: #ffffff;
        border-radius: 14px;
        padding: 18px 20px;
        box-shadow: 0 4px 20px rgba(27, 60, 83, .13);
        border-top: 3px solid #456882;
        border-left: 1px solid rgba(27, 60, 83, .08);
        border-right: 1px solid rgba(27, 60, 83, .08);
        border-bottom: 1px solid rgba(27, 60, 83, .08);
        position: relative;
        overflow: hidden;
        transition: transform 0.25s, box-shadow 0.25s;
    }

    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 28px rgba(27, 60, 83, .18);
    }

    .stat-card-blue { border-top-color: #234C6A; }
    .stat-card-green { border-top-color: #10b981; }
    .stat-card-red { border-top-color: #ef4444; }
    .stat-card-amber { border-top-color: #f59e0b; }

    .stat-icon-box {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        color: #ffffff;
        flex-shrink: 0;
    }

    .stat-icon-blue { background: linear-gradient(135deg, #234C6A, #456882); }
    .stat-icon-green { background: linear-gradient(135deg, #059669, #10b981); }
    .stat-icon-red { background: linear-gradient(135deg, #ef4444, #C0392B); }
    .stat-icon-amber { background: linear-gradient(135deg, #f59e0b, #C97B50); }

    .badge-present { background: #dcfce7; color: #166534; }
    .badge-absent { background: #fee2e2; color: #991b1b; }
    .badge-late, .badge-pending { background: #fef3c7; color: #92400e; }
    
    .active-course-indicator {
        background: rgba(245, 158, 11, 0.15);
        border: 1px solid rgba(245, 158, 11, 0.3);
        border-radius: 8px;
        padding: 4px 12px;
        font-size: 0.7rem;
        font-weight: 600;
        color: #f59e0b;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
</style>

<!-- [REST OF THE HTML - Same as before until the Topics section] -->

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Mobile Header -->
    <header style="background:linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6);"
        class="shadow-lg px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <button class="text-xl text-white/90 hover:text-white transition-colors" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-lg font-bold text-white flex items-center space-x-2">
            <div class="bg-white/20 p-2 rounded-lg backdrop-blur-sm">
                <i class="fas fa-layer-group text-white text-sm"></i>
            </div>
            <span>My Batches</span>
        </h1>
        <div class="flex items-center space-x-3">
            <button onclick="document.getElementById('verifModal').classList.remove('hidden')"
                class="relative w-9 h-9 bg-white/20 rounded-full flex items-center justify-center hover:bg-white/30 transition-colors backdrop-blur-sm">
                <i class="fas fa-bell text-white text-sm"></i>
                <?php if ($pending_verif_count > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-amber-400 text-white text-[10px] font-bold rounded-full flex items-center justify-center animate-pulse shadow-lg"><?= $pending_verif_count ?></span>
                <?php endif; ?>
            </button>
            <div class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm">
                <i class="fas fa-user-graduate text-white"></i>
            </div>
        </div>
    </header>

    <!-- Desktop Header -->
    <header style="background:linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6);"
        class="hidden md:flex shadow-xl px-6 py-5 justify-between items-center sticky top-0 z-30 overflow-hidden">
        <div style="position:absolute;width:280px;height:280px;border-radius:50%;background:rgba(255,255,255,0.06);top:-100px;right:200px;pointer-events:none;"></div>
        <div class="flex-1"></div>
        <h1 class="text-2xl font-bold text-white flex items-center space-x-3 relative z-10">
            <div class="bg-white/20 p-2.5 rounded-xl backdrop-blur-sm shadow-inner">
                <i class="fas fa-layer-group text-white text-xl"></i>
            </div>
            <span style="text-shadow:0 2px 8px rgba(0,0,0,0.2);">My Batches &amp; Progress</span>
        </h1>
        <div class="flex-1 flex justify-end items-center space-x-4 relative z-10">
            <button onclick="document.getElementById('verifModal').classList.remove('hidden')"
                class="relative w-10 h-10 bg-white/20 rounded-full flex items-center justify-center hover:bg-white/30 transition-all backdrop-blur-sm shadow">
                <i class="fas fa-bell text-white"></i>
                <?php if ($pending_verif_count > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-amber-400 text-white text-[10px] font-bold rounded-full flex items-center justify-center animate-pulse shadow-lg"><?= $pending_verif_count ?></span>
                <?php endif; ?>
            </button>
            <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center backdrop-blur-sm shadow">
                <i class="fas fa-user-graduate text-white"></i>
            </div>
        </div>
    </header>

    <!-- Notification Modal -->
    <div id="verifModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center hidden p-4" style="backdrop-filter:blur(4px);">
        <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl overflow-hidden transform transition-all">
            <div style="background:linear-gradient(90deg, #1B3C53, #234C6A, #456882, #D2C1B6);" class="px-6 py-5 flex justify-between items-center">
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <div class="bg-white/20 p-1.5 rounded-lg"><i class="fas fa-bell text-white text-sm"></i></div>
                    Topic Verifications
                    <?php if ($pending_verif_count > 0): ?>
                        <span class="bg-amber-400 text-white text-xs font-bold px-2.5 py-0.5 rounded-full"><?= $pending_verif_count ?></span>
                    <?php endif; ?>
                </h3>
                <button onclick="document.getElementById('verifModal').classList.add('hidden')"
                    class="w-8 h-8 bg-white/20 rounded-full flex items-center justify-center hover:bg-white/30 transition-colors text-white">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>
            <div class="p-6 space-y-3 max-h-[60vh] overflow-y-auto">
                <?php if (empty($pending_verifications)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-check-circle text-4xl text-emerald-400 mb-3 block"></i>
                        <p class="text-gray-500 font-medium">All caught up! No pending verifications.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_verifications as $v): ?>
                        <div class="p-4 rounded-2xl border" style="background:linear-gradient(135deg, #f5f3ff, #fdf2ff);border-color:rgba(27,60,83,0.18);">
                            <p class="text-sm font-bold text-gray-800 mb-0.5"><?= htmlspecialchars($v['topic_name']) ?></p>
                            <p class="text-xs font-medium mb-3" style="color:var(--primary);">
                                <i class="fas fa-layer-group mr-1 opacity-70"></i><?= htmlspecialchars($v['batch_name']) ?>
                                <span class="mx-1 opacity-40">|</span>
                                <i class="fas fa-book mr-1 opacity-70"></i><?= htmlspecialchars($v['course_name']) ?>
                            </p>
                            <form method="POST" class="flex gap-2">
                                <input type="hidden" name="verif_id" value="<?= $v['id'] ?>">
                                <button type="submit" name="verif_action" value="verified"
                                    class="flex-1 text-white py-2 text-xs font-bold transition-all hover:opacity-90 hover:shadow-lg"
                                    style="background:linear-gradient(135deg,#456882,#234C6A);border-radius:9999px;">
                                    <i class="fas fa-check mr-1"></i> Mark Verified
                                </button>
                                <button type="submit" name="verif_action" value="rejected"
                                    class="flex-1 text-white py-2 text-xs font-bold transition-all hover:opacity-90 hover:shadow-lg"
                                    style="background:linear-gradient(135deg,#ef4444,#C0392B);border-radius:9999px;">
                                    <i class="fas fa-times mr-1"></i> Not Covered
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Mobile Navigation Menu -->
    <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden">
        <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs bg-gradient-to-b from-neutral-bg to-white shadow-xl transform transition-transform duration-300 -translate-x-full">
            <div class="p-4 border-b border-neutral-light bg-gradient-to-r from-neutral-light to-white">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                    </div>
                    <button onclick="toggleSidebar()" class="text-gray-500 hover:text-primary text-xl">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="mt-4 flex items-center space-x-3">
                    <div class="w-10 h-10 bg-gradient-to-r from-primary to-primary-light rounded-full flex items-center justify-center text-white font-bold">
                        <?= strtoupper(substr($student['first_name'] ?? 'S', 0, 1)) ?>
                    </div>
                    <div>
                        <p class="font-medium text-gray-800"><?= htmlspecialchars($student['first_name'] ?? 'Student') ?> <?= htmlspecialchars($student['last_name'] ?? '') ?></p>
                        <p class="text-xs text-gray-600">Student</p>
                    </div>
                </div>
            </div>
            <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                <a href="../stu_dash/dashboard.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-tachometer-alt <?= $current_page == 'dashboard.php' ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Dashboard</span>
                </a>
                <a href="../stu_dash/my_batches.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_batches.php' ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-users <?= $current_page == 'my_batches.php' ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Batches</span>
                </a>
                <a href="../stu_dash/upcoming.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'upcoming.php' ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-calendar-alt <?= $current_page == 'upcoming.php' ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Upcoming Schedule</span>
                </a>
                <a href="../stu_dash/my_content.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_content.php' ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-book <?= $current_page == 'my_content.php' ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Content</span>
                </a>
                <a href="../student_test/student_dashboard.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= ($current_page == 'student_dashboard.php' && !isset($_GET['category'])) ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-vial <?= ($current_page == 'student_dashboard.php' && !isset($_GET['category'])) ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Test</span>
                </a>
                <a href="../stu_dash/my_performance.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_performance.php' ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-chart-line <?= $current_page == 'my_performance.php' ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Performance</span>
                </a>
                <a href="../stu_dash/student_feedback.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_feedback.php' ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-comment-dots <?= $current_page == 'student_feedback.php' ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Feedback</span>
                </a>
                <a href="../stu_dash/student_profile.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_profile.php' ? 'bg-white shadow-md text-primary' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-user-circle <?= $current_page == 'student_profile.php' ? 'text-primary' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Profile</span>
                </a>
                <a href="../logout.php" class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-50 hover:text-red-600 text-gray-700 mt-4 border-t pt-4" onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-sign-out-alt text-red-500"></i>
                    </div>
                    <span class="font-medium">Logout</span>
                </a>
            </nav>
        </div>
    </div>

    <div class="p-4 md:p-6 min-h-screen page-inner-wrap">

        <!-- Batch Selection Tabs -->
        <?php if (count($current_batches) > 1): ?>
            <div class="batch-tabs-wrap">
                <h3 class="text-lg font-bold mb-3 flex items-center" style="color:var(--primary-dark);">
                    <div class="mr-3 p-2 rounded-xl" style="background:linear-gradient(135deg, var(--primary), var(--primary-light));">
                        <i class="fas fa-exchange-alt text-white text-sm"></i>
                    </div>
                    Switch Batch
                    <?php if (count($current_batches) > 0): ?>
                        <span class="viewing-indicator">
                            <i class="fas fa-eye"></i>
                            Viewing: Batch <?= $selected_batch_index + 1 ?>
                        </span>
                    <?php endif; ?>
                </h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($current_batches as $index => $batch_info): ?>
                        <a href="?batch_index=<?= $index ?>" class="batch-tab px-5 py-2.5 transition-all duration-300 <?= $selected_batch_index == $index ? 'active' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-layer-group"></i>
                                <?php
                                $batch_label = "Batch ";
                                if ($batch_info['field_name'] == 'batch_name') $batch_label .= "1";
                                elseif ($batch_info['field_name'] == 'batch_name_2') $batch_label .= "2";
                                elseif ($batch_info['field_name'] == 'batch_name_3') $batch_label .= "3";
                                elseif ($batch_info['field_name'] == 'batch_name_4') $batch_label .= "4";
                                ?>
                                <span><?= $batch_label ?>: <?= htmlspecialchars($batch_info['batch_data']['batch_name']) ?></span>
                                <?php if ($selected_batch_index == $index): ?>
                                    <i class="fas fa-check-circle text-xs"></i>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- All Batches Display -->
        <?php if (count($current_batches) > 0): ?>
            <div class="batches-grid">
                <?php foreach ($current_batches as $index => $batch_info):
                    $batch = $batch_info['batch_data'];
                    $is_selected = ($selected_batch_index == $index);

                    $progress_stmt = $db->prepare("
                        SELECT 
                            COUNT(st.id) as total_sub_topics,
                            SUM(st.theory_completed) as theory_completed,
                            SUM(st.practical_completed) as practical_completed
                        FROM main_topics mt
                        LEFT JOIN sub_topics st ON mt.id = st.main_topic_id
                        WHERE mt.batch_name = ?
                        GROUP BY mt.id
                    ");
                    $progress_stmt->execute([$batch['batch_id']]);
                    $progress_data = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $total_sub_topics = 0;
                    $theory_completed = 0;
                    $practical_completed = 0;

                    foreach ($progress_data as $data) {
                        $total_sub_topics += $data['total_sub_topics'] ?? 0;
                        $theory_completed += $data['theory_completed'] ?? 0;
                        $practical_completed += $data['practical_completed'] ?? 0;
                    }

                    $overall_progress = $total_sub_topics > 0 ?
                        round((($theory_completed + $practical_completed) / ($total_sub_topics * 2)) * 100, 1) : 0;

                    $status_class = '';
                    switch ($batch['status']) {
                        case 'ongoing': $status_class = 'badge-ongoing'; break;
                        case 'upcoming': $status_class = 'badge-upcoming'; break;
                        case 'completed': $status_class = 'badge-completed'; break;
                        case 'cancelled': $status_class = 'badge-cancelled'; break;
                    }

                    $mode_class = $batch['mode'] === 'online' ? 'mode-online' : 'mode-offline';

                    $batch_label = "Batch ";
                    if ($batch_info['field_name'] == 'batch_name') $batch_label .= "1";
                    elseif ($batch_info['field_name'] == 'batch_name_2') $batch_label .= "2";
                    elseif ($batch_info['field_name'] == 'batch_name_3') $batch_label .= "3";
                    elseif ($batch_info['field_name'] == 'batch_name_4') $batch_label .= "4";
                    ?>
                    <div class="batch-card <?= $is_selected ? 'selected' : '' ?>" onclick="window.location.href='?batch_index=<?= $index ?>'">
                        <div class="batch-card-rainbow-bar"></div>
                        <div class="selected-badge">
                            <i class="fas fa-check-circle"></i>
                            Currently Viewing
                        </div>

                        <div class="batch-thumbnail-container">
                            <?php
                            $batch_thumb = !empty($batch['thumbnail_path']) ? '../' . $batch['thumbnail_path'] : '../uploads/batch_thumbnails/default_batch.svg';
                            ?>
                            <img src="<?= htmlspecialchars($batch_thumb) ?>" alt="<?= htmlspecialchars($batch['batch_name']) ?>" class="batch-thumbnail">
                            <div class="thumbnail-overlay">
                                <span class="text-white font-semibold"><?= htmlspecialchars($batch['batch_name']) ?></span>
                            </div>

                            <div class="batch-badge <?= $status_class ?>">
                                <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
                                <?= ucfirst($batch['status']) ?>
                            </div>
                        </div>

                        <div class="batch-content">
                            <div class="batch-header">
                                <div>
                                    <h3 class="batch-title"><?= htmlspecialchars($batch['batch_name']) ?></h3>
                                    <div class="batch-id">
                                        <i class="fas fa-hashtag"></i>
                                        <?= htmlspecialchars($batch['batch_id']) ?> • <?= $batch_label ?>
                                    </div>
                                </div>
                                <span class="mode-badge <?= $mode_class ?>">
                                    <i class="fas fa-<?= $batch['mode'] === 'online' ? 'wifi' : 'building' ?>"></i>
                                    <?= ucfirst($batch['mode']) ?>
                                </span>
                            </div>

                            <div class="batch-meta">
                                <div class="meta-item">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span><?= date('M d', strtotime($batch['start_date'])) ?> - <?= date('M d, Y', strtotime($batch['end_date'])) ?></span>
                                </div>
                                <?php if (!empty($batch['time_slot'])): ?>
                                    <div class="meta-item">
                                        <i class="fas fa-clock"></i>
                                        <span><?= htmlspecialchars($batch['time_slot']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($batch['assigned_courses'])): ?>
                                <div class="mb-3">
                                    <div class="text-xs font-bold mb-2 uppercase tracking-wider" style="color:var(--primary);">
                                        <i class="fas fa-graduation-cap mr-1"></i>Courses
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <?php foreach ($batch['assigned_courses'] as $course): ?>
                                            <span class="course-tag-card">
                                                <i class="fas fa-book"></i> <?= htmlspecialchars($course['name']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($is_selected): ?>
                                <div class="mt-2 text-center text-xs font-bold flex items-center justify-center gap-1.5 py-2 rounded-xl"
                                    style="background:linear-gradient(135deg,rgba(27,60,83,0.1),rgba(69,104,130,0.1));color:var(--primary);border:1px solid rgba(27,60,83,0.18);">
                                    <i class="fas fa-eye"></i>
                                    <span>Currently Viewing Details Below</span>
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state animate__animated animate__fadeIn">
                <i class="fas fa-layer-group"></i>
                <h3 class="text-xl font-semibold text-gray-700 mb-2">No Batches Assigned</h3>
                <p class="text-gray-500 mb-6">You haven't been assigned to any batches yet.</p>
                <p class="text-sm text-gray-400">Please contact the administration for assistance.</p>
            </div>
        <?php endif; ?>

        <!-- Selected Batch Details -->
        <?php if ($selected_batch && count($current_batches) > 0): ?>

            <?php
            $assigned_courses = $selected_batch['assigned_courses'];
            $total_courses = count($assigned_courses);
            $completed_courses = 0;
            foreach ($assigned_courses as $c) {
                if ($c['status'] === 'completed') {
                    $completed_courses++;
                }
            }
            ?>

            <div class="mt-8">
                <!-- Hero Banner -->
                <div class="detail-hero mb-6">
                    <div class="relative z-10">
                        <div class="flex flex-wrap items-center gap-3 mb-2">
                            <div class="bg-white/20 p-2.5 rounded-xl backdrop-blur-sm">
                                <i class="fas fa-info-circle text-white text-lg"></i>
                            </div>
                            <div>
                                <p class="text-white/70 text-xs font-semibold uppercase tracking-widest mb-0.5">Batch Details</p>
                                <h2 class="text-2xl font-bold text-white" style="text-shadow:0 2px 8px rgba(0,0,0,0.2);">
                                    <?= htmlspecialchars($selected_batch['batch_name']) ?>
                                </h2>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3 mt-4">
                            <span class="bg-white/20 backdrop-blur-sm text-white text-xs font-semibold px-3 py-1.5 rounded-full flex items-center gap-1.5">
                                <i class="fas fa-calendar-alt"></i>
                                <?= date('M d, Y', strtotime($selected_batch['start_date'])) ?> – <?= date('M d, Y', strtotime($selected_batch['end_date'])) ?>
                            </span>
                            <?php if (!empty($selected_batch['time_slot'])): ?>
                                <span class="bg-white/20 backdrop-blur-sm text-white text-xs font-semibold px-3 py-1.5 rounded-full flex items-center gap-1.5">
                                    <i class="fas fa-clock"></i>
                                    <?= htmlspecialchars($selected_batch['time_slot']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="bg-white/20 backdrop-blur-sm text-white text-xs font-semibold px-3 py-1.5 rounded-full flex items-center gap-1.5">
                                <i class="fas fa-<?= ($selected_batch['mode'] ?? 'online') === 'online' ? 'wifi' : 'building' ?>"></i>
                                <?= ucfirst($selected_batch['mode'] ?? 'Online') ?>
                            </span>
                        </div>

                        <?php if (!empty($assigned_courses)): ?>
                            <!-- Course Progress Timeline with Animations -->
                            <div class="mt-5 pt-4" style="border-top:1px solid rgba(255,255,255,0.15);">
                                <div class="flex items-center justify-between mb-4">
                                    <span class="text-white/80 text-sm font-bold uppercase tracking-widest flex items-center gap-2">
                                        <i class="fas fa-graduation-cap"></i> Course Progress
                                    </span>
                                    <span class="text-white text-sm font-bold bg-white/20 px-3 py-1 rounded-full">
                                        <?= $completed_courses ?> / <?= $total_courses ?> Completed
                                        <?php if ($in_progress_course_name): ?>
                                            <span class="ml-2 active-course-indicator">
                                                <i class="fas fa-arrow-right"></i> <?= htmlspecialchars($in_progress_course_name) ?>
                                            </span>
                                        <?php endif; ?>
                                    </span>
                                </div>

                                <!-- Timeline with Animated Progress Bar -->
                                <div class="course-timeline-wrapper">
                                    <div class="course-timeline-track">
                                        <div class="timeline-progress-fill"></div>
                                    </div>
                                    <div class="course-checkpoints-container">
                                        <?php foreach ($course_checkpoints as $index => $checkpoint): 
                                            $status_class = $checkpoint['is_completed'] ? 'completed' : ($checkpoint['is_in_progress'] ? 'in-progress' : 'pending');
                                            $icon = $checkpoint['is_completed'] ? 'fa-check' : ($checkpoint['is_in_progress'] ? 'fa-spinner fa-spin' : 'fa-clock');
                                            $label = $checkpoint['label'];
                                        ?>
                                            <a href="?batch_index=<?= $selected_batch_index ?>&course_id=<?= $checkpoint['id'] ?>" 
                                               class="course-checkpoint <?= $status_class ?>" 
                                               title="<?= htmlspecialchars($checkpoint['name']) ?> - <?= $label ?>"
                                               style="text-decoration:none;">
                                                <div class="checkpoint-dot">
                                                    <i class="fas <?= $icon ?> checkpoint-icon"></i>
                                                </div>
                                                <span class="checkpoint-label"><?= htmlspecialchars($checkpoint['name']) ?></span>
                                                <span class="checkpoint-status"><?= $label ?></span>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <!-- Trainer Details Card -->
                        <div class="info-card info-card-blue">
                            <div class="flex items-center mb-4">
                                <div class="p-3 rounded-xl mr-3 shadow-sm" style="background:linear-gradient(135deg, var(--primary), var(--primary-light));">
                                    <i class="fas fa-chalkboard-teacher text-white text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-gray-800 text-base">Trainer Details</h3>
                                    <p class="text-xs font-medium" style="color:var(--primary);">Your Mentor</p>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-center gap-2 px-3 py-2 rounded-xl" style="background:rgba(244,237,231,.7);">
                                    <i class="fas fa-user text-primary w-4 text-center"></i>
                                    <div>
                                        <p class="text-xs text-gray-400 leading-none">Name</p>
                                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($selected_batch['trainer_name'] ?? 'Not Assigned') ?></p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 px-3 py-2 rounded-xl" style="background:rgba(244,237,231,.7);">
                                    <i class="fas fa-envelope text-primary w-4 text-center"></i>
                                    <div>
                                        <p class="text-xs text-gray-400 leading-none">Email</p>
                                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($selected_batch['trainer_email'] ?? 'N/A') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Schedule Information Card -->
                        <div class="info-card info-card-purple">
                            <div class="flex items-center mb-4">
                                <div class="p-3 rounded-xl mr-3 shadow-sm" style="background:linear-gradient(135deg, var(--primary-light), var(--neutral));">
                                    <i class="fas fa-calendar-alt text-white text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="font-bold text-gray-800 text-base">Schedule Information</h3>
                                    <p class="text-xs font-medium" style="color:var(--primary-light);">Timing &amp; Duration</p>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex items-center gap-2 px-3 py-2 rounded-xl" style="background:rgba(244,237,231,.7);">
                                    <i class="fas fa-calendar-check text-primary-light w-4 text-center"></i>
                                    <div>
                                        <p class="text-xs text-gray-400 leading-none">Duration</p>
                                        <p class="text-sm font-semibold text-gray-800">
                                            <?= date('M d, Y', strtotime($selected_batch['start_date'])) ?> – <?= date('M d, Y', strtotime($selected_batch['end_date'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 px-3 py-2 rounded-xl" style="background:rgba(244,237,231,.7);">
                                    <i class="fas fa-clock text-primary-light w-4 text-center"></i>
                                    <div>
                                        <p class="text-xs text-gray-400 leading-none">Time Slot</p>
                                        <p class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($selected_batch['time_slot'] ?? 'Not Specified') ?></p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 px-3 py-2 rounded-xl" style="background:rgba(244,237,231,.7);">
                                    <i class="fas fa-<?= ($selected_batch['mode'] ?? 'online') === 'online' ? 'wifi' : 'building' ?> text-primary-light w-4 text-center"></i>
                                    <div>
                                        <p class="text-xs text-gray-400 leading-none">Mode</p>
                                        <p class="text-sm font-semibold text-gray-800"><?= ucfirst($selected_batch['mode'] ?? 'Online') ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Course Filter / Selection -->
                    <div class="mt-6 mb-4">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                            <h4 class="text-sm font-bold flex items-center gap-2" style="color:var(--primary-dark);">
                                <i class="fas fa-filter" style="color:var(--primary-light);"></i>
                                Select Course to View Topics
                            </h4>
                            <?php if ($selected_course_id && isset($course_names_map[$selected_course_id])): ?>
                                <span class="text-xs font-medium" style="color:var(--primary-light);">
                                    <i class="fas fa-eye mr-1"></i> Currently viewing: 
                                    <strong style="color:var(--primary-dark);"><?= htmlspecialchars($course_names_map[$selected_course_id]) ?></strong>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="course-filter-wrap">
                            <?php foreach ($course_checkpoints as $checkpoint): 
                                $is_active = ($selected_course_id == $checkpoint['id']);
                                $label = $checkpoint['label'];
                            ?>
                                <a href="?batch_index=<?= $selected_batch_index ?>&course_id=<?= $checkpoint['id'] ?>" 
                                   class="course-filter-btn <?= $is_active ? 'active' : '' ?>">
                                    <?= htmlspecialchars($checkpoint['name']) ?>
                                    <span class="status-badge" style="
                                        <?php if ($checkpoint['is_completed']): ?>background:#10b981;color:white;<?php elseif ($checkpoint['is_in_progress']): ?>background:#f59e0b;color:white;<?php else: ?>background:#9ca3af;color:white;<?php endif; ?>
                                    ">
                                        <?= $label ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Topics for Selected Course -->
                    <div class="mt-4 border-t pt-4">
                        <h3 class="text-lg font-bold mb-4 flex items-center" style="color:#234C6A;">
                            <i class="fas fa-list-ol mr-2" style="color:#456882;"></i>
                            Topics
                            <?php if ($selected_course_id && isset($course_names_map[$selected_course_id])): ?>
                                <span class="ml-2 text-sm font-medium" style="color:var(--primary-light);">
                                    for <span style="color:var(--primary-dark);"><?= htmlspecialchars($course_names_map[$selected_course_id]) ?></span>
                                </span>
                            <?php endif; ?>
                            <?php if ($selected_batch_index !== null): ?>
                                <span class="ml-2 text-xs font-medium text-gray-400">
                                    (Batch <?= $selected_batch_index + 1 ?>)
                                </span>
                            <?php endif; ?>
                        </h3>

                        <div class="mt-4">
                            <?php if (empty($filtered_topics)): ?>
                                <!-- "Not Updated Yet" Message -->
                                <div class="not-updated-yet">
                                    <div class="icon-wrapper">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                    <h4>Not Updated Yet</h4>
                                    <p>Topics for this course haven't been added by the trainer yet.</p>
                                    <p class="sub-text">
                                        <i class="fas fa-info-circle mr-1"></i>
                                        Please check back later for updates.
                                    </p>
                                    <?php if ($in_progress_course_id && $selected_course_id != $in_progress_course_id): ?>
                                        <p class="sub-text mt-2" style="color:var(--primary-light);">
                                            <i class="fas fa-arrow-right mr-1"></i>
                                            Try viewing the <strong><?= htmlspecialchars($in_progress_course_name) ?></strong> course which is currently in progress.
                                        </p>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                                    <?php foreach ($filtered_topics as $topic): ?>
                                        <?php
                                        $topic_total_subtopics = count($topic['sub_topics']);
                                        $topic_completed = 0;
                                        $topic_theory = 0;
                                        $topic_practical = 0;

                                        foreach ($topic['sub_topics'] as $sub_topic) {
                                            if ($sub_topic['theory_completed']) $topic_theory++;
                                            if ($sub_topic['practical_completed']) $topic_practical++;
                                            if ($sub_topic['theory_completed'] || $sub_topic['practical_completed']) {
                                                $topic_completed++;
                                            }
                                        }

                                        $topic_progress = $topic_total_subtopics > 0 ? round(($topic_completed / $topic_total_subtopics) * 100, 0) : 0;
                                        $theory_display = $topic_theory . '/' . $topic_total_subtopics;
                                        $practical_display = $topic_practical . '/' . $topic_total_subtopics;
                                        $combined_progress = $topic_total_subtopics > 0
                                            ? round((($topic_theory + $topic_practical) / ($topic_total_subtopics * 2)) * 100)
                                            : 0;
                                        $is_fully_covered = $topic['covered_by_trainer'];
                                        ?>
                                        <div class="flex flex-col" style="
                                            background: #ffffff;
                                            border-radius: 14px;
                                            border: 1px solid rgba(69,104,130,.18);
                                            box-shadow: 0 2px 12px rgba(27,60,83,.08);
                                            overflow: hidden;
                                            transition: box-shadow 0.22s, transform 0.22s;
                                        " onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(27,60,83,.14)';" 
                                           onmouseleave="this.style.transform='';this.style.boxShadow='0 2px 12px rgba(27,60,83,.08)'; ">

                                            <div class="flex items-center justify-between px-4 py-3" style="background:linear-gradient(90deg,#1B3C53,#2d5873);border-radius:14px 14px 0 0;">
                                                <span class="text-xs font-black uppercase tracking-widest text-white" style="letter-spacing:0.08em;">CHAPTER <?= $topic['chapter'] ?></span>
                                                <span class="text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full"
                                                      style="background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.3);display:inline-flex;align-items:center;gap:5px;">
                                                    <i class="fas fa-link" style="font-size:0.6rem;"></i>
                                                    <?= $topic['topic_type'] === 'both' || $topic['topic_type'] === '' ? 'BOTH' : strtoupper($topic['topic_type']) ?>
                                                </span>
                                            </div>

                                            <div class="px-4 pt-3 pb-2">
                                                <h5 class="text-sm font-bold" style="color:#1B3C53;">
                                                    <?= htmlspecialchars($topic['topic_name']) ?>
                                                </h5>
                                            </div>

                                            <div class="px-4 pb-3 flex gap-3">
                                                <div class="flex-1 rounded-xl py-3 text-center" style="background:linear-gradient(135deg,#dbeafe,#bfdbfe);border:1px solid rgba(59,130,246,.15);">
                                                    <div class="text-base mb-0.5" style="color:#1e40af;">&#128214;</div>
                                                    <div class="text-base font-black" style="color:#1e40af;line-height:1.2;"><?= $theory_display ?></div>
                                                    <div class="text-[10px] font-bold uppercase tracking-widest mt-0.5" style="color:#3b82f6;">THEORY</div>
                                                </div>
                                                <div class="flex-1 rounded-xl py-3 text-center" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border:1px solid rgba(245,158,11,.2);">
                                                    <div class="text-base mb-0.5" style="color:#92400e;">&#9879;</div>
                                                    <div class="text-base font-black" style="color:#92400e;line-height:1.2;"><?= $practical_display ?></div>
                                                    <div class="text-[10px] font-bold uppercase tracking-widest mt-0.5" style="color:#d97706;">PRACTICAL</div>
                                                </div>
                                            </div>

                                            <div class="px-4 pb-2">
                                                <div class="flex justify-end mb-1">
                                                    <span class="text-xs font-bold" style="color:#1B3C53;"><?= $combined_progress ?>%</span>
                                                </div>
                                                <div style="background:#e5e7eb;border-radius:999px;height:7px;overflow:hidden;">
                                                    <div style="
                                                        width:<?= $combined_progress ?>%;
                                                        height:100%;
                                                        background:<?= $combined_progress >= 100 ? 'linear-gradient(90deg,#1B3C53,#234C6A)' : 'linear-gradient(90deg,#94a3b8,#cbd5e1)' ?>;
                                                        border-radius:999px;
                                                        transition:width 0.6s ease;
                                                    "></div>
                                                </div>
                                            </div>

                                            <div class="px-4 pb-3 flex items-center justify-between">
                                                <?php if ($is_fully_covered): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold"
                                                          style="background:#dcfce7;color:#166534;border:1px solid rgba(22,163,74,.2);">
                                                        <i class="fas fa-check-circle"></i> Covered
                                                    </span>
                                                    <?php if ($topic['covered_date']): ?>
                                                        <span class="text-xs" style="color:#6b7280;">
                                                            <i class="far fa-calendar mr-1"></i><?= date('M d, Y', strtotime($topic['covered_date'])) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php elseif ($topic_theory > 0 || $topic_practical > 0): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold"
                                                          style="background:#fef3c7;color:#92400e;border:1px solid rgba(245,158,11,.25);">
                                                        <i class="fas fa-spinner"></i> In Progress
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold"
                                                          style="background:#f3f4f6;color:#6b7280;border:1px solid rgba(107,114,128,.2);">
                                                        <i class="fas fa-times-circle"></i> Not Covered
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <div style="border-top:1px solid rgba(69,104,130,.12);">
                                                <button onclick="toggleSubtopics('grid-subtopics-<?= $topic['id'] ?>', event)"
                                                    class="w-full px-4 py-2.5 text-xs font-semibold flex items-center justify-between"
                                                    style="color:#234C6A;background:transparent;border:none;cursor:pointer;"
                                                    onmouseenter="this.style.background='rgba(69,104,130,.07)'"
                                                    onmouseleave="this.style.background='transparent'">
                                                    <span class="flex items-center gap-2">
                                                        <i class="fas fa-list-ul" style="color:#456882;"></i>
                                                        Sub-Topics (<?= $topic_total_subtopics ?>)
                                                    </span>
                                                    <i class="fas fa-chevron-down transition-transform duration-200"
                                                       id="icon-grid-subtopics-<?= $topic['id'] ?>"
                                                       style="color:#456882;font-size:0.7rem;"></i>
                                                </button>
                                            </div>

                                            <div id="grid-subtopics-<?= $topic['id'] ?>"
                                                class="hidden p-4 max-h-[250px] overflow-y-auto"
                                                style="background:#f8fafc;border-top:1px solid rgba(69,104,130,.12);">
                                                <div class="space-y-2">
                                                    <?php if (empty($topic['sub_topics'])): ?>
                                                        <div class="text-xs italic text-center py-2" style="color:#456882;">No sub-topics available</div>
                                                    <?php else: ?>
                                                        <?php foreach ($topic['sub_topics'] as $sub_topic): ?>
                                                            <div class="p-2.5 rounded-lg flex justify-between items-center"
                                                                style="background:#ffffff;border:1px solid rgba(69,104,130,.15);"
                                                                onmouseenter="this.style.background='#f0f5f9';"
                                                                onmouseleave="this.style.background='#ffffff';">
                                                                <div class="text-xs font-semibold" style="color:#1B3C53;">
                                                                    <?= htmlspecialchars($sub_topic['name']) ?>
                                                                </div>
                                                                <div class="flex gap-1.5">
                                                                    <?php if ($sub_topic['theory_completed']): ?>
                                                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded uppercase"
                                                                            style="background:#dcfce7;color:#166534;"
                                                                            title="Theory Completed"><i class="fas fa-check mr-0.5"></i>Theory</span>
                                                                    <?php endif; ?>
                                                                    <?php if ($sub_topic['practical_completed']): ?>
                                                                        <span class="text-[9px] font-bold px-1.5 py-0.5 rounded uppercase"
                                                                            style="background:#fef3c7;color:#92400e;"
                                                                            title="Practical Completed"><i class="fas fa-check mr-0.5"></i>Practical</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!$sub_topic['theory_completed'] && !$sub_topic['practical_completed']): ?>
                                                                        <span class="text-[9px] font-medium px-1.5 py-0.5 rounded"
                                                                            style="background:#f3f4f6;color:#9ca3af;">Pending</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Batch History Section -->
            <?php if (!empty($batch_history)): ?>
                <div class="mt-8">
                    <div class="history-card-wrap">
                        <div class="history-card-header">
                            <div class="history-icon-wrap">
                                <i class="fas fa-history text-white"></i>
                            </div>
                            <span class="text-white font-bold text-base uppercase tracking-wider">Batch History</span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="history-table-v2">
                                <thead>
                                    <tr>
                                        <th>Batch</th>
                                        <th>Transferred From</th>
                                        <th>Reason</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($batch_history as $history): ?>
                                        <tr>
                                            <td>
                                                <div class="flex items-center">
                                                    <?php if (!empty($history['thumbnail_path'])): ?>
                                                        <img src="../<?= htmlspecialchars($history['thumbnail_path']) ?>" alt="Batch" class="history-thumb-img">
                                                    <?php else: ?>
                                                        <div class="history-thumb-placeholder">
                                                            <i class="fas fa-layer-group"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span class="history-batch-name"><?= htmlspecialchars($history['batch_name']) ?></span>
                                                </div>
                                            </td>
                                            <td><span class="history-batch-name"><?= htmlspecialchars($history['from_batch_id']) ?></span></td>
                                            <td><span class="text-sm text-gray-600"><?= htmlspecialchars($history['transfer_reason'] ?? 'N/A') ?></span></td>
                                            <td>
                                                <span class="history-date-pill">
                                                    <i class="fas fa-calendar-alt"></i>
                                                    <?= date('M d, Y', strtotime($history['transfer_date'])) ?>
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
</div>

<script>
    function toggleSidebar() {
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

    document.getElementById('mobileMenu').addEventListener('click', function(e) {
        if (e.target.id === 'mobileMenu') {
            toggleSidebar();
        }
    });

    function toggleSubtopics(elementId, event) {
        event.stopPropagation();
        const element = document.getElementById(elementId);
        const button = event.currentTarget;
        const iconId = 'icon-' + elementId;

        if (element.classList.contains('hidden')) {
            element.classList.remove('hidden');
            const iconElem = document.getElementById(iconId);
            if (iconElem) {
                iconElem.classList.remove('fa-chevron-down');
                iconElem.classList.add('fa-chevron-up');
            }
            element.style.opacity = '0';
            element.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                element.style.transition = 'all 0.3s ease-in-out';
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
            }, 10);
        } else {
            element.style.opacity = '0';
            element.style.transform = 'translateY(-10px)';
            setTimeout(() => {
                element.classList.add('hidden');
                const iconElem = document.getElementById(iconId);
                if (iconElem) {
                    iconElem.classList.remove('fa-chevron-up');
                    iconElem.classList.add('fa-chevron-down');
                }
            }, 300);
        }
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const mobileMenu = document.getElementById('mobileMenu');
            if (!mobileMenu.classList.contains('hidden')) {
                toggleSidebar();
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        // Animate progress bars
        const progressBars = document.querySelectorAll('.progress-bar-fill');
        progressBars.forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => {
                bar.style.width = width;
            }, 300);
        });

        // Animate timeline progress fill
        const timelineFill = document.querySelector('.timeline-progress-fill');
        if (timelineFill) {
            const width = timelineFill.style.width;
            timelineFill.style.width = '0%';
            setTimeout(() => {
                timelineFill.style.width = width;
            }, 400);
        }

        const selectedCard = document.querySelector('.batch-card.selected');
        if (selectedCard) {
            setTimeout(() => {
                selectedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 500);
        }
    });
</script>

<?php include '../footer.php'; ?>