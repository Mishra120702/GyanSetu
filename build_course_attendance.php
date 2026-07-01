<?php
$content = file_get_contents('attendance/attendance.php');

// 1. Initial setup for course_id and batch courses
$setupTarget = <<<PHP
// Check if batch_id is provided in URL
\$preselected_batch = isset(\$_GET['batch_id']) ? \$_GET['batch_id'] : '';
\$preselected_date = isset(\$_GET['date']) ? \$_GET['date'] : date('Y-m-d');

// Get all batches for the filter dropdown
try {
    \$stmt = \$db->query("SELECT batch_id, batch_name FROM batches");
    \$batches = \$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException \$e) {
    error_log("Database error fetching batches: " . \$e->getMessage());
    \$batches = [];
}
PHP;

$setupReplacement = <<<PHP
// Check if batch_id is provided in URL
\$preselected_batch = isset(\$_GET['batch_id']) ? \$_GET['batch_id'] : '';
\$course_id = isset(\$_GET['course_id']) ? \$_GET['course_id'] : '';
if(empty(\$course_id)) die('Course ID is required');
\$preselected_date = isset(\$_GET['date']) ? \$_GET['date'] : date('Y-m-d');

// Get course details
\$course_stmt = \$db->prepare('SELECT name FROM courses WHERE id = ?');
\$course_stmt->execute([\$course_id]);
\$course_name = \$course_stmt->fetchColumn() ?: 'Unknown Course';

// Get batch name
\$batch_stmt = \$db->prepare('SELECT batch_name FROM batches WHERE batch_id = ?');
\$batch_stmt->execute([\$preselected_batch]);
\$batch_name_display = \$batch_stmt->fetchColumn() ?: \$preselected_batch;

// Get courses for this batch for the dropdown
try {
    \$stmt = \$db->prepare("SELECT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id WHERE bc.batch_id = ? ORDER BY c.name");
    \$stmt->execute([\$preselected_batch]);
    \$batch_courses = \$stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException \$e) {
    \$batch_courses = [];
}
\$batches = []; // Keep defined to avoid errors
PHP;

$content = str_replace($setupTarget, $setupReplacement, $content);

// 2. Table replacements
$content = str_replace("FROM attendance", "FROM course_attendance", $content);
$content = str_replace("INTO attendance", "INTO course_attendance", $content);
$content = str_replace("DELETE FROM attendance", "DELETE FROM course_attendance", $content);

// 3. Query replacements
$content = str_replace(
    "FROM course_attendance WHERE batch_id = ? AND date = ?", 
    "FROM course_attendance WHERE batch_id = ? AND date = ? AND course_id = ?", 
    $content
);
$content = str_replace(
    "\$stmt->execute([\$batch_id, \$date]);", 
    "\$stmt->execute([\$batch_id, \$date, \$_POST['course_id'] ?? \$course_id]);", 
    $content
);

$content = str_replace(
    "INSERT INTO course_attendance (date, batch_id, student_id, student_name, status, camera_status)", 
    "INSERT INTO course_attendance (course_id, date, batch_id, student_id, student_name, status, camera_status)", 
    $content
);
$content = str_replace(
    "VALUES (?, ?, ?, ?, 'Absent', 'Off')", 
    "VALUES (?, ?, ?, ?, ?, 'Absent', 'Off')", 
    $content
);
$content = str_replace(
    "\$stmt->execute([\$date, \$batch_id, \$student['student_id'], \$student['student_name']])", 
    "\$stmt->execute([\$_POST['course_id'] ?? \$course_id, \$date, \$batch_id, \$student['student_id'], \$student['student_name']])", 
    $content
);

$content = str_replace(
    "DELETE FROM course_attendance WHERE batch_id = ? AND date = ?", 
    "DELETE FROM course_attendance WHERE batch_id = ? AND date = ? AND course_id = ?", 
    $content
);

// Form data propagation
$content = str_replace(
    "<form method=\"POST\" id=\"deleteAttendanceForm\">", 
    "<form method=\"POST\" id=\"deleteAttendanceForm\">\n<input type=\"hidden\" name=\"course_id\" value=\"<?= htmlspecialchars(\$course_id) ?>\">", 
    $content
);
$content = str_replace(
    "<form method=\"POST\" id=\"createAttendanceForm\">", 
    "<form method=\"POST\" id=\"createAttendanceForm\">\n<input type=\"hidden\" name=\"course_id\" value=\"<?= htmlspecialchars(\$course_id) ?>\">", 
    $content
);

