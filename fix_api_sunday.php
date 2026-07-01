<?php
$file = 'attendance/course_attendance_api.php';
$content = file_get_contents($file);

$target = <<<PHP
    if (empty(\$date)) {
        echo json_encode(['success' => false, 'message' => 'Date is required']);
        return;
    }
PHP;

$replacement = <<<PHP
    if (empty(\$date)) {
        echo json_encode(['success' => false, 'message' => 'Date is required']);
        return;
    }
    
    // Check if date is Sunday
    if (date('w', strtotime(\$date)) == 0) {
        echo json_encode(['success' => false, 'message' => 'Attendance cannot be marked on Sundays']);
        return;
    }
PHP;

$content = str_replace($target, $replacement, $content);
file_put_contents($file, $content);
echo "Fixed API sunday validation.";
?>
