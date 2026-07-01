<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

$content = str_replace(
    '<div class="card" style="overflow: visible !important;">',
    '<div class="card" style="overflow: visible !important; position: relative; z-index: 50;">',
    $content
);

file_put_contents($file, $content);
echo "Z-index fixed for export dropdown card.";
?>
