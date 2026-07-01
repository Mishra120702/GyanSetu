<?php
// session_start(); // Ensure session is started
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
?>
<!-- Enhanced Skeuomorphic Sidebar -->
<div id="sidebar" class="w-64 bg-gradient-to-b from-white via-gray-50 to-gray-100 border-r border-gray-200 h-screen fixed transform transition-all duration-300 ease-in-out -translate-x-full md:translate-x-0 z-40 shadow-2xl flex flex-col">
    <!-- Sidebar Header with Skeuomorphic Effect -->
    <div class="p-6 bg-gradient-to-b from-blue-50 to-blue-100 border-b border-blue-200 flex-shrink-0 relative overflow-hidden">
        <!-- Decorative Shine Effect -->
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-20 transform -skew-x-12"></div>
        
        <div class="flex items-center space-x-2 relative">
            <div class="w-10 h-10 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-xl shadow-lg flex items-center justify-center transform hover:scale-105 transition-transform duration-300">
                <i class="fas fa-graduation-cap text-white text-xl"></i>
            </div>
            <div class="flex-1">
                <a href="../dashboard/dashboard.php" class="block group">
                    <span class="text-xl font-bold bg-gradient-to-r from-blue-700 to-indigo-800 bg-clip-text text-transparent">ASD Admin</span>
                    <div class="h-0.5 w-0 group-hover:w-full bg-gradient-to-r from-blue-500 to-indigo-600 transition-all duration-300"></div>
                </a>
            </div>
            <button id="sidebarToggle" class="md:hidden text-gray-500 hover:text-blue-600 transition-all duration-200 hover:scale-110">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        
        <!-- Decorative Element -->
        <div class="absolute bottom-0 left-0 right-0 h-0.5 bg-gradient-to-r from-blue-400 via-indigo-500 to-blue-400"></div>
    </div>
    
    <!-- Navigation Menu Container with Improved Scrolling -->
    <div class="flex-1 overflow-hidden flex flex-col">
        <!-- Scrollable Content Area with Custom Scrollbar -->
        <nav class="flex-1 overflow-y-auto overflow-x-hidden p-4 space-y-2 scrollbar-custom" style="max-height: calc(100vh - 144px)">
            
            <!-- Dashboard Section -->
            <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
            <?php $current_path = $_SERVER['PHP_SELF']; ?>
            
            <a href="../dashboard/dashboard.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'dashboard.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-tachometer-alt text-lg <?= $current_page == 'dashboard.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'dashboard.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Dashboard</span>
                <?php if ($current_page == 'dashboard.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <!-- User Management Group -->
            <div class="mt-6 mb-3 px-4">
                <div class="flex items-center space-x-2">
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">User Management</div>
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                </div>
            </div>
            
            <a href="../batch/batch_list.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'batch_list.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'batch_list.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-users text-lg <?= $current_page == 'batch_list.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'batch_list.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Batch Management</span>
                <?php if ($current_page == 'batch_list.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../trainers/index.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= strpos($current_path, 'trainers') !== false ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= strpos($current_path, 'trainers') !== false ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-chalkboard-teacher text-lg <?= strpos($current_path, 'trainers') !== false ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= strpos($current_path, 'trainers') !== false ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Trainers</span>
                <?php if (strpos($current_path, 'trainers') !== false): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../student/students_list.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'students_list.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'students_list.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-user-graduate text-lg <?= $current_page == 'students_list.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'students_list.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Student Management</span>
                <?php if ($current_page == 'students_list.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <!-- Academic Group -->
            <div class="mt-6 mb-3 px-4">
                <div class="flex items-center space-x-2">
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Academic</div>
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                </div>
            </div>
            
            <a href="../attendance/attendance.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'attendance.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'attendance.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-clipboard-check text-lg <?= $current_page == 'attendance.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'attendance.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Attendance</span>
                <?php if ($current_page == 'attendance.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../exam/exams.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'exams.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'exams.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-file-alt text-lg <?= $current_page == 'exams.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'exams.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Exams</span>
                <?php if ($current_page == 'exams.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../leaves/leave_management.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= strpos($current_path, 'leave_management.php') !== false ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= strpos($current_path, 'leave_management.php') !== false ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-calendar-alt text-lg <?= strpos($current_path, 'leave_management.php') !== false ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= strpos($current_path, 'leave_management.php') !== false ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Leaves</span>
                <?php if (strpos($current_path, 'leave_management.php') !== false): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../workshops/workshop_list.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= strpos($current_path, 'workshops') !== false ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= strpos($current_path, 'workshops') !== false ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-tools text-lg <?= strpos($current_path, 'workshops') !== false ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= strpos($current_path, 'workshops') !== false ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Workshops</span>
                <?php if (strpos($current_path, 'workshops') !== false): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../content/upload_content.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'upload_content.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'upload_content.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-book text-lg <?= $current_page == 'upload_content.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'upload_content.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Content</span>
                <?php if ($current_page == 'upload_content.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../admin_test/admin_dashboard.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'admin_dashboard.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'admin_dashboard.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-vial text-lg <?= $current_page == 'admin_dashboard.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'admin_dashboard.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Tests</span>
                <?php if ($current_page == 'admin_dashboard.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <!-- Communication Group -->
            <div class="mt-6 mb-3 px-4">
                <div class="flex items-center space-x-2">
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Communication</div>
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                </div>
            </div>
            
            <a href="../chat/index.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= strpos($current_path, 'chat') !== false ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= strpos($current_path, 'chat') !== false ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-comments text-lg <?= strpos($current_path, 'chat') !== false ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= strpos($current_path, 'chat') !== false ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Chat</span>
                <span class="ml-auto bg-gradient-to-r from-blue-500 to-indigo-600 text-white text-xs font-medium px-2 py-0.5 rounded-full shadow-sm">New</span>
                <?php if (strpos($current_path, 'chat') !== false): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../feedback/feedback.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'feedback.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'feedback.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-comment-dots text-lg <?= $current_page == 'feedback.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'feedback.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Feedback</span>
                <?php if ($current_page == 'feedback.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../dashboard/tickets.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'tickets.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'tickets.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-ticket-alt text-lg <?= $current_page == 'tickets.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'tickets.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Tickets</span>
                <?php if ($current_page == 'tickets.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <!-- Financial Group -->
            <div class="mt-6 mb-3 px-4">
                <div class="flex items-center space-x-2">
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Financial</div>
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                </div>
            </div>
            
            <a href="../payment/payment_dash.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'payment_dash.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'payment_dash.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-credit-card text-lg <?= $current_page == 'payment_dash.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'payment_dash.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Payments</span>
                <?php if ($current_page == 'payment_dash.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../sales/sales.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'sales.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'sales.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-chart-line text-lg <?= $current_page == 'sales.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'sales.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Sales</span>
                <?php if ($current_page == 'sales.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <!-- System Group -->
            <div class="mt-6 mb-3 px-4">
                <div class="flex items-center space-x-2">
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider">System</div>
                    <div class="flex-1 h-px bg-gradient-to-r from-transparent via-gray-300 to-transparent"></div>
                </div>
            </div>
            
            <a href="../reports/index.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= strpos($current_path, 'reports') !== false ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= strpos($current_path, 'reports') !== false ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-chart-bar text-lg <?= strpos($current_path, 'reports') !== false ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= strpos($current_path, 'reports') !== false ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Reporting</span>
                <?php if (strpos($current_path, 'reports') !== false): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <a href="../settings/admin_settings.php" class="sidebar-link group relative py-3 px-4 rounded-xl flex items-center space-x-3 text-gray-700 transition-all duration-300 <?= $current_page == 'admin_settings.php' ? 'active-skeuo' : 'hover:bg-gradient-to-r hover:from-blue-50 hover:to-indigo-50 hover:shadow-md hover:translate-x-1' ?>">
                <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center <?= $current_page == 'admin_settings.php' ? 'bg-blue-600 shadow-md' : 'bg-gray-100 group-hover:bg-blue-100' ?> transition-all duration-300">
                    <i class="fas fa-cog text-lg <?= $current_page == 'admin_settings.php' ? 'text-white' : 'text-gray-600 group-hover:text-blue-600' ?>"></i>
                </div>
                <span class="font-medium <?= $current_page == 'admin_settings.php' ? 'text-blue-700' : 'group-hover:text-blue-700' ?>">Settings</span>
                <?php if ($current_page == 'admin_settings.php'): ?>
                    <div class="absolute right-3 w-1.5 h-8 bg-blue-600 rounded-full shadow-sm"></div>
                <?php endif; ?>
            </a>
            
            <!-- Logout Button with Special Styling -->
            <div class="mt-8 pt-4">
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-200"></div>
                    </div>
                    <div class="relative flex justify-center">
                        <span class="bg-gradient-to-b from-white via-gray-50 to-gray-100 px-4 text-xs text-gray-500">Account</span>
                    </div>
                </div>
                <a href="../logout_a.php" class="sidebar-link group mt-4 py-3 px-4 rounded-xl flex items-center space-x-3 text-red-600 transition-all duration-300 hover:bg-gradient-to-r hover:from-red-50 hover:to-red-100 hover:shadow-md hover:translate-x-1">
                    <div class="icon-wrapper w-8 h-8 rounded-lg flex items-center justify-center bg-red-100 group-hover:bg-red-200 transition-all duration-300">
                        <i class="fas fa-sign-out-alt text-lg text-red-600"></i>
                    </div>
                    <span class="font-medium">Logout</span>
                </a>
            </div>
        </nav>
    </div>
    
    <!-- Sidebar Footer - Fixed at bottom with Skeuomorphic Design -->
    <div class="p-4 border-t border-gray-200 bg-gradient-to-b from-gray-50 to-gray-100 flex-shrink-0 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-white to-transparent opacity-30"></div>
        <div class="flex items-center space-x-3 relative">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 shadow-lg flex items-center justify-center transform transition-all duration-300 hover:scale-105">
                <i class="fas fa-user text-white text-sm"></i>
            </div>
            <div class="flex-1">
                <div class="text-sm font-semibold text-gray-800"><?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?></div>
                <div class="text-xs text-gray-500">Administrator</div>
            </div>
            
        </div>
    </div>
</div>

<!-- Mobile Overlay with Blur Effect -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-30 hidden md:hidden transition-all duration-300"></div>

<style>
    /* Custom scrollbar styling with skeuomorphic design */
    .scrollbar-custom {
        scrollbar-width: thin;
        scrollbar-color: #3b82f6 #e2e8f0;
    }
    
    .scrollbar-custom::-webkit-scrollbar {
        width: 6px;
    }
    
    .scrollbar-custom::-webkit-scrollbar-track {
        background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
        border-radius: 10px;
    }
    
    .scrollbar-custom::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, #3b82f6 0%, #6366f1 100%);
        border-radius: 10px;
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.3), 0 1px 2px rgba(0,0,0,0.1);
    }
    
    .scrollbar-custom::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #2563eb 0%, #4f46e5 100%);
    }
    
    /* Active link styling with skeuomorphic effect */
    .active-skeuo {
        background: linear-gradient(135deg, #eff6ff 0%, #e0e7ff 100%);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.8), 0 2px 4px rgba(0,0,0,0.05);
        color: #1e40af;
        transform: translateX(2px);
    }
    
    .active-skeuo .icon-wrapper {
        background: linear-gradient(135deg, #3b82f6 0%, #4f46e5 100%);
        box-shadow: 0 2px 6px rgba(59,130,246,0.3);
    }
    
    /* Sidebar link hover effects */
    .sidebar-link {
        position: relative;
        overflow: hidden;
    }
    
    .sidebar-link::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(59,130,246,0.1), transparent);
        transition: left 0.5s ease;
    }
    
    .sidebar-link:hover::before {
        left: 100%;
    }
    
    /* Icon wrapper animation */
    .icon-wrapper {
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .sidebar-link:hover .icon-wrapper {
        transform: scale(1.05) rotate(2deg);
    }
    
    /* Group headers styling */
    .group-header {
        position: relative;
        margin: 1rem 0 0.5rem;
    }
    
    /* User dropdown animation */
    #userDropdown {
        animation: slideUp 0.2s ease-out;
    }
    
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Ensure proper height calculation */
    @media (max-height: 640px) {
        nav {
            max-height: calc(100vh - 144px) !important;
        }
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        #sidebar {
            box-shadow: 5px 0 25px rgba(0, 0, 0, 0.15);
        }
        
        .sidebar-link {
            margin: 2px 0;
        }
    }
    
    /* Glow effect for active link */
    .active-skeuo {
        position: relative;
    }
    
    .active-skeuo::after {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        left: 0;
        background: radial-gradient(circle at 30% 50%, rgba(59,130,246,0.1) 0%, transparent 70%);
        pointer-events: none;
    }
    
    /* Smooth transitions */
    #sidebar {
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    /* Improved hover states */
    .btn-hover-effect {
        transition: all 0.2s ease;
    }
    
    .btn-hover-effect:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
</style>

<script>
// Toggle sidebar on mobile
document.getElementById('sidebarToggle').addEventListener('click', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    sidebar.classList.toggle('-translate-x-full');
    overlay.classList.toggle('hidden');
});

// Close sidebar when clicking overlay
document.getElementById('sidebarOverlay').addEventListener('click', function() {
    document.getElementById('sidebar').classList.add('-translate-x-full');
    this.classList.add('hidden');
});

// Toggle user dropdown menu
document.addEventListener('DOMContentLoaded', function() {
    const userMenuButton = document.getElementById('userMenuButton');
    const userDropdown = document.getElementById('userDropdown');
    
    if (userMenuButton && userDropdown) {
        userMenuButton.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdown.classList.toggle('hidden');
        });
        
        // Close dropdown when clicking elsewhere
        document.addEventListener('click', function() {
            userDropdown.classList.add('hidden');
        });
        
        // Prevent dropdown from closing when clicking inside it
        userDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
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
    
    // Add active class to current page link for better visual feedback
    const currentPath = window.location.pathname;
    const sidebarLinks = document.querySelectorAll('.sidebar-link');
    
    sidebarLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && currentPath.includes(href.replace('../', ''))) {
            link.classList.add('active-skeuo');
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Toggle sidebar with Ctrl+Shift+S
        if (e.ctrlKey && e.shiftKey && e.key === 'S') {
            e.preventDefault();
            document.getElementById('sidebarToggle').click();
        }
        
        // Close sidebar with Escape
        if (e.key === 'Escape') {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebarOverlay');
            if (!sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
            }
        }
    });
    
    // Add ripple effect to sidebar links
    const links = document.querySelectorAll('.sidebar-link');
    links.forEach(link => {
        link.addEventListener('click', function(e) {
            const ripple = document.createElement('span');
            ripple.classList.add('ripple-effect');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = `${size}px`;
            ripple.style.left = `${e.clientX - rect.left - size / 2}px`;
            ripple.style.top = `${e.clientY - rect.top - size / 2}px`;
            ripple.style.position = 'absolute';
            ripple.style.borderRadius = '50%';
            ripple.style.backgroundColor = 'rgba(59,130,246,0.3)';
            ripple.style.pointerEvents = 'none';
            ripple.style.transform = 'scale(0)';
            ripple.style.transition = 'transform 0.5s ease, opacity 0.5s ease';
            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);
            
            setTimeout(() => {
                ripple.style.transform = 'scale(2)';
                ripple.style.opacity = '0';
            }, 10);
            
            setTimeout(() => {
                ripple.remove();
            }, 500);
        });
    });
});

// Close sidebar when window is resized to desktop size (if sidebar was open on mobile)
window.addEventListener('resize', function() {
    if (window.innerWidth >= 768) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.add('hidden');
    }
});

// Add smooth scroll behavior
document.querySelector('.scrollbar-custom')?.addEventListener('wheel', function(e) {
    if (e.deltaY !== 0) {
        this.scrollTop += e.deltaY;
        e.preventDefault();
    }
}, { passive: false });
</script>