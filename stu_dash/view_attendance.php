<?php
ob_start();
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id'])|| $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Get student information
$student_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT s.*
    FROM students s
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

$student_id_value = $student['student_id'];
$student_name = $student['first_name'] . ' ' . $student['last_name'];

// Get all current batches for this student
$current_batches = [];
$batch_names = ['batch_name', 'batch_name_2', 'batch_name_3', 'batch_name_4'];

foreach ($batch_names as $batch_field) {
    if (!empty($student[$batch_field])) {
        // Get the real batch name to display in the dropdown
        $batch_query = $db->prepare("SELECT batch_name FROM batches WHERE batch_id = :batch_id");
        $batch_query->execute([':batch_id' => $student[$batch_field]]);
        $b_data = $batch_query->fetch(PDO::FETCH_ASSOC);
        
        $current_batches[] = [
            'id' => $student[$batch_field],
            'name' => $b_data ? $b_data['batch_name'] : $student[$batch_field]
        ];
    }
}

// Selected batch from GET or default to first batch
$selected_batch_id = $_GET['batch_id'] ?? ($current_batches[0]['id'] ?? 'Not assigned');

// Get the display name for the selected batch
$selected_batch_name = $selected_batch_id;
foreach ($current_batches as $b) {
    if ($b['id'] === $selected_batch_id) {
        $selected_batch_name = $b['name'];
        break;
    }
}

