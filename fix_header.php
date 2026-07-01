<?php
$file = 'attendance/course_attendance.php';
$c = file_get_contents($file);
$target = <<<HTML
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600 hover:text-blue-500 transition-colors" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-clipboard-check text-blue-500"></i>
                <span><?= htmlspecialchars(\$course_name) ?> - Attendance</span>
            </h1>
        </header>
HTML;

$replacement = <<<HTML
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center">
                <button class="md:hidden text-xl text-gray-600 hover:text-blue-500 transition-colors mr-4" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                    <i class="fas fa-clipboard-check text-blue-500"></i>
                    <span><?= htmlspecialchars(\$course_name) ?> - Attendance</span>
                </h1>
            </div>
            <div>
                <a href="../batch/batch_course_view.php?batch_id=<?= urlencode(\$preselected_batch) ?>&course_id=<?= urlencode(\$course_id) ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-md transition-colors text-sm font-medium flex items-center shadow-sm">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Course
                </a>
            </div>
        </header>
HTML;

$c = str_replace($target, $replacement, $c);
file_put_contents($file, $c);
echo "Header fixed.\n";
?>
