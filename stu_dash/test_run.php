<?php
$db = new PDO("mysql:host=localhost;dbname=u621399201_koral", "root", "");
$stmt = $db->query("SELECT user_id, student_id, batch_name FROM students WHERE batch_name IS NOT NULL LIMIT 1");
$stu = $stmt->fetch();
file_put_contents("test_export.php", "<?php \$_GET['batch_id']='" . $stu['batch_name'] . "'; \$_GET['export']='weekly'; session_start(); \$_SESSION['user_id']='" . $stu['user_id'] . "'; \$_SESSION['user_role']='student'; include 'view_attendance.php'; ?>");
$out = shell_exec("C:\\xampp\\php\\php.exe test_export.php 2>&1");
file_put_contents("out.txt", $out);
