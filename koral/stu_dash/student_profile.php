<?php
session_start();
require_once '../db_connection.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../logout.php");
    exit();
}

// Get student information with proper joins
$student_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT 
        s.*, 
        c.name as course_name
    FROM students s
    LEFT JOIN courses c ON s.course = c.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student information not found");
}

// Get all batches assigned to this student (primary and additional batches)
$batch_ids = [];
if (!empty($student['batch_name'])) $batch_ids[] = $student['batch_name'];
if (!empty($student['batch_name_2'])) $batch_ids[] = $student['batch_name_2'];
if (!empty($student['batch_name_3'])) $batch_ids[] = $student['batch_name_3'];
if (!empty($student['batch_name_4'])) $batch_ids[] = $student['batch_name_4'];

$batches = [];
if (!empty($batch_ids)) {
    // Create placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    
    $batches_query = $db->prepare("
        SELECT 
            b.batch_id,
            b.batch_name,
            b.start_date,
            b.end_date,
            b.time_slot,
            b.mode,
            b.status,
            b.meeting_link,
            b.platform,
            c.name as course_name,
            t.name as trainer_name,
            b.current_enrollment,
            b.max_students
        FROM batches b
        LEFT JOIN courses c ON b.course_description LIKE CONCAT('%', c.name, '%')
        LEFT JOIN trainers t ON b.batch_mentor_id = t.id
        WHERE b.batch_id IN ($placeholders)
    ");
    
    $batches_query->execute($batch_ids);
    $batches = $batches_query->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize batches by type (primary vs additional)
    $primary_batch = null;
    $additional_batches = [];
    
    foreach ($batches as $batch) {
        if ($batch['batch_id'] == $student['batch_name']) {
            $primary_batch = $batch;
        } else {
            $additional_batches[] = $batch;
        }
    }
}
?>

<?php include '../header.php'; ?>
<?php include '../s_sidebar.php'; ?>

<!-- Main Content -->
<div class="flex-1 ml-0 md:ml-64 min-h-screen bg-gradient-to-br from-gray-50 to-blue-50">
    <!-- Mobile Header (Visible only on mobile) -->
    <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 md:hidden">
        <!-- Mobile Menu Button -->
        <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
            <i class="fas fa-bars"></i>
        </button>
        
        <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
            <div class="bg-blue-100 p-2 rounded-lg">
                <i class="fas fa-user-circle text-blue-600 text-sm"></i>
            </div>
            <span>My Profile</span>
        </h1>
        
        <div class="flex items-center space-x-3">
            <!-- User Profile/Indicator -->
            <div class="relative">
                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-graduate text-blue-600"></i>
                </div>
            </div>
        </div>
    </header>

    <!-- Desktop Header (Hidden on mobile) -->
    <header class="hidden md:flex bg-white shadow-lg px-6 py-4 justify-between items-center sticky top-0 z-30">
        <div class="flex-1"></div> <!-- Spacer for centering -->
        
        <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
            <i class="fas fa-user-circle text-blue-500"></i>
            <span class="bg-gradient-to-r from-blue-600 to-purple-600 bg-clip-text text-transparent">My Profile</span>
        </h1>
        
        <div class="flex-1 flex justify-end items-center space-x-4">
            <div class="hidden md:block text-sm text-gray-500">
                <?= date('l, F j, Y') ?>
            </div>
            <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                <i class="fas fa-user text-blue-600"></i>
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

    <div class="p-4 md:p-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Profile Info -->
            <div class="lg:col-span-2 space-y-6">
                <!-- Main Profile Card -->
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 transform transition-transform duration-300 hover:scale-[1.005]">
                    <div class="flex flex-col md:flex-row items-start md:items-center gap-6 mb-6">
                        <!-- Profile Picture -->
                        <div class="relative">
                            <div class="relative">
                                <?php if (!empty($student['profile_picture']) && file_exists($student['profile_picture'])): ?>
                                    <img src="<?= htmlspecialchars($student['profile_picture']) ?>" 
                                         alt="Profile Picture" 
                                         class="w-32 h-32 rounded-full object-cover border-4 border-blue-100 shadow-md">
                                <?php else: ?>
                                    <div class="w-32 h-32 rounded-full bg-gradient-to-br from-blue-100 to-purple-100 flex items-center justify-center shadow-md">
                                        <i class="fas fa-user text-5xl text-blue-500"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Basic Info -->
                        <div>
                            <h2 class="text-3xl font-bold text-gray-800">
                                <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
                            </h2>
                            <p class="text-gray-600 mb-2 flex items-center">
                                <i class="fas fa-id-card text-blue-400 mr-2"></i>
                                <?= htmlspecialchars($student['student_id']) ?>
                            </p>
                            <p class="text-gray-600 mb-2 flex items-center">
                                <i class="fas fa-book text-blue-400 mr-2"></i>
                                <?= htmlspecialchars($student['course_name'] ?? 'Not assigned') ?>
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Personal Info -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2 flex items-center">
                                <i class="fas fa-user-tag mr-2 text-blue-500 bg-blue-100 p-2 rounded-lg"></i>
                                Personal Information
                            </h3>
                            <div class="space-y-4">
                                <div class="flex items-center p-3 rounded-lg border border-gray-100 transition-all duration-300 hover:shadow-md hover:border-blue-200">
                                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-envelope text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Email</p>
                                        <p class="font-medium"><?= htmlspecialchars($student['email']) ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex items-center p-3 rounded-lg border border-gray-100 transition-all duration-300 hover:shadow-md hover:border-blue-200">
                                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-phone text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Phone</p>
                                        <p class="font-medium"><?= htmlspecialchars($student['phone_number'] ?? 'Not set') ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex items-center p-3 rounded-lg border border-gray-100 transition-all duration-300 hover:shadow-md hover:border-blue-200">
                                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-birthday-cake text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Date of Birth</p>
                                        <p class="font-medium"><?= $student['date_of_birth'] ? date('M j, Y', strtotime($student['date_of_birth'])) : 'Not set' ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex items-center p-3 rounded-lg border border-gray-100 transition-all duration-300 hover:shadow-md hover:border-blue-200">
                                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-calendar-plus text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Enrollment Date</p>
                                        <p class="font-medium"><?= date('M j, Y', strtotime($student['enrollment_date'])) ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Info -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2 flex items-center">
                                <i class="fas fa-graduation-cap mr-2 text-blue-500 bg-blue-100 p-2 rounded-lg"></i>
                                Academic Information
                            </h3>
                            <div class="space-y-4">
                                <div class="flex items-center p-3 rounded-lg border border-gray-100 transition-all duration-300 hover:shadow-md hover:border-blue-200">
                                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-id-card text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Student ID</p>
                                        <p class="font-medium"><?= htmlspecialchars($student['student_id']) ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex items-center p-3 rounded-lg border border-gray-100 transition-all duration-300 hover:shadow-md hover:border-blue-200">
                                    <div class="bg-blue-100 p-2 rounded-lg mr-3">
                                        <i class="fas fa-chart-line text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-500">Current Status</p>
                                        <p class="font-medium">
                                            <span class="px-2 py-1 rounded-full text-xs font-semibold
                                                <?php 
                                                $status = $student['current_status'];
                                                if ($status === 'active') echo 'bg-green-100 text-green-800';
                                                elseif ($status === 'completed') echo 'bg-blue-100 text-blue-800';
                                                elseif ($status === 'dropped') echo 'bg-red-100 text-red-800';
                                                else echo 'bg-yellow-100 text-yellow-800';
                                                ?>">
                                                <?= ucfirst($status) ?>
                                            </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Batches Information Section -->
                <?php if (!empty($batches)): ?>
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 transform transition-transform duration-300 hover:scale-[1.005]">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-users text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                        My Batches (<?= count($batches) ?>)
                    </h3>
                    
                    <!-- Primary Batch -->
                    <?php if ($primary_batch): ?>
                    <div class="mb-6 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl border border-blue-200">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-semibold text-blue-800 flex items-center">
                                <i class="fas fa-star text-yellow-500 mr-2"></i>
                                Primary Batch
                            </h4>
                            <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-xs font-semibold">
                                <?= htmlspecialchars($primary_batch['status']) ?>
                            </span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm text-gray-600">Batch Name</p>
                                <p class="font-medium"><?= htmlspecialchars($primary_batch['batch_name']) ?> (<?= htmlspecialchars($primary_batch['batch_id']) ?>)</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Course</p>
                                <p class="font-medium"><?= htmlspecialchars($primary_batch['course_name'] ?? 'Not specified') ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Schedule</p>
                                <p class="font-medium"><?= htmlspecialchars($primary_batch['time_slot']) ?> (<?= ucfirst($primary_batch['mode']) ?>)</p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Trainer</p>
                                <p class="font-medium"><?= htmlspecialchars($primary_batch['trainer_name'] ?? 'Not assigned') ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Duration</p>
                                <p class="font-medium"><?= date('M j, Y', strtotime($primary_batch['start_date'])) ?> - <?= date('M j, Y', strtotime($primary_batch['end_date'])) ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-600">Platform</p>
                                <p class="font-medium"><?= htmlspecialchars($primary_batch['platform'] ?? 'Not specified') ?></p>
                            </div>
                            <?php if ($primary_batch['meeting_link']): ?>
                            <div class="md:col-span-2">
                                <p class="text-sm text-gray-600">Meeting Link</p>
                                <a href="<?= htmlspecialchars($primary_batch['meeting_link']) ?>" 
                                   target="_blank" 
                                   class="text-blue-600 hover:underline inline-flex items-center">
                                    <i class="fas fa-video mr-1"></i>
                                    Join Class
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Additional Batches -->
                    <?php if (!empty($additional_batches)): ?>
                    <div class="space-y-4">
                        <h4 class="font-semibold text-gray-700 flex items-center">
                            <i class="fas fa-layer-group text-gray-500 mr-2"></i>
                            Additional Batches
                        </h4>
                        <?php foreach ($additional_batches as $index => $batch): ?>
                        <div class="p-4 bg-gray-50 rounded-xl border border-gray-200 hover:border-blue-300 transition-all duration-300">
                            <div class="flex items-center justify-between mb-2">
                                <h5 class="font-medium text-gray-800">
                                    Batch <?= $index + 1 ?>: <?= htmlspecialchars($batch['batch_name']) ?>
                                </h5>
                                <span class="px-2 py-1 bg-<?= 
                                    $batch['status'] == 'ongoing' ? 'green' : 
                                    ($batch['status'] == 'upcoming' ? 'yellow' : 
                                    ($batch['status'] == 'completed' ? 'blue' : 'gray')) ?>-100 
                                    text-<?= $batch['status'] == 'ongoing' ? 'green' : 
                                    ($batch['status'] == 'upcoming' ? 'yellow' : 
                                    ($batch['status'] == 'completed' ? 'blue' : 'gray')) ?>-800 
                                    rounded-full text-xs">
                                    <?= ucfirst($batch['status']) ?>
                                </span>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                                <div>
                                    <span class="text-gray-600">Batch ID:</span>
                                    <span class="ml-1 font-medium"><?= htmlspecialchars($batch['batch_id']) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Schedule:</span>
                                    <span class="ml-1 font-medium"><?= htmlspecialchars($batch['time_slot']) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Mode:</span>
                                    <span class="ml-1 font-medium"><?= ucfirst($batch['mode']) ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-600">Trainer:</span>
                                    <span class="ml-1 font-medium"><?= htmlspecialchars($batch['trainer_name'] ?? 'Not assigned') ?></span>
                                </div>
                                <div class="md:col-span-2">
                                    <span class="text-gray-600">Duration:</span>
                                    <span class="ml-1 font-medium"><?= date('M j, Y', strtotime($batch['start_date'])) ?> - <?= date('M j, Y', strtotime($batch['end_date'])) ?></span>
                                </div>
                                <?php if ($batch['meeting_link']): ?>
                                <div class="md:col-span-2">
                                    <a href="<?= htmlspecialchars($batch['meeting_link']) ?>" 
                                       target="_blank" 
                                       class="text-blue-600 hover:underline inline-flex items-center">
                                        <i class="fas fa-video mr-1"></i>
                                        Join Class
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100">
                    <div class="text-center py-8">
                        <i class="fas fa-users text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500">No batches assigned yet.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column - Parent Information -->
            <div class="space-y-6">
                <!-- Parent Information Card -->
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 transform transition-transform duration-300 hover:scale-[1.005]">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-users text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                        Parent Information
                    </h2>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 mb-1 text-sm">Father's Name</label>
                            <div class="w-full px-4 py-3 border border-gray-200 bg-gray-50 rounded-lg flex items-center transition-all duration-300 hover:border-blue-300 hover:shadow-sm">
                                <i class="fas fa-user-friends text-gray-400 mr-2"></i>
                                <?= htmlspecialchars($student['father_name'] ?? 'Not provided') ?>
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 mb-1 text-sm">Father's Phone</label>
                            <div class="w-full px-4 py-3 border border-gray-200 bg-gray-50 rounded-lg flex items-center transition-all duration-300 hover:border-blue-300 hover:shadow-sm">
                                <i class="fas fa-phone text-gray-400 mr-2"></i>
                                <?= htmlspecialchars($student['father_phone_number'] ?? 'Not provided') ?>
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 mb-1 text-sm">Father's Email</label>
                            <div class="w-full px-4 py-3 border border-gray-200 bg-gray-50 rounded-lg flex items-center transition-all duration-300 hover:border-blue-300 hover:shadow-sm">
                                <i class="fas fa-envelope text-gray-400 mr-2"></i>
                                <?= htmlspecialchars($student['father_email'] ?? 'Not provided') ?>
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 mb-1 text-sm">State</label>
                            <div class="w-full px-4 py-3 border border-gray-200 bg-gray-50 rounded-lg flex items-center transition-all duration-300 hover:border-blue-300 hover:shadow-sm">
                                <i class="fas fa-map-marker-alt text-gray-400 mr-2"></i>
                                <?= htmlspecialchars($student['state'] ?? 'Not provided') ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fee Information Card -->
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 transform transition-transform duration-300 hover:scale-[1.005]">
                    <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-rupee-sign text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                        Fee Information
                    </h2>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-700 mb-1 text-sm">Enrollment Fees</label>
                            <div class="w-full px-4 py-3 border border-gray-200 bg-gray-50 rounded-lg flex items-center transition-all duration-300 hover:border-blue-300 hover:shadow-sm">
                                <i class="fas fa-money-bill-wave text-gray-400 mr-2"></i>
                                ₹<?= number_format($student['enrollment_fees'] ?? 0, 2) ?>
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 mb-1 text-sm">Fees Status</label>
                            <div class="w-full px-4 py-3 border border-gray-200 bg-gray-50 rounded-lg">
                                <span class="px-2 py-1 rounded-full text-xs font-semibold
                                    <?php 
                                    $fee_status = $student['fees_status'] ?? 'unpaid';
                                    if ($fee_status === 'fully_paid') echo 'bg-green-100 text-green-800';
                                    elseif ($fee_status === 'partially_paid') echo 'bg-blue-100 text-blue-800';
                                    elseif ($fee_status === 'overdue') echo 'bg-red-100 text-red-800';
                                    else echo 'bg-yellow-100 text-yellow-800';
                                    ?>">
                                    <?= ucfirst(str_replace('_', ' ', $fee_status)) ?>
                                </span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-gray-700 mb-1 text-sm">Total Fees Paid</label>
                            <div class="w-full px-4 py-3 border border-gray-200 bg-gray-50 rounded-lg flex items-center transition-all duration-300 hover:border-blue-300 hover:shadow-sm">
                                <i class="fas fa-check-circle text-gray-400 mr-2"></i>
                                ₹<?= number_format($student['total_fees_paid'] ?? 0, 2) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Password Information -->
                <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-lock text-blue-500 mr-2 bg-blue-100 p-2 rounded-lg"></i>
                        Password Information
                    </h3>
                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-4 rounded-lg border border-blue-100 flex items-start transition-all duration-300 hover:shadow-md">
                        <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                        <p class="text-blue-800">
                            To change your password, please contact the administration.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add custom animations -->
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
    
    /* Mobile navigation styles */
    .mobile-nav-link.active {
        background-color: #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        font-weight: 600;
    }
    
    .mobile-nav-link i.active {
        transform: scale(1.1);
    }
    
    /* Mobile menu overlay */
    #mobileMenu {
        transition: opacity 0.3s ease-in-out;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .text-sm-mobile {
            font-size: 0.875rem !important;
        }
        
        .text-lg-mobile {
            font-size: 1.125rem !important;
        }
        
        .profile-picture-mobile {
            width: 80px;
            height: 80px;
        }
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

// Add staggered animations for profile sections
document.addEventListener('DOMContentLoaded', function() {
    // Animate cards on page load
    const cards = document.querySelectorAll('.bg-white.rounded-2xl');
    cards.forEach((card, index) => {
        card.classList.add('animate-fade-in');
        card.classList.add(`delay-${(index % 3) + 1}00`);
    });
    
    // Animate profile info items
    const infoItems = document.querySelectorAll('.flex.items-center.p-3.rounded-lg');
    infoItems.forEach((item, index) => {
        setTimeout(() => {
            item.classList.add('animate-fade-in');
        }, index * 100);
    });
});

// Handle ESC key to close mobile menu
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const mobileMenu = document.getElementById('mobileMenu');
        if (!mobileMenu.classList.contains('hidden')) {
            toggleMobileMenu();
        }
    }
});

// Add active state to current page link in mobile menu
document.addEventListener('DOMContentLoaded', function() {
    const currentPage = '<?= basename($_SERVER['PHP_SELF']) ?>';
    const mobileLinks = document.querySelectorAll('.mobile-nav-link');
    
    mobileLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && href.includes(currentPage)) {
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

// Original sidebar toggle function (kept for compatibility)
function toggleSidebar() {
    const sidebar = document.querySelector('.md\\:ml-64');
    if (sidebar.classList.contains('ml-0')) {
        sidebar.classList.remove('ml-0');
        sidebar.classList.add('ml-64');
    } else {
        sidebar.classList.remove('ml-64');
        sidebar.classList.add('ml-0');
    }
}
</script>

<?php include '../footer.php'; ?>