<?php
require 'db_connection.php';

try {
    $stmt = $db->query("SELECT student_id, first_name, last_name, batch_name FROM students WHERE first_name LIKE '%dixant%' OR first_name LIKE '%dikshant%'");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Students found:\n";
    print_r($students);
    
    foreach ($students as $s) {
        $sid = $s['student_id'];
        
        $r_stmt = $db->prepare("SELECT * FROM exam_results WHERE student_id = ?");
        $r_stmt->execute([$sid]);
        $results = $r_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nResults for $sid:\n";
        print_r($results);
        
        // Also check if there are any results by name instead of student_id? Sometimes there are inconsistencies in other tables.
        // Also check the exams for their batch
        $batch = $s['batch_name'];
        $e_stmt = $db->prepare("SELECT exam_id, exam_name FROM exams WHERE batch_id = ?");
        $e_stmt->execute([$batch]);
        $exams = $e_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\nExams for batch $batch:\n";
        print_r($exams);
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
?>
