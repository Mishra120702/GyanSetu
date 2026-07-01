<?php
require 'db_connection.php';

try {
    $e_stmt = $db->query("SELECT exam_id, batch_id, course_id, exam_name FROM exams WHERE exam_id IN ('EXM178092240', 'EXM178098294', 'EXM178098392')");
    $exams = $e_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Exams data:\n";
    print_r($exams);
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
