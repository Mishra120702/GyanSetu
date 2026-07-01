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
            <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 md:h-10 lg:h-12 w-auto object-contain transition-all duration-300 hover:scale-105 filter drop-shadow-lg">
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
            <a href="../stu_dash/dashboard.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-blue-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'bg-blue-200' : '' ?>"></div>
                    <i class="fas fa-tachometer-alt text-blue-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'text-blue-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-blue-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'dashboard.php' && $current_dir == 'stu_dash') ? 'text-blue-700 font-semibold' : '' ?>">Dashboard</span>
            </a>

            <!-- My Batches Link -->
            <a href="../stu_dash/my_batches.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-green-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'bg-green-200' : '' ?>"></div>
                    <i class="fas fa-users text-green-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'text-green-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-green-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'my_batches.php' && $current_dir == 'stu_dash') ? 'text-green-700 font-semibold' : '' ?>">My Batches</span>
            </a>


            <!-- Support Tickets Link -->
            <a href="../stu_dash/tickets.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'tickets.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-purple-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'tickets.php' && $current_dir == 'stu_dash') ? 'bg-purple-200' : '' ?>"></div>
                    <i class="fas fa-ticket-alt text-purple-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'tickets.php' && $current_dir == 'stu_dash') ? 'text-purple-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-purple-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'tickets.php' && $current_dir == 'stu_dash') ? 'text-purple-700 font-semibold' : '' ?>">Support Tickets</span>
            </a>

            <!-- My Leaves Link -->
            <a href="../stu_dash/my_leaves.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'my_leaves.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-yellow-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'my_leaves.php' && $current_dir == 'stu_dash') ? 'bg-yellow-200' : '' ?>"></div>
                     <i class="fas fa-calendar-check text-yellow-600 text-xs md:text-sm relative z-10 <?= $current_page == 'my_leaves.php' ? 'text-yellow-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-yellow-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'my_leaves.php' && $current_dir == 'stu_dash') ? 'text-yellow-700 font-semibold' : '' ?>">My Leaves</span>
            </a>

            <!-- My Certificate Link (NEW) -->
            <a href="../stu_dash/my_certificate.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'my_certificate.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-amber-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'my_certificate.php' && $current_dir == 'stu_dash') ? 'bg-amber-200' : '' ?>"></div>
                    <i class="fas fa-certificate text-amber-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'my_certificate.php' && $current_dir == 'stu_dash') ? 'text-amber-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-amber-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'my_certificate.php' && $current_dir == 'stu_dash') ? 'text-amber-700 font-semibold' : '' ?>">My Certificate</span>
            </a>

            <!-- My Profile Link -->
            <a href="../stu_dash/student_profile.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-cyan-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'bg-cyan-200' : '' ?>"></div>
                    <i class="fas fa-user-circle text-cyan-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'text-cyan-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-cyan-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'student_profile.php' && $current_dir == 'stu_dash') ? 'text-cyan-700 font-semibold' : '' ?>">My Profile</span>
            </a>

            <!-- FAQ Link (NEW) -->
            <a href="../stu_dash/faq.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'faq.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-teal-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'faq.php' && $current_dir == 'stu_dash') ? 'bg-teal-200' : '' ?>"></div>
                    <i class="fas fa-question-circle text-teal-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'faq.php' && $current_dir == 'stu_dash') ? 'text-teal-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-teal-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'faq.php' && $current_dir == 'stu_dash') ? 'text-teal-700 font-semibold' : '' ?>">FAQ</span>
            </a>

            <!-- My Performance Link -->
            <a href="../stu_dash/my_performance.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-red-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'bg-red-200' : '' ?>"></div>
                    <i class="fas fa-chart-line text-red-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'text-red-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-red-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'my_performance.php' && $current_dir == 'stu_dash') ? 'text-red-700 font-semibold' : '' ?>">My Performance</span>
            </a>

            <!-- Divider for remaining links -->
            <div class="my-2 border-t border-blue-200/50"></div>

            <!-- Upcoming Schedule Link -->
            <a href="../stu_dash/upcoming.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-purple-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'bg-purple-200' : '' ?>"></div>
                    <i class="fas fa-calendar-alt text-purple-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'text-purple-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-purple-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'upcoming.php' && $current_dir == 'stu_dash') ? 'text-purple-700 font-semibold' : '' ?>">Upcoming Schedule</span>
            </a>


            <!-- Feedback Link -->
            <a href="../stu_dash/student_feedback.php" class="sidebar-link group py-2 md:py-3 px-3 md:px-4 rounded-lg md:rounded-xl flex items-center space-x-2 md:space-x-3 transition-all duration-300 hover:bg-white/90 hover:shadow-md <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'active bg-white shadow-md' : '' ?>">
                <div class="relative w-6 h-6 md:w-8 md:h-8 flex items-center justify-center">
                    <div class="absolute inset-0 bg-indigo-100 rounded-lg transform transition-all duration-300 <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'bg-indigo-200' : '' ?>"></div>
                    <i class="fas fa-comment-dots text-indigo-600 text-xs md:text-sm relative z-10 <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'text-indigo-700' : '' ?>"></i>
                </div>
                <span class="font-medium text-gray-700 group-hover:text-indigo-700 transition-colors duration-300 text-xs md:text-sm <?= ($current_page == 'student_feedback.php' && $current_dir == 'stu_dash') ? 'text-indigo-700 font-semibold' : '' ?>">Feedback</span>
            </a>
        </nav>
    </div>
    
    <!-- Sidebar Footer with User Info -->
    <div class="p-2 md:p-3 lg:p-4 border-t border-gray-200 bg-white/90 backdrop-blur-sm flex-shrink-0">
        <div class="flex items-center space-x-2 md:space-x-3">
            <div class="relative">
                <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold transform transition-all duration-300 hover:scale-105">
                    <a href="../logout.php" title="Logout" class="w-full h-full flex items-center justify-center">
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
    /* ============================================================
       SIDEBAR — Brand Design System (ASD Academy)
       Palette: #1B3C53 | #234C6A | #456882 | #D2C1B6 | #A4C4D4
    ============================================================ */

    /* Google Fonts — Inter */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

    /* --- Typography baseline ---
       IMPORTANT: exclude <i> tags so Font Awesome pseudo-elements are never clobbered */
    #sidebar *:not(i) {
        font-family: 'Inter', sans-serif;
    }

    /* Restore Font Awesome font explicitly on icon elements */
    #sidebar i,
    #sidebar i::before,
    #sidebar i::after {
        font-family: 'Font Awesome 6 Free', 'Font Awesome 5 Free', 'FontAwesome' !important;
        font-style: normal;
        font-variant: normal;
        text-rendering: auto;
        -webkit-font-smoothing: antialiased;
    }
    #sidebar i.fab,
    #sidebar i.fab::before {
        font-family: 'Font Awesome 6 Brands', 'Font Awesome 5 Brands' !important;
    }

    /* --- Sidebar container --- */
    #sidebar {
        background: linear-gradient(180deg, #1B3C53 0%, #234C6A 55%, #456882 100%) !important;
        border-right: 1px solid rgba(164, 196, 212, 0.18) !important;
        box-shadow: 4px 0 24px rgba(27, 60, 83, 0.18) !important;
    }

    /* --- Sidebar header (logo area) --- */
    #sidebar > div:first-child {
        background: linear-gradient(90deg, #1B3C53, #234C6A) !important;
        border-bottom: 1px solid rgba(164, 196, 212, 0.2) !important;
    }

    /* Close button */
    #sidebarToggle {
        color: #A4C4D4 !important;
    }
    #sidebarToggle:hover {
        color: #ffffff !important;
    }

    /* --- Navigation links — default (inactive) --- */
    #sidebar .sidebar-link {
        border-radius: 12px;
    }

    /* Icon container background — override all Tailwind bg-*-100 colours */
    #sidebar .sidebar-link .absolute.inset-0 {
        background: rgba(255, 255, 255, 0.08) !important;
        border-radius: 8px;
    }

    /* Icon colour — beat all Tailwind text-*-600/700 utilities on <i> tags */
    #sidebar .sidebar-link i,
    #sidebar .sidebar-link i[class],
    #sidebar nav a i,
    #sidebar nav a i[class*="fa"] {
        color: #A4C4D4 !important;
    }

    /* Link text */
    #sidebar .sidebar-link span {
        color: #D2C1B6 !important;
        font-size: 0.92rem !important;
        font-weight: 500 !important;
    }

    /* --- Hover state --- */
    #sidebar .sidebar-link:hover {
        background: rgba(255, 255, 255, 0.12) !important;
        box-shadow: 0 2px 8px rgba(27, 60, 83, 0.18) !important;
    }
    #sidebar .sidebar-link:hover span {
        color: #ffffff !important;
    }
    #sidebar .sidebar-link:hover i,
    #sidebar .sidebar-link:hover i[class],
    #sidebar nav a:hover i[class*="fa"] {
        color: #ffffff !important;
    }
    #sidebar .sidebar-link:hover .absolute.inset-0 {
        background: rgba(255, 255, 255, 0.12) !important;
    }

    /* --- Active state --- */
    #sidebar .sidebar-link.active {
        background: linear-gradient(135deg, rgba(164, 196, 212, 0.22), rgba(255, 255, 255, 0.13)) !important;
        border-left: 3px solid #A4C4D4 !important;
        box-shadow: 0 2px 12px rgba(27, 60, 83, 0.22) !important;
    }
    #sidebar .sidebar-link.active span {
        color: #ffffff !important;
        font-weight: 700 !important;
    }
    #sidebar .sidebar-link.active i,
    #sidebar .sidebar-link.active i[class],
    #sidebar nav a.active i[class*="fa"] {
        color: #A4C4D4 !important;
    }
    #sidebar .sidebar-link.active .absolute.inset-0 {
        background: rgba(164, 196, 212, 0.22) !important;
    }

    /* --- Section divider --- */
    #sidebar .border-t {
        border-color: rgba(164, 196, 212, 0.18) !important;
    }

    /* --- Sidebar footer (user info / logout) --- */
    #sidebar > div:last-child {
        background: linear-gradient(135deg, rgba(27, 60, 83, 0.7), rgba(35, 76, 106, 0.85)) !important;
        border-top: 1px solid rgba(164, 196, 212, 0.18) !important;
    }

    /* User name */
    #sidebar > div:last-child p:first-child {
        color: #ffffff !important;
        font-weight: 600 !important;
    }

    /* Role / sub-text */
    #sidebar > div:last-child p:last-child {
        color: #A4C4D4 !important;
        font-size: 0.78rem !important;
    }

    /* Avatar / icon circle */
    #sidebar > div:last-child .rounded-full {
        background: linear-gradient(135deg, #456882, #234C6A) !important;
        border: 2px solid #A4C4D4 !important;
    }

    /* Logout icon colour inside avatar */
    #sidebar > div:last-child .rounded-full i {
        color: #ffffff !important;
    }

    /* --- Scrollbar --- */
    .scrollbar-thin {
        scrollbar-width: thin;
        scrollbar-color: #456882 #1B3C53;
    }
    .scrollbar-thin::-webkit-scrollbar {
        width: 5px;
    }
    .scrollbar-thin::-webkit-scrollbar-track {
        background: #1B3C53;
        border-radius: 3px;
    }
    .scrollbar-thin::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #456882, #234C6A);
        border-radius: 3px;
    }
    .scrollbar-thin::-webkit-scrollbar-thumb:hover {
        background: #A4C4D4;
    }

    /* --- Responsive widths (layout unchanged) --- */
    @media (max-width: 768px) {
        #sidebar { width: 280px; }
    }
    @media (min-width: 769px) and (max-width: 1024px) {
        #sidebar { width: 240px; }
    }
    @media (min-width: 1025px) {
        #sidebar { width: 256px; }
    }
</style>

<script>
// Toggle sidebar on mobile
document.addEventListener('DOMContentLoaded', function() {




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
    
    // Ignore clicks on toggle buttons
    if (e.target.closest('[onclick*="toggleSidebar"]')) {
        return;
    }
    
    if (window.innerWidth < 768 && sidebar && !sidebar.contains(e.target) && 
        overlay && !overlay.classList.contains('hidden')) {
        toggleSidebar();
    }
});

// Activity Tracking
function pingActivity() {
    const pageUrl = window.location.pathname + window.location.search;
    fetch('../activity_logs/stu_activity_ping.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'page_url=' + encodeURIComponent(pageUrl)
    }).catch(err => console.error('Activity ping failed:', err));
}
// Initial ping
pingActivity();
// Ping every 30 seconds
setInterval(pingActivity, 30000);

</script>