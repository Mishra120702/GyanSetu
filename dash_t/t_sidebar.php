<!-- t_sidebar.php -->

<?php
/* Dynamic sidebar path helper.
   Fixes links when this sidebar is included from /dash_t/profile.php
   and also from deeper pages like /dash_t/dashboard/dashboard.php. */
if (!function_exists('ts_sidebar_base')) {
    function ts_sidebar_base(): string {
        $script = str_replace('\\\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $marker = '/dash_t/';
        $pos = strpos($script, $marker);

        if ($pos === false) {
            return '';
        }

        $afterDashT = substr($script, $pos + strlen($marker));
        $dir = trim(dirname($afterDashT), './\\\\');

        // Direct page inside dash_t, example: /dash_t/profile.php
        if ($dir === '' || $dir === '.') {
            return '';
        }

        // Page inside one sub-folder, example: /dash_t/tickets/tickets.php
        return '../';
    }

    function ts_url(string $path): string {
        return htmlspecialchars(ts_sidebar_base() . ltrim($path, '/'), ENT_QUOTES, 'UTF-8');
    }

    function ts_asset(string $path): string {
        return htmlspecialchars(ts_sidebar_base() . '../' . ltrim($path, '/'), ENT_QUOTES, 'UTF-8');
    }
    function ts_profile_picture_url(?string $path): string {
        $clean = trim(str_replace('\\\\', '/', (string)$path));
        if ($clean === '') {
            return '';
        }

        if (preg_match('/^(https?:)?\/\//', $clean) || str_starts_with($clean, 'data:image/')) {
            return htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
        }

        $clean = ltrim($clean, './');

        // If DB stores ../../uploads/profiles/file.jpg, normalize it for current page depth.
        $uploadsPos = strpos($clean, 'uploads/');
        if ($uploadsPos !== false) {
            $uploadsPath = substr($clean, $uploadsPos);
            return htmlspecialchars(ts_sidebar_base() . '../../' . $uploadsPath, ENT_QUOTES, 'UTF-8');
        }

        // If DB stores only filename, assume uploads/profiles.
        return htmlspecialchars(ts_sidebar_base() . '../../uploads/profiles/' . basename($clean), ENT_QUOTES, 'UTF-8');
    }

}
?>

<!-- Trainer Sidebar -->
<aside class="fixed left-0 top-0 z-50 h-[100dvh] w-64 bg-gradient-to-b from-gray-900 to-black shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col">
    <!-- Logo and Brand -->
    <div class="flex items-center justify-center h-16 lg:h-20 border-b border-gray-800 px-4">
        <div class="flex items-center space-x-2 lg:space-x-3">
            <a href="<?= ts_url('dashboard/dashboard.php') ?>" class="flex items-center justify-center">
            <img src="<?= ts_asset('logo2.png') ?>" alt="ASD Academy Logo" class="h-8 md:h-10 lg:h-12 w-auto object-contain transition-all duration-300 hover:scale-105 filter drop-shadow-lg">
        </a>
        </div>
    </div>

    <!-- User Profile -->
    <div class="p-4 lg:p-6 border-b border-gray-800">
        <div class="flex items-center space-x-3">
            <div class="relative flex-shrink-0">
                <?php
                $ts_trainer_name = (isset($trainer) && !empty($trainer['name'])) ? $trainer['name'] : 'Trainer';
                $ts_trainer_initial = strtoupper(substr($ts_trainer_name, 0, 1));
                $ts_profile_picture = isset($trainer['profile_picture']) ? trim((string)$trainer['profile_picture']) : '';
                ?>
                <?php if ($ts_profile_picture !== ''): ?>
                    <img src="<?= ts_profile_picture_url($ts_profile_picture) ?>" 
                         alt="Profile Picture" 
                         class="sidebar-profile-photo"
                         onerror="this.style.display='none'; this.nextElementSibling.classList.remove('hidden-fallback');">
                    <div class="sidebar-profile-fallback hidden-fallback text-base lg:text-lg">
                        <?= htmlspecialchars($ts_trainer_initial, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php else: ?>
                    <div class="sidebar-profile-fallback text-base lg:text-lg">
                        <?= htmlspecialchars($ts_trainer_initial, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <span class="absolute bottom-0 right-0 w-2 h-2 lg:w-3 lg:h-3 bg-green-500 rounded-full border-2 border-gray-900"></span>
            </div>
            <div class="flex-1 min-w-0">
                <h3 class="text-white font-semibold text-sm lg:text-base truncate">
                    <?php 
                    if (isset($trainer) && !empty($trainer['name'])) {
                        echo htmlspecialchars($trainer['name']);
                    } else {
                        echo "Trainer";
                    }
                    ?>
                </h3>
                <p class="text-xs text-gray-400 truncate">Trainer</p>
            </div>
            <button id="sidebarToggle" class="lg:hidden text-gray-400 hover:text-white flex-shrink-0">
                <i class="fas fa-times text-base lg:text-lg"></i>
            </button>
        </div>
    </div>

    <!-- Navigation Menu -->
    <nav class="p-2 lg:p-4 space-y-1 overflow-y-auto flex-1 min-h-0">
        <!-- Dashboard -->
        <a href="<?= ts_url('dashboard/dashboard.php') ?>" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-blue-900 to-blue-700 flex items-center justify-center group-hover:from-blue-700 group-hover:to-cyan-500 flex-shrink-0">
                <i class="fas fa-tachometer-alt text-blue-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Dashboard</span>
        </a>

        <!-- My Courses -->
        <div class="mb-2">
            <a href="<?= ts_url('courses/my_courses.php') ?>" class="sidebar-link flex items-center px-4 lg:px-6 py-3 relative group <?php echo basename($_SERVER['PHP_SELF']) == 'my_courses.php' ? 'active-link bg-indigo-600/10' : 'hover:bg-gray-800/50' ?>">
                <div class="icon-container w-8 h-8 rounded-lg flex items-center justify-center mr-3 <?php echo basename($_SERVER['PHP_SELF']) == 'my_courses.php' ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-500/30' : 'bg-gray-800 text-gray-400 group-hover:text-indigo-400 group-hover:bg-gray-700 transition-all duration-300' ?>">
                    <i class="fas fa-book-open"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">My Courses</span>
                <?php if (basename($_SERVER['PHP_SELF']) == 'my_courses.php'): ?>
                    <div class="absolute right-0 top-0 bottom-0 w-1 bg-indigo-500 rounded-l-lg"></div>
                <?php endif; ?>
            </a>
        </div>

        <!-- My Students -->
        <a href="<?= ts_url('students/students.php') ?>" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-gray-800 bg-opacity-50 border-l-2 lg:border-l-4 border-blue-500' : ''; ?>">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-blue-900 to-blue-700 flex items-center justify-center group-hover:from-blue-600 group-hover:to-cyan-500 flex-shrink-0">
                <i class="fas fa-users text-blue-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">My Students</span>
        </a>

        <!-- Schedule -->
        <a href="<?= ts_url('schedule/schedule.php') ?>" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-green-900 to-green-700 flex items-center justify-center group-hover:from-green-600 group-hover:to-emerald-500 flex-shrink-0">
                <i class="fas fa-calendar-alt text-green-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Schedule</span>
        </a>

        <!-- Attendance -->
        <a href="<?= ts_url('attendance/trainer_attendance.php') ?>" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-amber-900 to-amber-700 flex items-center justify-center group-hover:from-amber-600 group-hover:to-orange-500 flex-shrink-0">
                <i class="fas fa-clipboard-check text-amber-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Attendance</span>
        </a>
        

        <a href="<?= ts_url('leaves/leaves.php') ?>" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'leaves.php' ? 'bg-gray-800 bg-opacity-50 border-l-2 lg:border-l-4 border-blue-500' : ''; ?>">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-blue-900 to-blue-700 flex items-center justify-center group-hover:from-blue-600 group-hover:to-cyan-500 flex-shrink-0">
                <i class="fas fa-users text-blue-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">My Leaves</span>
        </a>
        <!-- Exams & Assignments -->
        <div class="pt-3 lg:pt-4">
            <p class="text-xs uppercase text-gray-500 font-bold tracking-wider px-2 lg:px-3 mb-1 lg:mb-2 truncate">Assessments</p>
            
            <!-- Exams -->
            <a href="<?= ts_url('exam/trainer_dashboard.php') ?>" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-red-900 to-red-700 flex items-center justify-center group-hover:from-red-600 group-hover:to-pink-500 flex-shrink-0">
                    <i class="fas fa-file-alt text-red-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Exams</span>
            </a>
            <a href="<?= ts_url('tests/tests.php') ?>" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-red-900 to-red-700 flex items-center justify-center group-hover:from-red-600 group-hover:to-pink-500 flex-shrink-0">
                    <i class="fas fa-file-alt text-red-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Tests</span>
            </a>

            <!-- Assignments -->
            <a href="<?= ts_url('content/trainer_content.php') ?>" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-indigo-900 to-indigo-700 flex items-center justify-center group-hover:from-indigo-600 group-hover:to-purple-500 flex-shrink-0">
                    <i class="fas fa-tasks text-indigo-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Study Materials</span>
            </a>
            <a href="<?= ts_url('profile.php') ?>" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-indigo-900 to-indigo-700 flex items-center justify-center group-hover:from-indigo-600 group-hover:to-purple-500 flex-shrink-0">
                    <i class="fas fa-person text-indigo-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Profile</span>
            </a>
            <a href="<?= ts_url('tickets/tickets.php') ?>" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-purple-900 to-purple-700 flex items-center justify-center group-hover:from-purple-600 group-hover:to-pink-500 flex-shrink-0">
                    <i class="fas fa-ticket-alt text-purple-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Support Tickets</span>
            </a>
            <a href="<?= ts_url('feedback/feedback.php') ?>" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-orange-900 to-orange-700 flex items-center justify-center group-hover:from-orange-600 group-hover:to-yellow-500 flex-shrink-0">
                    <i class="fas fa-star text-orange-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Feedback</span>
            </a>

        </div>
    </nav>

    <!-- Bottom Section -->
    <div class="p-3 lg:p-4 border-t border-gray-800 bg-gray-900 bg-opacity-50 mt-auto">
        <!-- Settings and Logout -->
        <div class="flex justify-between items-center">
            <!-- Logout -->
            <a href="<?= ts_url('logout.php') ?>" 
               class="flex items-center space-x-2 p-2 text-gray-400 hover:text-red-400 transition-colors rounded-lg hover:bg-gray-800 w-full justify-center lg:justify-start">
                <i class="fas fa-sign-out-alt text-sm lg:text-lg"></i>
                <span class="text-xs lg:text-sm font-medium">Logout</span>
            </a>
        </div>
    </div>
</aside>

<!-- Mobile Toggle Button -->
<button id="mobileSidebarToggle" class="lg:hidden fixed top-3 left-3 z-40 p-2 bg-gradient-to-r from-[#1B3C53] via-[#234C6A] to-[#456882] text-white rounded-lg shadow-lg">
    <i class="fas fa-bars text-base lg:text-lg"></i>
</button>

<!-- Overlay for mobile -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden hidden transition-opacity duration-300" onclick="hideSidebar()"></div>

<style>
    /* Smooth animations */
    .sidebar-link {
        position: relative;
        overflow: hidden;
    }
    
    .sidebar-link::after {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
        transition: left 0.7s;
    }
    
    .sidebar-link:hover::after {
        left: 100%;
    }
    
    /* Custom scrollbar for sidebar */
    nav::-webkit-scrollbar {
        width: 3px;
    }
    
    @media (min-width: 1024px) {
        nav::-webkit-scrollbar {
            width: 4px;
        }
    }
    
    nav::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.05);
        border-radius: 10px;
    }
    
    nav::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,0.2);
        border-radius: 10px;
    }
    
    nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255,255,255,0.3);
    }
    
    /* Animation for active link */
    .active-sidebar-link {
        box-shadow: inset 2px 0 0 0 var(--active-color);
        background: linear-gradient(90deg, rgba(255,255,255,0.05), transparent);
    }
    
    @media (min-width: 1024px) {
        .active-sidebar-link {
            box-shadow: inset 4px 0 0 0 var(--active-color);
        }
    }
    
    /* Ripple effect for sidebar links */
    .ripple-effect {
        position: absolute;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.3);
        transform: scale(0);
        animation: ripple 0.6s linear;
        pointer-events: none;
    }
    
    @keyframes ripple {
        to {
            transform: scale(4);
            opacity: 0;
        }
    }
    
    /* Mobile optimizations */
    @media (max-width: 767px) {
        aside {
            width: 280px;
        }
        
        nav {
            padding: 0.75rem;
        }
        
        .sidebar-link {
            padding: 0.625rem 0.75rem;
        }
    }
    
    @media (max-width: 639px) {
        aside {
            width: 250px;
        }
    }
    
    /* Improve touch targets on mobile */
    @media (max-width: 1023px) {
        .sidebar-link {
            min-height: 44px;
        }
        
        #mobileSidebarToggle {
            min-width: 44px;
            min-height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    }
    
    /* Prevent text overflow */
    .truncate {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
}
body {
    background:
        radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
        radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
        linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-[#234C6A],.text-[#234C6A],.text-[#1B3C53],.text-[#1B3C53],.text-[#234C6A],.text-[#234C6A],.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-[#D2C1B6]/50,.border-[#D2C1B6]/50,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    
/* ===== REFINED SIDEBAR GLOW + SWITCH EFFECTS ===== */
aside {
    background:
        radial-gradient(circle at 18% 8%, rgba(210,193,182,.16), transparent 26%),
        linear-gradient(180deg, #0F2D42 0%, #1B3C53 42%, #071923 100%) !important;
    border-right: 1px solid rgba(210,193,182,.18) !important;
    box-shadow: 18px 0 45px rgba(27,60,83,.22) !important;
}

aside .border-gray-800,
aside .border-t,
aside .border-b {
    border-color: rgba(210,193,182,.14) !important;
}

.sidebar-link {
    position: relative !important;
    isolation: isolate !important;
    border: 1px solid transparent !important;
    border-radius: 15px !important;
    overflow: hidden !important;
    color: rgba(255,255,255,.78) !important;
    transition: transform .22s ease, background .22s ease, box-shadow .22s ease, border-color .22s ease !important;
}

.sidebar-link::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    background: linear-gradient(90deg, rgba(210,193,182,.18), rgba(69,104,130,.13)) !important;
    opacity: 0 !important;
    transition: opacity .25s ease !important;
    z-index: -1 !important;
}

.sidebar-link::after {
    background: linear-gradient(90deg, transparent, rgba(210,193,182,.20), transparent) !important;
}

.sidebar-link:hover {
    transform: translateX(7px) !important;
    background: rgba(210,193,182,.10) !important;
    border-color: rgba(210,193,182,.20) !important;
    box-shadow: 0 12px 26px rgba(0,0,0,.18) !important;
}

.sidebar-link:hover::before {
    opacity: 1 !important;
}

.sidebar-link span {
    color: rgba(255,255,255,.82) !important;
    font-weight: 750 !important;
}

.sidebar-link:hover span,
.sidebar-link.active-sidebar-link span,
.sidebar-link.active-link span {
    color: #ffffff !important;
}

.sidebar-link > div,
.sidebar-link .icon-container {
    background: linear-gradient(135deg, rgba(210,193,182,.18), rgba(69,104,130,.22)) !important;
    color: rgba(255,255,255,.88) !important;
    border: 1px solid rgba(210,193,182,.14) !important;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.12) !important;
    transition: transform .22s ease, box-shadow .22s ease, background .22s ease !important;
}

.sidebar-link:hover > div,
.sidebar-link:hover .icon-container,
.sidebar-link.active-sidebar-link > div,
.sidebar-link.active-link .icon-container {
    background: linear-gradient(135deg, #D2C1B6, #456882) !important;
    color: #1B3C53 !important;
    transform: scale(1.06) rotate(-2deg) !important;
    box-shadow: 0 10px 22px rgba(210,193,182,.20) !important;
}

.sidebar-link i {
    color: inherit !important;
}

.sidebar-link.active-sidebar-link,
.sidebar-link.active-link,
nav a.active-sidebar-link {
    background: linear-gradient(90deg, rgba(210,193,182,.23), rgba(69,104,130,.16)) !important;
    border-color: rgba(210,193,182,.32) !important;
    box-shadow: inset 4px 0 0 #D2C1B6, 0 12px 28px rgba(0,0,0,.16) !important;
    transform: translateX(4px) !important;
}

#mobileSidebarToggle {
    background: linear-gradient(135deg, #1B3C53, #234C6A, #456882) !important;
    box-shadow: 0 12px 26px rgba(27,60,83,.24) !important;
    border: 1px solid rgba(210,193,182,.25) !important;
}

aside .bg-gray-900 {
    background: rgba(8,25,35,.55) !important;
}

aside .text-gray-400 {
    color: rgba(255,255,255,.62) !important;
}

aside .text-gray-500 {
    color: rgba(210,193,182,.62) !important;
}

</style>

<script>
    // Sidebar Toggle Functions
    const sidebar = document.querySelector('aside');
    const mobileToggleBtn = document.getElementById('mobileSidebarToggle');
    const sidebarToggleBtn = document.getElementById('sidebarToggle');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    
    function showSidebar() {
        sidebar.classList.remove('-translate-x-full');
        sidebarOverlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    
    function hideSidebar() {
        sidebar.classList.add('-translate-x-full');
        sidebarOverlay.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
    
    // Mobile toggle from attendance page
    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', showSidebar);
    }
    
    mobileToggleBtn.addEventListener('click', showSidebar);
    sidebarToggleBtn.addEventListener('click', hideSidebar);
    sidebarOverlay.addEventListener('click', hideSidebar);
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', function(event) {
        if (window.innerWidth < 1024 && 
            sidebar && !sidebar.contains(event.target) && 
            mobileToggleBtn && !mobileToggleBtn.contains(event.target) && 
            mobileMenuToggle && !mobileMenuToggle.contains(event.target) && 
            !sidebar.classList.contains('-translate-x-full')) {
            hideSidebar();
        }
    });
    
    // Keyboard shortcut for toggling sidebar (Esc to close)
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && window.innerWidth < 1024) {
            hideSidebar();
        }
        if (event.ctrlKey && event.key === 'b') {
            event.preventDefault();
            if (window.innerWidth < 1024) {
                sidebar.classList.contains('-translate-x-full') ? showSidebar() : hideSidebar();
            }
        }
    });
    
    // Add active class to current page link
    document.addEventListener('DOMContentLoaded', function() {
        const currentPage = window.location.pathname.split('/').pop();
        const navLinks = document.querySelectorAll('nav a');
        
        navLinks.forEach(link => {
            const linkHref = link.getAttribute('href');
            if (linkHref && linkHref.includes(currentPage)) {
                link.classList.add('active-sidebar-link');
                
                // Add specific border color based on section
                if (linkHref.includes('batches.php')) {
                    link.style.setProperty('--active-color', '#234C6A', 'important');
                } else if (linkHref.includes('students.php')) {
                    link.style.setProperty('--active-color', '#234C6A', 'important');
                }
            }
        });
    });
    
    // Add ripple effect to sidebar links
    document.querySelectorAll('nav a').forEach(link => {
        link.addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const ripple = document.createElement('span');
            ripple.classList.add('ripple-effect');
            ripple.style.left = x + 'px';
            ripple.style.top = y + 'px';
            
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
            
            // Close sidebar on mobile after clicking a link
            if (window.innerWidth < 1024) {
                setTimeout(hideSidebar, 300);
            }
        });
    });
    
    // Handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 1024) {
            sidebar.classList.remove('-translate-x-full');
            sidebarOverlay.classList.add('hidden');
            document.body.style.overflow = 'auto';
        } else {
            if (!sidebar.classList.contains('-translate-x-full')) {
                document.body.style.overflow = 'hidden';
            }
        }
    });
    
    // Initialize based on screen size
    if (window.innerWidth >= 1024) {
        sidebar.classList.remove('-translate-x-full');
    }
