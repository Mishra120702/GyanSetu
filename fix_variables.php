<?php
$content = file_get_contents('attendance/course_attendance.php');

$topInsert = <<<PHP
// Auto-inserted missing variables
if(!isset(\$batch_name_display)) {
    \$batch_stmt = \$db->prepare('SELECT batch_name FROM batches WHERE batch_id = ?');
    \$batch_stmt->execute([\$preselected_batch]);
    \$batch_name_display = \$batch_stmt->fetchColumn() ?: \$preselected_batch;
}

if(!isset(\$course_name)) {
    \$course_stmt = \$db->prepare('SELECT name FROM courses WHERE id = ?');
    \$course_stmt->execute([\$course_id]);
    \$course_name = \$course_stmt->fetchColumn() ?: 'Unknown Course';
}

if(!isset(\$batch_courses) || empty(\$batch_courses)) {
    try {
        \$stmt = \$db->prepare("SELECT c.id, c.name FROM courses c JOIN batch_courses bc ON c.id = bc.course_id WHERE bc.batch_id = ? ORDER BY c.name");
        \$stmt->execute([\$preselected_batch]);
        \$batch_courses = \$stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException \$e) {
        \$batch_courses = [];
    }
}
PHP;

$content = str_replace(
    "\$preselected_date = isset(\$_GET['date']) ? \$_GET['date'] : date('Y-m-d');", 
    "\$preselected_date = isset(\$_GET['date']) ? \$_GET['date'] : date('Y-m-d');\n" . $topInsert, 
    $content
);

file_put_contents('attendance/course_attendance.php', $content);
echo "Variables injected.";
?>
