<?php
// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: ../login.php");
    exit();
}

// Get student information
$student_user_id = $_SESSION['user_id'];
$student_query = $db->prepare("
    SELECT s.*, u.email 
    FROM students s
    JOIN users u ON s.user_id = u.id
    WHERE s.user_id = :user_id
");
$student_query->execute([':user_id' => $student_user_id]);
$student = $student_query->fetch(PDO::FETCH_ASSOC);
?>

<!-- Mobile Sidebar Overlay -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden md:hidden" onclick="toggleSidebar()"></div>

<!-- Enhanced Scrollable Sidebar for Student -->
<div id="sidebar" class="w-64 bg-gradient-to-b from-blue-50 to-indigo-50 border-r border-gray-200 h-screen fixed transform transition-transform duration-300 ease-in-out -translate-x-full md:translate-x-0 z-50 shadow-xl flex flex-col">
    <!-- Sidebar Header -->
    <div class="p-4 md:p-5 lg:p-6 border-b border-blue-200 flex items-center justify-center bg-gradient-to-r from-blue-100 to-indigo-100 flex-shrink-0">
        <a href="dashboard.php" class="flex items-center justify-center">
            <img src="../../logo2.png" alt="ASD Academy Logo" class="h-8 md:h-10 lg:h-12 w-auto object-contain transition-all duration-300 hover:scale-105 filter drop-shadow-lg">
        </a>
        <button id="sidebarToggle" class="md:hidden ml-auto text-gray-500 hover:text-blue-600" onclick="toggleSidebar()">
            <i class="fas fa-times"></i>
        </button>
    </div>
    
    <!-- Navigation Menu Container with Improved Scrolling -->
    <div class="flex-1 overflow-hidden flex flex-col">
        <!-- Scrollable Content Area -->
        <nav class="flex-1 overflow-y-auto overflow-x-hidden p-2 md:p-3 lg:p-4 space-y-1 md:space-y-2 scrollbar-thin scrollbar-thumb-blue-200 scrollbar-track-blue-50 hover:scrollbar-thumb-blue-300" style="max-height: calc(100vh - 180px)">
            <?php 
            // Get current page for active state
            $current_script = $_SERVER['SCRIPT_NAME'];
            $current_page = basename($current_script);
            $current_dir = basename(dirname($current_script));
            ?>
            
            <!-- Dashboard Link -->
            <a href="../../stu_dash/dashboard.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-blue-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'bg-blue-200' : '' ?>"></div>
                    <i class="fas fa-tachometer-alt text-blue-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'text-blue-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-blue-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'text-blue-700 font-semibold' : '' ?>">Dashboard</span>
            </a>

            <!-- My Batches Link -->
            <a href="../../stu_dash/my_batches.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-green-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'bg-green-200' : '' ?>"></div>
                    <i class="fas fa-users text-green-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'text-green-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-green-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'text-green-700 font-semibold' : '' ?>">My Batches</span>
            </a>

            <!-- Upcoming Schedule Link -->
            <a href="../../stu_dash/upcoming.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-purple-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'bg-purple-200' : '' ?>"></div>
                    <i class="fas fa-calendar-alt text-purple-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'text-purple-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-purple-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'text-purple-700 font-semibold' : '' ?>">Upcoming Schedule</span>
            </a>

            <!-- My Content Link -->
            <a href="../../stu_dash/my_content.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'my_content.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-yellow-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'my_content.php' && $current_dir == 'stu_dash') ? 'bg-yellow-200' : '' ?>"></div>
                    <i class="fas fa-book text-yellow-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'my_content.php' && $current_dir == 'stu_dash') ? 'text-yellow-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-yellow-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'my_content.php' && $current_dir == 'stu_dash') ? 'text-yellow-700 font-semibold' : '' ?>">My Content</span>
            </a>
            <a href="../../stu_dash/my_leaves.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'apply_leave.php' && $current_dir == 'leaves') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-yellow-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'apply_leave.php' && $current_dir == 'leaves') ? 'bg-yellow-200' : '' ?>"></div>
                     <i class="fas fa-calendar-check text-yellow-600 text-xs md:text-sm relative z-10  <?= $current_page == 'apply_leave.php' ?  'text-yellow-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-yellow-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'apply_leave.php' && $current_dir == 'leaves') ? 'text-yellow-700 font-semibold' : '' ?>">My Leaves</span>
            </a>
            <!-- Test Link -->
            <a href="../../student_test/student_dashboard.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'student_dashboard.php' && $current_dir == 'student_test') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-orange-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'student_dashboard.php' && $current_dir == 'student_test') ? 'bg-orange-200' : '' ?>"></div>
                    <i class="fas fa-vial text-xs md:text-sm text-center relative z-10 <?= ($current_page == 'student_dashboard.php' && $current_dir == 'student_test') ? 'text-orange-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-orange-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'student_dashboard.php' && $current_dir == 'student_test') ? 'text-orange-700 font-semibold' : '' ?>">Test</span>
            </a>

            <!-- My Performance Link -->
            <a href="../../stu_dash/my_performance.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-red-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'bg-red-200' : '' ?>"></div>
                    <i class="fas fa-chart-line text-red-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'text-red-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-red-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'text-red-700 font-semibold' : '' ?>">My Performance</span>
            </a>

            <!-- Feedback Link -->
            <a href="../../stu_dash/student_feedback.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-indigo-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'bg-indigo-200' : '' ?>"></div>
                    <i class="fas fa-comment-dots text-indigo-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'text-indigo-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-indigo-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'text-indigo-700 font-semibold' : '' ?>">Feedback</span>
            </a>

            <!-- Chat Link (commented out) -->
            <!--<a href="../stu_chat/index.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= (basename($_SERVER['PHP_SELF']) == 'index.php' && basename(dirname($_SERVER['PHP_SELF'])) == 'stu_chat') ? 'active bg-white shadow-md' : '' ?>">-->
            <!--    <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">-->
            <!--        <div class="absolute inset-0 bg-pink-100 rounded-lg transform transition-all duration-300 <?= (basename($_SERVER['PHP_SELF']) == 'index.php' && basename(dirname($_SERVER['PHP_SELF'])) == 'stu_chat') ? 'bg-pink-200' : '' ?>"></div>-->
            <!--        <i class="fas fa-comments text-pink-600 text-xs md:text-sm relative z-10 <?= (basename($_SERVER['PHP_SELF']) == 'index.php' && basename(dirname($_SERVER['PHP_SELF'])) == 'stu_chat') ? 'text-pink-700' : '' ?>"></i>-->
            <!--    </div>-->
            <!--    <span class="font-medium text-gray-700 group-hover:text-pink-700 transition-colors duration-300 text-xs md:text-sm <?= (basename($_SERVER['PHP_SELF']) == 'index.php' && basename(dirname($_SERVER['PHP_SELF'])) == 'stu_chat') ? 'text-pink-700 font-semibold' : '' ?>">Chat</span>-->
            <!--</a> -->

            <!-- My Profile Link -->
            <a href="../../stu_dash/student_profile.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-cyan-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'bg-cyan-200' : '' ?>"></div>
                    <i class="fas fa-user-circle text-cyan-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'text-cyan-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-cyan-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'text-cyan-700 font-semibold' : '' ?>">My Profile</span>
            </a>
        </nav>
    </div>
    
    <!-- Sidebar Footer with User Info -->
    <div class="p-2 md:p-3 lg:p-4 border-t border-gray-200 bg-white/90 backdrop-blur-sm flex-shrink-0">
        <div class="flex items-center space-x-2 md:space-x-3">
            <div class="relative">
                <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold transform transition-all duration-300 hover:scale-105">
                    <a href="../../logout.php" title="Logout" class="w-full h-full flex items-center justify-center">
                        <div><i class="fas fa-sign-out-alt text-xs md:text-sm"></i></div>
                    </a>
                </div>
                <span class="absolute bottom-0 right-0 w-2 h-2 md:w-3 md:h-3 bg-green-500 rounded-full border-2 border-white"></span>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-xs md:text-sm font-medium text-gray-800 truncate"><?= htmlspecialchars($student['first_name'] ?? 'Student') ?> <?= htmlspecialchars($student['last_name'] ?? '') ?></p>
                <p class="text-xs text-gray-500 truncate">Student</p>
            </div>
        </div>
    </div>
</div>

<style>
    /* Custom scrollbar styling */
    .scrollbar-thin {
        scrollbar-width: thin;
        scrollbar-color: #bfdbfe #f0f9ff;
    }
    
    .scrollbar-thin::-webkit-scrollbar {
        width: 6px;
    }
    
    .scrollbar-thin::-webkit-scrollbar-track {
        background: #f0f9ff;
        border-radius: 3px;
    }
    
    .scrollbar-thin::-webkit-scrollbar-thumb {
        background-color: #bfdbfe;
        border-radius: 3px;
    }
    
    .scrollbar-thin::-webkit-scrollbar-thumb:hover {
        background-color: #93c5fd;
    }
    
    /* Active sidebar link styling */
    .sidebar-link.active {
        background-color: #ffffff;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        #sidebar {
            width: 280px;
        }
    }
    
    @media (min-width: 769px) and (max-width: 1024px) {
        #sidebar {
            width: 240px;
        }
    }
    
    @media (min-width: 1025px) {
        #sidebar {
            width: 256px;
        }
    }
