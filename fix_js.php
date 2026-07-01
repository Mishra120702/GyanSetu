<?php
$content = file_get_contents('attendance/course_attendance.php');

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
                url: 'attendance_api.php',
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

// Let's use generic replacement
$content = preg_replace(
    "/const batchId = \\$\\('#batchFilter'\\)\\.val\\(\\);\s*const date = \\$\\('#dateFilter'\\)\\.val\\(\\);/",
    "const batchId = $('#batchFilter').val();\n            const courseId = $('#courseFilter').val();\n            const date = $('#dateFilter').val();",
    $content
);

$content = preg_replace(
    "/batch_id: batchId,\s*date: date\s*\},/",
    "batch_id: batchId,\n                    date: date,\n                    course_id: courseId\n                },",
    $content
);

$content = str_replace("'attendance_api.php'", "'course_attendance_api.php'", $content);
$content = str_replace('action="attendance.php"', 'action="course_attendance.php"', $content);

file_put_contents('attendance/course_attendance.php', $content);
echo "Final touchups applied.\n";
?>
