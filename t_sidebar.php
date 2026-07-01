<!-- t_sidebar.php -->
<!-- Trainer Sidebar -->
<aside class="fixed left-0 top-0 z-50 h-[100dvh] w-64 bg-gradient-to-b from-gray-900 to-black shadow-2xl transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col">
    <!-- Logo and Brand -->
    <div class="flex items-center justify-center h-16 lg:h-20 border-b border-gray-800 px-4">
        <div class="flex items-center space-x-2 lg:space-x-3">
            <a href="../dashboard/dashboard.php" class="flex items-center justify-center">
            <img src="../logo2.png" alt="ASD Academy Logo" class="h-8 md:h-10 lg:h-12 w-auto object-contain transition-all duration-300 hover:scale-105 filter drop-shadow-lg">
        </a>
        </div>
    </div>

    <!-- User Profile -->
    <div class="p-4 lg:p-6 border-b border-gray-800">
        <div class="flex items-center space-x-3">
            <div class="relative flex-shrink-0">
                <div class="w-10 h-10 lg:w-12 lg:h-12 rounded-full bg-gradient-to-r from-blue-500 to-cyan-400 flex items-center justify-center text-white font-bold text-base lg:text-lg">
                    <?php 
                    if (isset($trainer) && !empty($trainer['name'])) {
                        echo strtoupper(substr($trainer['name'], 0, 1));
                    } else {
                        echo "T";
                    }
                    ?>
                </div>
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
        <a href="dashboard/dashboard.php" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-blue-900 to-blue-700 flex items-center justify-center group-hover:from-blue-700 group-hover:to-cyan-500 flex-shrink-0">
                <i class="fas fa-tachometer-alt text-blue-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Dashboard</span>
        </a>

        <!-- My Courses -->
        <a href="../courses/my_courses.php" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'my_courses.php' ? 'bg-gray-800 bg-opacity-50 border-l-2 lg:border-l-4 border-purple-500' : ''; ?>">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-purple-900 to-purple-700 flex items-center justify-center group-hover:from-purple-600 group-hover:to-pink-500 flex-shrink-0">
                <i class="fas fa-book-open text-purple-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">My Courses</span>
        </a>

        <!-- My Students -->
        <a href="students/students.php" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link <?php echo basename($_SERVER['PHP_SELF']) == 'students.php' ? 'bg-gray-800 bg-opacity-50 border-l-2 lg:border-l-4 border-blue-500' : ''; ?>">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-blue-900 to-blue-700 flex items-center justify-center group-hover:from-blue-600 group-hover:to-cyan-500 flex-shrink-0">
                <i class="fas fa-users text-blue-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">My Students</span>
        </a>

        <!-- Schedule -->
        <a href="schedule/schedule.php" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-green-900 to-green-700 flex items-center justify-center group-hover:from-green-600 group-hover:to-emerald-500 flex-shrink-0">
                <i class="fas fa-calendar-alt text-green-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Schedule</span>
        </a>

        <!-- Attendance -->
        <a href="attendance/trainer_attendance.php" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-amber-900 to-amber-700 flex items-center justify-center group-hover:from-amber-600 group-hover:to-orange-500 flex-shrink-0">
                <i class="fas fa-clipboard-check text-amber-300 group-hover:text-white text-xs lg:text-sm"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Attendance</span>
        </a>
        
        <!-- Feedback -->
        <a href="feedback/weekly_feedback.php" 
           class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
            <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-amber-900 to-amber-700 flex items-center justify-center group-hover:from-amber-600 group-hover:to-orange-500 flex-shrink-0">
                <i class="fas fa-comment-dots text-amber-300 text-xs group-hover:text-white lg:text-sm w-4 lg:w-5 text-center"></i>
            </div>
            <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Feedback</span>
        </a>

        <!-- Exams & Assignments -->
        <div class="pt-3 lg:pt-4">
            <p class="text-xs uppercase text-gray-500 font-bold tracking-wider px-2 lg:px-3 mb-1 lg:mb-2 truncate">Assessments</p>
            
            <!-- Exams -->
            <a href="exam/trainer_dashboard.php" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-red-900 to-red-700 flex items-center justify-center group-hover:from-red-600 group-hover:to-pink-500 flex-shrink-0">
                    <i class="fas fa-file-alt text-red-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Exams</span>
            </a>

            <!-- Assignments -->
            <a href="content/trainer_content.php" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-indigo-900 to-indigo-700 flex items-center justify-center group-hover:from-indigo-600 group-hover:to-purple-500 flex-shrink-0">
                    <i class="fas fa-tasks text-indigo-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Study Materials</span>
            </a>
            <a href="profile.php" 
               class="flex items-center space-x-2 lg:space-x-3 p-2 lg:p-3 rounded-lg transition-all duration-200 hover:bg-gray-800 hover:bg-opacity-50 hover:translate-x-1 lg:hover:translate-x-2 group sidebar-link">
                <div class="w-6 h-6 lg:w-8 lg:h-8 rounded-lg bg-gradient-to-r from-indigo-900 to-indigo-700 flex items-center justify-center group-hover:from-indigo-600 group-hover:to-purple-500 flex-shrink-0">
                    <i class="fas fa-person text-indigo-300 group-hover:text-white text-xs lg:text-sm"></i>
                </div>
                <span class="text-gray-300 group-hover:text-white font-medium text-sm lg:text-base truncate">Profile</span>
            </a>
        </div>
    </nav>

    <!-- Bottom Section -->
    <div class="p-3 lg:p-4 border-t border-gray-800 bg-gray-900 bg-opacity-50 mt-auto">
        <!-- Settings and Logout -->
        <div class="flex justify-between items-center">
            <!-- Logout -->
            <a href="logout.php" 
               class="flex items-center space-x-2 p-2 text-gray-400 hover:text-red-400 transition-colors rounded-lg hover:bg-gray-800 w-full justify-center lg:justify-start">
                <i class="fas fa-sign-out-alt text-sm lg:text-lg"></i>
                <span class="text-xs lg:text-sm font-medium">Logout</span>
            </a>
        </div>
    </div>
</aside>

<!-- Mobile Toggle Button -->
<button id="mobileSidebarToggle" class="lg:hidden fixed top-3 left-3 z-40 p-2 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-lg shadow-lg">
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
                    link.style.setProperty('--active-color', '#8b5cf6', 'important');
                } else if (linkHref.includes('students.php')) {
                    link.style.setProperty('--active-color', '#3b82f6', 'important');
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