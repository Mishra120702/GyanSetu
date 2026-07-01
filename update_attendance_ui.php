<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

// 1. Fetch batch name properly at the top
$batchNameFetch = <<<PHP
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
PHP;

$content = preg_replace(
    "/\\\$course_id = isset\(\\\$_GET\['course_id'\]\) \? \\\$_GET\['course_id'\] : '';\s*if\(empty\(\\\$course_id\)\) die\('Course ID is required'\);\s*\\\$preselected_date = isset\(\\\$_GET\['date'\]\) \? \\\$_GET\['date'\] : date\('Y-m-d'\);\s*\/\/ Get course details\s*\\\$course_stmt = \\\$db->prepare\('SELECT name FROM courses WHERE id = \?'\);\s*\\\$course_stmt->execute\(\[\\\$course_id\]\);\s*\\\$course_name = \\\$course_stmt->fetchColumn\(\) \?: 'Unknown Course';\s*\/\/ Get all batches for the filter dropdown\s*try \{\s*\\\$stmt = \\\$db->query\(\"SELECT batch_id, batch_name FROM batches\"\);\s*\\\$batches = \\\$stmt->fetchAll\(PDO::FETCH_ASSOC\);\s*\} catch \(PDOException \\\$e\) \{\s*error_log\(\"Database error fetching batches: \" \. \\\$e->getMessage\(\)\);\s*\\\$batches = \[\];\s*\}/",
    $batchNameFetch,
    $content
);


// 2. Change header title
$content = preg_replace(
    "/<span><\?= htmlspecialchars\(\\\$course_name\) \?> - Attendance<\/span>/",
    "<span><?= htmlspecialchars(\$batch_name_display) ?> | Course Attendance</span>",
    $content
);

// 3. Update filters grid dropdown from Batches to Courses
$filtersGridTarget = <<<HTML
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

$filtersGridReplacement = <<<HTML
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

$content = str_replace($filtersGridTarget, $filtersGridReplacement, $content);

// 4. Update the "Attendance Tracking" or "Manual Attendance Section" heading to show current batch and course prominently.
// We'll insert a nice banner right after the toggle buttons.
$toggleButtonsEnd = "</div>\n            \n            <!-- Manual Attendance Section -->\n            <div id=\"manualAttendanceSection\">";
$bannerHtml = <<<HTML
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
HTML;
$content = str_replace($toggleButtonsEnd, $bannerHtml, $content);

// 5. Update the Create Attendance form modal dropdown
$createModalTarget = <<<HTML
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

$createModalReplacement = <<<HTML
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
$content = str_replace($createModalTarget, $createModalReplacement, $content);

// Delete Modal
$deleteModalTarget = <<<HTML
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

$deleteModalReplacement = <<<HTML
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
$content = str_replace($deleteModalTarget, $deleteModalReplacement, $content);

// Fix JS variables in course_attendance.php
$content = preg_replace(
    "/const batchId = \$('#batchFilter'\)\.val\(\);/",
    "const batchId = $('#batchFilter').val();\n            const courseId = $('#courseFilter').val();",
    $content
);

$content = preg_replace(
    "/course_id: '<\?= \\\$course_id \?>'/",
    "course_id: courseId",
    $content
);

file_put_contents($file, $content);
echo "course_attendance.php updated successfully.\n";
?>