</script>


<!-- ===== FINAL SIDEBAR FIX: stable size + exact active state ===== -->
<style>
/* Sidebar width fallback: works even when Tailwind utilities are weak/missing on some pages */
body > aside,
aside.fixed,
aside[class*="w-64"] {
    width: 16rem !important;
    min-width: 16rem !important;
    max-width: 16rem !important;
    height: 100dvh !important;
    position: fixed !important;
    left: 0 !important;
    top: 0 !important;
    display: flex !important;
    flex-direction: column !important;
    flex-shrink: 0 !important;
    z-index: 9999 !important;
    box-sizing: border-box !important;
}

/* Desktop: sidebar should never shrink on Exams/Dashboard switching */
@media (min-width: 1024px) {
    body > aside,
    aside.fixed,
    aside[class*="w-64"] {
        transform: translateX(0) !important;
    }

    .lg\:ml-64,
    #main-content,
    main.lg\:ml-64,
    .flex-1.lg\:ml-64,
    .ml-0.lg\:ml-64 {
        margin-left: 16rem !important;
    }
}

/* Mobile size fallback */
@media (max-width: 1023px) {
    body > aside,
    aside.fixed,
    aside[class*="w-64"] {
        width: 17.5rem !important;
        min-width: 17.5rem !important;
        max-width: 17.5rem !important;
    }
}

