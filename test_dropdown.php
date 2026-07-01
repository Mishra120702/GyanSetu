<?php
$file = 'stu_dash/view_attendance.php';
$content = file_get_contents($file);

$content = str_replace(
    "onchange=\"window.location.href='?batch_id=<?= urlencode(\$selected_batch_id) ?>&course_id='+this.value\"",
    "onchange=\"window.location.href='view_attendance.php?batch_id=<?= urlencode(\$selected_batch_id) ?>&course_id='+this.value\"",
    $content
);

file_put_contents($file, $content);
echo "Dropdown URL fixed.";
?>
