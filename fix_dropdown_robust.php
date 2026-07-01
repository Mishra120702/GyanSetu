<?php
$file = 'stu_dash/view_attendance.php';
$content = file_get_contents($file);

$old_select = "<select id=\"course_filter\" onchange=\"window.location.href='view_attendance.php?batch_id=<?= urlencode(\$selected_batch_id) ?>&course_id=' + this.value;\" class=\"px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500\">";

$new_select = "<select id=\"course_filter\" onchange=\"var url = new URL(window.location.href); url.searchParams.set('course_id', this.value); window.location.href = url.toString();\" class=\"px-3 py-1.5 border border-gray-300 rounded-lg text-sm focus:ring-blue-500 focus:border-blue-500\">";

$content = str_replace($old_select, $new_select, $content);

file_put_contents($file, $content);
echo "Robust dropdown URL fixed.";
?>
