<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

$content = str_replace(
    "const remarks = row.find('.remarks-input').val();",
    "const remarks = ''; // row.find('.remarks-input').val();",
    $content
);

file_put_contents($file, $content);
echo "Remarks fixed.";
?>
