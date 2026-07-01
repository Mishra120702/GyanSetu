<?php
$content = file_get_contents('attendance/attendance.php');

// Normalize line endings to LF for easier regex
$content = str_replace("\r\n", "\n", $content);

// 1. Initial Setup
$setupRegex = '/\/\/ Check if batch_id is provided in URL.*?\$batches = \[\];\n\}/s';
$setupReplacement = <<<PHP
// Check if batch_id is provided in URL
\$preselected_batch = isset(\$_GET['batch_id']) ? \$_GET['batch_id'] : '';
\$course_id = isset(\$_GET['course_id']) ? \$_GET['course_id'] : '';
\$preselected_date = isset(\$_GET['date']) ? \$_GET['date'] : date('Y-m-d');

if (empty(\$course_id)) {
    die("Error: Course ID is required to view course attendance.");
}

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
\$batches = []; // Keep defined for backward compatibility
}
PHP;

// Let's use simpler str_replace for small chunks to avoid regex fails.
// 2. Database Table replacements
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

// 4. Form inputs & redirects
$content = preg_replace(
    "/<form method=\"POST\" id=\"deleteAttendanceForm\">/", 
    "<form method=\"POST\" id=\"deleteAttendanceForm\">\n<input type=\"hidden\" name=\"course_id\" value=\"<?= htmlspecialchars(\$course_id) ?>\">", 
    $content
);
$content = preg_replace(
    "/<form action=\"attendance.php\" method=\"POST\">/", 
    "<form action=\"course_attendance.php\" method=\"POST\">\n<input type=\"hidden\" name=\"course_id\" value=\"<?= htmlspecialchars(\$course_id) ?>\">", 
    $content
);
$content = preg_replace(
    "/header\(\"Location: attendance\.php\"\);/", 
    "header(\"Location: course_attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&course_id=\" . urlencode(\$_POST['course_id'] ?? \$course_id));", 
    $content
);
$content = preg_replace(
    "/header\(\"Location: attendance\.php\?batch_id=\" \. urlencode\(\\\$batch_id\) \. \"&date=\" \. urlencode\(\\\$date\)\);/", 
    "header(\"Location: course_attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&course_id=\" . urlencode(\$_POST['course_id'] ?? \$course_id) . \"&date=\" . urlencode(\$date));", 
    $content
);

// 5. Header Component
$content = preg_replace(
    "/<!-- Header -->\s*<header.*?<\/header>/s",
    <<<HTML
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
HTML,
    $content
);

// 6. Context Banner and Filters
$content = preg_replace(
    "/<!-- Toggle buttons -->\s*<div class=\"toggle-buttons\">.*?<!-- Manual Attendance Section -->/s",
    <<<HTML
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
HTML,
    $content
);

$content = preg_replace(
    "/<select id=\"batchFilter\" class=\"minimal-input\">.*?<\/select>/s",
    <<<HTML
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
HTML,
    $content
);

