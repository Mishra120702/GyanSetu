<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

// 1. Remove the "New Attendance" and "Delete Attendance" toggle buttons
$content = preg_replace(
    "/<button id=\"showCreateBtn\" class=\"toggle-btn\">\s*<i class=\"fas fa-plus-circle mr-2\"><\/i> New Attendance\s*<\/button>\s*<button id=\"showDeleteBtn\" class=\"toggle-btn\">\s*<i class=\"fas fa-trash-alt mr-2\"><\/i> Delete Attendance\s*<\/button>/s",
    "",
    $content
);

// 2. Remove the HTML sections for Create and Delete attendance
$content = preg_replace(
    "/<!-- Create New Attendance Section.*?<!-- Delete Confirmation Modal -->/s",
    "<!-- Delete Confirmation Modal -->",
    $content
);

// 3. Fix the Export dropdown CSS directly inline
$content = preg_replace(
    "/<div id=\"exportDropdown\" class=\"export-dropdown hidden\">/",
    "<div id=\"exportDropdown\" class=\"export-dropdown hidden\" style=\"position: absolute; top: 100%; right: 0; z-index: 100; min-width: 200px; background: white; border: 1px solid #e2e8f0; border-radius: 0.5rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);\">",
    $content
);

// 4. Remove the dtExportBtn from DataTables drawCallback
// It's located around line 1250:
// drawCallback: function() {
//      if ($('#dtExportBtn').length === 0) { ... }
// }
$content = preg_replace(
    "/drawCallback:\s*function\(\)\s*\{\s*\/\/\s*Add export button.*?\}\s*\}/s",
    "drawCallback: function() { }",
    $content
);

// 5. Remove JS for toggling the Create and Delete sections
$content = preg_replace(
    "/\s*\\$\\('#showCreateBtn'\\)\.click\(function\(\)\s*\{.*?\\}\\);/s",
    "",
    $content
);
$content = preg_replace(
    "/\s*\\$\\('#showDeleteBtn'\\)\.click\(function\(\)\s*\{.*?\\}\\);/s",
    "",
    $content
);
$content = preg_replace(
    "/\s*\\$\\('#createAttendanceSection'\\)\.hide\(\);/s",
    "",
    $content
);
$content = preg_replace(
    "/\s*\\$\\('#deleteAttendanceSection'\\)\.hide\(\);/s",
    "",
    $content
);

file_put_contents($file, $content);
echo "UI fixed securely.";
?>
