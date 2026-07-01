<?php
$file = 'stu_dash/view_attendance.php';
$content = file_get_contents($file);

// Normalize line endings
$content = str_replace("\r\n", "\n", $content);

// 1. Database table replacement
$content = str_replace("FROM attendance", "FROM course_attendance", $content);

// 2. Fetch courses for the selected batch and add course_id logic
$batchSelectionLogic = <<<PHP
// Selected batch from GET or default to first batch
\$selected_batch_id = \$_GET['batch_id'] ?? (\$current_batches[0]['id'] ?? 'Not assigned');

// Get the display name for the selected batch
\$selected_batch_name = \$selected_batch_id;
foreach (\$current_batches as \$b) {
    if (\$b['id'] === \$selected_batch_id) {
        \$selected_batch_name = \$b['name'];
        break;
    }
}
PHP;

$courseSelectionLogic = <<<PHP
// Selected batch from GET or default to first batch
\$selected_batch_id = \$_GET['batch_id'] ?? (\$current_batches[0]['id'] ?? 'Not assigned');

// Get the display name for the selected batch
\$selected_batch_name = \$selected_batch_id;
foreach (\$current_batches as \$b) {
    if (\$b['id'] === \$selected_batch_id) {
        \$selected_batch_name = \$b['name'];
        break;
    }
}

// Get courses for the selected batch
\$batch_courses = [];
if (\$selected_batch_id !== 'Not assigned') {
    \$course_stmt = \$db->prepare("SELECT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id WHERE bc.batch_id = ? ORDER BY c.name");
    \$course_stmt->execute([\$selected_batch_id]);
    \$batch_courses = \$course_stmt->fetchAll(PDO::FETCH_ASSOC);
}

\$selected_course_id = \$_GET['course_id'] ?? (\$batch_courses[0]['id'] ?? 'none');
\$selected_course_name = 'No Course';
foreach (\$batch_courses as \$c) {
    if (\$c['id'] == \$selected_course_id) {
        \$selected_course_name = \$c['name'];
        break;
    }
}
PHP;

$content = str_replace($batchSelectionLogic, $courseSelectionLogic, $content);

// 3. Update all queries to filter by course_id
// EXPORT Query
$exportQueryOld = <<<PHP
        SELECT date, status, camera_status, remarks 
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name) 
        AND batch_id = :batch_id 
        AND date BETWEEN :start_date AND :end_date
        ORDER BY date DESC
PHP;
$exportQueryNew = <<<PHP
        SELECT date, status, camera_status, remarks 
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name) 
        AND batch_id = :batch_id 
        AND course_id = :course_id
        AND date BETWEEN :start_date AND :end_date
        ORDER BY date DESC
PHP;
$content = str_replace($exportQueryOld, $exportQueryNew, $content);
$content = str_replace(
    "':batch_id' => \$selected_batch_id,\n        ':start_date'",
    "':batch_id' => \$selected_batch_id,\n        ':course_id' => \$selected_course_id,\n        ':start_date'",
    $content
);

// ATTENDANCE Query
$attendanceQueryOld = <<<PHP
    if (\$b['id'] === \$selected_batch_id) {
        \$is_valid_batch = true; break;
    }
}

if (\$selected_batch_id !== 'Not assigned' && \$is_valid_batch) {
    \$attendance_query = \$db->prepare("
        SELECT date, status, camera_status, remarks 
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name) AND batch_id = :batch_id 
        ORDER BY date DESC
    ");
    \$attendance_query->execute([
        ':student_id' => \$student_id_value,
        ':student_name' => \$student_name, 
        ':batch_id' => \$selected_batch_id
    ]);
PHP;

$attendanceQueryNew = <<<PHP
    if (\$b['id'] === \$selected_batch_id) {
        \$is_valid_batch = true; break;
    }
}