// Redirects
$content = str_replace(
    "header(\"Location: attendance.php\");", 
    "header(\"Location: course_attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&course_id=\" . urlencode(\$_POST['course_id'] ?? \$course_id));", 
    $content
);
$content = str_replace(
    "header(\"Location: attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&date=\" . urlencode(\$date));", 
    "header(\"Location: course_attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&course_id=\" . urlencode(\$_POST['course_id'] ?? \$course_id) . \"&date=\" . urlencode(\$date));", 
    $content
);

// Title
$content = str_replace("<title>Attendance Tracking - ASD Academy</title>", "<title><?= htmlspecialchars(\$batch_name_display) ?> | Course Attendance</title>", $content);

// Header component
$headerTarget = <<<HTML
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <button class="md:hidden text-xl text-gray-600 hover:text-blue-500 transition-colors" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <i class="fas fa-clipboard-check text-blue-500"></i>
                <span>Attendance Tracking</span>
            </h1>
        </header>
HTML;

$headerReplacement = <<<HTML
        <!-- Header -->
        <header class="bg-white shadow-sm px-6 py-4 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center">
                <button class="md:hidden text-xl text-gray-600 hover:text-blue-500 transition-colors mr-4" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                    <i class="fas fa-clipboard-check text-blue-500"></i>
                    <span><?= htmlspecialchars(\$batch_name_display) ?> | Course Attendance</span>
                </h1>
            </div>
            <div>
                <a href="../batch/batch_course_view.php?batch_id=<?= urlencode(\$preselected_batch) ?>&course_id=<?= urlencode(\$course_id) ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-md transition-colors text-sm font-medium flex items-center shadow-sm">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Course
                </a>
            </div>
        </header>
HTML;
$content = str_replace($headerTarget, $headerReplacement, $content);

