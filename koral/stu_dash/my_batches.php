<?php
require_once '../db_connection.php';
session_start();
if (!isset($_SESSION['user_id'])|| $_SESSION['user_role'] !== 'student') {
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

// Get current batches (batch_name, batch_name_2, batch_name_3, batch_name_4)
$current_batches = [];
$batch_names = ['batch_name', 'batch_name_2', 'batch_name_3', 'batch_name_4'];

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
                'batch_name' => $student[$batch_field], // batch_id
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

// --- Initial Progress Calculation (Set defaults to avoid errors) ---
$batch_progress = [
    'total_topics' => 0, 'covered_topics' => 0, 
    'total_sub_topics' => 0, 'completed_sub_topics' => 0, 
    'theory_completed_total' => 0, 'practical_completed_total' => 0,
    'topic_progress' => 0, 'sub_topic_progress' => 0,
    'theory_progress' => 0, 'practical_progress' => 0,
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
    
    // Calculate overall progress (average of theory and practical)
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
        ':student_id' => $student['student_id'],
        ':batch_id' => $selected_batch_id
    ]);
    $exam_data = $exam_query->fetch(PDO::FETCH_ASSOC);

    $exam_percentage = $exam_data && $exam_data['total_exams'] > 0 ? 
        round($exam_data['avg_percentage'], 2) : 0;
    $pass_rate = $exam_data && $exam_data['total_exams'] > 0 ? 
        round(($exam_data['passed_exams'] / $exam_data['total_exams']) * 100, 2) : 0;
}
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<!-- Add Font Awesome and Animation Libraries -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

