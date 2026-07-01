<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

// 1. Remove toggle buttons entirely
$content = preg_replace(
    "/<div class=\"toggle-buttons\">\s*<button id=\"showManualBtn\".*?<\/button>\s*<\/div>/s",
    "",
    $content
);

// 2. Fix card overflow to ensure absolute dropdown isn't clipped
$content = str_replace(
    "<!-- Filters Card -->\n                <div class=\"card\">",
    "<!-- Filters Card -->\n                <div class=\"card\" style=\"overflow: visible !important;\">",
    $content
);

$content = str_replace(
    "<div class=\"filters-grid\">",
    "<div class=\"filters-grid\" style=\"overflow: visible !important;\">",
    $content
);

// 3. Improve Export Dropdown CSS (higher z-index, slightly wider, drop shadow)
$dropdownOld = "style=\"position: absolute; top: 100%; right: 0; z-index: 100; min-width: 200px; background: white; border: 1px solid #e2e8f0; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);\"";
$dropdownNew = "style=\"position: absolute; top: calc(100% + 5px); right: 0; z-index: 9999; min-width: 260px; background: white; border: 1px solid #cbd5e1; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.1);\"";
$content = str_replace($dropdownOld, $dropdownNew, $content);

file_put_contents($file, $content);
echo "Final UI cleanup applied.";
?>
