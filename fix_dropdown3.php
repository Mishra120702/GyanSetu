<?php
$file = 'stu_dash/view_attendance.php';
$content = file_get_contents($file);

$content = str_replace(
    "&amp;course_id=",
    "&course_id=",
    $content
);

file_put_contents($file, $content);
echo "Dropdown fixed 3.";
?>