</style>

<script>
// Toggle sidebar on mobile
document.addEventListener('DOMContentLoaded', function() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            toggleSidebar();
        });
    }

    // Close sidebar when clicking overlay
    const overlay = document.getElementById('sidebarOverlay');
    if (overlay) {
        overlay.addEventListener('click', function() {
            toggleSidebar();
        });
    }

    // Ensure sidebar content is properly scrollable on all devices
    const sidebarNav = document.querySelector('#sidebar nav');
    const sidebarFooter = document.querySelector('#sidebar > div:last-child');
    
    function adjustSidebarHeight() {
        if (sidebarNav && sidebarFooter) {
            const footerHeight = sidebarFooter.offsetHeight;
            const headerHeight = 80; // Approximate header height
            sidebarNav.style.maxHeight = `calc(100vh - ${headerHeight + footerHeight}px)`;
        }
    }
    
    // Adjust on load and resize
    adjustSidebarHeight();
    window.addEventListener('resize', adjustSidebarHeight);
});

// Sidebar toggle function (accessible from both files)
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const mainContent = document.querySelector('.ml-0');
    
    if (sidebar && overlay && mainContent) {
        sidebar.classList.toggle('-translate-x-full');
        overlay.classList.toggle('hidden');
        mainContent.classList.toggle('md:ml-64');
    }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (window.innerWidth < 768 && sidebar && !sidebar.contains(e.target) && 
        overlay && !overlay.classList.contains('hidden')) {
        toggleSidebar();
    }
});
</script>