<?php
$file = 'attendance/course_attendance_api.php';
$content = file_get_contents($file);

$content = str_replace(
    "\$batch_id = isset(\$_GET['batch_id']) ? \$_GET['batch_id'] : '';",
    "\$batch_id = isset(\$_GET['batch_id']) ? \$_GET['batch_id'] : '';\n    \$course_id = isset(\$_GET['course_id']) ? \$_GET['course_id'] : '';",
    $content
);

$content = str_replace(
    "FROM attendance WHERE batch_id = ? AND date = ?",
    "FROM course_attendance WHERE batch_id = ? AND date = ? AND course_id = ?",
    $content
);

$content = str_replace(
    "\$stmt->execute([\$batch_id, \$date]);",
    "\$stmt->execute([\$batch_id, \$date, \$course_id]);",
    $content
);

$content = preg_replace("/UPDATE attendance/i", "UPDATE course_attendance", $content);

// For update_status
$content = str_replace(
    "UPDATE course_attendance SET status = ? WHERE batch_id = ? AND student_id = ? AND date = ?",
    "UPDATE course_attendance SET status = ? WHERE batch_id = ? AND student_id = ? AND date = ? AND course_id = ?",
    $content
);

$content = str_replace(
    "\$stmt->execute([\$status, \$batch_id, \$student_id, \$date])",
    "\$stmt->execute([\$status, \$batch_id, \$student_id, \$date, \$course_id])",
    $content
);

// For update_camera
$content = str_replace(
    "UPDATE course_attendance SET camera_status = ? WHERE batch_id = ? AND student_id = ? AND date = ?",
    "UPDATE course_attendance SET camera_status = ? WHERE batch_id = ? AND student_id = ? AND date = ? AND course_id = ?",
    $content
);

$content = str_replace(
    "\$stmt->execute([\$camera_status, \$batch_id, \$student_id, \$date])",
    "\$stmt->execute([\$camera_status, \$batch_id, \$student_id, \$date, \$course_id])",
    $content
);

// For save_remarks
$content = str_replace(
    "UPDATE course_attendance SET remarks = ? WHERE batch_id = ? AND student_id = ? AND date = ?",
    "UPDATE course_attendance SET remarks = ? WHERE batch_id = ? AND student_id = ? AND date = ? AND course_id = ?",
    $content
);

$content = str_replace(
    "\$stmt->execute([\$remarks, \$batch_id, \$student_id, \$date])",
    "\$stmt->execute([\$remarks, \$batch_id, \$student_id, \$date, \$course_id])",
    $content
);

// Wait, the API receives json payload
// Need to add $course_id = $data['course_id'] ?? '';
$content = str_replace(
    "\$date = \$data['date'] ?? '';",
    "\$date = \$data['date'] ?? '';\n        \$course_id = \$data['course_id'] ?? '';",
    $content
);

file_put_contents($file, $content);
echo "Transformed API successfully.\n";
?>
