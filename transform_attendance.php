<?php
$file = 'attendance/course_attendance.php';
$content = file_get_contents($file);

// Replace $_GET['batch_id'] with also $_GET['course_id']
$content = str_replace(
    "\$preselected_batch = isset(\$_GET['batch_id']) ? \$_GET['batch_id'] : '';",
    "\$preselected_batch = isset(\$_GET['batch_id']) ? \$_GET['batch_id'] : '';\n\$course_id = isset(\$_GET['course_id']) ? \$_GET['course_id'] : '';\nif(empty(\$course_id)) die('Course ID is required');",
    $content
);

// Get course name
$content = str_replace(
    "// Get all batches for the filter dropdown",
    "// Get course details\n\$course_stmt = \$db->prepare('SELECT name FROM courses WHERE id = ?');\n\$course_stmt->execute([\$course_id]);\n\$course_name = \$course_stmt->fetchColumn() ?: 'Unknown Course';\n\n// Get all batches for the filter dropdown",
    $content
);

// Replace table name `attendance` with `course_attendance` in queries
$content = preg_replace("/FROM attendance/i", "FROM course_attendance", $content);
$content = preg_replace("/INTO attendance/i", "INTO course_attendance", $content);
$content = preg_replace("/UPDATE attendance/i", "UPDATE course_attendance", $content);
$content = preg_replace("/DELETE FROM attendance/i", "DELETE FROM course_attendance", $content);
$content = preg_replace("/JOIN attendance/i", "JOIN course_attendance", $content);

// In creating attendance
// SELECT COUNT(*) FROM course_attendance WHERE batch_id = ? AND date = ? -> add course_id
$content = str_replace(
    "FROM course_attendance WHERE batch_id = ? AND date = ?",
    "FROM course_attendance WHERE batch_id = ? AND date = ? AND course_id = ?",
    $content
);
$content = str_replace(
    "\$stmt->execute([\$batch_id, \$date]);",
    "\$stmt->execute([\$batch_id, \$date, \$_POST['course_id'] ?? \$course_id]);",
    $content
);

// Insert into course_attendance
// INSERT INTO course_attendance (date, batch_id, student_id, student_name, status, camera_status) -> add course_id
$content = str_replace(
    "INSERT INTO course_attendance (date, batch_id, student_id, student_name, status, camera_status)",
    "INSERT INTO course_attendance (course_id, date, batch_id, student_id, student_name, status, camera_status)",
    $content
);
$content = str_replace(
    "VALUES (?, ?, ?, ?, 'Absent', 'Off')",
    "VALUES (?, ?, ?, ?, ?, 'Absent', 'Off')",
    $content
);
$content = str_replace(
    "\$stmt->execute([\$date, \$batch_id, \$student['student_id'], \$student['student_name']])",
    "\$stmt->execute([\$_POST['course_id'] ?? \$course_id, \$date, \$batch_id, \$student['student_id'], \$student['student_name']])",
    $content
);

// Delete attendance
$content = str_replace(
    "\$stmt = \$db->prepare(\"DELETE FROM course_attendance WHERE batch_id = ? AND date = ?\");",
    "\$stmt = \$db->prepare(\"DELETE FROM course_attendance WHERE batch_id = ? AND date = ? AND course_id = ?\");",
    $content
);

// Pass course_id in forms
$content = str_replace(
    "<form method=\"POST\" id=\"deleteAttendanceForm\">",
    "<form method=\"POST\" id=\"deleteAttendanceForm\">\n<input type=\"hidden\" name=\"course_id\" value=\"<?= htmlspecialchars(\$course_id) ?>\">",
    $content
);
$content = str_replace(
    "<form method=\"POST\" id=\"createAttendanceForm\">",
    "<form method=\"POST\" id=\"createAttendanceForm\">\n<input type=\"hidden\" name=\"course_id\" value=\"<?= htmlspecialchars(\$course_id) ?>\">",
    $content
);

// Header redirects
$content = str_replace(
    "header(\"Location: attendance.php\");",
    "header(\"Location: course_attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&course_id=\" . urlencode(\$_POST['course_id'] ?? \$course_id));",
    $content
);
$content = str_replace(
    "header(\"Location: attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&date=\" . urlencode(\$date));",
    "header(\"Location: course_attendance.php?batch_id=\" . urlencode(\$batch_id) . \"&course_id=\" . urlencode(\$_POST['course_id'] ?? \$course_id) . \"&date=\" . urlencode(\$date));",
    $content
);

// Title
$content = str_replace(
    "<title>Attendance Tracking - ASD Academy</title>",
    "<title><?= htmlspecialchars(\$course_name) ?> Attendance - ASD Academy</title>",
    $content
);
$content = str_replace(
    "<span>Attendance Tracking</span>",
    "<span><?= htmlspecialchars(\$course_name) ?> - Attendance</span>",
    $content
);

// Include course_id in all API endpoints or fetch calls within script?
// Let's check for fetch('attendance_api.php'
$content = str_replace(
    "fetch(`attendance_api.php?action=get_attendance&batch_id=\${batchId}&date=\${date}`)",
    "fetch(`course_attendance_api.php?action=get_attendance&batch_id=\${batchId}&date=\${date}&course_id=<?= \$course_id ?>`)",
    $content
);

file_put_contents($file, $content);
echo "Transformed successfully.\n";
?>
