
<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT s.*, 
           b1.batch_id as batch1_id, b1.batch_name as batch1_name, b1.start_date as batch1_start, b1.end_date as batch1_end, 
           b1.time_slot as batch1_time, b1.mode as batch1_mode, b1.status as batch1_status, b1.meeting_link as batch1_link,
           b2.batch_id as batch2_id, b2.batch_name as batch2_name, b2.start_date as batch2_start, b2.end_date as batch2_end, 
           b2.time_slot as batch2_time, b2.mode as batch2_mode, b2.status as batch2_status, b2.meeting_link as batch2_link,
           b3.batch_id as batch3_id, b3.batch_name as batch3_name, b3.start_date as batch3_start, b3.end_date as batch3_end, 
           b3.time_slot as batch3_time, b3.mode as batch3_mode, b3.status as batch3_status, b3.meeting_link as batch3_link,
           b4.batch_id as batch4_id, b4.batch_name as batch4_name, b4.start_date as batch4_start, b4.end_date as batch4_end, 
           b4.time_slot as batch4_time, b4.mode as batch4_mode, b4.status as batch4_status, b4.meeting_link as batch4_link
    FROM students s
    LEFT JOIN batches b1 ON s.batch_name = b1.batch_id
    LEFT JOIN batches b2 ON s.batch_name_2 = b2.batch_id
    LEFT JOIN batches b3 ON s.batch_name_3 = b3.batch_id
    LEFT JOIN batches b4 ON s.batch_name_4 = b4.batch_id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Collect all batches
$all_batches = [];
$student_db_id = $student['student_id'];

// Batch 1
if (!empty($student['batch1_id'])) {
    $all_batches[] = [
        'id' => $student['batch1_id'],
        'name' => $student['batch1_name'],
        'start_date' => $student['batch1_start'],
        'end_date' => $student['batch1_end'],
        'time_slot' => $student['batch1_time'],
        'mode' => $student['batch1_mode'],
        'status' => $student['batch1_status'],
        'meeting_link' => $student['batch1_link'],
        'field_name' => 'batch1'
    ];
}

// Batch 2
if (!empty($student['batch2_id'])) {
    $all_batches[] = [
        'id' => $student['batch2_id'],
        'name' => $student['batch2_name'],
        'start_date' => $student['batch2_start'],
        'end_date' => $student['batch2_end'],
        'time_slot' => $student['batch2_time'],
        'mode' => $student['batch2_mode'],
        'status' => $student['batch2_status'],
        'meeting_link' => $student['batch2_link'],
        'field_name' => 'batch2'
    ];
}

// Batch 3
if (!empty($student['batch3_id'])) {
    $all_batches[] = [
        'id' => $student['batch3_id'],
        'name' => $student['batch3_name'],
        'start_date' => $student['batch3_start'],
        'end_date' => $student['batch3_end'],
        'time_slot' => $student['batch3_time'],
        'mode' => $student['batch3_mode'],
        'status' => $student['batch3_status'],
        'meeting_link' => $student['batch3_link'],
        'field_name' => 'batch3'
    ];
}

// Batch 4
if (!empty($student['batch4_id'])) {
    $all_batches[] = [
        'id' => $student['batch4_id'],
        'name' => $student['batch4_name'],
        'start_date' => $student['batch4_start'],
        'end_date' => $student['batch4_end'],
        'time_slot' => $student['batch4_time'],
        'mode' => $student['batch4_mode'],
        'status' => $student['batch4_status'],
        'meeting_link' => $student['batch4_link'],
        'field_name' => 'batch4'
    ];
}

// Get selected batch from URL or default to first
$selected_batch_index = isset($_GET['batch_index']) ? intval($_GET['batch_index']) : 0;
if ($selected_batch_index >= count($all_batches)) {
    $selected_batch_index = 0;
}

$selected_batch = !empty($all_batches) ? $all_batches[$selected_batch_index] : null;
$batch_id = $selected_batch ? $selected_batch['id'] : null;

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

// Initialize variables
$all_events = [];
$completed_classes = 0;
$upcoming_class_count = 0;
$cancelled_count = 0;
$missed_count = 0;
$exam_count = 0;
$total_events = 0;

