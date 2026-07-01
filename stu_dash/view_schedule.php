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
    SELECT s.*, b.batch_id, b.batch_name, b.time_slot, b.mode, c.name as course_name
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

$batch_id = $student['batch_name'] ?? 'Not assigned';

// Get schedule
$schedule = [];
if ($batch_id !== 'Not assigned') {
    $schedule_query = $db->prepare("
        SELECT schedule_date, start_time, end_time, topic, description, is_cancelled, cancellation_reason
        FROM schedule 
        WHERE batch_id = :batch_id 
        AND schedule_date >= CURDATE()
        ORDER BY schedule_date ASC, start_time ASC
    ");
    $schedule_query->execute([':batch_id' => $batch_id]);
    $schedule = $schedule_query->fetchAll(PDO::FETCH_ASSOC);
}

// Get today's classes
$today_schedule = [];
if ($batch_id !== 'Not assigned') {
    $today_query = $db->prepare("
        SELECT schedule_date, start_time, end_time, topic, description, is_cancelled
        FROM schedule 
        WHERE batch_id = :batch_id 
        AND schedule_date = CURDATE()
        ORDER BY start_time ASC
    ");
    $today_query->execute([':batch_id' => $batch_id]);
    $today_schedule = $today_query->fetchAll(PDO::FETCH_ASSOC);
}

// Get current week dates
$week_start = date('Y-m-d', strtotime('monday this week'));
$week_end = date('Y-m-d', strtotime('sunday this week'));
$current_week_schedule = [];

if ($batch_id !== 'Not assigned') {
    $week_query = $db->prepare("
        SELECT schedule_date, start_time, end_time, topic, description, is_cancelled
        FROM schedule 
        WHERE batch_id = :batch_id 
        AND schedule_date BETWEEN :week_start AND :week_end
        ORDER BY schedule_date ASC, start_time ASC
    ");
    $week_query->execute([
        ':batch_id' => $batch_id,
        ':week_start' => $week_start,
        ':week_end' => $week_end
    ]);
    $current_week_schedule = $week_query->fetchAll(PDO::FETCH_ASSOC);
}
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<!-- Mobile Navigation Header (Hidden on desktop) -->
<nav class="md:hidden fixed top-0 left-0 right-0 bg-gradient-to-r from-indigo-600 to-purple-600 text-white z-50 shadow-lg">
    <div class="flex items-center justify-between px-4 py-3">
        <!-- Logo/Title -->
        <div class="flex items-center space-x-2">
            <i class="fas fa-calendar-alt text-xl"></i>
            <span class="font-bold text-lg">Schedule</span>
        </div>
        
        <!-- Mobile Menu Button -->
        <button onclick="toggleSidebar()" class="text-white text-2xl hover:text-gray-200 transition-colors duration-300">
            <i class="fas fa-bars"></i>
        </button>
    </div>
    
    <!-- Mobile Menu Dropdown -->
    <div id="mobileMenu" class="hidden bg-white text-gray-800 shadow-lg">
        <div class="px-4 py-3 border-b border-gray-200 bg-gray-50">
            <p class="text-sm text-gray-600">Welcome,</p>
            <p class="font-bold"><?= htmlspecialchars($student['first_name'] ?? 'Student') ?> <?= htmlspecialchars($student['last_name'] ?? '') ?></p>
        </div>
        
        <div class="max-h-96 overflow-y-auto">
            <!-- Quick Actions -->
            <a href="dashboard.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-blue-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-blue-100 text-blue-600 p-2 rounded-lg">
                    <i class="fas fa-tachometer-alt"></i>
                </div>
                <span class="font-medium">Dashboard</span>
            </a>
            
            <a href="my_batches.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-green-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-green-100 text-green-600 p-2 rounded-lg">
                    <i class="fas fa-users"></i>
                </div>
                <span class="font-medium">My Batches</span>
            </a>
            
            <a href="upcoming.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-purple-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-purple-100 text-purple-600 p-2 rounded-lg">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <span class="font-medium">Schedule</span>
            </a>
            
            <a href="my_content.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-yellow-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-yellow-100 text-yellow-600 p-2 rounded-lg">
                    <i class="fas fa-book"></i>
                </div>
                <span class="font-medium">My Content</span>
            </a>
            
            <a href="../student_test/student_dashboard.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-orange-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-orange-100 text-orange-600 p-2 rounded-lg">
                    <i class="fas fa-vial"></i>
                </div>
                <span class="font-medium">Tests</span>
            </a>
            
            <a href="my_performance.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-red-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-red-100 text-red-600 p-2 rounded-lg">
                    <i class="fas fa-chart-line"></i>
                </div>
                <span class="font-medium">Performance</span>
            </a>
            
            <a href="student_feedback.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-indigo-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-indigo-100 text-indigo-600 p-2 rounded-lg">
                    <i class="fas fa-comment-dots"></i>
                </div>
                <span class="font-medium">Feedback</span>
            </a>
            
            <a href="student_profile.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-cyan-50 border-b border-gray-100 transition-colors duration-200">
                <div class="bg-cyan-100 text-cyan-600 p-2 rounded-lg">
                    <i class="fas fa-user-circle"></i>
                </div>
                <span class="font-medium">My Profile</span>
            </a>
            
            <!-- Logout -->
            <a href="../logout.php" class="flex items-center space-x-3 px-4 py-3 hover:bg-gray-100 transition-colors duration-200">
                <div class="bg-gray-100 text-gray-600 p-2 rounded-lg">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <span class="font-medium text-red-600">Logout</span>
            </a>
        </div>
    </div>
</nav>

<!-- Main Content -->
<div class="flex-1 ml-0 md:ml-64 min-h-screen transition-all duration-300 ease-in-out relative overflow-hidden">
    <!-- Animated background -->
    <div class="absolute top-0 left-0 w-full h-full -z-10 opacity-5">
        <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-indigo-400 rounded-full filter blur-3xl animate-pulse-slow"></div>
        <div class="absolute top-2/3 left-2/3 w-96 h-96 bg-purple-400 rounded-full filter blur-3xl animate-pulse-slower"></div>
    </div>
    
    <!-- Header -->
    <header class="bg-white bg-opacity-90 backdrop-blur-md shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30 md:top-0">
        <!-- Removed hamburger button -->
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-calendar-alt text-indigo-500"></i>
            <span class="bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">Class Schedule</span>
        </h1>
        <div class="flex items-center space-x-4">
            <a href="dashboard.php" class="text-sm text-blue-600 hover:underline flex items-center space-x-1 transition-all duration-300 hover:scale-105">
                <i class="fas fa-arrow-left"></i>
                <span>Back to Dashboard</span>
            </a>
        </div>
    </header>

    <div class="p-4 md:p-6 md:mt-0 mt-16"> <!-- Added mt-16 for mobile to account for fixed nav -->
        <!-- Quick Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <!-- Today's Classes -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Today's Classes</p>
                        <p class="text-2xl font-bold text-blue-600 mt-1"><?= count($today_schedule) ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= date('M j, Y') ?></p>
                    </div>
                    <div class="text-blue-500 bg-blue-100 p-3 rounded-full">
                        <i class="fas fa-calendar-day text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- This Week -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">This Week</p>
                        <p class="text-2xl font-bold text-green-600 mt-1"><?= count($current_week_schedule) ?></p>
                        <p class="text-xs text-gray-500 mt-1"><?= date('M j', strtotime($week_start)) ?> - <?= date('M j', strtotime($week_end)) ?></p>
                    </div>
                    <div class="text-green-500 bg-green-100 p-3 rounded-full">
                        <i class="fas fa-calendar-week text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Upcoming -->
            <div class="bg-white bg-opacity-90 backdrop-blur-md p-6 rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 transform hover:-translate-y-1">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-500">Total Upcoming</p>
                        <p class="text-2xl font-bold text-purple-600 mt-1"><?= count($schedule) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Future classes</p>
                    </div>
                    <div class="text-purple-500 bg-purple-100 p-3 rounded-full">
                        <i class="fas fa-calendar text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Today's Schedule -->
        <?php if (!empty($today_schedule)): ?>
        <div class="bg-white bg-opacity-90 backdrop-blur-md rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 mb-6 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-indigo-50">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-sun text-yellow-500 mr-2"></i>
                    Today's Schedule
                    <span class="ml-2 text-sm font-normal text-gray-600">(<?= date('l, F j, Y') ?>)</span>
                </h2>
            </div>
            <div class="divide-y divide-gray-200">
                <?php foreach ($today_schedule as $index => $class): ?>
                    <div class="p-6 hover:bg-blue-50 transition-colors duration-200 animate-fade-in-up" 
                         style="animation-delay: <?= $index * 0.1 ?>s">
                        <div class="flex items-start justify-between">
                            <div class="flex-1">
                                <div class="flex items-center space-x-3 mb-2">
                                    <h3 class="text-lg font-medium text-gray-900"><?= htmlspecialchars($class['topic']) ?></h3>
                                    <?php if ($class['is_cancelled']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            <i class="fas fa-times mr-1"></i>
                                            Cancelled
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <i class="fas fa-check mr-1"></i>
                                            Scheduled
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex items-center space-x-4 text-sm text-gray-600">
                                    <span class="flex items-center">
                                        <i class="fas fa-clock mr-1 text-blue-500"></i>
                                        <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-video mr-1 text-purple-500"></i>
                                        <?= ucfirst($student['mode']) ?> Class
                                    </span>
                                </div>
                                <?php if ($class['description']): ?>
                                    <p class="text-sm text-gray-700 mt-2"><?= htmlspecialchars($class['description']) ?></p>
                                <?php endif; ?>
                                <?php if ($class['is_cancelled'] && $class['cancellation_reason']): ?>
                                    <div class="mt-2 p-3 bg-red-50 rounded-lg border border-red-200">
                                        <p class="text-sm text-red-700">
                                            <strong>Cancellation Reason:</strong> <?= htmlspecialchars($class['cancellation_reason']) ?>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!$class['is_cancelled']): ?>
                                <div class="ml-4 flex-shrink-0">
                                    <?php if ($student['mode'] === 'online' && isset($student['meeting_link'])): ?>
                                        <a href="<?= htmlspecialchars($student['meeting_link']) ?>" target="_blank" 
                                           class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                            <i class="fas fa-video mr-2"></i>
                                            Join Class
                                        </a>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg">
                                            <i class="fas fa-building mr-2"></i>
                                            Offline Class
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Weekly Schedule -->
        <div class="bg-white bg-opacity-90 backdrop-blur-md rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 mb-6 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-calendar-week text-green-500 mr-2"></i>
                    Weekly Schedule
                    <span class="ml-2 text-sm font-normal text-gray-600">
                        (<?= date('M j', strtotime($week_start)) ?> - <?= date('M j', strtotime($week_end)) ?>)
                    </span>
                </h2>
            </div>

            <?php if (empty($current_week_schedule)): ?>
                <div class="p-8 text-center">
                    <div class="text-gray-400 mb-4">
                        <i class="fas fa-calendar-times text-5xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-700 mb-2">No Classes This Week</h3>
                    <p class="text-gray-500">No classes are scheduled for the current week.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Topic</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($current_week_schedule as $index => $class): 
                                $is_today = $class['schedule_date'] == date('Y-m-d');
                                $is_past = $class['schedule_date'] < date('Y-m-d');
                            ?>
                                <tr class="<?= $is_today ? 'bg-blue-50' : '' ?> hover:bg-gray-50 transition-colors duration-200 animate-fade-in-up" 
                                    style="animation-delay: <?= $index * 0.05 ?>s">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?= $is_today ? 'text-blue-900' : 'text-gray-900' ?>">
                                        <?= date('M j, Y', strtotime($class['schedule_date'])) ?>
                                        <?php if ($is_today): ?>
                                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                Today
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('l', strtotime($class['schedule_date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900">
                                        <div class="font-medium"><?= htmlspecialchars($class['topic']) ?></div>
                                        <?php if ($class['description']): ?>
                                            <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($class['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($class['is_cancelled']): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times mr-1"></i>
                                                Cancelled
                                            </span>
                                        <?php elseif ($is_past): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                                <i class="fas fa-check-circle mr-1"></i>
                                                Completed
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-clock mr-1"></i>
                                                Upcoming
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Full Schedule -->
        <div class="bg-white bg-opacity-90 backdrop-blur-md rounded-2xl shadow-lg hover:shadow-xl transition-all duration-500 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-calendar text-purple-500 mr-2"></i>
                    Full Schedule
                </h2>
                <p class="text-sm text-gray-600 mt-1">All upcoming classes for your batch</p>
            </div>

            <?php if (empty($schedule)): ?>
                <div class="p-8 text-center">
                    <div class="text-gray-400 mb-4">
                        <i class="fas fa-calendar-plus text-5xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-700 mb-2">No Upcoming Classes</h3>
                    <p class="text-gray-500">No upcoming classes are scheduled. Check back later for updates.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Day</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Topic</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($schedule as $index => $class): 
                                $is_today = $class['schedule_date'] == date('Y-m-d');
                                $is_tomorrow = $class['schedule_date'] == date('Y-m-d', strtotime('+1 day'));
                            ?>
                                <tr class="<?= $is_today ? 'bg-blue-50' : ($is_tomorrow ? 'bg-green-50' : '') ?> hover:bg-gray-50 transition-colors duration-200 animate-fade-in-up" 
                                    style="animation-delay: <?= $index * 0.03 ?>s">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?= $is_today ? 'text-blue-900' : ($is_tomorrow ? 'text-green-900' : 'text-gray-900') ?>">
                                        <?= date('M j, Y', strtotime($class['schedule_date'])) ?>
                                        <?php if ($is_today): ?>
                                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
                                                Today
                                            </span>
                                        <?php elseif ($is_tomorrow): ?>
                                            <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                                                Tomorrow
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('l', strtotime($class['schedule_date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= date('g:i A', strtotime($class['start_time'])) ?> - <?= date('g:i A', strtotime($class['end_time'])) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($class['topic']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?= $class['description'] ? htmlspecialchars($class['description']) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($class['is_cancelled']): ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times mr-1"></i>
                                                Cancelled
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-clock mr-1"></i>
                                                Scheduled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Batch Information -->
        <div class="mt-6 bg-gradient-to-br from-indigo-50 to-purple-100 rounded-2xl shadow-lg border border-indigo-200 p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-info-circle text-indigo-500 mr-2"></i>
                Batch Information
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex items-center space-x-3">
                    <div class="bg-indigo-100 text-indigo-600 p-2 rounded-full">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Batch Name</p>
                        <p class="text-sm text-gray-600"><?= htmlspecialchars($student['batch_name'] ?? 'Not assigned') ?></p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="bg-green-100 text-green-600 p-2 rounded-full">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Time Slot</p>
                        <p class="text-sm text-gray-600"><?= htmlspecialchars($student['time_slot'] ?? 'Not specified') ?></p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="bg-blue-100 text-blue-600 p-2 rounded-full">
                        <i class="fas fa-laptop"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Mode</p>
                        <p class="text-sm text-gray-600"><?= ucfirst($student['mode'] ?? 'Not specified') ?></p>
                    </div>
                </div>
                <div class="flex items-center space-x-3">
                    <div class="bg-purple-100 text-purple-600 p-2 rounded-full">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Course</p>
                        <p class="text-sm text-gray-600"><?= htmlspecialchars($student['course_name'] ?? 'Not assigned') ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Mobile menu toggle
const mobileMenuButton = document.getElementById('mobileMenuButton');
const mobileMenu = document.getElementById('mobileMenu');

if (mobileMenuButton && mobileMenu) {
    mobileMenuButton.addEventListener('click', function() {
        mobileMenu.classList.toggle('hidden');
        // Toggle icon between bars and times
        const icon = mobileMenuButton.querySelector('i');
        if (icon.classList.contains('fa-bars')) {
            icon.classList.remove('fa-bars');
            icon.classList.add('fa-times');
        } else {
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    });
    
    // Close menu when clicking outside
    document.addEventListener('click', function(e) {
        if (!mobileMenu.contains(e.target) && !mobileMenuButton.contains(e.target) && !mobileMenu.classList.contains('hidden')) {
            mobileMenu.classList.add('hidden');
            const icon = mobileMenuButton.querySelector('i');
            icon.classList.remove('fa-times');
            icon.classList.add('fa-bars');
        }
    });
}

// Mobile sidebar toggle is handled by s_sidebar.php

// Auto-refresh page every 5 minutes to get updated schedule
setTimeout(() => {
    location.reload();
}, 300000); // 5 minutes

// Close mobile menu when clicking on a link
document.querySelectorAll('#mobileMenu a').forEach(link => {
    link.addEventListener('click', function() {
        mobileMenu.classList.add('hidden');
        const icon = mobileMenuButton.querySelector('i');
        icon.classList.remove('fa-times');
        icon.classList.add('fa-bars');
    });
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

/* Mobile menu scrollbar */
#mobileMenu div::-webkit-scrollbar {
    width: 4px;
}

#mobileMenu div::-webkit-scrollbar-track {
    background: #f1f1f1;
}

#mobileMenu div::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 2px;
}

#mobileMenu div::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Ensure main content doesn't go under fixed mobile nav */
@media (max-width: 767px) {
    .flex-1.ml-0 {
        margin-top: 0;
    }
}
</style>

<?php include '../footer.php'; ?>