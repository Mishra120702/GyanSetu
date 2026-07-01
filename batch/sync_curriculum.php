<?php
/**
 * Function to sync curriculum from course template to a batch
 */
function sync_course_curriculum_to_batch($db, $batch_id, $course_id) {
    try {
        $course_main_stmt = $db->prepare("SELECT * FROM course_main_topics WHERE course_id = ? AND (batch_id IS NULL OR batch_id = ?) AND (deleted_in_batches IS NULL OR deleted_in_batches NOT LIKE ?)");
        $course_main_stmt->execute([$course_id, $batch_id, "%[$batch_id]%"]);
        $course_mains = $course_main_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($course_mains as $cm) {
            // Check if main topic exists for this batch
            // The column is named 'batch_name' but it stores the batch_id (e.g., 'B001')
            $check_mt = $db->prepare("SELECT id FROM main_topics WHERE batch_name = ? AND topic_name = ? AND chapter = ? AND (course_id = ? OR course_id IS NULL)");
            $check_mt->execute([$batch_id, $cm['topic_name'], $cm['chapter'], $course_id]);
            $existing_mt = $check_mt->fetch(PDO::FETCH_ASSOC);
            
            $main_topic_id = null;
            if (!$existing_mt) {
                $insert_mt = $db->prepare("INSERT INTO main_topics (batch_name, course_id, chapter, topic_name, topic_type) VALUES (?, ?, ?, ?, ?)");
                $insert_mt->execute([$batch_id, $course_id, $cm['chapter'], $cm['topic_name'], $cm['topic_type']]);
                $main_topic_id = $db->lastInsertId();
            } else {
                $main_topic_id = $existing_mt['id'];
                
                // Also update the course_id if it's currently NULL for existing topics
                $update_mt = $db->prepare("UPDATE main_topics SET course_id = ? WHERE id = ? AND course_id IS NULL");
                $update_mt->execute([$course_id, $main_topic_id]);
            }
            
            // Sync subtopics
            $course_sub_stmt = $db->prepare("SELECT * FROM course_sub_topics WHERE course_main_topic_id = ? AND (batch_id IS NULL OR batch_id = ?) AND (deleted_in_batches IS NULL OR deleted_in_batches NOT LIKE ?)");
            $course_sub_stmt->execute([$cm['id'], $batch_id, "%[$batch_id]%"]);
            $course_subs = $course_sub_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($course_subs as $cs) {
                $check_st = $db->prepare("SELECT id FROM sub_topics WHERE main_topic_id = ? AND sub_topic_name = ?");
                $check_st->execute([$main_topic_id, $cs['sub_topic_name']]);
                if (!$check_st->fetch()) {
                    $insert_st = $db->prepare("INSERT INTO sub_topics (main_topic_id, sub_topic_name) VALUES (?, ?)");
                    $insert_st->execute([$main_topic_id, $cs['sub_topic_name']]);
                }
            }
        }
    } catch (Exception $e) {
        // Just log or ignore errors here to prevent breaking the main flow
        error_log("Error syncing curriculum: " . $e->getMessage());
    }
}

/**
 * Function to sync a course's curriculum to all batches it is assigned to
 */
function sync_course_to_all_batches($db, $course_id) {
    try {
        $stmt = $db->prepare("SELECT batch_id FROM batch_courses WHERE course_id = ?");
        $stmt->execute([$course_id]);
        $batches = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($batches as $batch_id) {
            sync_course_curriculum_to_batch($db, $batch_id, $course_id);
            
            // Also handle deletions: if a topic was removed from the course template, remove it from the batch
            // (Only for topics strictly tied to this course)
            
            // 1. Delete subtopics that no longer exist in template for this batch
            $db->prepare("
                DELETE st FROM sub_topics st
                JOIN main_topics mt ON st.main_topic_id = mt.id
                WHERE mt.batch_name = ? AND mt.course_id = ? 
                AND st.sub_topic_name NOT IN (
                    SELECT cst.sub_topic_name 
                    FROM course_sub_topics cst 
                    JOIN course_main_topics cmt ON cst.course_main_topic_id = cmt.id
                    WHERE cmt.course_id = ? 
                      AND (cst.batch_id IS NULL OR cst.batch_id = ?)
                      AND (cst.deleted_in_batches IS NULL OR cst.deleted_in_batches NOT LIKE ?)
                      AND cmt.topic_name = mt.topic_name
                )
            ")->execute([$batch_id, $course_id, $course_id, $batch_id, "%[$batch_id]%"]);
            
            // 2. Delete main topics that no longer exist in template for this batch
            $db->prepare("
                DELETE FROM main_topics 
                WHERE batch_name = ? AND course_id = ? 
                AND topic_name NOT IN (
                    SELECT topic_name FROM course_main_topics 
                    WHERE course_id = ?
                      AND (batch_id IS NULL OR batch_id = ?)
                      AND (deleted_in_batches IS NULL OR deleted_in_batches NOT LIKE ?)
                )
            ")->execute([$batch_id, $course_id, $course_id, $batch_id, "%[$batch_id]%"]);
        }
    } catch (Exception $e) {
        error_log("Error in auto-sync to all batches: " . $e->getMessage());
    }
}
?>