if ($batch_id) {
    // Get classes for the selected month (plus one day before/after for edge cases)
    $first_day_of_month = date('Y-m-01', strtotime("$current_year-$current_month-01"));
    $last_day_of_month = date('Y-m-t', strtotime("$current_year-$current_month-01"));
    
    // Get classes (3 months range for better navigation)
    $start_range = date('Y-m-01', strtotime("-1 month", strtotime($first_day_of_month)));
    $end_range = date('Y-m-t', strtotime("+1 month", strtotime($last_day_of_month)));
    
    // Get upcoming classes for selected batch
    $upcoming_query = $db->prepare("
        SELECT s.id, s.schedule_date, s.start_time, s.end_time, s.topic, s.description, 
               s.is_cancelled, s.cancellation_reason, s.created_at,
               CASE 
                   WHEN a.id IS NOT NULL THEN 'completed'
                   WHEN s.is_cancelled = 1 THEN 'cancelled'
                   WHEN s.schedule_date < CURDATE() THEN 'missed'
                   ELSE 'upcoming'
               END as class_status
        FROM schedule s
        LEFT JOIN attendance a ON s.batch_id = a.batch_id 
            AND DATE(s.schedule_date) = DATE(a.date) 
            AND a.student_id = :student_id
            AND a.status = 'Present'
        WHERE s.batch_id = :batch_id 
        AND s.schedule_date BETWEEN :start_range AND :end_range
        ORDER BY s.schedule_date ASC, s.start_time ASC
    ");
    $upcoming_query->execute([
        ':batch_id' => $batch_id, 
        ':student_id' => $student_db_id,
        ':start_range' => $start_range,
        ':end_range' => $end_range
    ]);
    $all_classes = $upcoming_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming exams for selected batch
    $exams_query = $db->prepare("
        SELECT exam_id, exam_name, subject, exam_date, total_marks, passing_marks, exam_type, description,
               enrollment_status, exam_components, mcq_marks, project_marks, viva_marks
        FROM exams 
        WHERE batch_id = :batch_id 
        AND exam_date BETWEEN :start_range AND :end_range
        ORDER BY exam_date ASC
    ");
    $exams_query->execute([
        ':batch_id' => $batch_id,
        ':start_range' => $start_range,
        ':end_range' => $end_range
    ]);
    $all_exams = $exams_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Also get exam enrollments to check if student is enrolled
    if (!empty($all_exams)) {
        foreach ($all_exams as &$exam) {
            if ($exam['enrollment_status'] === 'selected_students') {
                $enrollment_query = $db->prepare("
                    SELECT * FROM exam_enrollments 
                    WHERE exam_id = :exam_id AND student_id = :student_id
                ");
                $enrollment_query->execute([
                    ':exam_id' => $exam['exam_id'],
                    ':student_id' => $student['student_id']
                ]);
                $exam['is_enrolled'] = $enrollment_query->rowCount() > 0;
            } else {
                $exam['is_enrolled'] = true; // All students are enrolled
            }
        }
        unset($exam); // Break reference
    }
    
    // Combine all events
    foreach ($all_classes as $class) {
        $class_date = $class['schedule_date'];
        $day_of_week = date('N', strtotime($class_date)); // 1=Monday, 7=Sunday
        
        $all_events[] = [
            'date' => $class['schedule_date'],
            'type' => 'class',
            'title' => $class['topic'],
            'status' => $class['class_status'],
            'data' => $class,
            'batch_id' => $batch_id,
            'batch_name' => $selected_batch['name']
        ];
    }
    
    foreach ($all_exams as $exam) {
        if ($exam['is_enrolled']) {
            $all_events[] = [
                'date' => $exam['exam_date'],
                'type' => 'exam',
                'title' => $exam['exam_name'],
                'status' => 'upcoming',
                'data' => $exam,
                'batch_id' => $batch_id,
                'batch_name' => $selected_batch['name']
            ];
        }
    }
    
    // Count totals for current month only
    $month_classes = array_filter($all_classes, function($c) use ($current_month, $current_year) {
        $class_month = date('n', strtotime($c['schedule_date']));
        $class_year = date('Y', strtotime($c['schedule_date']));
        return $class_month == $current_month && $class_year == $current_year;
    });
    
    $month_exams = array_filter($all_exams, function($e) use ($current_month, $current_year) {
        if (!$e['is_enrolled']) return false;
        $exam_month = date('n', strtotime($e['exam_date']));
        $exam_year = date('Y', strtotime($e['exam_date']));
        return $exam_month == $current_month && $exam_year == $current_year;
    });
    
    $completed_classes = count(array_filter($month_classes, function($c) { 
        return $c['class_status'] === 'completed'; 
    }));
    $upcoming_class_count = count(array_filter($month_classes, function($c) { 
        return $c['class_status'] === 'upcoming' && !$c['is_cancelled']; 
    }));
    $cancelled_count = count(array_filter($month_classes, function($c) { 
        return $c['is_cancelled'] || $c['class_status'] === 'cancelled'; 
    }));
    $missed_count = count(array_filter($month_classes, function($c) { 
        return $c['class_status'] === 'missed'; 
    }));
    $exam_count = count($month_exams);
    $total_events = $upcoming_class_count + $exam_count + $completed_classes;
}

// Get events from ALL batches for combined view
$all_batches_events = [];
if (!empty($all_batches)) {
    foreach ($all_batches as $batch) {
        $batch_events = [];
        
        // Get classes for this batch
        $batch_classes_query = $db->prepare("
            SELECT s.id, s.schedule_date, s.start_time, s.end_time, s.topic, s.description, 
                   s.is_cancelled, s.cancellation_reason, s.created_at,
                   CASE 
                       WHEN a.id IS NOT NULL THEN 'completed'
                       WHEN s.is_cancelled = 1 THEN 'cancelled'
                       WHEN s.schedule_date < CURDATE() THEN 'missed'
                       ELSE 'upcoming'
                   END as class_status
            FROM schedule s
            LEFT JOIN attendance a ON s.batch_id = a.batch_id 
                AND DATE(s.schedule_date) = DATE(a.date) 
                AND a.student_id = :student_id
                AND a.status = 'Present'
            WHERE s.batch_id = :batch_id 
            AND s.schedule_date BETWEEN :start_range AND :end_range
            ORDER BY s.schedule_date ASC, s.start_time ASC
        ");
        $batch_classes_query->execute([
            ':batch_id' => $batch['id'], 
            ':student_id' => $student_db_id,
            ':start_range' => $start_range,
            ':end_range' => $end_range
        ]);
        $batch_classes = $batch_classes_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Get exams for this batch
        $batch_exams_query = $db->prepare("
            SELECT exam_id, exam_name, subject, exam_date, total_marks, passing_marks, exam_type, description,
                   enrollment_status, exam_components, mcq_marks, project_marks, viva_marks
            FROM exams 
            WHERE batch_id = :batch_id 
            AND exam_date BETWEEN :start_range AND :end_range
            ORDER BY exam_date ASC
        ");
        $batch_exams_query->execute([
            ':batch_id' => $batch['id'],
            ':start_range' => $start_range,
            ':end_range' => $end_range
        ]);
        $batch_exams = $batch_exams_query->fetchAll(PDO::FETCH_ASSOC);
        
        // Check exam enrollments
        if (!empty($batch_exams)) {
            foreach ($batch_exams as &$exam) {
                if ($exam['enrollment_status'] === 'selected_students') {
                    $enrollment_query = $db->prepare("
                        SELECT * FROM exam_enrollments 
                        WHERE exam_id = :exam_id AND student_id = :student_id
                    ");
                    $enrollment_query->execute([
                        ':exam_id' => $exam['exam_id'],
                        ':student_id' => $student['student_id']
                    ]);
                    $exam['is_enrolled'] = $enrollment_query->rowCount() > 0;
                } else {
                    $exam['is_enrolled'] = true;
                }
            }
            unset($exam);
        }
        
        // Combine events for this batch
        foreach ($batch_classes as $class) {
            $batch_events[] = [
                'date' => $class['schedule_date'],
                'type' => 'class',
                'title' => $class['topic'],
                'status' => $class['class_status'],
                'data' => $class,
                'batch_id' => $batch['id'],
                'batch_name' => $batch['name']
            ];
        }
        
        foreach ($batch_exams as $exam) {
            if ($exam['is_enrolled']) {
                $batch_events[] = [
                    'date' => $exam['exam_date'],
                    'type' => 'exam',
                    'title' => $exam['exam_name'],
                    'status' => 'upcoming',
                    'data' => $exam,
                    'batch_id' => $batch['id'],
                    'batch_name' => $batch['name']
                ];
            }
        }
        
        $all_batches_events = array_merge($all_batches_events, $batch_events);
    }
    
    // Sort all events by date
    usort($all_batches_events, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
}

// Generate calendar days for current month with events
$days_in_month = date('t', mktime(0, 0, 0, $current_month, 1, $current_year));
$first_day_of_week = date('N', mktime(0, 0, 0, $current_month, 1, $current_year));

// Group events by date for current month
$events_by_date = [];
foreach ($all_events as $event) {
    $event_date = $event['date'];
    $event_month = date('n', strtotime($event_date));
    $event_year = date('Y', strtotime($event_date));
    
    // Only include events from current month
    if ($event_month == $current_month && $event_year == $current_year) {
        if (!isset($events_by_date[$event_date])) {
            $events_by_date[$event_date] = [];
        }
        $events_by_date[$event_date][] = $event;
    }
}

// Prepare calendar days array
$calendar_days = [];
$empty_days = $first_day_of_week - 1; // Monday is 1, Sunday is 7

// Add empty days for first week
for ($i = 0; $i < $empty_days; $i++) {
    $calendar_days[] = ['day' => '', 'date' => null, 'events' => [], 'is_today' => false, 'has_events' => false, 'is_sunday' => false, 'is_saturday' => false, 'has_completed' => false, 'has_missed' => false];
}

// Add days of the month
for ($day = 1; $day <= $days_in_month; $day++) {
    $date = sprintf('%04d-%02d-%02d', $current_year, $current_month, $day);
    $is_today = ($date == date('Y-m-d') && $current_month == date('n') && $current_year == date('Y'));
    $has_events = isset($events_by_date[$date]) && !empty($events_by_date[$date]);
    $day_of_week = date('N', strtotime($date));
    $is_sunday = ($day_of_week == 7);
    $is_saturday = ($day_of_week == 6);
    
    $day_events = $has_events ? $events_by_date[$date] : [];
    
    // Check if any event is completed
    $has_completed = false;
    $has_missed = false;
    foreach ($day_events as $event) {
        if ($event['status'] === 'completed') {
            $has_completed = true;
        }
        if ($event['status'] === 'missed') {
            $has_missed = true;
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
        'has_completed' => $has_completed,
        'has_missed' => $has_missed
    ];
}

// Calculate total empty days at the end to make full weeks
$total_cells = 42; // 6 rows * 7 days
$remaining_empty_days = $total_cells - count($calendar_days);
for ($i = 0; $i < $remaining_empty_days; $i++) {
    $calendar_days[] = ['day' => '', 'date' => null, 'events' => [], 'is_today' => false, 'has_events' => false, 'is_sunday' => false, 'is_saturday' => false, 'has_completed' => false, 'has_missed' => false];
}
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out">
    <!-- Mobile Header (Visible only on mobile) -->
    <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <!-- Mobile Menu Button -->
        <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        
        <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-calendar-alt text-indigo-600 text-sm"></i>
            </div>
            <span>Upcoming Schedule</span>
        </h1>
        
        <div class="flex items-center space-x-3">
            <!-- User Profile/Indicator -->
            <div class="relative">
                <div class="w-8 h-8 bg-indigo-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-graduate text-indigo-600"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Desktop Header (Hidden on mobile) -->
    <header class="hidden md:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
        <div class="flex-1"></div> <!-- Spacer for centering -->
        
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-indigo-100 p-2 rounded-lg">
                <i class="fas fa-calendar-alt text-indigo-600 text-xl"></i>
            </div>
            <span>Upcoming Schedule & Exams</span>
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
                    <button onclick="toggleSidebar()" class="text-gray-500 hover:text-indigo-600 text-xl">
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
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-tachometer-alt <?= $current_page == 'dashboard.php' ? 'text-blue-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Dashboard</span>
                </a>

                <a href="../stu_dash/my_batches.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_batches.php' ? 'bg-white shadow-md text-green-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-users <?= $current_page == 'my_batches.php' ? 'text-green-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Batches</span>
                </a>

                <a href="../stu_dash/upcoming.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'upcoming.php' ? 'bg-white shadow-md text-purple-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-calendar-alt <?= $current_page == 'upcoming.php' ? 'text-purple-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Upcoming Schedule</span>
                </a>

                <a href="../stu_dash/my_content.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_content.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-book <?= $current_page == 'my_content.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Content</span>
                </a>
                
                <a href="../student_test/student_dashboard.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_dashboard.php' ? 'bg-white shadow-md text-yellow-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-vial <?= $current_page == 'student_dashboard.php' ? 'text-yellow-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Test</span>
                </a>

                <a href="../stu_dash/my_performance.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_performance.php' ? 'bg-white shadow-md text-red-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-chart-line <?= $current_page == 'my_performance.php' ? 'text-red-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">My Performance</span>
                </a>

                <a href="../stu_dash/student_feedback.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_feedback.php' ? 'bg-white shadow-md text-indigo-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-comment-dots <?= $current_page == 'student_feedback.php' ? 'text-indigo-600' : 'text-gray-500' ?>"></i>
                    </div>
                    <span class="font-medium">Feedback</span>
                </a>

                <a href="../stu_dash/student_profile.php" 
                   class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'student_profile.php' ? 'bg-white shadow-md text-cyan-600' : 'hover:bg-white/90 hover:shadow-sm text-gray-700' ?>"
                   onclick="toggleSidebar()">
                    <div class="w-8 h-8 flex items-center justify-center">
                        <i class="fas fa-user-circle <?= $current_page == 'student_profile.php' ? 'text-cyan-600' : 'text-gray-500' ?>"></i>
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

    <div class="p-4 md:p-6 min-h-screen">
        <!-- Batch Selection Tabs (Only show if multiple batches) -->
        <?php if (count($all_batches) > 1): ?>
        <div class="mb-6 glass-card p-4">
            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-exchange-alt text-indigo-600 mr-2"></i>
                Select Batch to View
            </h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($all_batches as $index => $batch): ?>
                    <a href="?batch_index=<?= $index ?>&month=<?= $current_month ?>&year=<?= $current_year ?>" 
                       class="px-4 py-2 rounded-lg transition-all duration-300 btn-batch-select-<?= $index ?> <?= ($selected_batch_index == $index && (!isset($_GET['batch_index']) || $_GET['batch_index'] !== 'all')) ? 'active bg-indigo-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                        <div class="flex items-center">
                            <i class="fas fa-layer-group mr-2"></i>
                            <?php 
                            $batch_label = "Batch ";
                            if ($batch['field_name'] == 'batch1') $batch_label .= "1";
                            elseif ($batch['field_name'] == 'batch2') $batch_label .= "2";
                            elseif ($batch['field_name'] == 'batch3') $batch_label .= "3";
                            elseif ($batch['field_name'] == 'batch4') $batch_label .= "4";
                            ?>
                            <span><?= $batch_label ?>: <?= htmlspecialchars($batch['name']) ?></span>
                            <?php if ($selected_batch_index == $index && (!isset($_GET['batch_index']) || $_GET['batch_index'] !== 'all')): ?>
                                <i class="fas fa-check ml-2"></i>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                
                <!-- Combined View Button -->
                <a href="?batch_index=all&month=<?= $current_month ?>&year=<?= $current_year ?>" 
                   class="px-4 py-2 rounded-lg transition-all duration-300 btn-combined-select <?= (isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') ? 'active bg-purple-600 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                    <div class="flex items-center">
                        <i class="fas fa-th-large mr-2"></i>
                        <span>Combined View (All Batches)</span>
                        <?php if (isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                            <i class="fas fa-check ml-2"></i>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
            <p class="text-sm text-gray-500 mt-2">
                Currently viewing: 
                <span class="font-semibold <?= (isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') ? 'text-purple-600' : 'text-indigo-600' ?>">
                    <?php if (isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                        Combined View - All Batches
                    <?php else: ?>
                        <?php 
                        $current_label = "Batch ";
                        if ($all_batches[$selected_batch_index]['field_name'] == 'batch1') $current_label .= "1";
                        elseif ($all_batches[$selected_batch_index]['field_name'] == 'batch2') $current_label .= "2";
                        elseif ($all_batches[$selected_batch_index]['field_name'] == 'batch3') $current_label .= "3";
                        elseif ($all_batches[$selected_batch_index]['field_name'] == 'batch4') $current_label .= "4";
                        echo $current_label . " - " . htmlspecialchars($selected_batch['name']);
                        ?>
                    <?php endif; ?>
                </span>
            </p>
        </div>
        <?php endif; ?>

        <!-- Batch Info Header -->
        <?php if (isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
            <!-- Combined View Header -->
            <div class="banner-combined text-white rounded-xl shadow-lg p-4 md:p-6 mb-6 fade-in">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-xl md:text-2xl font-bold">All Batches Combined View</h2>
                        <p class="text-purple-100 mt-1 text-sm md:text-base">Showing schedule from all your batches</p>
                        <div class="flex flex-wrap gap-2 mt-3">
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-white/20 rounded-full text-xs md:text-sm backdrop-blur-sm">
                                <i class="fas fa-layer-group mr-1"></i>
                                <?= count($all_batches) ?> Batches
                            </span>
                            <?php 
                            $total_events_all = count(array_filter($all_batches_events, function($e) use ($current_month, $current_year) {
                                $event_month = date('n', strtotime($e['date']));
                                $event_year = date('Y', strtotime($e['date']));
                                return $event_month == $current_month && $event_year == $current_year;
                             }));
                            ?>
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-white/20 rounded-full text-xs md:text-sm backdrop-blur-sm">
                                <i class="fas fa-calendar-day mr-1"></i>
                                <?= $total_events_all ?> Total Events
                            </span>
                        </div>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <span class="px-3 py-1 text-xs rounded-full animate-pulse bg-purple-100 text-purple-800">
                            Combined View
                        </span>
                    </div>
                </div>
            </div>
        <?php elseif($selected_batch): ?>
            <!-- Single Batch Header -->
            <div class="banner-batch-<?= $selected_batch_index ?> text-white rounded-xl shadow-lg p-4 md:p-6 mb-6 fade-in">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-xl md:text-2xl font-bold"><?php echo htmlspecialchars($selected_batch['name']); ?></h2>
                        <p class="text-blue-100 mt-1 text-sm md:text-base">Your Learning Schedule, Attendance & Exams</p>
                        <div class="flex flex-wrap gap-2 mt-3">
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-white/20 rounded-full text-xs md:text-sm backdrop-blur-sm">
                                <i class="far fa-calendar mr-1"></i>
                                <?php echo date('M j, Y', strtotime($selected_batch['start_date'])) . ' - ' . date('M j, Y', strtotime($selected_batch['end_date'])); ?>
                            </span>
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-white/20 rounded-full text-xs md:text-sm backdrop-blur-sm">
                                <i class="far fa-clock mr-1"></i>
                                <?php echo htmlspecialchars($selected_batch['time_slot']); ?>
                            </span>
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-white/20 rounded-full text-xs md:text-sm backdrop-blur-sm">
                                <i class="fas fa-laptop mr-1"></i>
                                <?php echo ucfirst($selected_batch['mode']); ?>
                            </span>
                        </div>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <span class="px-3 py-1 text-xs rounded-full animate-pulse
                            <?= $selected_batch['status'] === 'ongoing' ? 'bg-blue-100 text-blue-800' : 
                               ($selected_batch['status'] === 'upcoming' ? 'bg-yellow-100 text-yellow-800' : 
                               ($selected_batch['status'] === 'completed' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800')) ?>">
                            <?= ucfirst($selected_batch['status']) ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if((isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') || $selected_batch): ?>
            <!-- Quick Stats -->
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 md:gap-6 mb-6">
                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                    <!-- Combined View Stats -->
                    <?php 
                    $current_month_events = array_filter($all_batches_events, function($e) use ($current_month, $current_year) {
                        $event_month = date('n', strtotime($e['date']));
                        $event_year = date('Y', strtotime($e['date']));
                        return $event_month == $current_month && $event_year == $current_year;
                    });
                    
                    $total_combined_events = count($current_month_events);
                    $completed_combined = count(array_filter($current_month_events, function($e) { 
                        return $e['type'] === 'class' && $e['status'] === 'completed'; 
                    }));
                    $upcoming_combined = count(array_filter($current_month_events, function($e) { 
                        return $e['type'] === 'class' && $e['status'] === 'upcoming'; 
                    }));
                    $exams_combined = count(array_filter($current_month_events, function($e) { 
                        return $e['type'] === 'exam'; 
                    }));
                    $missed_combined = count(array_filter($current_month_events, function($e) { 
                        return $e['type'] === 'class' && $e['status'] === 'missed'; 
                    }));
                    ?>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-th-large text-purple-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?= $total_combined_events ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Total Events (All Batches)</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-check-circle text-green-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?= $completed_combined ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Completed Classes</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-chalkboard-teacher text-blue-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?= $upcoming_combined ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Upcoming Classes</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-pink-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-alt text-pink-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?= $exams_combined ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Exams</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-exclamation-triangle text-amber-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?= $missed_combined ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Missed Classes</p>
                    </div>
                <?php else: ?>
                    <!-- Single Batch Stats -->
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-calendar-day text-blue-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $total_events; ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Total Events (<?php echo date('F', mktime(0, 0, 0, $current_month, 1)); ?>)</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-check-circle text-green-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $completed_classes; ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Completed Classes</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-chalkboard-teacher text-blue-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $upcoming_class_count; ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Upcoming Classes</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-file-alt text-purple-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $exam_count; ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Exams</p>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow-lg p-4 md:p-6 text-center hover-lift">
                        <div class="w-10 h-10 md:w-12 md:h-12 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i class="fas fa-exclamation-triangle text-amber-600 text-lg md:text-xl"></i>
                        </div>
                        <h3 class="text-xl md:text-2xl font-bold text-gray-800"><?php echo $missed_count; ?></h3>
                        <p class="text-gray-600 text-xs md:text-sm">Missed Classes</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Enhanced Calendar View -->
            <div class="bg-white rounded-xl shadow-lg mb-8 fade-in">
                <div class="p-4 md:p-6 border-b border-gray-200">
                    <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                        <h3 class="text-base md:text-lg font-semibold text-gray-800 flex items-center mb-2 md:mb-0">
                            <i class="fas fa-calendar text-purple-500 mr-2"></i>
                            Monthly Calendar View - <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?>
                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                <span class="ml-2 px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">All Batches</span>
                            <?php endif; ?>
                        </h3>
                        <div class="flex space-x-2 mt-2 md:mt-0">
                            <a href="?batch_index=<?= isset($_GET['batch_index']) ? $_GET['batch_index'] : $selected_batch_index ?>&month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="month-navigation px-3 py-1 border border-gray-300 rounded-lg text-xs md:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                            <a href="?batch_index=<?= isset($_GET['batch_index']) ? $_GET['batch_index'] : $selected_batch_index ?>&month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" class="month-navigation px-3 py-1 border border-gray-300 rounded-lg text-xs md:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                Today
                            </a>
                            <a href="?batch_index=<?= isset($_GET['batch_index']) ? $_GET['batch_index'] : $selected_batch_index ?>&month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="month-navigation px-3 py-1 border border-gray-300 rounded-lg text-xs md:text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="p-4 md:p-6">
                    <!-- Weekday headers -->
                    <div class="grid grid-cols-7 gap-1 md:gap-2 mb-2">
                        <?php 
                        $weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                        foreach($weekdays as $day): 
                        ?>
                            <div class="text-center font-semibold text-gray-600 py-1 md:py-2 text-xs md:text-sm uppercase">
                                <?php echo $day; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Calendar grid -->
                    <div class="grid grid-cols-7 gap-1 md:gap-2">
                        <?php 
                        // For combined view, use all_batches_events
                        if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                            // Regroup events by date for combined view
                            $combined_events_by_date = [];
                            foreach ($all_batches_events as $event) {
                                $event_date = $event['date'];
                                $event_month = date('n', strtotime($event_date));
                                $event_year = date('Y', strtotime($event_date));
                                
                                if ($event_month == $current_month && $event_year == $current_year) {
                                    if (!isset($combined_events_by_date[$event_date])) {
                                        $combined_events_by_date[$event_date] = [];
                                    }
                                    $combined_events_by_date[$event_date][] = $event;
                                }
                            }
                            
                            // Regenerate calendar days for combined view
                            $calendar_days = [];
                            $empty_days = $first_day_of_week - 1;
                            
                            for ($i = 0; $i < $empty_days; $i++) {
                                $calendar_days[] = ['day' => '', 'date' => null, 'events' => [], 'is_today' => false, 'has_events' => false, 'is_sunday' => false, 'is_saturday' => false, 'has_completed' => false, 'has_missed' => false];
                            }
                            
                            for ($day = 1; $day <= $days_in_month; $day++) {
                                $date = sprintf('%04d-%02d-%02d', $current_year, $current_month, $day);
                                $is_today = ($date == date('Y-m-d') && $current_month == date('n') && $current_year == date('Y'));
                                $has_events = isset($combined_events_by_date[$date]) && !empty($combined_events_by_date[$date]);
                                $day_of_week = date('N', strtotime($date));
                                $is_sunday = ($day_of_week == 7);
                                $is_saturday = ($day_of_week == 6);
                                
                                $day_events = $has_events ? $combined_events_by_date[$date] : [];
                                
                                $has_completed = false;
                                $has_missed = false;
                                foreach ($day_events as $event) {
                                    if ($event['status'] === 'completed') {
                                        $has_completed = true;
                                    }
                                    if ($event['status'] === 'missed') {
                                        $has_missed = true;
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
                                    'has_completed' => $has_completed,
                                    'has_missed' => $has_missed
                                ];
                            }
                            
                            $remaining_empty_days = $total_cells - count($calendar_days);
                            for ($i = 0; $i < $remaining_empty_days; $i++) {
                                $calendar_days[] = ['day' => '', 'date' => null, 'events' => [], 'is_today' => false, 'has_events' => false, 'is_sunday' => false, 'is_saturday' => false, 'has_completed' => false, 'has_missed' => false];
                            }
                        }
                        
                        foreach($calendar_days as $index => $day): ?>
                            <div class="calendar-day p-1 md:p-3 border border-gray-200 rounded-lg 
                                        <?php echo $day['is_today'] ? 'today' : ''; ?>
                                        <?php echo $day['has_events'] ? 'has-events' : ''; ?>
                                        <?php echo $day['is_sunday'] ? 'sunday' : ''; ?>
                                        <?php echo $day['is_saturday'] ? 'saturday' : ''; ?>
                                        <?php echo $day['has_completed'] ? 'completed' : ''; ?>
                                        <?php echo $day['has_missed'] ? 'missed' : ''; ?>
                                        <?php echo !empty($day['events']) ? (array_reduce($day['events'], function($carry, $event) {
                                            return $carry || $event['type'] === 'class';
                                        }, false) ? 'has-class' : 'has-exam') : ''; ?>">
                                <?php if($day['day'] !== ''): ?>
                                    <div class="flex justify-between items-start mb-1 md:mb-2">
                                        <div class="flex items-center">
                                            <div class="text-xs md:text-sm font-medium <?php echo $day['is_today'] ? 'text-blue-600' : ($day['is_sunday'] ? 'text-pink-600' : ($day['is_saturday'] ? 'text-purple-600' : 'text-gray-700')); ?>">
                                                <?php echo $day['day']; ?>
                                            </div>
                                            <?php if($day['is_sunday']): ?>
                                                <span class="ml-1 px-1 py-0.5 md:ml-2 md:px-2 md:py-0.5 bg-pink-100 text-pink-800 text-xs font-medium rounded-full">Sun</span>
                                            <?php elseif($day['is_saturday']): ?>
                                                <span class="ml-1 px-1 py-0.5 md:ml-2 md:px-2 md:py-0.5 bg-purple-100 text-purple-800 text-xs font-medium rounded-full">Sat</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if($day['is_today']): ?>
                                            <span class="px-1 py-0.5 md:px-2 md:py-0.5 bg-blue-100 text-blue-800 text-xs font-medium rounded-full">Today</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Events for this day -->
                                    <?php if(!empty($day['events'])): ?>
                                        <div class="space-y-0.5 md:space-y-1 max-h-12 md:max-h-20 overflow-y-auto scrollbar-thin">
                                            <?php foreach($day['events'] as $event): ?>
                                                <div class="calendar-event-item text-xxs md:text-xs
                                                    <?php echo 'event-' . $event['type']; ?>
                                                    <?php echo 'event-' . $event['status']; ?>
                                                    <?php echo isset($event['data']['is_sunday']) && $event['data']['is_sunday'] ? 'event-sunday' : ''; ?>"
                                                     onclick="showEventDetails('<?php echo $event['type']; ?>', <?php echo htmlspecialchars(json_encode(array_merge($event['data'], ['batch_name' => $event['batch_name']]))); ?>)">
                                                    <div class="flex items-center">
                                                        <span class="event-dot 
                                                            <?php echo 'dot-' . $event['type']; ?>
                                                            <?php echo 'dot-' . $event['status']; ?>
                                                            <?php echo isset($event['data']['is_sunday']) && $event['data']['is_sunday'] ? 'dot-sunday' : ''; ?>"></span>
                                                        <span class="truncate" title="<?php echo htmlspecialchars($event['title'] . ' (' . $event['batch_name'] . ')'); ?>">
                                                            <?php echo htmlspecialchars($event['title']); ?>
                                                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                                                <span class="text-xxs text-gray-500 ml-1">(<?php echo $event['batch_name']; ?>)</span>
                                                            <?php endif; ?>
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
                    <div class="flex flex-wrap items-center justify-center mt-4 md:mt-6 gap-3 md:gap-4 text-xs md:text-sm text-gray-600">
                        <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                            <div class="flex items-center">
                                <div class="w-2 h-2 md:w-3 md:h-3 bg-blue-500 rounded-full mr-1 md:mr-2"></div>
                                <span>Batch 1</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-2 h-2 md:w-3 md:h-3 bg-green-500 rounded-full mr-1 md:mr-2"></div>
                                <span>Batch 2</span>
                            </div>
                            <div class="flex items-center">
                                <div class="w-2 h-2 md:w-3 md:h-3 bg-purple-500 rounded-full mr-1 md:mr-2"></div>
                                <span>Batch 3</span>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center">
                            <div class="w-2 h-2 md:w-3 md:h-3 bg-blue-400 rounded-full mr-1 md:mr-2"></div>
                            <span>Class</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-2 h-2 md:w-3 md:h-3 bg-green-400 rounded-full mr-1 md:mr-2"></div>
                            <span>Completed</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-2 h-2 md:w-3 md:h-3 bg-purple-400 rounded-full mr-1 md:mr-2"></div>
                            <span>Exam</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-2 h-2 md:w-3 md:h-3 bg-pink-500 rounded-full mr-1 md:mr-2"></div>
                            <span>Sunday</span>
                        </div>
                        <div class="flex items-center">
                            <div class="w-2 h-2 md:w-3 md:h-3 bg-amber-500 rounded-full mr-1 md:mr-2"></div>
                            <span>Missed</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs for Classes and Exams -->
            <div class="mb-6">
                <div class="border-b border-gray-200 overflow-x-auto">
                    <nav class="-mb-px flex space-x-4 md:space-x-8 min-w-max md:min-w-0">
                        <button id="tab-all" class="tab-btn py-2 md:py-3 px-1 border-b-2 font-medium text-xs md:text-sm border-blue-500 text-blue-600 whitespace-nowrap" onclick="switchTab('all')">
                            <i class="fas fa-list mr-1 md:mr-2"></i>All Events
                            <span class="ml-1 md:ml-2 px-1 md:px-2 py-0.5 text-xs bg-blue-100 text-blue-800 rounded-full">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $total_combined_events ?>
                                <?php else: ?>
                                    <?= $total_events ?>
                                <?php endif; ?>
                            </span>
                        </button>
                        <button id="tab-classes" class="tab-btn py-2 md:py-3 px-1 border-b-2 font-medium text-xs md:text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap" onclick="switchTab('classes')">
                            <i class="fas fa-chalkboard-teacher mr-1 md:mr-2"></i>All Classes
                            <span class="ml-1 md:ml-2 px-1 md:px-2 py-0.5 text-xs bg-blue-100 text-blue-800 rounded-full">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $completed_combined + $upcoming_combined + $missed_combined ?>
                                <?php else: ?>
                                    <?= count(array_filter($all_classes ?? [], function($c) use ($current_month, $current_year) {
                                        $class_month = date('n', strtotime($c['schedule_date']));
                                        $class_year = date('Y', strtotime($c['schedule_date']));
                                        return $class_month == $current_month && $class_year == $current_year;
                                    })); ?>
                                <?php endif; ?>
                            </span>
                        </button>
                        <button id="tab-upcoming" class="tab-btn py-2 md:py-3 px-1 border-b-2 font-medium text-xs md:text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap" onclick="switchTab('upcoming')">
                            <i class="fas fa-calendar-plus mr-1 md:mr-2"></i>Upcoming
                            <span class="ml-1 md:ml-2 px-1 md:px-2 py-0.5 text-xs bg-blue-100 text-blue-800 rounded-full">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $upcoming_combined ?>
                                <?php else: ?>
                                    <?= $upcoming_class_count ?>
                                <?php endif; ?>
                            </span>
                        </button>
                        <button id="tab-exams" class="tab-btn py-2 md:py-3 px-1 border-b-2 font-medium text-xs md:text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap" onclick="switchTab('exams')">
                            <i class="fas fa-file-alt mr-1 md:mr-2"></i>Exams
                            <span class="ml-1 md:ml-2 px-1 md:px-2 py-0.5 text-xs bg-purple-100 text-purple-800 rounded-full">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $exams_combined ?>
                                <?php else: ?>
                                    <?= $exam_count ?>
                                <?php endif; ?>
                            </span>
                        </button>
                    </nav>
                </div>
            </div>

            <!-- All Events View -->
            <div id="view-all" class="tab-content fade-in">
                <div class="bg-white rounded-xl shadow-lg">
                    <div class="p-4 md:p-6 border-b border-gray-200">
                        <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800 flex items-center mb-2 md:mb-0">
                                <i class="fas fa-list-ul text-blue-500 mr-2"></i>
                                All Events (<?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?>)
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <span class="ml-2 px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">All Batches</span>
                                <?php endif; ?>
                            </h3>
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-blue-100 text-blue-800 rounded-full text-xs md:text-sm font-medium">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $total_combined_events ?> events
                                <?php else: ?>
                                    <?= $total_events ?> events
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-4 md:p-6">
                        <?php 
                        // Get events for current month only
                        if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                            $month_events = array_filter($all_batches_events, function($event) use ($current_month, $current_year) {
                                $event_month = date('n', strtotime($event['date']));
                                $event_year = date('Y', strtotime($event['date']));
                                return $event_month == $current_month && $event_year == $current_year;
                            });
                        } else {
                            $month_events = array_filter($all_events, function($event) use ($current_month, $current_year) {
                                $event_month = date('n', strtotime($event['date']));
                                $event_year = date('Y', strtotime($event['date']));
                                return $event_month == $current_month && $event_year == $current_year;
                            });
                        }
                        
                        if(count($month_events) > 0): ?>
                            <?php 
                            $current_date = null;
                            $has_events = false;
                            
                            // Sort by date
                            usort($month_events, function($a, $b) {
                                return strtotime($a['date']) - strtotime($b['date']);
                            });
                            
                            foreach($month_events as $event): 
                                $event_date = $event['date'];
                                $display_date = date('l, F j, Y', strtotime($event_date));
                                
                                if ($event_date !== $current_date):
                                    $current_date = $event_date;
                                    if ($has_events): ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="border-l-4 
                                        <?php echo $event['type'] === 'class' ? 'border-blue-400' : 'border-purple-400'; ?> 
                                        <?php echo $event['status'] === 'completed' ? '!border-green-400' : ''; ?>
                                        <?php echo $event['status'] === 'missed' ? '!border-amber-400' : ''; ?>
                                        pl-3 md:pl-4 mb-3 md:mb-4">
                                        <h4 class="text-base md:text-lg font-semibold text-gray-800 mb-1 md:mb-2">
                                            <?php echo $display_date; ?>
                                            <?php if($event_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full ml-1 md:ml-2">Today</span>
                                            <?php elseif(strtotime($event_date) < strtotime(date('Y-m-d'))): ?>
                                                <span class="px-2 py-0.5 bg-amber-100 text-amber-800 text-xs rounded-full ml-1 md:ml-2">Past</span>
                                            <?php endif; ?>
                                            <?php if(date('N', strtotime($event_date)) == 7): ?>
                                                <span class="px-2 py-0.5 bg-pink-100 text-pink-800 text-xs rounded-full ml-1 md:ml-2">Sunday</span>
                                            <?php endif; ?>
                                        </h4>
                                    </div>
                                    <div class="mb-3 md:mb-4 ml-3 md:ml-4">
                                <?php 
                                $has_events = true;
                                endif; 
                            ?>
                            
                            <div class="schedule-card 
                                <?php echo $event['type']; ?> 
                                <?php echo $event['status']; ?>
                                <?php echo ($event_date == date('Y-m-d')) ? 'today' : ''; ?>
                                <?php echo (date('N', strtotime($event_date)) == 7) ? 'sunday' : ''; ?>
                                bg-white p-3 md:p-4 rounded-lg shadow-sm hover-lift mb-2 md:mb-3">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="flex-1">
                                        <div class="flex flex-wrap items-center mb-2 gap-1 md:gap-2">
                                            <h4 class="font-medium text-gray-900 mr-2 text-sm md:text-base">
                                                <?php 
                                                if ($event['type'] === 'class') {
                                                    echo htmlspecialchars($event['data']['topic']);
                                                } else {
                                                    echo htmlspecialchars($event['data']['exam_name']) . ' (' . htmlspecialchars($event['data']['subject']) . ')';
                                                }
                                                ?>
                                            </h4>
                                            <span class="px-2 py-0.5 text-xs rounded-md bg-blue-100 text-blue-800">
                                                <?php echo ucfirst($event['type']); ?>
                                            </span>
                                            
                                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full <?php 
                                                    echo $event['batch_id'] === $all_batches[0]['id'] ? 'bg-blue-100 text-blue-800' : 
                                                           ($event['batch_id'] === $all_batches[1]['id'] ? 'bg-green-100 text-green-800' : 
                                                           'bg-purple-100 text-purple-800'); ?>">
                                                    <?php echo htmlspecialchars($event['batch_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if($event['status'] === 'completed'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 flex items-center">
                                                    <i class="fas fa-check-circle mr-1"></i>Completed
                                                </span>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 flex items-center">
                                                    <i class="fas fa-user-check mr-1"></i>Present
                                                </span>
                                            <?php elseif($event['status'] === 'cancelled'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800">Cancelled</span>
                                            <?php elseif($event['status'] === 'missed'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800">Missed</span>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 flex items-center">
                                                    <i class="fas fa-user-clock mr-1"></i>No Attendance
                                                </span>
                                            <?php elseif($event['type'] === 'class' && $event['data']['is_cancelled']): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800">Cancelled</span>
                                            <?php elseif($event_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">Today</span>
                                            <?php endif; ?>
                                            
                                            <?php if($event['type'] === 'exam'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-800">
                                                    <?php echo ucfirst(str_replace('_', ' ', $event['data']['exam_type'])); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if(date('N', strtotime($event_date)) == 7): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-pink-100 text-pink-800 flex items-center">
                                                    <i class="fas fa-umbrella-beach mr-1"></i>Sunday
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-2 md:gap-4 text-xs md:text-sm text-gray-600">
                                            <?php if($event['type'] === 'class'): ?>
                                                <span class="flex items-center">
                                                    <i class="far fa-clock mr-1 md:mr-2"></i>
                                                    <?php echo date('g:i A', strtotime($event['data']['start_time'])) . ' - ' . date('g:i A', strtotime($event['data']['end_time'])); ?>
                                                </span>
                                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] != 'all'): ?>
                                                    <?php if($selected_batch['mode'] == 'online' && $selected_batch['meeting_link'] && !$event['data']['is_cancelled']): ?>
                                                        <span class="flex items-center">
                                                            <i class="fas fa-video mr-1 md:mr-2"></i>
                                                            Online Class
                                                        </span>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php 
                                                    $batch_mode = '';
                                                    foreach($all_batches as $batch) {
                                                        if($batch['id'] === $event['batch_id']) {
                                                            $batch_mode = $batch['mode'];
                                                            break;
                                                        }
                                                    }
                                                    if($batch_mode == 'online'): ?>
                                                        <span class="flex items-center">
                                                            <i class="fas fa-video mr-1 md:mr-2"></i>
                                                            Online Class
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="flex items-center">
                                                    <i class="far fa-calendar mr-1 md:mr-2"></i>
                                                    Exam Date
                                                </span>
                                                <span class="flex items-center">
                                                    <i class="fas fa-chart-bar mr-1 md:mr-2"></i>
                                                    Total Marks: <?php echo $event['data']['total_marks']; ?>
                                                </span>
                                                <span class="flex items-center">
                                                    <i class="fas fa-check-circle mr-1 md:mr-2"></i>
                                                    Passing: <?php echo $event['data']['passing_marks']; ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if($event['type'] === 'exam' && !empty($event['data']['exam_components'])): ?>
                                            <div class="flex flex-wrap gap-1 md:gap-2 mt-1 md:mt-2">
                                                <?php 
                                                $components = explode(',', $event['data']['exam_components']);
                                                foreach($components as $component): 
                                                    $component = trim($component);
                                                    if (!empty($component)): 
                                                ?>
                                                    <span class="px-1 md:px-2 py-0.5 bg-gray-100 text-gray-700 text-xs rounded"><?php echo ucfirst($component); ?></span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($event['data']['description'])): ?>
                                            <p class="text-gray-500 text-xs md:text-sm mt-1 md:mt-2"><?php echo htmlspecialchars($event['data']['description']); ?></p>
                                        <?php endif; ?>
                                        
                                        <?php if($event['type'] === 'class' && !empty($event['data']['cancellation_reason'])): ?>
                                            <p class="text-red-500 text-xs md:text-sm mt-1 md:mt-2">
                                                <i class="fas fa-exclamation-circle mr-1"></i>
                                                <?php echo htmlspecialchars($event['data']['cancellation_reason']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if($event['type'] === 'class'): ?>
                                        <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] != 'all'): ?>
                                            <?php if($selected_batch['mode'] == 'online' && $selected_batch['meeting_link'] && !$event['data']['is_cancelled'] && $event_date == date('Y-m-d')): ?>
                                                <div class="mt-3 md:mt-0">
                                                    <a href="<?php echo htmlspecialchars($selected_batch['meeting_link']); ?>" 
                                                       target="_blank"
                                                       class="w-full md:w-auto px-3 md:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors ripple flex items-center justify-center">
                                                        <i class="fas fa-video mr-1 md:mr-2"></i> Join Class
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php 
                                            $meeting_link = '';
                                            foreach($all_batches as $batch) {
                                                if($batch['id'] === $event['batch_id']) {
                                                    $meeting_link = $batch['meeting_link'];
                                                    break;
                                                }
                                            }
                                            if($meeting_link && !$event['data']['is_cancelled'] && $event_date == date('Y-m-d')): ?>
                                                <div class="mt-3 md:mt-0">
                                                    <a href="<?php echo htmlspecialchars($meeting_link); ?>" 
                                                       target="_blank"
                                                       class="w-full md:w-auto px-3 md:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors ripple flex items-center justify-center">
                                                        <i class="fas fa-video mr-1 md:mr-2"></i> Join Class
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php elseif($event['type'] === 'exam' && $event['data']['is_enrolled']): ?>
                                        <div class="mt-3 md:mt-0">
                                            <a href="exam_view.php?exam_id=<?php echo $event['data']['exam_id']; ?>" 
                                               class="w-full md:w-auto px-3 md:px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors ripple flex items-center justify-center">
                                                <i class="fas fa-eye mr-1 md:mr-2"></i> View Details
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if($has_events): ?>
                                </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-calendar-times text-gray-400 text-3xl md:text-4xl mb-3"></i>
                                <p class="text-gray-500 text-sm md:text-base">No events found for <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?></p>
                                <p class="text-gray-400 text-xs md:text-sm mt-1">Check other months or check back later for updates to your schedule</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- All Classes View -->
            <div id="view-classes" class="tab-content hidden">
                <div class="bg-white rounded-xl shadow-lg">
                    <div class="p-4 md:p-6 border-b border-gray-200">
                        <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800 flex items-center mb-2 md:mb-0">
                                <i class="fas fa-chalkboard-teacher text-blue-500 mr-2"></i>
                                All Classes (<?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?>)
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <span class="ml-2 px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">All Batches</span>
                                <?php endif; ?>
                            </h3>
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-blue-100 text-blue-800 rounded-full text-xs md:text-sm font-medium">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $completed_combined + $upcoming_combined + $missed_combined ?> classes
                                <?php else: ?>
                                    <?= count(array_filter($all_classes ?? [], function($c) use ($current_month, $current_year) {
                                        $class_month = date('n', strtotime($c['schedule_date']));
                                        $class_year = date('Y', strtotime($c['schedule_date']));
                                        return $class_month == $current_month && $class_year == $current_year;
                                    })); ?> classes
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-4 md:p-6">
                        <?php 
                        // Get classes for current month only
                        if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                            $month_classes = array_filter($all_batches_events, function($e) use ($current_month, $current_year) {
                                $event_month = date('n', strtotime($e['date']));
                                $event_year = date('Y', strtotime($e['date']));
                                return $e['type'] === 'class' && $event_month == $current_month && $event_year == $current_year;
                            });
                        } else {
                            $month_classes = array_filter($all_classes ?? [], function($c) use ($current_month, $current_year) {
                                $class_month = date('n', strtotime($c['schedule_date']));
                                $class_year = date('Y', strtotime($c['schedule_date']));
                                return $class_month == $current_month && $class_year == $current_year;
                            });
                        }
                        
                        if(count($month_classes) > 0): ?>
                            <?php 
                            $current_date = null;
                            $has_classes = false;
                            
                            // Sort classes by date
                            if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                                usort($month_classes, function($a, $b) {
                                    return strtotime($a['date']) - strtotime($b['date']);
                                });
                            } else {
                                usort($month_classes, function($a, $b) {
                                    return strtotime($a['schedule_date']) - strtotime($b['schedule_date']);
                                });
                            }
                            
                            foreach($month_classes as $class): 
                                if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                                    $class_date = $class['date'];
                                    $class_data = $class['data'];
                                    $class_status = $class['status'];
                                    $batch_name = $class['batch_name'];
                                } else {
                                    $class_date = $class['schedule_date'];
                                    $class_data = $class;
                                    $class_status = $class['class_status'];
                                }
                                $display_date = date('l, F j, Y', strtotime($class_date));
                                $day_of_week = date('N', strtotime($class_date));
                                
                                if ($class_date !== $current_date):
                                    $current_date = $class_date;
                                    if ($has_classes): ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="border-l-4 
                                        <?php echo $class_status === 'completed' ? 'border-green-400' : 
                                               ($class_status === 'missed' ? 'border-amber-400' : 
                                               (isset($class_data['is_cancelled']) && $class_data['is_cancelled'] ? 'border-red-400' : 'border-blue-400')); ?> 
                                        pl-3 md:pl-4 mb-3 md:mb-4">
                                        <h4 class="text-base md:text-lg font-semibold text-gray-800 mb-1 md:mb-2">
                                            <?php echo $display_date; ?>
                                            <?php if($class_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full ml-1 md:ml-2">Today</span>
                                            <?php elseif(strtotime($class_date) < strtotime(date('Y-m-d'))): ?>
                                                <span class="px-2 py-0.5 bg-amber-100 text-amber-800 text-xs rounded-full ml-1 md:ml-2">Past</span>
                                            <?php endif; ?>
                                            <?php if($day_of_week == 7): ?>
                                                <span class="px-2 py-0.5 bg-pink-100 text-pink-800 text-xs rounded-full ml-1 md:ml-2">Sunday</span>
                                            <?php endif; ?>
                                        </h4>
                                    </div>
                                    <div class="mb-3 md:mb-4 ml-3 md:ml-4">
                                <?php 
                                $has_classes = true;
                                endif; 
                            ?>
                            
                            <div class="schedule-card class 
                                <?php echo $class_status; ?>
                                <?php echo $class_date == date('Y-m-d') ? 'today' : ''; ?>
                                <?php echo $day_of_week == 7 ? 'sunday' : ''; ?>
                                bg-white p-3 md:p-4 rounded-lg shadow-sm hover-lift mb-2 md:mb-3">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="flex-1">
                                        <div class="flex flex-wrap items-center mb-2 gap-1 md:gap-2">
                                            <h4 class="font-medium text-gray-900 mr-2 text-sm md:text-base">
                                                <?php echo htmlspecialchars($class_data['topic']); ?>
                                            </h4>
                                            
                                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full <?php 
                                                    echo $class['batch_id'] === $all_batches[0]['id'] ? 'bg-blue-100 text-blue-800' : 
                                                           ($class['batch_id'] === $all_batches[1]['id'] ? 'bg-green-100 text-green-800' : 
                                                           'bg-purple-100 text-purple-800'); ?>">
                                                    <?php echo htmlspecialchars($batch_name); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if($class_status === 'completed'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 flex items-center">
                                                    <i class="fas fa-check-circle mr-1"></i>Completed
                                                </span>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-800 flex items-center">
                                                    <i class="fas fa-user-check mr-1"></i>Present
                                                </span>
                                            <?php elseif($class_status === 'cancelled' || (isset($class_data['is_cancelled']) && $class_data['is_cancelled'])): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-red-100 text-red-800">Cancelled</span>
                                            <?php elseif($class_status === 'missed'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800">Missed</span>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-amber-100 text-amber-800 flex items-center">
                                                    <i class="fas fa-user-clock mr-1"></i>No Attendance
                                                </span>
                                            <?php elseif($class_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">Today</span>
                                            <?php endif; ?>
                                            
                                            <?php if($day_of_week == 7): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-pink-100 text-pink-800 flex items-center">
                                                    <i class="fas fa-umbrella-beach mr-1"></i>Sunday
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-2 md:gap-4 text-xs md:text-sm text-gray-600">
                                            <span class="flex items-center">
                                                <i class="far fa-clock mr-1 md:mr-2"></i>
                                                <?php echo date('g:i A', strtotime($class_data['start_time'])) . ' - ' . date('g:i A', strtotime($class_data['end_time'])); ?>
                                            </span>
                                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] != 'all'): ?>
                                                <?php if($selected_batch['mode'] == 'online' && $selected_batch['meeting_link'] && !$class_data['is_cancelled']): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-video mr-1 md:mr-2"></i>
                                                        Online Class
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php 
                                                $batch_mode = '';
                                                foreach($all_batches as $batch) {
                                                    if($batch['id'] === $class['batch_id']) {
                                                        $batch_mode = $batch['mode'];
                                                        break;
                                                    }
                                                }
                                                if($batch_mode == 'online'): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-video mr-1 md:mr-2"></i>
                                                        Online Class
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if(!empty($class_data['description'])): ?>
                                            <p class="text-gray-500 text-xs md:text-sm mt-1 md:mt-2"><?php echo htmlspecialchars($class_data['description']); ?></p>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($class_data['cancellation_reason'])): ?>
                                            <p class="text-red-500 text-xs md:text-sm mt-1 md:mt-2">
                                                <i class="fas fa-exclamation-circle mr-1"></i>
                                                <?php echo htmlspecialchars($class_data['cancellation_reason']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] != 'all'): ?>
                                        <?php if($selected_batch['mode'] == 'online' && $selected_batch['meeting_link'] && !$class_data['is_cancelled'] && $class_date == date('Y-m-d')): ?>
                                            <div class="mt-3 md:mt-0">
                                                <a href="<?php echo htmlspecialchars($selected_batch['meeting_link']); ?>" 
                                                   target="_blank"
                                                   class="w-full md:w-auto px-3 md:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors ripple flex items-center justify-center">
                                                    <i class="fas fa-video mr-1 md:mr-2"></i> Join Class
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php 
                                        $meeting_link = '';
                                        foreach($all_batches as $batch) {
                                            if($batch['id'] === $class['batch_id']) {
                                                $meeting_link = $batch['meeting_link'];
                                                break;
                                            }
                                        }
                                        if($meeting_link && !$class_data['is_cancelled'] && $class_date == date('Y-m-d')): ?>
                                            <div class="mt-3 md:mt-0">
                                                <a href="<?php echo htmlspecialchars($meeting_link); ?>" 
                                                   target="_blank"
                                                   class="w-full md:w-auto px-3 md:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors ripple flex items-center justify-center">
                                                    <i class="fas fa-video mr-1 md:mr-2"></i> Join Class
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if($has_classes): ?>
                                </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-chalkboard-teacher text-gray-400 text-3xl md:text-4xl mb-3"></i>
                                <p class="text-gray-500 text-sm md:text-base">No classes found for <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?></p>
                                <p class="text-gray-400 text-xs md:text-sm mt-1">Check other months or check back later for updates to your schedule</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Upcoming Classes Only View -->
            <div id="view-upcoming" class="tab-content hidden">
                <div class="bg-white rounded-xl shadow-lg">
                    <div class="p-4 md:p-6 border-b border-gray-200">
                        <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800 flex items-center mb-2 md:mb-0">
                                <i class="fas fa-calendar-plus text-blue-500 mr-2"></i>
                                Upcoming Classes (<?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?>)
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <span class="ml-2 px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">All Batches</span>
                                <?php endif; ?>
                            </h3>
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-blue-100 text-blue-800 rounded-full text-xs md:text-sm font-medium">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $upcoming_combined ?> classes
                                <?php else: ?>
                                    <?= $upcoming_class_count ?> classes
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-4 md:p-6">
                        <?php if((isset($_GET['batch_index']) && $_GET['batch_index'] == 'all' && $upcoming_combined > 0) || $upcoming_class_count > 0): ?>
                            <?php 
                            $current_date = null;
                            $has_classes = false;
                            
                            // Filter only upcoming classes for current month
                            if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                                $upcoming_only = array_filter($all_batches_events, function($e) use ($current_month, $current_year) { 
                                    $event_month = date('n', strtotime($e['date']));
                                    $event_year = date('Y', strtotime($e['date']));
                                    return $e['type'] === 'class' && $event_month == $current_month && $event_year == $current_year && 
                                           $e['status'] === 'upcoming' && !$e['data']['is_cancelled']; 
                                });
                            } else {
                                $upcoming_only = array_filter($all_classes ?? [], function($c) use ($current_month, $current_year) { 
                                    $class_month = date('n', strtotime($c['schedule_date']));
                                    $class_year = date('Y', strtotime($c['schedule_date']));
                                    return $class_month == $current_month && $class_year == $current_year && 
                                           $c['class_status'] === 'upcoming' && !$c['is_cancelled']; 
                                });
                            }
                            
                            // Sort by date
                            if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                                usort($upcoming_only, function($a, $b) {
                                    return strtotime($a['date']) - strtotime($b['date']);
                                });
                            } else {
                                usort($upcoming_only, function($a, $b) {
                                    return strtotime($a['schedule_date']) - strtotime($b['schedule_date']);
                                });
                            }
                            
                            foreach($upcoming_only as $class): 
                                if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                                    $class_date = $class['date'];
                                    $class_data = $class['data'];
                                    $batch_name = $class['batch_name'];
                                } else {
                                    $class_date = $class['schedule_date'];
                                    $class_data = $class;
                                }
                                $display_date = date('l, F j, Y', strtotime($class_date));
                                $day_of_week = date('N', strtotime($class_date));
                                
                                if ($class_date !== $current_date):
                                    $current_date = $class_date;
                                    if ($has_classes): ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="border-l-4 border-blue-400 pl-3 md:pl-4 mb-3 md:mb-4">
                                        <h4 class="text-base md:text-lg font-semibold text-gray-800 mb-1 md:mb-2">
                                            <?php echo $display_date; ?>
                                            <?php if($class_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full ml-1 md:ml-2">Today</span>
                                            <?php endif; ?>
                                            <?php if($day_of_week == 7): ?>
                                                <span class="px-2 py-0.5 bg-pink-100 text-pink-800 text-xs rounded-full ml-1 md:ml-2">Sunday</span>
                                            <?php endif; ?>
                                        </h4>
                                    </div>
                                    <div class="mb-3 md:mb-4 ml-3 md:ml-4">
                                <?php 
                                $has_classes = true;
                                endif; 
                            ?>
                            
                            <div class="schedule-card class 
                                <?php echo $class_date == date('Y-m-d') ? 'today' : ''; ?>
                                <?php echo $day_of_week == 7 ? 'sunday' : ''; ?>
                                bg-white p-3 md:p-4 rounded-lg shadow-sm hover-lift mb-2 md:mb-3">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="flex-1">
                                        <div class="flex flex-wrap items-center mb-2 gap-1 md:gap-2">
                                            <h4 class="font-medium text-gray-900 mr-2 text-sm md:text-base"><?php echo htmlspecialchars($class_data['topic']); ?></h4>
                                            
                                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full <?php 
                                                    echo $class['batch_id'] === $all_batches[0]['id'] ? 'bg-blue-100 text-blue-800' : 
                                                           ($class['batch_id'] === $all_batches[1]['id'] ? 'bg-green-100 text-green-800' : 
                                                           'bg-purple-100 text-purple-800'); ?>">
                                                    <?php echo htmlspecialchars($batch_name); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if($day_of_week == 7): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-pink-100 text-pink-800 flex items-center">
                                                    <i class="fas fa-umbrella-beach mr-1"></i>Sunday
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if($class_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">Today</span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-2 md:gap-4 text-xs md:text-sm text-gray-600">
                                            <span class="flex items-center">
                                                <i class="far fa-clock mr-1 md:mr-2"></i>
                                                <?php echo date('g:i A', strtotime($class_data['start_time'])) . ' - ' . date('g:i A', strtotime($class_data['end_time'])); ?>
                                            </span>
                                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] != 'all'): ?>
                                                <?php if($selected_batch['mode'] == 'online' && $selected_batch['meeting_link']): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-video mr-1 md:mr-2"></i>
                                                        Online Class
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php 
                                                $batch_mode = '';
                                                foreach($all_batches as $batch) {
                                                    if($batch['id'] === $class['batch_id']) {
                                                        $batch_mode = $batch['mode'];
                                                        break;
                                                    }
                                                }
                                                if($batch_mode == 'online'): ?>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-video mr-1 md:mr-2"></i>
                                                        Online Class
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if(!empty($class_data['description'])): ?>
                                            <p class="text-gray-500 text-xs md:text-sm mt-1 md:mt-2"><?php echo htmlspecialchars($class_data['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] != 'all'): ?>
                                        <?php if($selected_batch['mode'] == 'online' && $selected_batch['meeting_link'] && $class_date == date('Y-m-d')): ?>
                                            <div class="mt-3 md:mt-0">
                                                <a href="<?php echo htmlspecialchars($selected_batch['meeting_link']); ?>" 
                                                   target="_blank"
                                                   class="w-full md:w-auto px-3 md:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors ripple flex items-center justify-center">
                                                    <i class="fas fa-video mr-1 md:mr-2"></i> Join Class
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php 
                                        $meeting_link = '';
                                        foreach($all_batches as $batch) {
                                            if($batch['id'] === $class['batch_id']) {
                                                $meeting_link = $batch['meeting_link'];
                                                break;
                                            }
                                        }
                                        if($meeting_link && $class_date == date('Y-m-d')): ?>
                                            <div class="mt-3 md:mt-0">
                                                <a href="<?php echo htmlspecialchars($meeting_link); ?>" 
                                                   target="_blank"
                                                   class="w-full md:w-auto px-3 md:px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors ripple flex items-center justify-center">
                                                    <i class="fas fa-video mr-1 md:mr-2"></i> Join Class
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if($has_classes): ?>
                                </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-chalkboard-teacher text-gray-400 text-3xl md:text-4xl mb-3"></i>
                                <p class="text-gray-500 text-sm md:text-base">No upcoming classes for <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?></p>
                                <p class="text-gray-400 text-xs md:text-sm mt-1">Check other months or check back later for updates to your schedule</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Exams Only View -->
            <div id="view-exams" class="tab-content hidden">
                <div class="bg-white rounded-xl shadow-lg">
                    <div class="p-4 md:p-6 border-b border-gray-200">
                        <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                            <h3 class="text-base md:text-lg font-semibold text-gray-800 flex items-center mb-2 md:mb-0">
                                <i class="fas fa-file-alt text-purple-500 mr-2"></i>
                                Exams (<?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?>)
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <span class="ml-2 px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">All Batches</span>
                                <?php endif; ?>
                            </h3>
                            <span class="px-2 py-1 md:px-3 md:py-1 bg-purple-100 text-purple-800 rounded-full text-xs md:text-sm font-medium">
                                <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                    <?= $exams_combined ?> exams
                                <?php else: ?>
                                    <?= $exam_count ?> exams
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="p-4 md:p-6">
                        <?php if((isset($_GET['batch_index']) && $_GET['batch_index'] == 'all' && $exams_combined > 0) || $exam_count > 0): ?>
                            <?php 
                            $current_date = null;
                            $has_exams = false;
                            
                            if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                                $enrolled_exams = array_filter($all_batches_events, function($e) use ($current_month, $current_year) { 
                                    $event_month = date('n', strtotime($e['date']));
                                    $event_year = date('Y', strtotime($e['date']));
                                    return $e['type'] === 'exam' && $event_month == $current_month && $event_year == $current_year;
                                });
                            } else {
                                $enrolled_exams = array_filter($all_exams ?? [], function($e) use ($current_month, $current_year) { 
                                    if (!$e['is_enrolled']) return false;
                                    $exam_month = date('n', strtotime($e['exam_date']));
                                    $exam_year = date('Y', strtotime($e['exam_date']));
                                    return $exam_month == $current_month && $exam_year == $current_year;
                                });
                            }
                            
                            foreach($enrolled_exams as $exam): 
                                if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all') {
                                    $exam_date = $exam['date'];
                                    $exam_data = $exam['data'];
                                    $batch_name = $exam['batch_name'];
                                } else {
                                    $exam_date = $exam['exam_date'];
                                    $exam_data = $exam;
                                }
                                $display_date = date('l, F j, Y', strtotime($exam_date));
                                
                                if ($exam_date !== $current_date):
                                    $current_date = $exam_date;
                                    if ($has_exams): ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="border-l-4 border-purple-400 pl-3 md:pl-4 mb-3 md:mb-4">
                                        <h4 class="text-base md:text-lg font-semibold text-gray-800 mb-1 md:mb-2">
                                            <?php echo $display_date; ?>
                                            <?php if($exam_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full ml-1 md:ml-2">Today</span>
                                            <?php endif; ?>
                                        </h4>
                                    </div>
                                    <div class="mb-3 md:mb-4 ml-3 md:ml-4">
                                <?php 
                                $has_exams = true;
                                endif; 
                            ?>
                            
                            <div class="schedule-card exam <?php echo $exam_date == date('Y-m-d') ? 'today' : ''; ?> bg-white p-3 md:p-4 rounded-lg shadow-sm hover-lift mb-2 md:mb-3">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="flex-1">
                                        <div class="flex flex-wrap items-center mb-2 gap-1 md:gap-2">
                                            <h4 class="font-medium text-gray-900 mr-2 text-sm md:text-base">
                                                <?php echo htmlspecialchars($exam_data['exam_name']) . ' (' . htmlspecialchars($exam_data['subject']) . ')'; ?>
                                            </h4>
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-800">Exam</span>
                                            
                                            <?php if(isset($_GET['batch_index']) && $_GET['batch_index'] == 'all'): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full <?php 
                                                    echo $exam['batch_id'] === $all_batches[0]['id'] ? 'bg-blue-100 text-blue-800' : 
                                                           ($exam['batch_id'] === $all_batches[1]['id'] ? 'bg-green-100 text-green-800' : 
                                                           'bg-purple-100 text-purple-800'); ?>">
                                                    <?php echo htmlspecialchars($batch_name); ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if($exam_date == date('Y-m-d')): ?>
                                                <span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-800">Today</span>
                                            <?php endif; ?>
                                            
                                            <span class="px-2 py-0.5 text-xs rounded-full bg-purple-100 text-purple-800">
                                                <?php echo ucfirst(str_replace('_', ' ', $exam_data['exam_type'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="flex flex-wrap gap-2 md:gap-4 text-xs md:text-sm text-gray-600">
                                            <span class="flex items-center">
                                                <i class="far fa-calendar mr-1 md:mr-2"></i>
                                                Exam Date
                                            </span>
                                            <span class="flex items-center">
                                                <i class="fas fa-chart-bar mr-1 md:mr-2"></i>
                                                Total Marks: <?php echo $exam_data['total_marks']; ?>
                                            </span>
                                            <span class="flex items-center">
                                                <i class="fas fa-check-circle mr-1 md:mr-2"></i>
                                                Passing: <?php echo $exam_data['passing_marks']; ?>
                                            </span>
                                        </div>
                                        
                                        <?php if(!empty($exam_data['exam_components'])): ?>
                                            <div class="flex flex-wrap gap-1 md:gap-2 mt-1 md:mt-2">
                                                <?php 
                                                $components = explode(',', $exam_data['exam_components']);
                                                foreach($components as $component): 
                                                    $component = trim($component);
                                                    if (!empty($component)): 
                                                ?>
                                                    <span class="px-1 md:px-2 py-0.5 bg-gray-100 text-gray-700 text-xs rounded"><?php echo ucfirst($component); ?></span>
                                                <?php 
                                                    endif;
                                                endforeach; 
                                                ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($exam_data['description'])): ?>
                                            <p class="text-gray-500 text-xs md:text-sm mt-1 md:mt-2"><?php echo htmlspecialchars($exam_data['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="mt-3 md:mt-0">
                                        <a href="exam_view.php?exam_id=<?php echo $exam_data['exam_id']; ?>" 
                                           class="w-full md:w-auto px-3 md:px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors ripple flex items-center justify-center">
                                            <i class="fas fa-eye mr-1 md:mr-2"></i> View Details
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if($has_exams): ?>
                                </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-6 md:py-8">
                                <i class="fas fa-file-alt text-gray-400 text-3xl md:text-4xl mb-3"></i>
                                <p class="text-gray-500 text-sm md:text-base">No exams found for <?php echo date('F Y', mktime(0, 0, 0, $current_month, 1, $current_year)); ?></p>
                                <p class="text-gray-400 text-xs md:text-sm mt-1">Check other months or check back later for exam schedule updates</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- No Batch Assigned -->
            <div class="bg-white rounded-xl shadow-lg p-6 md:p-8 text-center">
                <i class="fas fa-users-slash text-gray-400 text-3xl md:text-4xl mb-4"></i>
                <h3 class="text-lg md:text-xl font-semibold text-gray-800 mb-2">No Batch Assigned</h3>
                <p class="text-gray-600 text-sm md:text-base">You are not currently assigned to any batch.</p>
                <p class="text-gray-500 text-xs md:text-sm mt-2">Please contact the administrator to get assigned to a batch.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Event Details Popup -->
<div id="eventPopup" class="event-popup hidden w-full max-w-md">
    <div class="bg-white rounded-xl shadow-2xl p-4 md:p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 id="popupTitle" class="text-lg md:text-xl font-semibold text-gray-800"></h3>
            <button onclick="closeEventPopup()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="popupContent" class="space-y-3"></div>
        <div class="mt-4 md:mt-6 flex justify-end">
            <button onclick="closeEventPopup()" class="px-3 md:px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300 transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<div id="overlay" class="overlay hidden"></div>
<style>
    /* Navy header styling */
    header {
        background: linear-gradient(135deg, rgba(27, 60, 83, 0.92) 0%, rgba(35, 76, 106, 0.92) 60%, rgba(69, 104, 130, 0.92) 100%) !important;
        backdrop-filter: blur(12px) !important;
        -webkit-backdrop-filter: blur(12px) !important;
        box-shadow: 0 4px 20px rgba(27, 60, 83, 0.25) !important;
    }
    header h1, header .text-gray-800 { color: #fff !important; }
    header .text-indigo-600          { color: rgba(255, 255, 255, 0.85) !important; }
    header .bg-indigo-100            { background: rgba(255, 255, 255, 0.2) !important; }
    header .animate-pulse.bg-indigo-100 { background: rgba(255, 255, 255, 0.2) !important; }
    header .text-indigo-800          { color: #fff !important; }
    header button                    { color: #fff !important; }

    /* Glassmorphic calendar grid with navy palette */
    body {
        background: linear-gradient(135deg, #eef2f5 0%, #f5f0ed 40%, #ede8e4 100%) !important;
    }
    
    .glass-card {
        background: rgba(255, 255, 255, 0.75) !important;
        backdrop-filter: blur(16px) !important;
        -webkit-backdrop-filter: blur(16px) !important;
        border: 1px solid rgba(69, 104, 130, 0.25) !important;
        box-shadow: 0 10px 30px -5px rgba(27, 60, 83, 0.08) !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .glass-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 20px 40px -10px rgba(27, 60, 83, 0.15) !important;
    }

    /* Enhanced Calendar container card wrapper */
    .bg-white.rounded-xl.shadow-lg.mb-8.fade-in {
        background: rgba(255, 255, 255, 0.75) !important;
        backdrop-filter: blur(16px) !important;
        -webkit-backdrop-filter: blur(16px) !important;
        border: 1px solid rgba(69, 104, 130, 0.25) !important;
        box-shadow: 0 10px 30px -5px rgba(27, 60, 83, 0.08) !important;
    }

    .calendar-day {
        background-color: rgba(255, 255, 255, 0.45) !important;
        backdrop-filter: blur(8px) !important;
        border: 1px solid rgba(69, 104, 130, 0.15) !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        min-height: 100px;
        position: relative;
        border-radius: 12px !important;
    }
    
    .calendar-day:hover {
        background-color: rgba(255, 255, 255, 0.95) !important;
        border-color: #1B3C53 !important;
        box-shadow: 0 12px 24px -8px rgba(27, 60, 83, 0.2), 0 4px 12px -2px rgba(27, 60, 83, 0.08) !important;
        transform: translateY(-3px) scale(1.02) !important;
        z-index: 10 !important;
    }
    
    /* Today indicator */
    .calendar-day.today {
        border: 2px solid #1B3C53 !important;
        background-color: rgba(238, 242, 245, 0.9) !important;
        box-shadow: 0 0 0 4px rgba(27, 60, 83, 0.15) !important;
    }
    
    .calendar-day.today::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #1B3C53, #456882);
        border-radius: 4px 4px 0 0;
    }
    
    /* Calendar Event Items visual elevation */
    .calendar-event-item {
        position: relative;
        padding: 4px 8px;
        border-radius: 8px;
        margin-bottom: 4px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.25s ease-in-out;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
    
    .calendar-event-item:hover {
        transform: translateX(4px) scale(1.03);
        filter: brightness(1.05);
        box-shadow: 0 6px 12px -2px rgba(0, 0, 0, 0.1);
    }
    
    /* Prevent tooltip clipping on hover without changing layout height */
    .calendar-day:hover .overflow-y-auto,
    .calendar-day:focus-within .overflow-y-auto {
        overflow: visible !important;
    }
    
    /* Premium CSS Tooltip for hovering details preview */
    .calendar-event-item::after {
        content: attr(data-tooltip);
        position: absolute;
        bottom: 125%;
        left: 50%;
        transform: translateX(-50%) translateY(6px);
        background: rgba(17, 24, 39, 0.95);
        color: #f9fafb;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 10px;
        line-height: 1.4;
        white-space: pre-wrap;
        width: 200px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.2), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
        opacity: 0;
        pointer-events: none;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 100;
        border: 1px solid rgba(255, 255, 255, 0.12);
        backdrop-filter: blur(8px);
    }
    
    .calendar-event-item:hover::after {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    /* Event color themes classes override with soft gradients */
    .calendar-event-item.event-class {
        background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%) !important;
        color: #0369a1 !important;
        border-left: 4px solid #0ea5e9 !important;
    }
    .calendar-event-item.event-exam {
        background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%) !important;
        color: #b91c1c !important;
        border-left: 4px solid #f43f5e !important;
    }
    .calendar-event-item.event-completed {
        background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%) !important;
        color: #047857 !important;
        border-left: 4px solid #10b981 !important;
    }
    .calendar-event-item.event-missed {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%) !important;
        color: #b45309 !important;
        border-left: 4px solid #f59e0b !important;
    }
    .calendar-event-item.event-cancelled {
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%) !important;
        color: #475569 !important;
        border-left: 4px solid #64748b !important;
    }

    /* Premium Glassmorphic Event Pop-up Modal */
    #eventPopup {
        position: fixed;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%) scale(0.92);
        z-index: 100;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        opacity: 0;
        pointer-events: none;
    }
    
    #eventPopup.active {
        transform: translate(-50%, -50%) scale(1);
        opacity: 1;
        pointer-events: auto;
    }
    
    #eventPopup > div {
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.5);
        border-radius: 24px;
        box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.2);
    }
    
    /* Overlay blur backdrop */
    .overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(15, 23, 42, 0.4);
        backdrop-filter: blur(8px);
        z-index: 90;
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
    }
    
    .overlay.active {
        opacity: 1;
        pointer-events: auto;
    }
    
    /* Tab button custom sliding underline transitions */
    .tab-btn {
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        font-weight: 600;
    }
    
    .tab-btn:hover {
        transform: translateY(-2px);
        text-shadow: 0 0 12px rgba(13, 148, 136, 0.3);
    }
    
    .tab-btn span {
        transition: all 0.25s ease;
    }
    
    .tab-btn:hover span {
        transform: scale(1.1);
    }

    /* Batch Header Card glowing effects and pill hover animation */
    .batch-header-card {
        transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }
    
    .batch-header-card:hover {
        box-shadow: 0 20px 40px -10px rgba(13, 148, 136, 0.3), 0 10px 20px -10px rgba(79, 70, 229, 0.15);
        border-color: rgba(255, 255, 255, 0.5);
        transform: translateY(-4px);
    }
    
    .batch-header-card span {
        transition: all 0.25s ease;
    }
    
    .batch-header-card span:hover {
        background-color: rgba(255, 255, 255, 0.3) !important;
        box-shadow: 0 0 15px rgba(255, 255, 255, 0.5);
        transform: scale(1.08);
    }
    
    /* Hover Lift Elements styling and unique subtle colors */
    .hover-lift {
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 1px solid rgba(229, 231, 235, 0.5);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.6) !important;
        backdrop-filter: blur(8px) !important;
    }
    
    .hover-lift:hover {
        transform: translateY(-8px);
        background: rgba(255, 255, 255, 0.95) !important;
        box-shadow: 0 20px 30px -8px rgba(13, 148, 136, 0.15), 0 10px 15px -5px rgba(13, 148, 136, 0.08);
        border-color: rgba(13, 148, 136, 0.4);
    }
    
    /* Unique Gradient backgrounds for Stats Cards */
    .grid > .hover-lift:nth-child(1) { background: linear-gradient(135deg, rgba(255,255,255,0.7) 70%, rgba(245,243,255,0.9) 100%) !important; } /* Total */
    .grid > .hover-lift:nth-child(2) { background: linear-gradient(135deg, rgba(255,255,255,0.7) 70%, rgba(240,253,244,0.9) 100%) !important; } /* Completed */
    .grid > .hover-lift:nth-child(3) { background: linear-gradient(135deg, rgba(255,255,255,0.7) 70%, rgba(239,246,255,0.9) 100%) !important; } /* Upcoming */
    .grid > .hover-lift:nth-child(4) { background: linear-gradient(135deg, rgba(255,255,255,0.7) 70%, rgba(253,242,248,0.9) 100%) !important; } /* Exams */
    .grid > .hover-lift:nth-child(5) { background: linear-gradient(135deg, rgba(255,255,255,0.7) 70%, rgba(255,251,235,0.9) 100%) !important; } /* Missed */
    
    /* Custom button grading and active shadows */
    button, a.month-navigation {
        border-radius: 10px !important;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }
    
    button:hover, a.month-navigation:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(13, 148, 136, 0.15);
    }
    
    .tab-btn span {
        transition: all 0.25s ease;
    }
    
    .tab-btn:hover span {
        transform: scale(1.08);
    }

    /* Batch Header Card glowing effects and pill hover animation */
    .batch-header-card {
        transition: all 0.4s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 1px solid rgba(255, 255, 255, 0.15);
    }
    
    .batch-header-card:hover {
        box-shadow: 0 20px 35px -10px rgba(13, 148, 136, 0.35), 0 10px 20px -10px rgba(79, 70, 229, 0.2);
        border-color: rgba(255, 255, 255, 0.35);
        transform: translateY(-2px);
    }
    
    .batch-header-card span {
        transition: all 0.25s ease;
    }
    
    .batch-header-card span:hover {
        background-color: rgba(255, 255, 255, 0.3) !important;
        box-shadow: 0 0 12px rgba(255, 255, 255, 0.4);
        transform: scale(1.05);
    }
    
    /* Hover Lift Elements styling and unique subtle colors */
    .hover-lift {
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 1px solid rgba(229, 231, 235, 0.7);
    }
    
    .hover-lift:hover {
        transform: translateY(-6px);
        box-shadow: 0 20px 25px -5px rgba(13, 148, 136, 0.12), 0 10px 10px -5px rgba(13, 148, 136, 0.06);
        border-color: rgba(13, 148, 136, 0.35);
    }
    
    /* Unique Gradient backgrounds for Stats Cards */
    .grid > .hover-lift:nth-child(1) { background: linear-gradient(135deg, #ffffff 75%, #D2C1B6 100%); } /* Total (Warm Beige Theme) */
    .grid > .hover-lift:nth-child(2) { background: linear-gradient(135deg, #ffffff 75%, #f0fdf4 100%); } /* Completed (Green Theme) */
    .grid > .hover-lift:nth-child(3) { background: linear-gradient(135deg, #ffffff 75%, #eff6ff 100%); } /* Upcoming (Blue Theme) */
    .grid > .hover-lift:nth-child(4) { background: linear-gradient(135deg, #ffffff 75%, #fdf2f8 100%); } /* Exams (Pink/Red Theme) */
    .grid > .hover-lift:nth-child(5) { background: linear-gradient(135deg, #ffffff 75%, #fffbeb 100%); } /* Missed (Amber Theme) */

    /* Glass card effect for batch selection */
    .glass-card {
        background: rgba(255, 255, 255, 0.85) !important;
        backdrop-filter: blur(12px) !important;
        border-radius: 16px !important;
        border: 1px solid rgba(69, 104, 130, 0.3) !important;
        box-shadow: 0 8px 32px rgba(27, 60, 83, 0.08) !important;
    }

    /* All Events Tab Content container card */
    .tab-content .bg-white.rounded-xl.shadow-lg {
        background: rgba(255, 255, 255, 0.75) !important;
        backdrop-filter: blur(16px) !important;
        -webkit-backdrop-filter: blur(16px) !important;
        border: 1px solid rgba(69, 104, 130, 0.3) !important;
        box-shadow: 0 10px 30px -5px rgba(27, 60, 83, 0.08) !important;
    }

    /* Individual Event Sub-cards inside list columns */
    .schedule-card.hover-lift {
        background: rgba(255, 255, 255, 0.5) !important;
        border: 1px solid rgba(69, 104, 130, 0.18) !important;
        border-radius: 12px !important;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        box-shadow: 0 4px 12px rgba(27, 60, 83, 0.02) !important;
    }
    .schedule-card.hover-lift:hover {
        background: rgba(255, 255, 255, 0.95) !important;
        transform: translateY(-4px) !important;
        border-color: rgba(27, 60, 83, 0.45) !important;
        box-shadow: 0 12px 24px -8px rgba(27, 60, 83, 0.15) !important;
    }

    /* Event Category Border indicators */
    .border-l-4.border-blue-400 {
        border-left-color: #0ea5e9 !important; /* class: teal */
    }
    .border-l-4.border-purple-400 {
        border-left-color: #456882 !important; /* exam: steel blue */
    }
    .border-l-4.\!border-green-400 {
        border-left-color: #10b981 !important; /* completed: emerald */
    }
    .border-l-4.\!border-amber-400 {
        border-left-color: #f59e0b !important; /* missed: amber */
    }

    /* Date column headings styling */
    .border-l-4 h4 {
        color: #1B3C53 !important;
        font-weight: 700 !important;
    }

    /* Event status & type Badges colors overrides */
    .schedule-card span[class*="bg-blue-100"] {
        background: #e0f2fe !important;
        color: #0369a1 !important;
        border: .5px solid #bae6fd !important;
    }
    .schedule-card span[class*="bg-green-100"] {
        background: #ecfdf5 !important;
        color: #047857 !important;
        border: .5px solid #a7f3d0 !important;
    }
    .schedule-card span[class*="bg-red-100"] {
        background: #f1f5f9 !important; /* slate/gray cancelled */
        color: #475569 !important;
        border: .5px solid #cbd5e1 !important;
    }
    .schedule-card span[class*="bg-amber-100"] {
        background: #fffbeb !important;
        color: #b45309 !important;
        border: .5px solid #fde68a !important;
    }
    .schedule-card span[class*="bg-purple-100"] {
        background: #fdf2f8 !important; /* exam: pink/rose */
        color: #b91c1c !important;
        border: .5px solid #fecdd3 !important;
    }
    .schedule-card span[class*="bg-pink-100"] {
        background: rgba(210, 193, 182, 0.2) !important;
        color: #234C6A !important;
        border: .5px solid rgba(69, 104, 130, 0.3) !important;
    }

    /* Event Card Action Buttons styling overrides */
    .schedule-card a[class*="bg-purple-600"], .schedule-card button[class*="bg-blue-600"], .schedule-card a[class*="bg-blue-600"] {
        background: linear-gradient(135deg, #456882, #1B3C53) !important;
        color: #fff !important;
        border: none !important;
        transition: all 0.3s ease !important;
    }
    .schedule-card a[class*="bg-purple-600"]:hover, .schedule-card button[class*="bg-blue-600"]:hover, .schedule-card a[class*="bg-blue-600"]:hover {
        background: linear-gradient(135deg, #234C6A, #1B3C53) !important;
        transform: translateY(-2px) !important;
        box-shadow: 0 4px 12px rgba(27, 60, 83, 0.3) !important;
    }

    /* =====================================================
       DYNAMIC BATCH THEMES — Premium Overrides
       ===================================================== */
    /* Page Navigation Headers */
    header.bg-gradient-to-r {
        background: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%) !important;
    }
    header.bg-gradient-to-r h1, header.bg-gradient-to-r span, header.bg-gradient-to-r i {
        color: #ffffff !important;
    }
    header.bg-gradient-to-r div[class*="bg-indigo-100"] {
        background: rgba(210, 193, 182, 0.25) !important;
    }

    /* Active Batch selector tab buttons dynamic colors */
    .btn-batch-select-0.active {
        background: linear-gradient(135deg, #1B3C53 0%, #456882 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(27, 60, 83, 0.25) !important;
    }
    .btn-batch-select-0.active * { color: #ffffff !important; }

    .btn-batch-select-1.active {
        background: linear-gradient(135deg, #234C6A 0%, #456882 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(35, 76, 106, 0.25) !important;
    }
    .btn-batch-select-1.active * { color: #ffffff !important; }

    .btn-batch-select-2.active {
        background: linear-gradient(135deg, #456882 0%, #D2C1B6 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(69, 104, 130, 0.25) !important;
    }
    .btn-batch-select-2.active * { color: #ffffff !important; }

    .btn-combined-select.active {
        background: linear-gradient(135deg, #1B3C53 0%, #234C6A 50%, #456882 100%) !important;
        color: #ffffff !important;
        box-shadow: 0 4px 12px rgba(27, 60, 83, 0.25) !important;
    }
    .btn-combined-select.active * { color: #ffffff !important; }

    /* Dynamic header banner color themes */
    .banner-combined {
        background: linear-gradient(135deg, #1B3C53 0%, #234C6A 55%, #456882 100%) !important;
        box-shadow: 0 10px 25px -5px rgba(27, 60, 83, 0.3) !important;
    }
    .banner-batch-0 {
        background: linear-gradient(135deg, #1B3C53 0%, #456882 100%) !important;
        box-shadow: 0 10px 25px -5px rgba(27, 60, 83, 0.3) !important;
    }
    .banner-batch-1 {
        background: linear-gradient(135deg, #234C6A 0%, #456882 100%) !important;
        box-shadow: 0 10px 25px -5px rgba(35, 76, 106, 0.3) !important;
    }
    .banner-batch-2 {
        background: linear-gradient(135deg, #456882 0%, #D2C1B6 100%) !important;
        box-shadow: 0 10px 25px -5px rgba(69, 104, 130, 0.3) !important;
    }

    /* =====================================================
       PREMIUM ENHANCEMENTS - Navy Theme + Batch Tooltips
       ===================================================== */

    /* Weekday Header Row - Navy Themed */
    .weekday-header {
        transition: all 0.2s ease;
    }
    .weekday-normal {
        background: linear-gradient(135deg, rgba(27,60,83,0.08) 0%, rgba(35,76,106,0.12) 100%) !important;
        color: #1B3C53 !important;
        border: 1px solid rgba(69, 104, 130, 0.2) !important;
    }
    .weekday-sat {
        background: linear-gradient(135deg, rgba(69,104,130,0.15) 0%, rgba(210,193,182,0.3) 100%) !important;
        color: #456882 !important;
        border: 1px solid rgba(69, 104, 130, 0.25) !important;
    }
    .weekday-sun {
        background: linear-gradient(135deg, rgba(210,193,182,0.3) 0%, rgba(210,193,182,0.5) 100%) !important;
        color: #7a5c4e !important;
        border: 1px solid rgba(210, 193, 182, 0.5) !important;
    }

    /* Staggered calendar cell fade-in animation */
    @keyframes calCellIn {
        from { opacity: 0; transform: translateY(10px) scale(0.97); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .calendar-day {
        animation: calCellIn 0.4s ease-out both;
    }
    .calendar-day:nth-child(7n+1)  { animation-delay: 0.02s; }
    .calendar-day:nth-child(7n+2)  { animation-delay: 0.04s; }
    .calendar-day:nth-child(7n+3)  { animation-delay: 0.06s; }
    .calendar-day:nth-child(7n+4)  { animation-delay: 0.08s; }
    .calendar-day:nth-child(7n+5)  { animation-delay: 0.10s; }
    .calendar-day:nth-child(7n+6)  { animation-delay: 0.12s; }
    .calendar-day:nth-child(7n)    { animation-delay: 0.14s; }

    /* Saturday and Sunday calendar cells subtle tint */
    .calendar-day.saturday {
        background-color: rgba(69, 104, 130, 0.06) !important;
        border-color: rgba(69, 104, 130, 0.2) !important;
    }
    .calendar-day.sunday {
        background-color: rgba(210, 193, 182, 0.18) !important;
        border-color: rgba(210, 193, 182, 0.4) !important;
    }

    /* Today glowing pulse ring */
    @keyframes todayPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(27,60,83,0.3), 0 0 0 4px rgba(27,60,83,0.12); }
        50%       { box-shadow: 0 0 0 6px rgba(27,60,83,0.08), 0 0 0 10px rgba(27,60,83,0.04); }
    }
    .calendar-day.today {
        animation: calCellIn 0.4s ease-out both, todayPulse 2.5s infinite 0.5s !important;
    }

    /* Enhanced event border colors using brand palette */
    .calendar-event-item.event-class {
        background: linear-gradient(135deg, rgba(35,76,106,0.06) 0%, rgba(69,104,130,0.1) 100%) !important;
        color: #1B3C53 !important;
        border-left: 3px solid #234C6A !important;
    }
    .calendar-event-item.event-exam {
        background: linear-gradient(135deg, rgba(210,193,182,0.15) 0%, rgba(210,193,182,0.3) 100%) !important;
        color: #5a3e35 !important;
        border-left: 3px solid #D2C1B6 !important;
    }
    .calendar-event-item.event-completed {
        background: linear-gradient(135deg, rgba(16,185,129,0.08) 0%, rgba(16,185,129,0.15) 100%) !important;
        color: #047857 !important;
        border-left: 3px solid #10b981 !important;
    }
    .calendar-event-item.event-missed {
        background: linear-gradient(135deg, rgba(245,158,11,0.08) 0%, rgba(245,158,11,0.15) 100%) !important;
        color: #b45309 !important;
        border-left: 3px solid #f59e0b !important;
    }
    .calendar-event-item.event-cancelled {
        background: linear-gradient(135deg, rgba(100,116,139,0.06) 0%, rgba(100,116,139,0.12) 100%) !important;
        color: #475569 !important;
        border-left: 3px solid #64748b !important;
    }

    /* PREMIUM BATCH DETAILS TOOLTIP on event item hover */
    .calendar-event-item[data-tooltip] {
        position: relative;
    }
    .calendar-event-item[data-tooltip]::after {
        content: attr(data-tooltip);
        position: absolute;
        bottom: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%) translateY(6px);
        background: linear-gradient(135deg, rgba(27,60,83,0.97) 0%, rgba(35,76,106,0.97) 100%);
        color: #f1f5f9;
        padding: 10px 14px;
        border-radius: 10px;
        font-size: 10px;
        line-height: 1.6;
        white-space: pre-line;
        width: 210px;
        max-width: 90vw;
        box-shadow: 0 12px 24px -6px rgba(27,60,83,0.35), 0 4px 8px -2px rgba(27,60,83,0.2);
        opacity: 0;
        pointer-events: none;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 200;
        border: 1px solid rgba(210, 193, 182, 0.25);
        backdrop-filter: blur(12px);
    }
    .calendar-event-item[data-tooltip]::before {
        content: '';
        position: absolute;
        bottom: calc(100% + 2px);
        left: 50%;
        transform: translateX(-50%) translateY(6px);
        border: 6px solid transparent;
        border-top-color: rgba(35,76,106,0.97);
        opacity: 0;
        pointer-events: none;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        z-index: 201;
    }
    .calendar-event-item[data-tooltip]:hover::after {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }
    .calendar-event-item[data-tooltip]:hover::before {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
    }

    /* Event timeline list - left accent strip */
    .border-l-4.border-blue-400 {
        border-left-color: #234C6A !important;
    }
    .border-l-4.border-purple-400 {
        border-left-color: #456882 !important;
    }
    .border-l-4.\!border-green-400 {
        border-left-color: #10b981 !important;
    }
    .border-l-4.\!border-amber-400 {
        border-left-color: #f59e0b !important;
    }

    /* Batch name chip in event list cards - Navy themed */
    .schedule-card span[class*="bg-blue-100"],
    .schedule-card span[class*="bg-green-100"],
    .schedule-card span[class*="bg-purple-100"] {
        font-weight: 600 !important;
        letter-spacing: 0.01em !important;
    }

    /* Month navigation buttons - brand styled */
    a.month-navigation {
        background: rgba(255,255,255,0.8) !important;
        border-color: rgba(69, 104, 130, 0.3) !important;
        color: #1B3C53 !important;
        font-weight: 600 !important;
        transition: all 0.25s ease !important;
    }
    a.month-navigation:hover {
        background: linear-gradient(135deg, #1B3C53, #456882) !important;
        color: #ffffff !important;
        border-color: transparent !important;
        transform: translateY(-2px) !important;
        box-shadow: 0 6px 16px rgba(27, 60, 83, 0.25) !important;
    }

    /* Calendar legend dots - brand palette */
    .calendar-legend-class   { background: #234C6A !important; }
    .calendar-legend-exam    { background: #D2C1B6 !important; }
    .calendar-legend-done    { background: #10b981 !important; }
    .calendar-legend-missed  { background: #f59e0b !important; }
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

// Ripple effect
document.addEventListener('DOMContentLoaded', function() {
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
    
    // Initialize with all events tab
    switchTab('all');
    
    // Add animation to calendar days
    const calendarDays = document.querySelectorAll('.calendar-day');
    calendarDays.forEach((day, index) => {
        if (day.querySelector('.calendar-event-item')) {
            day.style.animationDelay = `${index * 0.05}s`;
            day.classList.add('fade-in');
        }
    });
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
function showEventDetails(type, eventData) {
    const popup = document.getElementById('eventPopup');
    const overlay = document.getElementById('overlay');
    const title = document.getElementById('popupTitle');
    const content = document.getElementById('popupContent');
    
    // Set title based on event type
    if (type === 'class') {
        title.textContent = eventData.topic;
        
        let statusHtml = '';
        if (eventData.class_status === 'completed') {
            statusHtml = `<div class="p-2 bg-green-50 border-l-4 border-green-500 rounded-r mb-3">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                    <span class="font-medium text-green-700">Class Completed</span>
                </div>
                <p class="text-sm text-green-600 mt-1">Attendance marked as Present</p>
            </div>`;
        } else if (eventData.class_status === 'missed') {
            statusHtml = `<div class="p-2 bg-amber-50 border-l-4 border-amber-500 rounded-r mb-3">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-amber-500 mr-2"></i>
                    <span class="font-medium text-amber-700">Class Missed</span>
                </div>
                <p class="text-sm text-amber-600 mt-1">No attendance record found</p>
            </div>`;
        } else if (eventData.is_cancelled) {
            statusHtml = `<div class="p-2 bg-red-50 border-l-4 border-red-500 rounded-r mb-3">
                <div class="flex items-center">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>
                    <span class="font-medium text-red-700">Class Cancelled</span>
                </div>
            </div>`;
        }
        
        let dayTypeHtml = '';
        const dayOfWeek = new Date(eventData.schedule_date).getDay(); // 0=Sunday, 6=Saturday
        if (dayOfWeek === 0) { // Sunday
            dayTypeHtml = `<div class="p-2 bg-pink-50 border-l-4 border-pink-500 rounded-r mb-3">
                <div class="flex items-center">
                    <i class="fas fa-umbrella-beach text-pink-500 mr-2"></i>
                    <span class="font-medium text-pink-700">Sunday</span>
                </div>
            </div>`;
        }
        
        // Add batch info if available
        let batchInfoHtml = '';
        if (eventData.batch_name) {
            batchInfoHtml = `<div class="p-2 bg-blue-50 border-l-4 border-blue-500 rounded-r mb-3">
                <div class="flex items-center">
                    <i class="fas fa-layer-group text-blue-500 mr-2"></i>
                    <span class="font-medium text-blue-700">Batch: ${eventData.batch_name}</span>
                </div>
            </div>`;
        }
        
        content.innerHTML = batchInfoHtml + statusHtml + dayTypeHtml + `
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
            </div>
        `;
    } else if (type === 'exam') {
        title.textContent = eventData.exam_name;
        
        // Add batch info if available
        let batchInfoHtml = '';
        if (eventData.batch_name) {
            batchInfoHtml = `<div class="p-2 bg-purple-50 border-l-4 border-purple-500 rounded-r mb-3">
                <div class="flex items-center">
                    <i class="fas fa-layer-group text-purple-500 mr-2"></i>
                    <span class="font-medium text-purple-700">Batch: ${eventData.batch_name}</span>
                </div>
            </div>`;
        }
        
        content.innerHTML = batchInfoHtml + `
            <div class="space-y-2">
                <div class="flex items-center text-sm text-gray-600">
                    <i class="far fa-calendar mr-2 w-5"></i>
                    <span>${new Date(eventData.exam_date).toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}</span>
                </div>
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-book mr-2 w-5"></i>
                    <span>Subject: ${eventData.subject}</span>
                </div>
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-chart-bar mr-2 w-5"></i>
                    <span>Total Marks: ${eventData.total_marks}</span>
                </div>
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-check-circle mr-2 w-5"></i>
                    <span>Passing Marks: ${eventData.passing_marks}</span>
                </div>
                <div class="flex items-center text-sm text-gray-600">
                    <i class="fas fa-clipboard-list mr-2 w-5"></i>
                    <span>Type: ${eventData.exam_type.replace('_', ' ')}</span>
                </div>
                ${eventData.exam_components ? `
                <div class="mt-2">
                    <h4 class="font-medium text-gray-700 mb-1">Components:</h4>
                    <div class="flex flex-wrap gap-2">
                        ${eventData.exam_components.split(',').map(comp => `<span class="px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded">${comp.trim()}</span>`).join('')}
                    </div>
                </div>` : ''}
                ${eventData.description ? `
                <div class="mt-3">
                    <h4 class="font-medium text-gray-700 mb-1">Description:</h4>
                    <p class="text-sm text-gray-600">${eventData.description}</p>
                </div>` : ''}
                <div class="mt-4">
                    <a href="exam_view.php?exam_id=${eventData.exam_id}" class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                        <i class="fas fa-eye mr-2"></i> View Exam Details
                    </a>
                </div>
            </div>
        `;
    }
    
    // Show popup and overlay
    popup.classList.remove('hidden');
    overlay.classList.remove('hidden');
}

// Close event popup
function closeEventPopup() {
    document.getElementById('eventPopup').classList.add('hidden');
    document.getElementById('overlay').classList.add('hidden');
}

// Format time function
function formatTime(timeString) {
    const time = new Date(`2000-01-01T${timeString}`);
    return time.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
}

// Handle ESC key to close mobile menu
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const mobileMenu = document.getElementById('mobileMenu');
        if (!mobileMenu.classList.contains('hidden')) {
            toggleMobileMenu();
        }
        closeEventPopup();
    }
});

// Add active state to current page link in mobile menu
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = '<?= basename($_SERVER['PHP_SELF']) ?>';
    const mobileLinks = document.querySelectorAll('.mobile-nav-link');
    
    mobileLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href.includes(currentPage)) {
            link.classList.add('bg-white', 'shadow-md');
            const icon = link.querySelector('i');
            if (icon) {
                if (currentPage === 'dashboard.php') icon.classList.add('text-blue-600');
                else if (currentPage === 'my_batches.php') icon.classList.add('text-green-600');
                else if (currentPage === 'upcoming.php') icon.classList.add('text-purple-600');
                else if (currentPage === 'my_content.php') icon.classList.add('text-yellow-600');
                else if (currentPage === 'student_dashboard.php') icon.classList.add('text-yellow-600');
                else if (currentPage === 'my_performance.php') icon.classList.add('text-red-600');
                else if (currentPage === 'student_feedback.php') icon.classList.add('text-indigo-600');
                else if (currentPage === 'student_profile.php') icon.classList.add('text-cyan-600');
            }
        }
    });
});
</script>

<?php include '../footer.php'; ?>