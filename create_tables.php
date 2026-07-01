<?php
require_once 'db_connection.php';

try {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $query1 = "CREATE TABLE IF NOT EXISTS `course_main_topics` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `course_id` int(11) NOT NULL,
      `chapter` int(11) NOT NULL,
      `topic_name` varchar(255) NOT NULL,
      `topic_type` enum('theory','practical','both') DEFAULT 'both',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `course_id` (`course_id`),
      CONSTRAINT `fk_cmt_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
    
    $db->exec($query1);
    echo "course_main_topics created.\n";

    $query2 = "CREATE TABLE IF NOT EXISTS `course_sub_topics` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `course_main_topic_id` int(11) NOT NULL,
      `sub_topic_name` varchar(255) NOT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `course_main_topic_id` (`course_main_topic_id`),
      CONSTRAINT `fk_cst_main_topic` FOREIGN KEY (`course_main_topic_id`) REFERENCES `course_main_topics` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
    
    $db->exec($query2);
    echo "course_sub_topics created.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