// Get courses for the selected batch
$batch_courses = [];
if ($selected_batch_id !== 'Not assigned') {
    $course_stmt = $db->prepare("SELECT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id WHERE bc.batch_id = ? ORDER BY c.name");
    $course_stmt->execute([$selected_batch_id]);
    $batch_courses = $course_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$selected_course_id = $_GET['course_id'] ?? ($batch_courses[0]['id'] ?? 'none');
$selected_course_name = 'No Course';
foreach ($batch_courses as $c) {
    if ($c['id'] == $selected_course_id) {
        $selected_course_name = $c['name'];
        break;
    }
}

// Handle CSV Export
if (isset($_GET['export']) && $selected_batch_id !== 'Not assigned') {
    $export_type = $_GET['export'];
    
    if ($export_type === 'weekly') {
        $start_date = date('Y-m-d', strtotime('-6 days'));
        $end_date = date('Y-m-d');
        $filename = "weekly_attendance_" . date('Y-m-d') . ".csv";
    } elseif ($export_type === 'monthly') {
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-t');
        $filename = "monthly_attendance_" . date('Y-m') . ".csv";
    } else {
        $start_date = '2000-01-01';
        $end_date = date('Y-m-d');
        $filename = "full_attendance.csv";
    }

    $export_query = $db->prepare("
        SELECT date, status, camera_status, remarks 
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name) 
        AND batch_id = :batch_id 
        AND course_id = :course_id
        AND date BETWEEN :start_date AND :end_date
        ORDER BY date DESC
    ");
    
    $export_query->execute([
        ':student_id' => $student_id_value,
        ':student_name' => $student_name,
        ':batch_id' => $selected_batch_id,
        ':course_id' => $selected_course_id,
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    
    $records = $export_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Clear any previous output buffers to prevent headers already sent error
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Output headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    // Add BOM for Excel UTF-8 compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    fputcsv($output, ['Date', 'Day', 'Status', 'Camera', 'Remarks']);
    
    foreach ($records as $row) {
        fputcsv($output, [
            $row['date'],
            date('l', strtotime($row['date'])),
            $row['status'],
            $row['camera_status'],
            $row['remarks']
        ]);
    }
    fclose($output);
    exit();
}

// Get attendance records
$attendance_records = [];
$is_valid_batch = false;
foreach ($current_batches as $b) {
    if ($b['id'] === $selected_batch_id) {
        $is_valid_batch = true; break;
    }
}

if ($selected_batch_id !== 'Not assigned' && $is_valid_batch && $selected_course_id !== 'none') {
    $attendance_query = $db->prepare("
        SELECT date, status, camera_status, remarks 
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name) AND batch_id = :batch_id AND course_id = :course_id
        ORDER BY date DESC
    ");
    
    $attendance_query->execute([
        ':student_id' => $student_id_value,
        ':student_name' => $student_name, 
        ':batch_id' => $selected_batch_id,
        ':course_id' => $selected_course_id
    ]);
    $attendance_records = $attendance_query->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate attendance statistics (Overall Batch)
$total_classes = 0;
$present_count = 0;
$absent_count = 0;

if ($selected_batch_id !== 'Not assigned' && $is_valid_batch) {
    $overall_query = $db->prepare("
        SELECT COUNT(*) as total, 
               SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present,
               SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as absent
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name) AND batch_id = :batch_id
    ");
    $overall_query->execute([
        ':student_id' => $student_id_value,
        ':student_name' => $student_name, 
        ':batch_id' => $selected_batch_id
    ]);
    $overall_stats = $overall_query->fetch(PDO::FETCH_ASSOC);
    $total_classes = $overall_stats['total'] ?? 0;
    $present_count = $overall_stats['present'] ?? 0;
    $absent_count = $overall_stats['absent'] ?? 0;
}

$attendance_percentage = $total_classes > 0 ? round(($present_count / $total_classes) * 100, 2) : 0;

// Get current month attendance (Overall Batch)
$current_month = date('Y-m');
$current_month_query = $db->prepare("
    SELECT COUNT(*) as total, 
           SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present
    FROM course_attendance 
    WHERE (student_id = :student_id OR student_name = :student_name)
    AND batch_id = :batch_id
    AND DATE_FORMAT(date, '%Y-%m') = :current_month
");

$current_month_query->execute([
    ':student_id' => $student_id_value,
    ':student_name' => $student_name,
    ':batch_id' => $selected_batch_id,
    ':current_month' => $current_month
]);
$current_month_stats = $current_month_query->fetch(PDO::FETCH_ASSOC);

$current_month_present = $current_month_stats['present'] ?? 0;
$current_month_total = $current_month_stats['total'] ?? 0;
$current_month_percentage = $current_month_total > 0 ? round(($current_month_present / $current_month_total) * 100, 2) : 0;

// Get last 7 days (Weekly Report) attendance (Overall Batch)
$last_7_days_start = date('Y-m-d', strtotime('-6 days'));
$weekly_query = $db->prepare("
    SELECT COUNT(*) as total, 
           SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present
    FROM course_attendance 
    WHERE (student_id = :student_id OR student_name = :student_name)
    AND batch_id = :batch_id
    AND date >= :start_date
");

$weekly_query->execute([
    ':student_id' => $student_id_value,
    ':student_name' => $student_name,
    ':batch_id' => $selected_batch_id,
    ':start_date' => $last_7_days_start
]);
$weekly_stats = $weekly_query->fetch(PDO::FETCH_ASSOC);

$weekly_present = $weekly_stats['present'] ?? 0;
$weekly_total = $weekly_stats['total'] ?? 0;
$weekly_percentage = $weekly_total > 0 ? round(($weekly_present / $weekly_total) * 100, 2) : 0;
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<!-- Main Content -->
<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out relative overflow-hidden" style="background: linear-gradient(150deg, #f8f5f0 0%, #f0ebe6 45%, #e8e0d9 100%);">
    <!-- Animated background blobs with new palette -->
    <div class="absolute top-0 left-0 w-full h-full -z-10">
        <div class="absolute top-1/4 left-1/4 w-72 h-72 rounded-full filter blur-3xl opacity-20 animate-pulse-slow" style="background: #1B3C53;"></div>
        <div class="absolute top-2/3 left-2/3 w-96 h-96 rounded-full filter blur-3xl opacity-15 animate-pulse-slower" style="background: #456882;"></div>
        <div class="absolute top-1/3 right-1/4 w-56 h-56 rounded-full filter blur-3xl opacity-15 animate-pulse-slow" style="background: #234C6A;"></div>
        <div class="absolute bottom-1/4 left-1/3 w-64 h-64 rounded-full filter blur-3xl opacity-20 animate-pulse-slower" style="background: #D2C1B6;"></div>
    </div>
    
    <!-- Header -->
    <header class="backdrop-blur-md shadow-md px-6 py-4 flex justify-between items-center sticky top-0 z-30" style="background: linear-gradient(90deg, rgba(255,255,255,0.95) 0%, rgba(242,238,234,0.95) 100%); border-bottom: 2px solid rgba(27,60,83,0.15);">
        <button class="md:hidden text-xl text-primary-dark hover:text-primary transition-colors duration-300" onclick="toggleSidebar()" style="color: #1B3C53;">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold flex items-center space-x-3">
            <span class="p-2 rounded-xl shadow-md" style="background: linear-gradient(135deg, #1B3C53, #456882);">
                <i class="fas fa-calendar-check text-white text-lg"></i>
            </span>
            <span class="bg-gradient-to-r from-[#1B3C53] via-[#234C6A] to-[#456882] bg-clip-text text-transparent">Attendance Record</span>
        </h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="flex items-center space-x-2 px-4 py-2 rounded-xl text-sm font-semibold text-white shadow-md transition-all duration-300 hover:scale-105 hover:shadow-lg" style="background: linear-gradient(135deg, #1B3C53, #456882);">
                <i class="fas fa-arrow-left"></i>
                <span>Dashboard</span>
            </a>
        </div>
    </header>

    <div class="p-4 md:p-6">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6">
            <!-- Overall Attendance -->
            <div class="p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2 text-white relative overflow-hidden" style="background: linear-gradient(135deg, #10b981, #059669);">
                <div class="absolute -top-4 -right-4 w-24 h-24 bg-white opacity-10 rounded-full"></div>
                <div class="absolute -bottom-6 -left-6 w-32 h-32 bg-white opacity-10 rounded-full"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <p class="text-sm text-emerald-100 font-medium">Overall Attendance</p>
                        <p class="text-3xl font-bold mt-1"><?= $attendance_percentage ?>%</p>
                        <p class="text-xs text-emerald-200 mt-1"><?= $present_count ?>/<?= $total_classes ?> classes</p>
                    </div>
                    <div class="bg-white bg-opacity-20 p-3 rounded-2xl backdrop-blur-sm">
                        <i class="fas fa-chart-line text-2xl"></i>
                    </div>
                </div>
                <div class="w-full bg-white bg-opacity-30 rounded-full h-2 mt-4 relative z-10">
                    <div class="bg-white h-2 rounded-full transition-all duration-1000 ease-out" 
                         style="width: <?= $attendance_percentage ?>%"></div>
                </div>
            </div>

            <!-- Present Count -->
            <div class="p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2 text-white relative overflow-hidden" style="background: linear-gradient(135deg, #1B3C53, #234C6A);">
                <div class="absolute -top-4 -right-4 w-24 h-24 bg-white opacity-10 rounded-full"></div>
                <div class="absolute -bottom-6 -left-6 w-32 h-32 bg-white opacity-10 rounded-full"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <p class="text-sm text-blue-200 font-medium">Present</p>
                        <p class="text-3xl font-bold mt-1"><?= $present_count ?></p>
                        <p class="text-xs text-blue-200 mt-1">Classes attended</p>
                    </div>
                    <div class="bg-white bg-opacity-20 p-3 rounded-2xl backdrop-blur-sm">
                        <i class="fas fa-check-circle text-2xl"></i>
                    </div>
                </div>
            </div>

            <!-- Absent Count -->
            <div class="p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2 text-white relative overflow-hidden" style="background: linear-gradient(135deg, #f43f5e, #e11d48);">
                <div class="absolute -top-4 -right-4 w-24 h-24 bg-white opacity-10 rounded-full"></div>
                <div class="absolute -bottom-6 -left-6 w-32 h-32 bg-white opacity-10 rounded-full"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <p class="text-sm text-rose-200 font-medium">Absent</p>
                        <p class="text-3xl font-bold mt-1"><?= $absent_count ?></p>
                        <p class="text-xs text-rose-200 mt-1">Classes missed</p>
                    </div>
                    <div class="bg-white bg-opacity-20 p-3 rounded-2xl backdrop-blur-sm">
                        <i class="fas fa-times-circle text-2xl"></i>
                    </div>
                </div>
            </div>

            <!-- Current Month -->
            <div class="p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2 text-white relative overflow-hidden" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <div class="absolute -top-4 -right-4 w-24 h-24 bg-white opacity-10 rounded-full"></div>
                <div class="absolute -bottom-6 -left-6 w-32 h-32 bg-white opacity-10 rounded-full"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <p class="text-sm text-amber-100 font-medium">This Month</p>
                        <p class="text-3xl font-bold mt-1"><?= $current_month_percentage ?>%</p>
                        <p class="text-xs text-amber-200 mt-1"><?= $current_month_present ?>/<?= $current_month_total ?> classes</p>
                    </div>
                    <div class="bg-white bg-opacity-20 p-3 rounded-2xl backdrop-blur-sm">
                        <i class="fas fa-calendar-alt text-2xl"></i>
                    </div>
                </div>
                <div class="w-full bg-white bg-opacity-30 rounded-full h-2 mt-4 relative z-10">
                    <div class="bg-white h-2 rounded-full transition-all duration-1000 ease-out" 
                         style="width: <?= $current_month_percentage ?>%"></div>
                </div>
            </div>

            <!-- Weekly Report -->
            <div class="p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500 transform hover:-translate-y-2 text-white relative overflow-hidden" style="background: linear-gradient(135deg, #456882, #1B3C53);">
                <div class="absolute -top-4 -right-4 w-24 h-24 bg-white opacity-10 rounded-full"></div>
                <div class="absolute -bottom-6 -left-6 w-32 h-32 bg-white opacity-10 rounded-full"></div>
                <div class="flex items-center justify-between relative z-10">
                    <div>
                        <p class="text-sm text-blue-200 font-medium">Last 7 Days</p>
                        <p class="text-3xl font-bold mt-1"><?= $weekly_percentage ?>%</p>
                        <p class="text-xs text-blue-200 mt-1"><?= $weekly_present ?>/<?= $weekly_total ?> classes</p>
                    </div>
                    <div class="bg-white bg-opacity-20 p-3 rounded-2xl backdrop-blur-sm">
                        <i class="fas fa-calendar-week text-2xl"></i>
                    </div>
                </div>
                <div class="w-full bg-white bg-opacity-30 rounded-full h-2 mt-4 relative z-10">
                    <div class="bg-white h-2 rounded-full transition-all duration-1000 ease-out" 
                         style="width: <?= $weekly_percentage ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Attendance Table -->
        <div class="bg-white bg-opacity-95 backdrop-blur-md rounded-2xl shadow-xl hover:shadow-2xl transition-all duration-500 overflow-hidden" style="border: 1px solid rgba(27,60,83,0.12);">
            <div class="px-6 py-5 flex flex-col md:flex-row md:items-center justify-between gap-4" style="background: linear-gradient(90deg, #f5f0eb 0%, #ede8e2 100%); border-bottom: 2px solid rgba(27,60,83,0.1);">
                <div>
                    <h2 class="text-lg font-bold flex items-center" style="color: #1B3C53;">
                        <span class="p-1.5 rounded-lg mr-2 shadow-sm" style="background: linear-gradient(135deg, #1B3C53, #456882);">
                            <i class="fas fa-list-alt text-white text-sm"></i>
                        </span>
                        Detailed Attendance Record
                    </h2>
                    <p class="text-sm text-gray-500 mt-1">Attendance for <span class="font-semibold" style="color: #1B3C53;"><?= htmlspecialchars($selected_course_name) ?></span> in batch <span class="font-semibold" style="color: #456882;"><?= htmlspecialchars($selected_batch_name) ?></span></p>
                </div>
                
                <div class="flex flex-wrap items-center gap-4 relative z-20">
                    <?php if (count($current_batches) > 0): ?>
                    <div class="flex items-center space-x-2">
                        <label for="batch_filter" class="text-sm font-semibold" style="color: #1B3C53;">Batch:</label>
                        <select id="batch_filter" onchange="window.location.href='?batch_id='+this.value" class="px-3 py-1.5 rounded-xl text-sm cursor-pointer font-medium shadow-sm" style="border: 2px solid #D2C1B6; color: #1B3C53; background: #f8f5f0; outline: none;">
                            <?php foreach ($current_batches as $batch): ?>
                                <option value="<?= htmlspecialchars($batch['id']) ?>" <?= $selected_batch_id === $batch['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($batch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($batch_courses)): ?>
                    <div class="flex items-center space-x-2">
                        <label for="course_filter" class="text-sm font-semibold" style="color: #234C6A;">Course:</label>
                        <select id="course_filter" onchange="window.location.href='?batch_id=<?= urlencode($selected_batch_id) ?>&course_id='+this.value" class="px-3 py-1.5 rounded-xl text-sm cursor-pointer font-medium shadow-sm" style="border: 2px solid #D2C1B6; color: #234C6A; background: #f8f5f0; outline: none;">
                            <?php foreach ($batch_courses as $course): ?>
                                <option value="<?= htmlspecialchars($course['id']) ?>" <?= $selected_course_id == $course['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($course['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($selected_batch_id === 'Not assigned'): ?>
                <div class="p-12 text-center">
                    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full mb-4" style="background: linear-gradient(135deg, #D2C1B6, #E8E0D9);">
                        <i class="fas fa-calendar-times text-4xl" style="color: #1B3C53;"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">No Batch Assigned</h3>
                    <p class="text-gray-500 max-w-md mx-auto">You haven't been assigned to a batch yet. Attendance records will appear here once you're enrolled in a batch.</p>
                </div>
            <?php elseif (empty($attendance_records)): ?>
                <div class="p-12 text-center">
                    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full mb-4" style="background: linear-gradient(135deg, #234C6A, #456882);">
                        <i class="fas fa-clipboard-list text-4xl text-white"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">No Attendance Records</h3>
                    <p class="text-gray-500 max-w-md mx-auto">No attendance records found for your batch. Records will appear here once classes begin.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr style="background: linear-gradient(90deg, #1B3C53 0%, #234C6A 50%, #456882 100%);">
                                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">
                                    <i class="fas fa-calendar mr-1 opacity-80"></i> Date
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">
                                    <i class="fas fa-sun mr-1 opacity-80"></i> Day
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">
                                    <i class="fas fa-user-check mr-1 opacity-80"></i> Status
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">
                                    <i class="fas fa-video mr-1 opacity-80"></i> Camera
                                </th>
                                <th class="px-6 py-4 text-left text-xs font-bold text-white uppercase tracking-wider">
                                    <i class="fas fa-comment mr-1 opacity-80"></i> Remarks
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y" style="divide-color: #f3f4f6;">
                            <?php foreach ($attendance_records as $index => $record): ?>
                                <tr class="transition-all duration-200 animate-fade-in-up hover-row" 
                                    style="animation-delay: <?= $index * 0.05 ?>s; background: <?= $index % 2 === 0 ? '#ffffff' : '#f8f5f0' ?>;">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold" style="color: #1B3C53;">
                                        <i class="far fa-calendar-alt mr-1" style="color: #456882;"></i>
                                        <?= date('M j, Y', strtotime($record['date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-600">
                                        <?= date('l', strtotime($record['date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold shadow-sm
                                            <?= $record['status'] === 'Present' 
                                                ? '' : '' ?>"
                                            style="<?= $record['status'] === 'Present' 
                                                ? 'background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #065f46; border: 1px solid #6ee7b7;' 
                                                : 'background: linear-gradient(135deg, #fee2e2, #fecaca); color: #991b1b; border: 1px solid #fca5a5;' ?>">
                                            <i class="fas <?= $record['status'] === 'Present' ? 'fa-check-circle' : 'fa-times-circle' ?> mr-1.5"></i>
                                            <?= $record['status'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold shadow-sm"
                                            style="<?= $record['camera_status'] === 'On' 
                                                ? 'background: linear-gradient(135deg, #dbeafe, #bfdbfe); color: #1e40af; border: 1px solid #93c5fd;' 
                                                : 'background: linear-gradient(135deg, #f3f4f6, #e5e7eb); color: #4b5563; border: 1px solid #d1d5db;' ?>">
                                            <i class="fas fa-video mr-1.5"></i>
                                            <?= $record['camera_status'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 italic">
                                        <?= $record['remarks'] ? htmlspecialchars($record['remarks']) : '<span class="text-gray-300">—</span>' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Attendance Trends -->
        <?php if (!empty($attendance_records)): ?>
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Weekly Breakdown -->
            <div class="bg-white bg-opacity-95 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500" style="border: 1px solid rgba(69,104,130,0.15);">
                <h3 class="text-lg font-bold mb-4 flex items-center" style="color: #1B3C53;">
                    <span class="p-1.5 rounded-lg mr-2 shadow-sm" style="background: linear-gradient(135deg, #234C6A, #456882);">
                        <i class="fas fa-chart-line text-white text-sm"></i>
                    </span>
                    Weekly Attendance Trend
                </h3>
                <div class="space-y-4">
                    <?php
                    // Group by week
                    $weekly_data = [];
                    foreach ($attendance_records as $record) {
                        // Create a week identifier (e.g. "2026-W23")
                        $week = date('o-\WW', strtotime($record['date']));
                        if (!isset($weekly_data[$week])) {
                            // Find monday of this week
                            $dto = new DateTime();
                            $dto->setISODate(date('o', strtotime($record['date'])), date('W', strtotime($record['date'])));
                            $week_start = $dto->format('Y-m-d');
                            $weekly_data[$week] = ['total' => 0, 'present' => 0, 'start' => $week_start];
                        }
                        $weekly_data[$week]['total']++;
                        if ($record['status'] === 'Present') {
                            $weekly_data[$week]['present']++;
                        }
                    }
                    
                    krsort($weekly_data); // Sort by latest week first
                    $display_weeks = array_slice($weekly_data, 0, 5); // Last 5 weeks
                    ?>
                    
                    <?php foreach ($display_weeks as $week => $data): 
                        $percentage = $data['total'] > 0 ? round(($data['present'] / $data['total']) * 100, 1) : 0;
                        $bar_style = $percentage >= 80 ? 'background: linear-gradient(90deg,#10b981,#34d399)' : ($percentage >= 60 ? 'background: linear-gradient(90deg,#f59e0b,#fbbf24)' : 'background: linear-gradient(90deg,#f43f5e,#fb7185)');
                        $week_end = date('M j', strtotime($data['start'] . ' + 6 days'));
                    ?>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-gray-700"><?= date('M j', strtotime($data['start'])) ?> - <?= $week_end ?></span>
                            <div class="flex items-center space-x-3">
                                <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= $data['present'] ?>/<?= $data['total'] ?></span>
                                <span class="text-sm font-bold text-gray-700 w-12 text-right"><?= $percentage ?>%</span>
                                <div class="w-16 md:w-20 bg-gray-100 rounded-full h-2.5">
                                    <div class="h-2.5 rounded-full transition-all duration-1000 ease-out shadow-sm" 
                                         style="width: <?= $percentage ?>%; <?= $bar_style ?>"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Monthly Breakdown -->
            <div class="bg-white bg-opacity-95 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500" style="border: 1px solid rgba(27,60,83,0.15);">
                <h3 class="text-lg font-bold mb-4 flex items-center" style="color: #1B3C53;">
                    <span class="p-1.5 rounded-lg mr-2 shadow-sm" style="background: linear-gradient(135deg, #1B3C53, #234C6A);">
                        <i class="fas fa-chart-bar text-white text-sm"></i>
                    </span>
                    Monthly Attendance Trend
                </h3>
                <div class="space-y-4">
                    <?php
                    // Group by month
                    $monthly_data = [];
                    foreach ($attendance_records as $record) {
                        $month = date('Y-m', strtotime($record['date']));
                        if (!isset($monthly_data[$month])) {
                            $monthly_data[$month] = ['total' => 0, 'present' => 0];
                        }
                        $monthly_data[$month]['total']++;
                        if ($record['status'] === 'Present') {
                            $monthly_data[$month]['present']++;
                        }
                    }
                    
                    krsort($monthly_data); // Sort by latest month first
                    $display_months = array_slice($monthly_data, 0, 6); // Last 6 months
                    ?>
                    
                    <?php foreach ($display_months as $month => $data): 
                        $percentage = $data['total'] > 0 ? round(($data['present'] / $data['total']) * 100, 1) : 0;
                        $bar_style2 = $percentage >= 80 ? 'background: linear-gradient(90deg,#1B3C53,#456882)' : ($percentage >= 60 ? 'background: linear-gradient(90deg,#f59e0b,#fbbf24)' : 'background: linear-gradient(90deg,#f43f5e,#fb7185)');
                    ?>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-semibold text-gray-700"><?= date('M Y', strtotime($month . '-01')) ?></span>
                            <div class="flex items-center space-x-3">
                                <span class="text-xs font-medium px-2 py-0.5 rounded-full bg-gray-100 text-gray-600"><?= $data['present'] ?>/<?= $data['total'] ?></span>
                                <span class="text-sm font-bold text-gray-700 w-12 text-right"><?= $percentage ?>%</span>
                                <div class="w-16 md:w-20 bg-gray-100 rounded-full h-2.5">
                                    <div class="h-2.5 rounded-full transition-all duration-1000 ease-out shadow-sm" 
                                         style="width: <?= $percentage ?>%; <?= $bar_style2 ?>"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Attendance Tips -->
            <div class="p-6 rounded-2xl shadow-lg hover:shadow-2xl transition-all duration-500" style="background: linear-gradient(135deg, #ede8e2 0%, #d2c1b6 100%); border: 1px solid rgba(27,60,83,0.2);">
                <h3 class="text-lg font-bold mb-4 flex items-center" style="color: #1B3C53;">
                    <span class="p-1.5 rounded-lg mr-2 shadow-sm" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <i class="fas fa-lightbulb text-white text-sm"></i>
                    </span>
                    Attendance Tips
                </h3>
                <div class="space-y-4">
                    <div class="flex items-start space-x-3 p-3 rounded-xl" style="background: rgba(255,255,255,0.6);">
                        <div class="p-2 rounded-xl shadow-sm flex-shrink-0" style="background: linear-gradient(135deg, #bfdbfe, #93c5fd);">
                            <i class="fas fa-bell text-sm text-blue-700"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold" style="color: #1B3C53;">Regular Attendance</p>
                            <p class="text-xs text-gray-600 mt-0.5">Maintain above 75% attendance for optimal learning outcomes</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3 p-3 rounded-xl" style="background: rgba(255,255,255,0.6);">
                        <div class="p-2 rounded-xl shadow-sm flex-shrink-0" style="background: linear-gradient(135deg, #a7f3d0, #6ee7b7);">
                            <i class="fas fa-video text-sm text-emerald-700"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold" style="color: #234C6A;">Camera Usage</p>
                            <p class="text-xs text-gray-600 mt-0.5">Keep your camera on during online classes for better engagement</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3 p-3 rounded-xl" style="background: rgba(255,255,255,0.6);">
                        <div class="p-2 rounded-xl shadow-sm flex-shrink-0" style="background: linear-gradient(135deg, #e9d5ff, #d8b4fe);">
                            <i class="fas fa-clock text-sm text-purple-700"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold" style="color: #456882;">Punctuality</p>
                            <p class="text-xs text-gray-600 mt-0.5">Join classes on time to not miss important announcements</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Mobile sidebar toggle
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.flex-1');
    
    if (sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        mainContent.classList.add('ml-64');
    } else {
        sidebar.classList.add('-translate-x-full');
        mainContent.classList.remove('ml-64');
    }
}

// Animate progress bars on scroll
document.addEventListener('DOMContentLoaded', function() {
    const progressBars = document.querySelectorAll('.bg-green-500, .bg-blue-500, .bg-red-500, .bg-yellow-500');
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const bar = entry.target;
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 300);
            }
        });
    }, { threshold: 0.5 });

    progressBars.forEach(bar => observer.observe(bar));
});
</script>

<style>
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.animate-fade-in-up {
    animation: fadeInUp 0.5s ease-out forwards;
    opacity: 0;
}

@keyframes pulse-slow {
    0%, 100% { opacity: 0.2; transform: scale(1); }
    50% { opacity: 0.35; transform: scale(1.05); }
}

@keyframes pulse-slower {
    0%, 100% { opacity: 0.15; transform: scale(1); }
    50% { opacity: 0.28; transform: scale(1.08); }
}

.animate-pulse-slow {
    animation: pulse-slow 5s ease-in-out infinite;
}

.animate-pulse-slower {
    animation: pulse-slower 7s ease-in-out infinite;
}

/* Hover row highlight for table */
.hover-row:hover {
    background: #ede8e2 !important;
}

/* Custom scrollbar */
::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
::-webkit-scrollbar-track {
    background: #f3f4f6;
    border-radius: 10px;
}
::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #234C6A, #456882);
    border-radius: 10px;
}
::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #1B3C53, #456882);
}

/* Export button glow effect (if any) */
.export-btn:hover {
    box-shadow: 0 0 15px rgba(27,60,83,0.4);
}
</style>

<?php include '../footer.php'; ?>