// 7. Modals - createBatch to createCourse
$content = preg_replace(
    "/<label for=\"createBatch\".*?<\/select>\s*<\/div>/s",
    <<<HTML
                            <input type="hidden" name="batch_id" value="<?= htmlspecialchars(\$preselected_batch) ?>">
                            <label for="createCourse" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Course
                            </label>
                            <select id="createCourse" name="course_id" class="minimal-input" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach (\$batch_courses as \$bc): ?>
                                <option value="<?= htmlspecialchars(\$bc['id']) ?>"
                                    <?= (\$course_id == \$bc['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(\$bc['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
HTML,
    $content
);

$content = preg_replace(
    "/<label for=\"deleteBatch\".*?<\/select>\s*<\/div>/s",
    <<<HTML
                            <input type="hidden" id="deleteBatch" name="delete_batch_id" value="<?= htmlspecialchars(\$preselected_batch) ?>">
                            <label for="deleteCourse" class="block text-sm font-medium text-gray-700 mb-2">
                                Select Course <span class="text-red-500">*</span>
                            </label>
                            <select id="deleteCourse" name="course_id" class="minimal-input" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach (\$batch_courses as \$bc): ?>
                                <option value="<?= htmlspecialchars(\$bc['id']) ?>"
                                    <?= (\$course_id == \$bc['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(\$bc['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
HTML,
    $content
);

// 8. JS & API replacements
$content = str_replace("'attendance_api.php'", "'course_attendance_api.php'", $content);
$content = str_replace("action=\"attendance.php\"", "action=\"course_attendance.php\"", $content);

$content = preg_replace(
    "/const batchId = \\$\\('#batchFilter'\\)\.val\(\);\s+const date = \\$\\('#dateFilter'\\)\.val\(\);/",
    "const batchId = $('#batchFilter').val();\n            const courseId = $('#courseFilter').val();\n            const date = $('#dateFilter').val();",
    $content
);

$content = preg_replace(
    "/batch_id:\s*batchId,\s*date:\s*date/",
    "batch_id: batchId,\n                    date: date,\n                    course_id: courseId",
    $content
);

// 9. Sunday Logic (flatpickr)
$content = preg_replace(
    "/flatpickr\(\"#dateFilter\"(.*?)\}\);/s",
    "flatpickr(\"#dateFilter\"$1, disable: [function(date) { return (date.getDay() === 0); }]});",
    $content
);
$content = preg_replace(
    "/flatpickr\(\"#createDate\"(.*?)\}\);/s",
    "flatpickr(\"#createDate\"$1, disable: [function(date) { return (date.getDay() === 0); }]});",
    $content
);
$content = preg_replace(
    "/flatpickr\(\"#deleteDate\"(.*?)\}\);/s",
    "flatpickr(\"#deleteDate\"$1, disable: [function(date) { return (date.getDay() === 0); }]});",
    $content
);

// 10. Table Headers - EXACT
$content = preg_replace(
    "/<th>Student ID<\/th>\s*<th>Student Name<\/th>.*?<th>Remarks<\/th>/s",
    <<<HTML
                                    <th>Date</th>
                                    <th>Batch Name</th>
                                    <th>Course Name</th>
                                    <th>Student Name</th>
                                    <th>Status</th>
                                    <th>Camera</th>
HTML,
    $content
);

// 11. DataTable columns
$content = preg_replace('/columns:\s*\[.*?\]\,/s', <<<JAVASCRIPT
            columns: [
                { data: 'date' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars(\$batch_name_display) ?>';
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return '<?= htmlspecialchars(\$course_name) ?>';
                    }
                },
                { data: 'student_name' },
                { 
                    data: null,
                    render: function(data, type, row) {
                        return `
                            <label class="switch status-slider">
                                <input type="checkbox" class="status-toggle" data-id="\${row.id}" \${row.status === 'Present' ? 'checked' : ''}>
                                <span class="slider">
                                    <span class="status-label status-present-label">P</span>
                                    <span class="status-label status-absent-label">A</span>
                                </span>
                            </label>
                        `;
                    }
                },
                { 
                    data: null,
                    render: function(data, type, row) {
                        const isPresent = row.status === 'Present';
                        const isCameraOn = row.camera_status === 'On';
                        const disabledClass = !isPresent ? 'disabled' : '';
                        
                        return `
                            <label class="switch camera-slider \${disabledClass}">
                                <input type="checkbox" class="camera-toggle" data-id="\${row.id}" 
                                    \${isCameraOn ? 'checked' : ''} 
                                    \${!isPresent ? 'disabled' : ''}>
                                <span class="slider"></span>
                            </label>
                        `;
                    }
                }
            ],
JAVASCRIPT, $content);

// 12. Fix Remarks JS usage
$content = str_replace(
    "const remarks = row.find('.remarks-input').val();",
    "const remarks = '';",
    $content
);

// Ensure Initial setup was actually replaced by replacing it if regex missed
if (strpos($content, "Check if batch_id is provided in URL") !== false && strpos($content, "\$course_id = isset(\$_GET['course_id'])") === false) {
    // Fallback if regex failed
    $content = preg_replace('/(\/\/ Check if batch_id is provided in URL.*?)\$batches = \[\];/s', $setupReplacement, $content);
}

file_put_contents('attendance/course_attendance.php', $content);
echo "Final rebuild 2 applied securely.";
?>
