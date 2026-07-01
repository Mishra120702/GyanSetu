<?php
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
    SELECT s.*, b.batch_id, b.batch_name, c.name as course_name
    FROM students s
    LEFT JOIN batches b ON s.batch_name = b.batch_id
    LEFT JOIN courses c ON s.course = c.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

$student_name = $student['first_name'] . ' ' . $student['last_name'];
$batch_id = $student['batch_name'] ?? 'Not assigned';

// Get attendance records
$attendance_records = [];
if ($batch_id !== 'Not assigned') {
    $attendance_query = $db->prepare("
        SELECT date, status, camera_status, remarks 
        FROM attendance 
        WHERE student_name = :student_name AND batch_id = :batch_id 
        ORDER BY date DESC
    ");
    $attendance_query->execute([':student_name' => $student_name, ':batch_id' => $batch_id]);
    $attendance_records = $attendance_query->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate attendance statistics
$total_classes = count($attendance_records);
$present_count = 0;
$absent_count = 0;

foreach ($attendance_records as $record) {
    if ($record['status'] === 'Present') {
        $present_count++;
    } else {
        $absent_count++;
    }
}

$attendance_percentage = $total_classes > 0 ? round(($present_count / $total_classes) * 100, 2) : 0;

// Get current month attendance
$current_month = date('Y-m');
$current_month_query = $db->prepare("
    SELECT COUNT(*) as total, 
           SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present
    FROM attendance 
    WHERE student_name = :student_name 
    AND batch_id = :batch_id 
    AND DATE_FORMAT(date, '%Y-%m') = :current_month
");
$current_month_query->execute([
    ':student_name' => $student_name,
    ':batch_id' => $batch_id,
    ':current_month' => $current_month
]);
$current_month_stats = $current_month_query->fetch(PDO::FETCH_ASSOC);

$current_month_present = $current_month_stats['present'] ?? 0;
$current_month_total = $current_month_stats['total'] ?? 0;
$current_month_percentage = $current_month_total > 0 ? round(($current_month_present / $current_month_total) * 100, 2) : 0;
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<!-- Main Content -->
<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out relative overflow-hidden">
    <!-- Animated background -->
    <div class="absolute top-0 left-0 w-full h-full -z-10 opacity-5">
        <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-blue-400 rounded-full filter blur-3xl animate-pulse-slow"></div>
        <div class="absolute top-2/3 left-2/3 w-96 h-96 bg-green-400 rounded-full filter blur-3xl animate-pulse-slower"></div>
    </div>
    
    <!-- Header -->
    <header class="bg-white bg-opacity-90 backdrop-blur-md shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
        <button class="md:hidden text-xl text-gray-600 hover:text-blue-500 transition-colors duration-300" onclick="toggleSidebar()">
            <i class="fas fa-bars"></i>
        </button>
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-calendar-check text-green-500"></i>
            <span class="bg-gradient-to-r from-green-600 to-blue-600 bg-clip-text text-transparent">Attendance Record</span>
        </h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="text-sm text-blue-600 hover:underline flex items-center space-x-1 transition-all duration-300 hover:scale-105">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>

    <div class="p-4 md:p-6">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
            <!-- Overall Attendance -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Overall Attendance</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1"><?= $attendance_percentage ?>%</p>
                        <p class="text-xs text-gray-500 mt-1"><?= $present_count ?>/<?= $total_classes ?> classes</p>
                    </div>
                    <div class="text-green-500 bg-green-100 p-3 rounded-full">
                        <i class="fas fa-chart-line text-xl"></i>
                    </div>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
                    <div class="bg-green-500 h-2 rounded-full transition-all duration-1000 ease-out" 
                         style="width: <?= $attendance_percentage ?>%"></div>
                </div>
            </div>

            <!-- Present Count -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Present</p>
                        <p class="text-2xl font-bold text-green-600 mt-1"><?= $present_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">Classes attended</p>
                    </div>
                    <div class="text-green-500 bg-green-100 p-3 rounded-full">
                        <i class="fas fa-check-circle text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Absent Count -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Absent</p>
                        <p class="text-2xl font-bold text-red-600 mt-1"><?= $absent_count ?></p>
                        <p class="text-xs text-gray-500 mt-1">Classes missed</p>
                    </div>
                    <div class="text-red-500 bg-red-100 p-3 rounded-full">
                        <i class="fas fa-times-circle text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Current Month -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">This Month</p>
                        <p class="text-2xl font-bold text-blue-600 mt-1"><?= $current_month_percentage ?>%</p>
                        <p class="text-xs text-gray-500 mt-1"><?= $current_month_present ?>/<?= $current_month_total ?> classes</p>
                    </div>
                    <div class="text-blue-500 bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-calendar-alt text-xl"></i>
                    </div>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2 mt-3">
                    <div class="bg-blue-500 h-2 rounded-full transition-all duration-1000 ease-out" 
                         style="width: <?= $current_month_percentage ?>%"></div>
                </div>
            </div>
        </div>

        <!-- Attendance Table -->
        <div class="bg-white bg-opacity-90 backdrop-blur-md rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-list-alt text-blue-500 mr-2"></i>
                    Detailed Attendance Record
                </h2>
                <p class="text-sm text-gray-600 mt-1">Your complete attendance history for batch <?= htmlspecialchars($student['batch_name']) ?></p>
            </div>

            <?php if ($batch_id === 'Not assigned'): ?>
                <div class="p-8 text-center">
                    <div class="text-gray-400 mb-4">
                        <i class="fas fa-calendar-times text-5xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-700 mb-2">No Batch Assigned</h3>
                    <p class="text-gray-500">You haven't been assigned to a batch yet. Attendance records will appear here once you're enrolled in a batch.</p>
                </div>
            <?php elseif (empty($attendance_records)): ?>
                <div class="p-8 text-center">
                    <div class="text-gray-400 mb-4">
                        <i class="fas fa-clipboard-list text-5xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-700 mb-2">No Attendance Records</h3>
                    <p class="text-gray-500">No attendance records found for your batch. Records will appear here once classes begin.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Camera</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($attendance_records as $index => $record): ?>
                                <tr class="hover:bg-gray-50 transition-colors duration-200 animate-fade-in-up" 
                                    style="animation-delay: <?= $index * 0.05 ?>s">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= date('M j, Y', strtotime($record['date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('l', strtotime($record['date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?= $record['status'] === 'Present' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                            <i class="fas <?= $record['status'] === 'Present' ? 'fa-check' : 'fa-times' ?> mr-1"></i>
                                            <?= $record['status'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                            <?= $record['camera_status'] === 'On' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800' ?>">
                                            <i class="fas fa-video mr-1"></i>
                                            <?= $record['camera_status'] ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?= $record['remarks'] ? htmlspecialchars($record['remarks']) : '-' ?>
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
        <div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Monthly Breakdown -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-chart-bar text-purple-500 mr-2"></i>
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
                        $color = $percentage >= 80 ? 'bg-green-500' : ($percentage >= 60 ? 'bg-yellow-500' : 'bg-red-500');
                    ?>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700"><?= date('M Y', strtotime($month . '-01')) ?></span>
                            <div class="flex items-center space-x-3">
                                <span class="text-sm text-gray-500"><?= $data['present'] ?>/<?= $data['total'] ?></span>
                                <span class="text-sm font-medium text-gray-700"><?= $percentage ?>%</span>
                                <div class="w-24 bg-gray-200 rounded-full h-2">
                                    <div class="h-2 rounded-full <?= $color ?> transition-all duration-1000 ease-out" 
                                         style="width: <?= $percentage ?>%"></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Attendance Tips -->
            <div class="bg-gradient-to-br from-blue-50 to-indigo-100 p-6 rounded-2xl shadow-lg border border-blue-200">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                    <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
                    Attendance Tips
                </h3>
                <div class="space-y-3">
                    <div class="flex items-start space-x-3">
                        <div class="bg-blue-100 text-blue-600 p-2 rounded-full mt-1">
                            <i class="fas fa-bell text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-800">Regular Attendance</p>
                            <p class="text-xs text-gray-600">Maintain above 75% attendance for optimal learning outcomes</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="bg-green-100 text-green-600 p-2 rounded-full mt-1">
                            <i class="fas fa-video text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-800">Camera Usage</p>
                            <p class="text-xs text-gray-600">Keep your camera on during online classes for better engagement</p>
                        </div>
                    </div>
                    <div class="flex items-start space-x-3">
                        <div class="bg-purple-100 text-purple-600 p-2 rounded-full mt-1">
                            <i class="fas fa-clock text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-800">Punctuality</p>
                            <p class="text-xs text-gray-600">Join classes on time to not miss important announcements</p>
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
    animation: fadeInUp 0.6s ease-out forwards;
}

@keyframes pulse-slow {
    0%, 100% { opacity: 0.3; }
    50% { opacity: 0.5; }
}

@keyframes pulse-slower {
    0%, 100% { opacity: 0.2; }
    50% { opacity: 0.4; }
}

.animate-pulse-slow {
    animation: pulse-slow 4s ease-in-out infinite;
}

.animate-pulse-slower {
    animation: pulse-slower 6s ease-in-out infinite;
}
</style>

<?php include '../footer.php'; ?>