if (\$selected_batch_id !== 'Not assigned' && \$is_valid_batch && \$selected_course_id !== 'none') {
    \$attendance_query = \$db->prepare("
        SELECT date, status, camera_status, remarks 
        FROM course_attendance 
        WHERE (student_id = :student_id OR student_name = :student_name) AND batch_id = :batch_id AND course_id = :course_id
        ORDER BY date DESC
    ");
    \$attendance_query->execute([
        ':student_id' => \$student_id_value,
        ':student_name' => \$student_name, 
        ':batch_id' => \$selected_batch_id,
        ':course_id' => \$selected_course_id
    ]);
PHP;
$content = str_replace($attendanceQueryOld, $attendanceQueryNew, $content);

// MONTH Query
$monthQueryOld = <<<PHP
    WHERE (student_id = :student_id OR student_name = :student_name)
    AND batch_id = :batch_id 
    AND DATE_FORMAT(date, '%Y-%m') = :current_month
PHP;
$monthQueryNew = <<<PHP
    WHERE (student_id = :student_id OR student_name = :student_name)
    AND batch_id = :batch_id AND course_id = :course_id
    AND DATE_FORMAT(date, '%Y-%m') = :current_month
PHP;
$content = str_replace($monthQueryOld, $monthQueryNew, $content);
$content = str_replace(
    "':batch_id' => \$selected_batch_id,\n    ':current_month'",
    "':batch_id' => \$selected_batch_id,\n    ':course_id' => \$selected_course_id,\n    ':current_month'",
    $content
);

// WEEK Query
$weekQueryOld = <<<PHP
    WHERE (student_id = :student_id OR student_name = :student_name)
    AND batch_id = :batch_id 
    AND date >= :start_date
PHP;
$weekQueryNew = <<<PHP
    WHERE (student_id = :student_id OR student_name = :student_name)
    AND batch_id = :batch_id AND course_id = :course_id
    AND date >= :start_date
PHP;
$content = str_replace($weekQueryOld, $weekQueryNew, $content);
$content = str_replace(
    "':batch_id' => \$selected_batch_id,\n    ':start_date'",
    "':batch_id' => \$selected_batch_id,\n    ':course_id' => \$selected_course_id,\n    ':start_date'",
    $content
);

// 4. UI changes
// Add Course filter to the UI next to Batch filter
$filtersOld = <<<HTML
                <?php if (count(\$current_batches) > 1): ?>
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center space-x-2">
                        <label for="batch_filter" class="text-sm font-medium text-gray-700">Filter by Batch:</label>
                        <select id="batch_filter" onchange="window.location.href='?batch_id='+this.value" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach (\$current_batches as \$batch): ?>
                                <option value="<?= htmlspecialchars(\$batch['id']) ?>" <?= \$selected_batch_id === \$batch['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(\$batch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
HTML;
$filtersNew = <<<HTML
                <div class="flex flex-wrap items-center gap-4">
                    <?php if (count(\$current_batches) > 1): ?>
                    <div class="flex items-center space-x-2">
                        <label for="batch_filter" class="text-sm font-medium text-gray-700">Batch:</label>
                        <select id="batch_filter" onchange="window.location.href='?batch_id='+this.value" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach (\$current_batches as \$batch): ?>
                                <option value="<?= htmlspecialchars(\$batch['id']) ?>" <?= \$selected_batch_id === \$batch['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(\$batch['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty(\$batch_courses)): ?>
                    <div class="flex items-center space-x-2">
                        <label for="course_filter" class="text-sm font-medium text-gray-700">Course:</label>
                        <select id="course_filter" onchange="window.location.href='?batch_id=<?= urlencode(\$selected_batch_id) ?>&course_id='+this.value" class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach (\$batch_courses as \$course): ?>
                                <option value="<?= htmlspecialchars(\$course['id']) ?>" <?= \$selected_course_id == \$course['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(\$course['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
HTML;
$content = str_replace($filtersOld, $filtersNew, $content);

// Update Header description
$content = str_replace(
    "<p class=\"text-sm text-gray-600 mt-1\">Your complete attendance history for batch <?= htmlspecialchars(\$selected_batch_name) ?></p>",
    "<p class=\"text-sm text-gray-600 mt-1\">Attendance for <span class=\"font-semibold\"><?= htmlspecialchars(\$selected_course_name) ?></span> in batch <span class=\"font-semibold\"><?= htmlspecialchars(\$selected_batch_name) ?></span></p>",
    $content
);

file_put_contents($file, $content);
echo "Student dashboard updated to support course-wise attendance.";
?>