// Context banner and filters
$filtersTarget = <<<HTML
            <div class="toggle-buttons">
                <button id="showManualBtn" class="toggle-btn active">
                    <i class="fas fa-edit mr-2"></i> Manual Attendance
                </button>
                <button id="showUploadBtn" class="toggle-btn">
                    <i class="fas fa-file-upload mr-2"></i> Upload Excel
                </button>
                <a href="monthly_attendance.php" class="toggle-btn">
                    <i class="fas fa-chart-bar mr-2"></i> Reports
                </a>
                <button id="showCreateBtn" class="toggle-btn">
                    <i class="fas fa-plus-circle mr-2"></i> New Attendance
                </button>
                <button id="showDeleteBtn" class="toggle-btn">
                    <i class="fas fa-trash-alt mr-2"></i> Delete Attendance
                </button>
            </div>
            
            <!-- Manual Attendance Section -->
            <div id="manualAttendanceSection">
                <!-- Filters Card -->
                <div class="card">
                    <div class="filters-grid">
                        <select id="batchFilter" class="minimal-input">
                            <option value="">All Batches</option>
                            <?php foreach (\$batches as \$batch): ?>
                            <option value="<?= htmlspecialchars(\$batch['batch_id']) ?>" 
                                <?= (\$preselected_batch === \$batch['batch_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(\$batch['batch_id'] . ' - ' . \$batch['batch_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
HTML;

$filtersReplacement = <<<HTML
            <div class="toggle-buttons">
                <button id="showManualBtn" class="toggle-btn active">
                    <i class="fas fa-edit mr-2"></i> Manual Attendance
                </button>
                <button id="showCreateBtn" class="toggle-btn">
                    <i class="fas fa-plus-circle mr-2"></i> New Attendance
                </button>
                <button id="showDeleteBtn" class="toggle-btn">
                    <i class="fas fa-trash-alt mr-2"></i> Delete Attendance
                </button>
            </div>

            <!-- Context Banner -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 shadow-sm flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold text-blue-800"><i class="fas fa-users mr-2"></i><?= htmlspecialchars(\$batch_name_display) ?></h2>
                    <p class="text-sm text-blue-600 mt-1">Marking attendance for course: <span class="font-semibold"><?= htmlspecialchars(\$course_name) ?></span></p>
                </div>
            </div>
            
            <!-- Manual Attendance Section -->
            <div id="manualAttendanceSection">
                <!-- Filters Card -->
                <div class="card">
                    <div class="filters-grid">
                        <input type="hidden" id="batchFilter" value="<?= htmlspecialchars(\$preselected_batch) ?>">
                        <select id="courseFilter" class="minimal-input" onchange="window.location.href='course_attendance.php?batch_id=<?= urlencode(\$preselected_batch) ?>&course_id=' + this.value + '&date=' + document.getElementById('dateFilter').value">
                            <option value="">-- Select Course --</option>
                            <?php foreach (\$batch_courses as \$bc): ?>
                            <option value="<?= htmlspecialchars(\$bc['id']) ?>" 
                                <?= (\$course_id == \$bc['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(\$bc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
HTML;

$content = str_replace($filtersTarget, $filtersReplacement, $content);

// Create modal batch dropdown -> course dropdown
$createTarget = <<<HTML
                        <select id="createBatchId" name="batch_id" required class="minimal-input">
                            <option value="">Select Batch</option>
                            <?php foreach (\$batches as \$batch): ?>
                            <option value="<?= htmlspecialchars(\$batch['batch_id']) ?>"
                                <?= (\$preselected_batch === \$batch['batch_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(\$batch['batch_id'] . ' - ' . \$batch['batch_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
HTML;

$createReplacement = <<<HTML
                        <input type="hidden" name="batch_id" value="<?= htmlspecialchars(\$preselected_batch) ?>">
                        <input type="hidden" id="createBatchId" value="<?= htmlspecialchars(\$preselected_batch) ?>">
                        <select id="createCourseId" name="course_id" required class="minimal-input">
                            <option value="">Select Course</option>
                            <?php foreach (\$batch_courses as \$bc): ?>
                            <option value="<?= htmlspecialchars(\$bc['id']) ?>"
                                <?= (\$course_id == \$bc['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(\$bc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
HTML;
$content = str_replace($createTarget, $createReplacement, $content);

// Delete modal
$deleteTarget = <<<HTML
                        <select id="deleteBatchId" name="delete_batch_id" required class="minimal-input">
                            <option value="">Select Batch</option>
                            <?php foreach (\$batches as \$batch): ?>
                            <option value="<?= htmlspecialchars(\$batch['batch_id']) ?>"
                                <?= (\$preselected_batch === \$batch['batch_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(\$batch['batch_id'] . ' - ' . \$batch['batch_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
HTML;
$deleteReplacement = <<<HTML
                        <input type="hidden" name="delete_batch_id" value="<?= htmlspecialchars(\$preselected_batch) ?>">
                        <input type="hidden" id="deleteBatchId" value="<?= htmlspecialchars(\$preselected_batch) ?>">
                        <select id="deleteCourseId" name="course_id" required class="minimal-input">
                            <option value="">Select Course</option>
                            <?php foreach (\$batch_courses as \$bc): ?>
                            <option value="<?= htmlspecialchars(\$bc['id']) ?>"
                                <?= (\$course_id == \$bc['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(\$bc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
HTML;
$content = str_replace($deleteTarget, $deleteReplacement, $content);

// API URL fixes
$content = str_replace("'attendance_api.php'", "'course_attendance_api.php'", $content);

// loadAttendanceData function
$loadFuncTarget = <<<JAVASCRIPT
        function loadAttendanceData() {
            const batchId = $('#batchFilter').val();
            const date = $('#dateFilter').val();
            
            if (!date) {
                showError('Please select a date');
                return;
            }
            
            showLoading();
            hideError();
            
            $.ajax({
                url: 'course_attendance_api.php',
                type: 'GET',
                data: { 
                    action: 'fetch',
                    batch_id: batchId,
                    date: date
                },
JAVASCRIPT;

$loadFuncReplacement = <<<JAVASCRIPT
        function loadAttendanceData() {
            const batchId = $('#batchFilter').val();
            const courseId = $('#courseFilter').val();
            const date = $('#dateFilter').val();
            
            if (!date) {
                showError('Please select a date');
                return;
            }
            
            showLoading();
            hideError();
            
            $.ajax({
                url: 'course_attendance_api.php',
                type: 'GET',
                data: { 
                    action: 'fetch',
                    batch_id: batchId,
                    date: date,
                    course_id: courseId
                },
JAVASCRIPT;
$content = str_replace($loadFuncTarget, $loadFuncReplacement, $content);

file_put_contents('attendance/course_attendance.php', $content);
echo "Built course_attendance.php successfully.\n";
?>
