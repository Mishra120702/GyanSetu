<?php
require 'db_connection.php';

try {
    // 1. Create a dummy batch
    $batch_id = 'DUMMY_' . rand(1000, 9999);
    $batch_name = 'Dummy Master Batch';
    
    $stmt = $db->prepare("INSERT INTO batches (batch_id, batch_name, status, start_date, max_students, current_enrollment, created_by) VALUES (?, ?, 'upcoming', CURDATE(), 50, 0, 1)");
    $stmt->execute([$batch_id, $batch_name]);
    echo "Created batch: $batch_name ($batch_id)<br>";
    
    // 2. Define the list of courses
    $course_names = [
        'Linux Fundamental',
        'Networking',
        'Python',
        'Windows Server',
        'SOC',
        'CEH',
        'WAPT',
        'Mobile App Testing',
        'API Testing',
        'Secure Code Review',
        'SC-900',
        'ISO-27001'
    ];
    
    // 3. Find or Create each course, and link it to the batch
    foreach ($course_names as $c_name) {
        $c_stmt = $db->prepare("SELECT id FROM courses WHERE name = ? LIMIT 1");
        $c_stmt->execute([$c_name]);
        $c_row = $c_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($c_row) {
            $course_id = $c_row['id'];
            echo "Found course: $c_name (ID: $course_id)<br>";
        } else {
            // Create it. Assuming basic columns.
            // Let's check what columns exist via try catch or just minimal.
            // `name` should be there.
            $ins_stmt = $db->prepare("INSERT INTO courses (name) VALUES (?)");
            $ins_stmt->execute([$c_name]);
            $course_id = $db->lastInsertId();
            echo "Created course: $c_name (ID: $course_id)<br>";
        }
        
        // Link it to the batch in course_content_visibility table
        $link_stmt = $db->prepare("INSERT IGNORE INTO course_content_visibility (course_id, batch_id) VALUES (?, ?)");
        $link_stmt->execute([$course_id, $batch_id]);
    }
    
    echo "Done linking courses to batch $batch_id.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