/* Keep logo/profile/nav spacing consistent across all pages */
aside nav {
    padding: 1rem !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
}

aside .sidebar-link {
    display: flex !important;
    align-items: center !important;
    gap: .75rem !important;
    min-height: 48px !important;
    padding: .72rem .82rem !important;
    border-radius: 15px !important;
    line-height: 1.2 !important;
    text-decoration: none !important;
}

aside .sidebar-link > div,
aside .sidebar-link .icon-container {
    width: 2rem !important;
    height: 2rem !important;
    min-width: 2rem !important;
    min-height: 2rem !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}

aside .sidebar-link span {
    font-size: .95rem !important;
    line-height: 1.2 !important;
}

/* Only one active link should glow. No double-active nonsense, thank you browser. */
aside .sidebar-link.active-sidebar-link,
aside .sidebar-link.active-link {
    background: linear-gradient(90deg, rgba(210,193,182,.25), rgba(69,104,130,.18)) !important;
    border-color: rgba(210,193,182,.36) !important;
    box-shadow: inset 4px 0 0 #D2C1B6, 0 12px 28px rgba(0,0,0,.18) !important;
    transform: translateX(4px) !important;
}

aside .sidebar-link.active-sidebar-link > div,
aside .sidebar-link.active-link > div,
aside .sidebar-link.active-sidebar-link .icon-container,
aside .sidebar-link.active-link .icon-container {
    background: linear-gradient(135deg, #D2C1B6, #456882) !important;
    color: #1B3C53 !important;
}

aside .sidebar-link.active-sidebar-link i,
aside .sidebar-link.active-link i {
    color: #1B3C53 !important;
}

aside .sidebar-link.active-sidebar-link span,
aside .sidebar-link.active-link span {
    color: #ffffff !important;
}
</style>

<script>
/* Exact active sidebar resolver.
   Old code used linkHref.includes(currentPage), so dashboard.php also matched trainer_dashboard.php.
   Humanity invented substring bugs, then asked me to clean them. */
(function () {
    function getDashRelativePath(urlLike) {
        try {
            const url = new URL(urlLike, window.location.href);
            const path = url.pathname.replace(/\\/g, '/');
            const marker = '/dash_t/';
            const index = path.indexOf(marker);
            if (index >= 0) {
                return path.slice(index + marker.length).replace(/^\/+/, '');
            }
            return path.split('/').pop() || '';
        } catch (e) {
            return '';
        }
    }

    function applyExactSidebarActive() {
        const currentRel = getDashRelativePath(window.location.href);
        const navLinks = document.querySelectorAll('aside nav a.sidebar-link');

        navLinks.forEach(link => {
            link.classList.remove('active-sidebar-link', 'active-link', 'bg-indigo-600/10', 'bg-gray-800', 'bg-opacity-50', 'border-l-2', 'lg:border-l-4', 'border-blue-500');

            const indicator = link.querySelector('.absolute.right-0.top-0.bottom-0.w-1');
            if (indicator) indicator.style.display = 'none';

            const linkRel = getDashRelativePath(link.getAttribute('href') || '');
            if (linkRel === currentRel) {
                link.classList.add('active-sidebar-link');
                link.style.setProperty('--active-color', '#D2C1B6', 'important');
                if (indicator) indicator.style.display = 'block';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyExactSidebarActive);
    } else {
        applyExactSidebarActive();
    }

    window.addEventListener('pageshow', applyExactSidebarActive);
})();
</script>
<style>

/* ===== SAFE SIDEBAR PROFILE PICTURE PATCH ===== */
/* Visual-only. Sidebar links, active state JS, dynamic paths and navigation untouched. */
.sidebar-profile-photo,
.sidebar-profile-fallback {
    width: 3rem !important;
    height: 3rem !important;
    min-width: 3rem !important;
    min-height: 3rem !important;
    border-radius: 999px !important;
    border: 2px solid rgba(210,193,182,.62) !important;
    box-shadow:
        0 12px 26px rgba(0,0,0,.24),
        inset 0 1px 0 rgba(255,255,255,.18) !important;
}

.sidebar-profile-photo {
    object-fit: cover !important;
    background: rgba(255,255,255,.10) !important;
}

.sidebar-profile-fallback {
    align-items: center !important;
    justify-content: center !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.28), transparent 34%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 52%, #456882 100%) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    font-weight: 900 !important;
}

.sidebar-profile-fallback:not(.hidden-fallback) {
    display: flex !important;
}

.sidebar-profile-fallback.hidden-fallback {
    display: none !important;
}

@media (max-width: 1023px) {
    .sidebar-profile-photo,
    .sidebar-profile-fallback {
        width: 2.6rem !important;
        height: 2.6rem !important;
        min-width: 2.6rem !important;
        min-height: 2.6rem !important;
    }
}

</style>
