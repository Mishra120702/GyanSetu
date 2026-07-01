<?php
require_once 'db_connection.php';
try {
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $db->query("SELECT b.batch_id, b.batch_name, c.id as course_id, c.name, bc.status FROM batch_courses bc JOIN batches b ON bc.batch_id = b.batch_id JOIN courses c ON bc.course_id = c.id WHERE b.batch_name LIKE '%Dummy%' AND c.name LIKE '%api testing%';");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    // Let's also check the topic verifications for this course in this batch!
    $stmt = $db->query("SELECT tv.status, COUNT(*) as count FROM topic_verifications tv JOIN batches b ON tv.batch_id = b.batch_id JOIN main_topics mt ON tv.main_topic_id = mt.id WHERE b.batch_name LIKE '%Dummy%' AND mt.course_id = (SELECT id FROM courses WHERE name LIKE '%api testing%' LIMIT 1) GROUP BY tv.status;");
    echo "\nTopic Verification stats:\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    // Also check if there's any automatic status update logic that triggered recently
    // Maybe we should look for recent updates in student_status_log or activity_logs
    
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
