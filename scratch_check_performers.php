<?php
require_once 'c:/xampp/htdocs/version3/db_connection.php';
try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $top_performers_query = "
        SELECT 
            s.student_id,
            b.batch_name,
            COUNT(DISTINCT ta.test_id) as tests_taken,
            ROUND(AVG(ta.percentage), 2) as avg_score,
            SUM(CASE WHEN ta.percentage >= t.passing_marks THEN 1 ELSE 0 END) as tests_passed,
            MAX(ta.percentage) as highest_score,
            RANK() OVER (ORDER BY AVG(ta.percentage) DESC) as overall_rank
        FROM test_attempts ta
        JOIN tests t ON ta.test_id = t.id
        JOIN batches b ON t.batch_id = b.batch_id
        JOIN students s ON ta.student_id = s.student_id
        WHERE b.batch_mentor_id = :trainer_id
        AND ta.status = 'submitted'
        GROUP BY s.student_id, b.batch_name
        HAVING COUNT(DISTINCT ta.test_id) >= 1
    ";
    
    $stmt = $db->prepare($top_performers_query);
    $stmt->execute([':trainer_id' => 2]); // Trainer ID 2 is backend batch B003 mentor
    echo "TOP PERFORMERS: \n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