<style>
    :root {
        --primary: #4361ee;
        --primary-light: #4895ef;
        --secondary: #7209b7;
        --success: #4cc9f0;
        --info: #3a86ff;
        --warning: #ff9e00;
        --danger: #f72585;
        --light: #f8f9fa;
        --dark: #212529;
        --gray: #6c757d;
        --light-gray: #e9ecef;
        --glass-bg: rgba(255, 255, 255, 0.95);
        --glass-border: rgba(255, 255, 255, 0.2);
        --shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
        --shadow-light: 0 5px 20px rgba(0, 0, 0, 0.05);
        --gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --gradient-success: linear-gradient(135deg, #4cc9f0 0%, #3a86ff 100%);
        --gradient-warning: linear-gradient(135deg, #ff9e00 0%, #ff5400 100%);
        --gradient-danger: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
        --selected-glow: 0 0 0 4px rgba(67, 97, 238, 0.3), 0 0 0 8px rgba(67, 97, 238, 0.1), 0 20px 40px rgba(0, 0, 0, 0.2);
    }

    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #e4edf5 100%);
        font-family: 'Inter', sans-serif;
    }

    /* Batch Cards Grid */
    .batches-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 25px;
        margin: 20px 0;
    }

    /* Batch Card Styles */
    .batch-card {
        background: var(--glass-bg);
        backdrop-filter: blur(10px);
        border: 1px solid var(--glass-border);
        border-radius: 24px;
        overflow: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: var(--shadow);
        position: relative;
        cursor: pointer;
        animation: fadeInUp 0.6s ease-out forwards;
        opacity: 0;
    }

    /* Selected Batch Card - HIGHLIGHT STYLES */
    .batch-card.selected {
        border: 2px solid #4361ee;
        box-shadow: var(--selected-glow);
        transform: scale(1.02);
        position: relative;
        z-index: 2;
    }

    .batch-card.selected::before {
        content: '';
        position: absolute;
        top: -2px;
        left: -2px;
        right: -2px;
        bottom: -2px;
        background: linear-gradient(135deg, #4361ee, #764ba2, #4361ee);
        border-radius: 26px;
        z-index: -1;
        opacity: 0.5;
        filter: blur(8px);
        animation: pulseGlow 2s ease-in-out infinite;
    }

    @keyframes pulseGlow {
        0%, 100% { opacity: 0.3; transform: scale(1); }
        50% { opacity: 0.7; transform: scale(1.02); }
    }

    .batch-card.selected .selected-badge {
        display: flex;
    }

    .selected-badge {
        position: absolute;
        top: 15px;
        left: 15px;
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        color: white;
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.5px;
        z-index: 10;
        box-shadow: 0 4px 12px rgba(67, 97, 238, 0.4);
        align-items: center;
        gap: 6px;
        display: none;
        backdrop-filter: blur(4px);
        animation: slideInLeft 0.4s ease-out;
    }

    @keyframes slideInLeft {
        from {
            opacity: 0;
            transform: translateX(-20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .batch-card.selected .selected-badge i {
        font-size: 0.7rem;
    }

    .batch-card.selected .batch-thumbnail-container::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(135deg, rgba(67, 97, 238, 0.2), rgba(118, 75, 162, 0.2));
        pointer-events: none;
        z-index: 1;
    }

    .batch-card:nth-child(1) { animation-delay: 0.1s; }
    .batch-card:nth-child(2) { animation-delay: 0.2s; }
    .batch-card:nth-child(3) { animation-delay: 0.3s; }
    .batch-card:nth-child(4) { animation-delay: 0.4s; }

    .batch-card:hover {
        transform: translateY(-8px) scale(1.02);
        box-shadow: 0 30px 60px rgba(0, 0, 0, 0.12);
    }

    .batch-card.selected:hover {
        transform: translateY(-8px) scale(1.03);
    }

    .batch-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: var(--gradient-primary);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform 0.4s ease;
    }

    .batch-card:hover::before {
        transform: scaleX(1);
    }

    .batch-card.selected::before {
        transform: scaleX(1);
    }

    /* Thumbnail Styles */
    .batch-thumbnail-container {
        position: relative;
        height: 200px;
        overflow: hidden;
    }

    .batch-thumbnail {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .batch-card:hover .batch-thumbnail {
        transform: scale(1.1);
    }

    .thumbnail-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.1), rgba(0,0,0,0.7));
        display: flex;
        align-items: flex-end;
        padding: 20px;
        opacity: 0;
        transition: opacity 0.4s ease;
    }

    .batch-card:hover .thumbnail-overlay {
        opacity: 1;
    }

    .thumbnail-placeholder {
        height: 200px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 4rem;
        position: relative;
        overflow: hidden;
    }

    .placeholder-pattern {
        position: absolute;
        top: -50%;
        left: -50%;
        right: -50%;
        bottom: -50%;
        background: repeating-linear-gradient(
            45deg,
            transparent,
            transparent 20px,
            rgba(255, 255, 255, 0.1) 20px,
            rgba(255, 255, 255, 0.1) 40px
        );
        animation: patternMove 20s linear infinite;
    }

    @keyframes patternMove {
        0% { transform: translate(0, 0) rotate(0deg); }
        100% { transform: translate(50px, 50px) rotate(45deg); }
    }

    .batch-badge {
        position: absolute;
        top: 15px;
        right: 15px;
        padding: 8px 16px;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        z-index: 2;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        backdrop-filter: blur(5px);
    }

    .badge-ongoing { background: linear-gradient(135deg, #4cc9f0, #3a86ff); color: white; }
    .badge-upcoming { background: linear-gradient(135deg, #ff9e00, #ff5400); color: white; }
    .badge-completed { background: linear-gradient(135deg, #6c757d, #495057); color: white; }
    .badge-cancelled { background: linear-gradient(135deg, #f72585, #b5179e); color: white; }

    /* Card Content */
    .batch-content {
        padding: 20px;
    }

    .batch-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }

    .batch-title {
        font-family: 'Poppins', sans-serif;
        font-weight: 600;
        color: var(--dark);
        font-size: 1.2rem;
        margin-bottom: 5px;
    }

    .batch-id {
        font-size: 0.8rem;
        color: var(--primary);
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .batch-id i {
        font-size: 0.7rem;
    }

    .batch-meta {
        display: flex;
        gap: 20px;
        margin-bottom: 15px;
        color: var(--gray);
        font-size: 0.9rem;
    }

    .meta-item {
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .meta-item i {
        color: var(--primary);
        width: 18px;
    }

    /* Progress Section */
    .progress-section {
        background: rgba(67, 97, 238, 0.03);
        border-radius: 16px;
        padding: 15px;
        margin: 15px 0;
    }

    .progress-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
    }

    .progress-title {
        font-weight: 600;
        color: var(--dark);
        font-size: 0.9rem;
    }

    .progress-percentage {
        font-weight: 700;
        color: var(--primary);
        font-size: 1.1rem;
    }

    .progress-bar-container {
        height: 8px;
        background: rgba(67, 97, 238, 0.1);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 10px;
    }

    .progress-bar-fill {
        height: 100%;
        background: var(--gradient-primary);
        border-radius: 4px;
        transition: width 1s cubic-bezier(0.4, 0, 0.2, 1);
        width: 0%;
    }

    .batch-card:hover .progress-bar-fill {
        width: <?= $batch_progress['overall_progress'] ?>%;
    }

    .progress-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 10px;
    }

    .stat {
        text-align: center;
        padding: 8px;
        background: white;
        border-radius: 12px;
        box-shadow: var(--shadow-light);
    }

    .stat-value {
        font-weight: 700;
        color: var(--primary);
        font-size: 1rem;
    }

    .stat-label {
        font-size: 0.7rem;
        color: var(--gray);
        margin-top: 2px;
    }

    /* Mode Badge */
    .mode-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .mode-online {
        background: rgba(76, 201, 240, 0.1);
        color: #3a86ff;
        border: 1px solid rgba(76, 201, 240, 0.3);
    }

    .mode-offline {
        background: rgba(255, 193, 7, 0.1);
        color: #ff9e00;
        border: 1px solid rgba(255, 193, 7, 0.3);
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: var(--glass-bg);
        backdrop-filter: blur(10px);
        border-radius: 24px;
        box-shadow: var(--shadow);
    }

    .empty-state i {
        font-size: 5rem;
        color: rgba(67, 97, 238, 0.2);
        margin-bottom: 20px;
        animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }

    /* Loading Animation */
    .loading-skeleton {
        background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
        background-size: 200% 100%;
        animation: loading 1.5s infinite;
    }

    @keyframes loading {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }

    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    /* History Card Thumbnails */
    .history-thumbnail {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        object-fit: cover;
        margin-right: 12px;
    }

    .history-placeholder {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: linear-gradient(135deg, #667eea, #764ba2);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.2rem;
        margin-right: 12px;
    }

    /* Batch Selection Tabs Enhancement */
    .batch-tab {
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .batch-tab.active {
        background: linear-gradient(135deg, #4361ee, #3a0ca3);
        color: white;
        box-shadow: 0 8px 20px rgba(67, 97, 238, 0.3);
    }

    .batch-tab.active::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 30px;
        height: 3px;
        background: white;
        border-radius: 3px;
    }

    .batch-tab:not(.active):hover {
        background: #e9ecef;
        transform: translateY(-2px);
    }

    /* Viewing Indicator */
    .viewing-indicator {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #4361ee15, #764ba215);
        padding: 6px 14px;
        border-radius: 40px;
        font-size: 0.8rem;
        color: #4361ee;
        margin-left: 12px;
        animation: gentlePulse 2s ease-in-out infinite;
    }

    @keyframes gentlePulse {
        0%, 100% { opacity: 0.7; }
        50% { opacity: 1; }
    }
</style>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Mobile Header (Visible only on mobile) -->
    <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
        
        <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-layer-group text-indigo-600 text-sm"></i>
            </div>
            <span>My Batches</span>
        </h1>
        
        <div class="flex items-center space-x-3">
            <div class="relative">
                <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-graduate text-indigo-600"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Desktop Header -->
    <header class="hidden md:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
        <div class="flex-1"></div>
        
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-layer-group text-indigo-600 text-xl"></i>
            </div>
            <span>My Batches & Progress</span>
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

                <a href="../stu_dash/student_profile.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_profile.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleMobileMenu()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-user-circle <?= $current_page == 'student_profile.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Profile</span>
                </a>
                
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

    <div class="p-4 md:p-6 bg-gray-50 min-h-screen">
        <!-- Batch Selection Tabs (Only show if multiple batches) -->
        <?php if (count($current_batches) > 1): ?>
        <div class="mb-6 glass-card p-4 rounded-2xl bg-white/80 backdrop-blur-sm shadow-lg">
            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-exchange-alt text-indigo-600 mr-2"></i>
                My Batches
                <?php if (count($current_batches) > 0): ?>
                <span class="viewing-indicator">
                    <i class="fas fa-eye"></i>
                    Viewing: Batch <?= $selected_batch_index + 1 ?>
                </span>
                <?php endif; ?>
            </h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($current_batches as $index => $batch_info): ?>
                    <a href="?batch_index=<?= $index ?>" 
                       class="batch-tab px-4 py-2 rounded-lg transition-all duration-300 <?= $selected_batch_index == $index ? 'active bg-indigo-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
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
                                <i class="fas fa-check-circle ml-2"></i>
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
                    
                    // Calculate progress for this batch
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
                    
                    // Status badge class
                    $status_class = '';
                    switch($batch['status']) {
                        case 'ongoing': $status_class = 'badge-ongoing'; break;
                        case 'upcoming': $status_class = 'badge-upcoming'; break;
                        case 'completed': $status_class = 'badge-completed'; break;
                        case 'cancelled': $status_class = 'badge-cancelled'; break;
                    }
                    
                    // Mode badge class
                    $mode_class = $batch['mode'] === 'online' ? 'mode-online' : 'mode-offline';
                    
                    // Batch label
                    $batch_label = "Batch ";
                    if ($batch_info['field_name'] == 'batch_name') $batch_label .= "1";
                    elseif ($batch_info['field_name'] == 'batch_name_2') $batch_label .= "2";
                    elseif ($batch_info['field_name'] == 'batch_name_3') $batch_label .= "3";
                    elseif ($batch_info['field_name'] == 'batch_name_4') $batch_label .= "4";
                ?>
                <div class="batch-card <?= $is_selected ? 'selected' : '' ?>" onclick="window.location.href='?batch_index=<?= $index ?>'">
                    <!-- Selected Badge -->
                    <div class="selected-badge">
                        <i class="fas fa-check-circle"></i>
                        Currently Viewing
                    </div>
                    
                    <!-- Thumbnail Section -->
                    <div class="batch-thumbnail-container">
                        <?php if (!empty($batch['thumbnail_path'])): ?>
                            <img src="../<?= htmlspecialchars($batch['thumbnail_path']) ?>" 
                                 alt="<?= htmlspecialchars($batch['batch_name']) ?>" 
                                 class="batch-thumbnail">
                            <div class="thumbnail-overlay">
                                <span class="text-white font-semibold"><?= htmlspecialchars($batch['batch_name']) ?></span>
                            </div>
                        <?php else: ?>
                            <div class="thumbnail-placeholder">
                                <div class="placeholder-pattern"></div>
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Status Badge -->
                        <div class="batch-badge <?= $status_class ?>">
                            <i class="fas fa-circle me-1" style="font-size: 0.5rem;"></i>
                            <?= ucfirst($batch['status']) ?>
                        </div>
                    </div>
                    
                    <!-- Content Section -->
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
                        
                        <!-- Progress Section -->
                        <div class="progress-section">
                            <div class="progress-header">
                                <span class="progress-title">Overall Progress</span>
                                <span class="progress-percentage"><?= $overall_progress ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill" style="width: <?= $overall_progress ?>%"></div>
                            </div>
                            
                            <div class="progress-stats">
                                <div class="stat">
                                    <div class="stat-value"><?= $theory_completed ?>/<?= $total_sub_topics ?></div>
                                    <div class="stat-label">Theory</div>
                                </div>
                                <div class="stat">
                                    <div class="stat-value"><?= $practical_completed ?>/<?= $total_sub_topics ?></div>
                                    <div class="stat-label">Practical</div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($is_selected): ?>
                        <div class="mt-2 text-center text-xs text-indigo-600 font-semibold flex items-center justify-center gap-1 bg-indigo-50 py-1.5 rounded-lg">
                            <i class="fas fa-arrow-right"></i>
                            <span>Currently Viewing Details Below</span>
                            <i class="fas fa-arrow-down"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Empty State -->
            <div class="empty-state animate__animated animate__fadeIn">
                <i class="fas fa-layer-group"></i>
                <h3 class="text-xl font-semibold text-gray-700 mb-2">No Batches Assigned</h3>
                <p class="text-gray-500 mb-6">You haven't been assigned to any batches yet.</p>
                <p class="text-sm text-gray-400">Please contact the administration for assistance.</p>
            </div>
        <?php endif; ?>

        <!-- Selected Batch Details (if a batch is selected and exists) -->
        <?php if ($selected_batch && count($current_batches) > 0): ?>
        <div class="mt-8 bg-white p-6 rounded-2xl shadow-lg transform transition-transform duration-300 hover:scale-[1.01] border-l-4 border-indigo-500">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-bold text-gray-800 flex items-center">
                    <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                        <i class="fas fa-star text-indigo-600"></i>
                    </div>
                    Detailed Progress: <?= htmlspecialchars($selected_batch['batch_name']) ?>
                    <span class="ml-3 text-sm font-normal text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full">
                        <i class="fas fa-chart-line mr-1"></i>Live Progress
                    </span>
                </h2>
            </div>

            <!-- Progress Overview Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                <!-- Course Progress -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl border-t-4 border-blue-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="bg-blue-100 p-3 rounded-lg mr-3">
                                <i class="fas fa-book-open text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800">Course Progress</h3>
                                <p class="text-sm text-gray-500">Overall completion</p>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Progress</span>
                            <span class="font-bold text-blue-600"><?= $batch_progress['overall_progress'] ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-blue-600 h-2 rounded-full transition-all duration-1000 ease-out" 
                                 style="width: <?= $batch_progress['overall_progress'] ?>%"></div>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500 flex justify-between">
                        <span><?= $batch_progress['theory_completed_total'] + $batch_progress['practical_completed_total'] ?> of <?= $batch_progress['total_sub_topics'] * 2 ?> topics</span>
                        <span><?= $batch_progress['theory_completed_total'] ?> theory + <?= $batch_progress['practical_completed_total'] ?> practical</span>
                    </div>
                </div>

                <!-- Attendance Progress -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl border-t-4 border-green-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="bg-green-100 p-3 rounded-lg mr-3">
                                <i class="fas fa-user-check text-green-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800">Attendance</h3>
                                <p class="text-sm text-gray-500">Class participation</p>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Rate</span>
                            <span class="font-bold text-green-600"><?= $attendance_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-green-600 h-2 rounded-full transition-all duration-1000 ease-out" 
                                 style="width: <?= $attendance_percentage ?>%"></div>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?php if ($attendance_data): ?>
                            <span><?= $attendance_data['present_count'] ?> of <?= $attendance_data['total_classes'] ?> classes</span>
                        <?php else: ?>
                            <span>No attendance data</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Exam Performance -->
                <div class="bg-white p-6 rounded-2xl shadow-lg transform transition-all duration-300 hover:scale-[1.02] hover:shadow-xl border-t-4 border-purple-500">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="bg-purple-100 p-3 rounded-lg mr-3">
                                <i class="fas fa-chart-line text-purple-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800">Exam Performance</h3>
                                <p class="text-sm text-gray-500">Average score</p>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-gray-600">Average</span>
                            <span class="font-bold text-purple-600"><?= $exam_percentage ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2">
                            <div class="bg-purple-600 h-2 rounded-full transition-all duration-1000 ease-out" 
                                 style="width: <?= $exam_percentage ?>%"></div>
                        </div>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?php if ($exam_data && $exam_data['total_exams'] > 0): ?>
                            <span><?= $exam_data['passed_exams'] ?> of <?= $exam_data['total_exams'] ?> passed</span>
                        <?php else: ?>
                            <span>No exam data</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Detailed Topic List -->
            <div class="mt-8 border-t pt-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-list-ol text-indigo-600 mr-2"></i>
                    Detailed Course Topics
                </h3>
                
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gradient-to-r from-blue-50 to-indigo-50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Chapter</th>
                                    <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Main Topic</th>
                                    <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Sub-Topics</th>
                                    <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Progress</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($batch_topics as $index => $topic): 
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
                                ?>
                                <tr class="hover:bg-blue-50 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                                                <i class="fas fa-chapter text-indigo-600"></i>
                                            </div>
                                            <span class="text-sm font-medium text-gray-900">Chapter <?= $topic['chapter'] ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($topic['topic_name']) ?></div>
                                        <div class="text-xs text-gray-500">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                                <?= $topic['topic_type'] === 'theory' ? 'bg-blue-100 text-blue-800' : 
                                                   ($topic['topic_type'] === 'practical' ? 'bg-green-100 text-green-800' : 'bg-purple-100 text-purple-800') ?>">
                                                <i class="fas fa-<?= $topic['topic_type'] === 'theory' ? 'book' : ($topic['topic_type'] === 'practical' ? 'flask' : 'cogs') ?> mr-1"></i>
                                                <?= ucfirst($topic['topic_type']) ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900"><?= $topic_total_subtopics ?> Sub-Topics</div>
                                        <button onclick="toggleSubtopics('subtopics-<?= $index ?>', event)" 
                                                class="text-xs text-indigo-600 hover:text-indigo-800 transition-colors flex items-center mt-1">
                                            <i class="fas fa-chevron-down mr-1"></i>
                                            Show Details
                                        </button>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="mr-3">
                                                <div class="text-sm font-medium text-gray-900"><?= $topic_progress ?>%</div>
                                                <div class="text-xs text-gray-500"><?= $topic_theory ?>T / <?= $topic_practical ?>P</div>
                                            </div>
                                            <div class="w-16 bg-gray-200 rounded-full h-2">
                                                <div class="h-2 rounded-full transition-all duration-1000 ease-out 
                                                    <?= $topic_progress >= 80 ? 'bg-green-600' : 
                                                       ($topic_progress >= 50 ? 'bg-yellow-600' : 'bg-red-600') ?>" 
                                                     style="width: <?= $topic_progress ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <!-- Sub-topics Row -->
                                <tr id="subtopics-<?= $index ?>" class="hidden bg-blue-50/50">
                                    <td colspan="4" class="px-6 py-4">
                                        <div class="pl-8 border-l-2 border-indigo-200">
                                            <h4 class="text-sm font-medium text-gray-800 mb-2 flex items-center">
                                                <i class="fas fa-list mr-2 text-indigo-600"></i>
                                                Sub-Topics for Chapter <?= $topic['chapter'] ?>
                                            </h4>
                                            <div class="space-y-2">
                                                <?php if (empty($topic['sub_topics'])): ?>
                                                    <div class="text-sm text-gray-500 italic">No sub-topics available</div>
                                                <?php else: ?>
                                                    <?php foreach ($topic['sub_topics'] as $sub_topic): ?>
                                                        <div class="bg-white p-3 rounded-lg border border-gray-200 hover:border-indigo-300 transition-colors duration-200">
                                                            <div class="flex justify-between items-start">
                                                                <div class="flex-1">
                                                                    <div class="flex items-center mb-1">
                                                                        <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($sub_topic['name']) ?></span>
                                                                    </div>
                                                                    <div class="flex space-x-4 text-xs">
                                                                        <div class="flex items-center">
                                                                            <i class="fas fa-book mr-1 <?= $sub_topic['theory_completed'] ? 'text-green-600' : 'text-gray-400' ?>"></i>
                                                                            <span class="<?= $sub_topic['theory_completed'] ? 'text-green-700' : 'text-gray-500' ?>">
                                                                                Theory <?= $sub_topic['theory_completed'] ? '✓' : '○' ?>
                                                                            </span>
                                                                        </div>
                                                                        <div class="flex items-center">
                                                                            <i class="fas fa-flask mr-1 <?= $sub_topic['practical_completed'] ? 'text-green-600' : 'text-gray-400' ?>"></i>
                                                                            <span class="<?= $sub_topic['practical_completed'] ? 'text-green-700' : 'text-gray-500' ?>">
                                                                                Practical <?= $sub_topic['practical_completed'] ? '✓' : '○' ?>
                                                                            </span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Batch History -->
        <?php if (count($batch_history) > 0): ?>
        <div class="mt-8 bg-white p-6 rounded-2xl shadow-lg transform transition-transform duration-300 hover:scale-[1.005]">
            <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center">
                <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                    <i class="fas fa-history text-indigo-600"></i>
                </div>
                Batch History
            </h2>
            
            <div class="overflow-x-auto rounded-2xl shadow-inner">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gradient-to-r from-indigo-50 to-blue-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Batch</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Course</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Duration</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-indigo-600 uppercase tracking-wider">Transfer Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($batch_history as $index => $history): ?>
                            <tr class="transition-all duration-300 hover:bg-indigo-50 <?= $index % 2 === 0 ? 'bg-gray-50' : 'bg-white' ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <?php if (!empty($history['thumbnail_path'])): ?>
                                            <img src="../<?= htmlspecialchars($history['thumbnail_path']) ?>" 
                                                 alt="Batch" 
                                                 class="history-thumbnail">
                                        <?php else: ?>
                                            <div class="history-placeholder">
                                                <i class="fas fa-graduation-cap"></i>
                                            </div>
                                        <?php endif; ?>
                                        <span class="text-sm font-medium text-gray-900">
                                            <?= htmlspecialchars($history['to_batch_id']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($history['batch_name']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div class="flex items-center">
                                        <i class="far fa-calendar text-indigo-400 mr-2"></i>
                                        <?= date('M j, Y', strtotime($history['start_date'])) ?> - 
                                        <?= date('M j, Y', strtotime($history['end_date'])) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div class="flex items-center">
                                        <i class="far fa-clock text-indigo-400 mr-2"></i>
                                        <?= date('M j, Y', strtotime($history['transfer_date'])) ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

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

// Function to toggle subtopics visibility
function toggleSubtopics(elementId, event) {
    event.stopPropagation();
    const element = document.getElementById(elementId);
    const button = event.currentTarget;
    const icon = button.querySelector('i');
    
    if (element.classList.contains('hidden')) {
        element.classList.remove('hidden');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
        button.innerHTML = button.innerHTML.replace('Show', 'Hide');
        
        // Animate the expansion
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
            icon.classList.remove('fa-chevron-up');
            icon.classList.add('fa-chevron-down');
            button.innerHTML = button.innerHTML.replace('Hide', 'Show');
        }, 300);
    }
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

// Animate progress bars on page load
document.addEventListener('DOMContentLoaded', function() {
    const progressBars = document.querySelectorAll('.progress-bar-fill');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 100);
    });
    
    // Scroll to selected batch card if needed (smooth scroll)
    const selectedCard = document.querySelector('.batch-card.selected');
    if (selectedCard) {
        selectedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>

<?php include '../footer.php'; ?>