<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in as trainer
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../logout_t.php");
    exit;
}

try {
    // Database connection
    $db = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get trainer details
    $trainer_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT t.*, u.name FROM trainers t JOIN users u ON t.user_id = u.id WHERE t.user_id = ?");
    $stmt->execute([$trainer_id]);
    $trainer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trainer) {
        // Handle case where trainer data is not found
        header("Location: ../../log2.php");
        exit;
    }

    // Get all courses assigned to this trainer
    $courses_stmt = $db->prepare("
        SELECT bc.id as bc_id, bc.batch_id, bc.course_id, c.name as course_name, 
               b.batch_name, b.status, b.created_at, b.mode, b.start_date, b.end_date, b.time_slot,
               (SELECT COUNT(DISTINCT student_id) FROM students WHERE batch_name = b.batch_id OR batch_name_2 = b.batch_id OR batch_name_3 = b.batch_id OR batch_name_4 = b.batch_id) as student_count
        FROM batch_courses bc
        JOIN batches b ON bc.batch_id = b.batch_id
        JOIN courses c ON bc.course_id = c.id
        WHERE bc.trainer_id = ?
        ORDER BY b.created_at DESC
    ");
    $courses_stmt->execute([$trainer['id']]);
    $all_batches = $courses_stmt->fetchAll(PDO::FETCH_ASSOC);

    // UI summary metrics for the My Courses page
    $course_status_counts = [
        'ongoing' => 0,
        'upcoming' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
    $total_course_students = 0;
    $unique_course_keys = [];
    foreach ($all_batches as $ui_batch) {
        $ui_status = strtolower($ui_batch['status'] ?? 'upcoming');
        if (isset($course_status_counts[$ui_status])) {
            $course_status_counts[$ui_status]++;
        }
        $total_course_students += (int)($ui_batch['student_count'] ?? 0);
        if (!empty($ui_batch['course_id'])) {
            $unique_course_keys[$ui_batch['course_id']] = true;
        }
    }
    $total_unique_courses = count($unique_course_keys);


    // Get selected batch or default to first
    $selected_batch_id = $_GET['batch_id'] ?? ($all_batches[0]['batch_id'] ?? null);
    $selected_course_id = $_GET['course_id'] ?? ($all_batches[0]['course_id'] ?? null);
    $selected_batch = null;
    $batch_progress = [];
    $batch_topics = [];
    
    // --- Initial Progress Calculation (Set defaults to avoid errors) ---
    $batch_progress = [
        'total_topics' => 0, 'covered_topics' => 0, 
        'total_sub_topics' => 0, 'completed_sub_topics' => 0, 
        'theory_completed_total' => 0, 'practical_completed_total' => 0,
        'topic_progress' => 0, 'sub_topic_progress' => 0,
        'theory_progress' => 0, 'practical_progress' => 0
    ];

    if ($selected_batch_id && $selected_course_id) {
        // Get selected course details
        $batch_stmt = $db->prepare("
            SELECT bc.*, c.name as course_name, b.batch_name, b.status, b.created_at, b.mode, b.start_date, b.end_date, b.time_slot,
                   (SELECT COUNT(DISTINCT student_id) FROM students WHERE batch_name = b.batch_id OR batch_name_2 = b.batch_id OR batch_name_3 = b.batch_id OR batch_name_4 = b.batch_id) as student_count
            FROM batch_courses bc
            JOIN batches b ON bc.batch_id = b.batch_id
            JOIN courses c ON bc.course_id = c.id
            WHERE bc.batch_id = ? AND bc.course_id = ? AND bc.trainer_id = ?
        ");
        $batch_stmt->execute([$selected_batch_id, $selected_course_id, $trainer['id']]);
        $selected_batch = $batch_stmt->fetch(PDO::FETCH_ASSOC);

        if ($selected_batch) {
            // Get progress data for selected course with detailed sub-topic information
            $progress_stmt = $db->prepare("
                SELECT 
                    mt.id,
                    mt.chapter,
                    mt.topic_name,
                    mt.topic_type,
                    mt.covered_by_trainer,
                    mt.covered_date,
                    COUNT(st.id) as total_sub_topics,
                    SUM(CASE WHEN st.theory_completed = 1 AND st.practical_completed = 1 THEN 1 ELSE 0 END) as fully_completed_sub_topics,
                    SUM(st.theory_completed) as theory_completed_count,
                    SUM(st.practical_completed) as practical_completed_count,
                    GROUP_CONCAT(DISTINCT CONCAT(st.id, ':', st.sub_topic_name, ':', st.theory_completed, ':', st.practical_completed) SEPARATOR '||') as sub_topic_details
                FROM main_topics mt
                LEFT JOIN sub_topics st ON mt.id = st.main_topic_id
                WHERE mt.batch_name = ? AND mt.course_id = ?
                GROUP BY mt.id
                ORDER BY mt.chapter
            ");
            $progress_stmt->execute([$selected_batch_id, $selected_course_id]);
            $batch_topics = $progress_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calculate overall progress
            $total_topics = count($batch_topics);
            $covered_topics = 0;
            $total_sub_topics = 0;
            $completed_sub_topics = 0;
            $theory_completed_total = 0;
            $practical_completed_total = 0;

            foreach ($batch_topics as $topic) {
                // Count covered topics regardless of whether they have sub-topics
                if ($topic['covered_by_trainer']) $covered_topics++;
                // Only count sub-topic stats if they exist
                if ($topic['sub_topic_details'] !== null) {
                    $total_sub_topics += $topic['total_sub_topics'] ?? 0;
                    $completed_sub_topics += $topic['fully_completed_sub_topics'] ?? 0;
                    $theory_completed_total += $topic['theory_completed_count'] ?? 0;
                    $practical_completed_total += $topic['practical_completed_count'] ?? 0;
                }
            }

            $batch_progress = [
                'total_topics' => $total_topics,
                'covered_topics' => $covered_topics,
                'total_sub_topics' => $total_sub_topics,
                'completed_sub_topics' => $completed_sub_topics,
                'theory_completed_total' => $theory_completed_total,
                'practical_completed_total' => $practical_completed_total,
                'topic_progress' => $total_topics > 0 ? round(($covered_topics / $total_topics) * 100) : 0,
                'sub_topic_progress' => $total_sub_topics > 0 ? round(($completed_sub_topics / $total_sub_topics) * 100) : 0,
                'theory_progress' => $total_sub_topics > 0 ? round(($theory_completed_total / $total_sub_topics) * 100) : 0,
                'practical_progress' => $total_sub_topics > 0 ? round(($practical_completed_total / $total_sub_topics) * 100) : 0
            ];
        }
    }

    // Helper function to sync course journey status
    function updateCourseJourneyStatus($db, $batch_id, $course_id) {
        if (!$batch_id || !$course_id) return;
        
        $stmt = $db->prepare("
            SELECT COUNT(id) as total_topics, SUM(covered_by_trainer) as covered_topics
            FROM main_topics 
            WHERE batch_name = ? AND course_id = ?
        ");
        $stmt->execute([$batch_id, $course_id]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        $new_status = 'pending';
        if ($progress && $progress['total_topics'] > 0) {
            if ($progress['covered_topics'] == $progress['total_topics']) {
                $new_status = 'completed';
            } elseif ($progress['covered_topics'] > 0) {
                $new_status = 'in_progress';
            } else {
                $sub_stmt = $db->prepare("
                    SELECT COUNT(st.id) 
                    FROM sub_topics st
                    JOIN main_topics mt ON st.main_topic_id = mt.id
                    WHERE mt.batch_name = ? AND mt.course_id = ? AND (st.theory_completed = 1 OR st.practical_completed = 1)
                ");
                $sub_stmt->execute([$batch_id, $course_id]);
                if ($sub_stmt->fetchColumn() > 0) {
                    $new_status = 'in_progress';
                }
            }
        }
        $update_bc = $db->prepare("UPDATE batch_courses SET status = ? WHERE batch_id = ? AND course_id = ?");
        $update_bc->execute([$new_status, $batch_id, $course_id]);
    }

    // Handle POST requests for progress marking
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        // ** Main Topic Marking - AJAX version **
        if (isset($_POST['mark_covered'])) {
            $topic_id = $_POST['topic_id'];
            $is_covered = intval($_POST['is_covered'] ?? 0);
            
            // 1. Update main_topics table
            $update_stmt = $db->prepare("
                UPDATE main_topics 
                SET covered_by_trainer = ?, covered_date = CASE WHEN ? = 1 THEN NOW() ELSE NULL END 
                WHERE id = ? AND batch_name = ?
            ");
            $update_stmt->execute([$is_covered, $is_covered, $topic_id, $selected_batch_id]);
            
            // 2. If marking main topic as completed, mark all its sub-topics as completed (both theory and practical)
            if ($is_covered) {
                $update_sub_stmt = $db->prepare("
                    UPDATE sub_topics 
                    SET theory_completed = 1, practical_completed = 1, 
                        completed_by = ?, completed_at = NOW()
                    WHERE main_topic_id = ?
                ");
                $update_sub_stmt->execute([$_SESSION['user_id'], $topic_id]);
            }
            // 2b. If marking main topic as UNCOVERED, mark all its sub-topics as UNCOMPLETED
            else {
                 $update_sub_stmt = $db->prepare("
                    UPDATE sub_topics 
                    SET theory_completed = 0, practical_completed = 0, 
                        completed_by = NULL, completed_at = NULL
                    WHERE main_topic_id = ?
                ");
                $update_sub_stmt->execute([$topic_id]);
            }
            
            updateCourseJourneyStatus($db, $selected_batch_id, $selected_course_id);
            
            // If marking as COVERED, create/reset verification requests for all enrolled students
            if ($is_covered) {
                // Get all students enrolled in this batch
                $students_stmt = $db->prepare("
                    SELECT student_id FROM students 
                    WHERE batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?
                ");
                $students_stmt->execute([$selected_batch_id, $selected_batch_id, $selected_batch_id, $selected_batch_id]);
                $enrolled_students = $students_stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!empty($enrolled_students)) {
                    $insert_verif = $db->prepare("
                        INSERT INTO topic_verifications (main_topic_id, batch_id, course_id, student_id, status, created_at)
                        VALUES (?, ?, ?, ?, 'pending', NOW())
                        ON DUPLICATE KEY UPDATE status = 'pending', created_at = NOW(), responded_at = NULL, response_note = NULL
                    ");
                    foreach ($enrolled_students as $sid) {
                        $insert_verif->execute([$topic_id, $selected_batch_id, $selected_course_id, $sid]);
                    }
                    
                    // Get topic name and course name for notification
                    $topic_info = $db->prepare("SELECT mt.topic_name, mt.chapter, c.name as course_name FROM main_topics mt JOIN courses c ON c.id = mt.course_id WHERE mt.id = ?");
                    $topic_info->execute([$topic_id]);
                    $tinfo = $topic_info->fetch(PDO::FETCH_ASSOC);
                    
                    if ($tinfo) {
                        $notif_title = "✅ Chapter Covered: " . $tinfo['topic_name'];
                        $notif_msg   = "Ch." . $tinfo['chapter'] . " of " . $tinfo['course_name'] . " has been marked as covered by your trainer. Please verify if this topic was taught in your class today.";
                        $notif_stmt  = $db->prepare("
                            INSERT INTO admin_notifications (title, message, category, target_type, target_id, created_at)
                            VALUES (?, ?, 'CURRICULUM', 'batch', ?, NOW())
                        ");
                        $notif_stmt->execute([$notif_title, $notif_msg, $selected_batch_id]);
                    }
                }
            } else {
                // If UNCOVERING, remove ALL verifications for this topic
                $db->prepare("DELETE FROM topic_verifications WHERE main_topic_id = ? AND batch_id = ?")->execute([$topic_id, $selected_batch_id]);
            }
            
            // Return JSON response for AJAX
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'is_covered' => $is_covered]);
                exit;
            }
            
            // Refresh page to show updated data
            header("Location: my_courses.php?batch_id=" . $selected_batch_id . "&course_id=" . $selected_course_id);
            exit;
        }
        
        // ** Sub-Topic Marking - AJAX version **
        if (isset($_POST['update_sub_topic'])) {
            $sub_topic_id = $_POST['sub_topic_id'];
            $theory_completed = (int)$_POST['theory_completed_value']; 
            $practical_completed = (int)$_POST['practical_completed_value'];
            $completed_by = $_SESSION['user_id'];
            
            // 1. Update sub-topic completion
            $update_stmt = $db->prepare("
                UPDATE sub_topics 
                SET theory_completed = ?, practical_completed = ?, 
                    completed_by = CASE WHEN ? = 1 OR ? = 1 THEN ? ELSE NULL END, 
                    completed_at = CASE WHEN ? = 1 OR ? = 1 THEN NOW() ELSE NULL END
                WHERE id = ?
            ");
            $update_stmt->execute([
                $theory_completed, 
                $practical_completed, 
                $theory_completed, $practical_completed, $completed_by,
                $theory_completed, $practical_completed, 
                $sub_topic_id
            ]);
            
            // 2. Check if all sub-topics for the main topic are completed
            $check_stmt = $db->prepare("
                SELECT st.main_topic_id,
                       COUNT(st.id) as total_sub_topics,
                       SUM(CASE WHEN st.theory_completed = 1 AND st.practical_completed = 1 THEN 1 ELSE 0 END) as fully_completed
                FROM sub_topics st
                WHERE st.main_topic_id = (
                    SELECT main_topic_id FROM sub_topics WHERE id = ?
                )
                GROUP BY st.main_topic_id
            ");
            $check_stmt->execute([$sub_topic_id]);
            $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get the main topic ID for redirection
            $main_topic_id_for_redirect = $result['main_topic_id'] ?? null;

            // 3. If all sub-topics are completed, mark main topic as covered
            if ($result && $result['total_sub_topics'] > 0 && 
                $result['fully_completed'] == $result['total_sub_topics']) {
                $update_main_stmt = $db->prepare("
                    UPDATE main_topics 
                    SET covered_by_trainer = 1, covered_date = NOW()
                    WHERE id = ?
                ");
                $update_main_stmt->execute([$result['main_topic_id']]);
            } 
            // 4. If not all sub-topics are completed, ensure main topic is NOT covered (in case it was manually marked covered)
            else if ($result && $result['main_topic_id']) {
                 $update_main_stmt = $db->prepare("
                    UPDATE main_topics 
                    SET covered_by_trainer = 0, covered_date = NULL
                    WHERE id = ?
                ");
                $update_main_stmt->execute([$result['main_topic_id']]);
            }
            
            // 5. Send verification notification if ANY part (Theory/Practical) is marked
            if ($theory_completed == 1 || $practical_completed == 1) {
                if ($main_topic_id_for_redirect) {
                    $topic_id = $main_topic_id_for_redirect;
                    
                    // Get all students enrolled in this batch
                    $students_stmt = $db->prepare("
                        SELECT student_id FROM students 
                        WHERE batch_name = ? OR batch_name_2 = ? OR batch_name_3 = ? OR batch_name_4 = ?
                    ");
                    $students_stmt->execute([$selected_batch_id, $selected_batch_id, $selected_batch_id, $selected_batch_id]);
                    $enrolled_students = $students_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (!empty($enrolled_students)) {
                        $insert_verif = $db->prepare("
                            INSERT INTO topic_verifications (main_topic_id, batch_id, course_id, student_id, status, created_at)
                            VALUES (?, ?, ?, ?, 'pending', NOW())
                            ON DUPLICATE KEY UPDATE status = 'pending', created_at = NOW(), responded_at = NULL, response_note = NULL
                        ");
                        foreach ($enrolled_students as $sid) {
                            $insert_verif->execute([$topic_id, $selected_batch_id, $selected_course_id, $sid]);
                        }
                        
                        // Get topic name and course name for notification
                        $topic_info = $db->prepare("SELECT mt.topic_name, mt.chapter, c.name as course_name FROM main_topics mt JOIN courses c ON c.id = mt.course_id WHERE mt.id = ?");
                        $topic_info->execute([$topic_id]);
                        $tinfo = $topic_info->fetch(PDO::FETCH_ASSOC);
                        
                        if ($tinfo) {
                            $part_marked = [];
                            if ($theory_completed == 1) $part_marked[] = "Theory";
                            if ($practical_completed == 1) $part_marked[] = "Practical";
                            $part_str = implode(" and ", $part_marked);
                            
                            $notif_title = "📚 Progress Update: " . $tinfo['topic_name'];
                            $notif_msg   = "Ch." . $tinfo['chapter'] . " ($part_str) of " . $tinfo['course_name'] . " has been marked by your trainer. Please verify this progress in your dashboard.";
                            $notif_stmt  = $db->prepare("
                                INSERT INTO admin_notifications (title, message, category, target_type, target_id, created_at)
                                VALUES (?, ?, 'CURRICULUM', 'batch', ?, NOW())
                            ");
                            $notif_stmt->execute([$notif_title, $notif_msg, $selected_batch_id]);
                        }
                    }
                }
            }
            
            updateCourseJourneyStatus($db, $selected_batch_id, $selected_course_id);
            
            // Return JSON response for AJAX
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true, 
                    'theory_completed' => $theory_completed,
                    'practical_completed' => $practical_completed
                ]);
                exit;
            }
            
            // Refresh page to show updated data
            if ($selected_batch_id && $selected_course_id) {
                header("Location: my_courses.php?batch_id=" . $selected_batch_id . "&course_id=" . $selected_course_id);
            } else {
                header("Location: my_courses.php");
            }
            exit;
        }
    }

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>My Courses | Trainer Dashboard</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --success-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
            --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(229, 231, 235, 0.5);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        
        @media (max-width: 640px) {
            .glass-card {
                border-radius: 12px;
                padding: 1rem !important;
            }
        }
        
        .gradient-text {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            font-weight: bold;
        }
        
        .gradient-bg {
            background: var(--primary-gradient);
        }
        
        .progress-ring {
            transform: rotate(-90deg);
        }
        
        .progress-ring-circle {
            transition: stroke-dashoffset 0.5s ease;
        }
        
        .hover-lift {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .hover-lift:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        .status-ongoing {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }
        
        .status-upcoming {
            background: rgba(59, 130, 246, 0.15);
            color: #234C6A;
        }
        
        .status-completed {
            background: rgba(249, 115, 22, 0.15);
            color: #ea580c;
        }
        
        .status-cancelled {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
        }
        
        .topic-card {
            transition: all 0.3s ease;
            border-left: 4px solid #1B3C53;
        }
        
        .topic-card.covered {
            border-left-color: #10b981;
            background: rgba(16, 185, 129, 0.05);
        }
        
        .progress-bar-container {
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
            background: #e5e7eb;
        }
        
        .progress-bar-fill {
            height: 100%;
            transition: width 1s ease-in-out;
            border-radius: 3px;
        }
        
        .progress-checkbox {
            width: 1.25rem;
            height: 1.25rem;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid #d1d5db;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
            color: white;
            user-select: none;
            flex-shrink: 0;
        }
        
        @media (min-width: 640px) {
            .progress-checkbox {
                width: 1.5rem;
                height: 1.5rem;
                border-radius: 0.375rem;
            }
        }
        
        .progress-checkbox.checked {
            background-color: #10b981;
            border-color: #10b981;
        }
        
        .progress-checkbox.checked::after {
            content: '✓';
        }
        
        .progress-checkbox.theory {
            border-color: #234C6A;
            background-color: #e0f2fe;
        }
        
        .progress-checkbox.theory.checked {
            background-color: #234C6A;
            border-color: #234C6A;
        }
        
        .progress-checkbox.practical {
            border-color: #234C6A;
            background-color: #ede9fe;
        }
        
        .progress-checkbox.practical.checked {
            background-color: #234C6A;
            border-color: #234C6A;
        }
        
        .topic-type-badge {
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: inline-block;
            white-space: nowrap;
        }
        
        @media (min-width: 640px) {
            .topic-type-badge {
                padding: 0.25rem 0.75rem;
                font-size: 0.75rem;
            }
        }
        
        .topic-type-theory {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }
        
        .topic-type-practical {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .topic-type-both {
            background-color: #f3e8ff;
            color: #5b21b6;
            border: 1px solid #c4b5fd;
        }
        
        .chapter-progress-toggle {
            width: 2.5rem;
            height: 1.5rem;
            border-radius: 0.75rem;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #e5e7eb;
            position: relative;
            flex-shrink: 0;
        }
        
        @media (min-width: 640px) {
            .chapter-progress-toggle {
                width: 3rem;
                height: 1.75rem;
                border-radius: 0.875rem;
            }
        }
        
        .chapter-progress-toggle.active {
            background: #10b981;
        }
        
        .chapter-progress-toggle .toggle-slider {
            position: absolute;
            top: 2px;
            left: 2px;
            width: 1.25rem;
            height: 1.25rem;
            background: white;
            border-radius: 50%;
            transition: all 0.3s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        
        @media (min-width: 640px) {
            .chapter-progress-toggle .toggle-slider {
                width: 1.5rem;
                height: 1.5rem;
            }
        }
        
        .chapter-progress-toggle.active .toggle-slider {
            transform: translateX(1rem);
        }
        
        @media (min-width: 640px) {
            .chapter-progress-toggle.active .toggle-slider {
                transform: translateX(1.25rem);
            }
        }
        
        .loading-spinner {
            display: inline-block;
            width: 0.875rem;
            height: 0.875rem;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Scrollable topics section styles */
        .scrollable-topics-container {
            max-height: 65vh;
            overflow-y: auto;
            padding-right: 0.5rem;
        }
        
        .scrollable-topics-container::-webkit-scrollbar {
            width: 8px;
        }
        
        .scrollable-topics-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        
        .scrollable-topics-container::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        
        .scrollable-topics-container::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Mobile Navigation Styles */
        .mobile-nav-link {
            transition: all 0.3s ease;
        }
        
        .mobile-nav-link.active {
            background-color: #ffffff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-weight: 600;
        }
        
        #mobileMenu {
            transition: opacity 0.3s ease-in-out;
        }
        
        /* Mobile-specific styles */
        @media (max-width: 640px) {
            h1 { font-size: 1.5rem !important; }
            h2 { font-size: 1.25rem !important; }
            h3 { font-size: 1.125rem !important; }
            
            .text-2xl { font-size: 1.25rem !important; }
            .text-xl { font-size: 1.125rem !important; }
            
            .px-6 { padding-left: 1rem !important; padding-right: 1rem !important; }
            .py-4 { padding-top: 1rem !important; padding-bottom: 1rem !important; }
            
            .space-y-6 > * + * { margin-top: 1rem !important; }
            .space-y-4 > * + * { margin-top: 0.75rem !important; }
            
            .flex-col-mobile {
                flex-direction: column !important;
            }
            
            .flex-wrap-mobile {
                flex-wrap: wrap !important;
            }
            
            .text-center-mobile {
                text-align: center !important;
            }
            
            .w-full-mobile {
                width: 100% !important;
            }
            
            .stack-on-mobile {
                display: block !important;
            }
            
            .stack-on-mobile > * {
                margin-bottom: 0.75rem !important;
            }
            
            .stack-on-mobile > *:last-child {
                margin-bottom: 0 !important;
            }
            
            /* Adjust scrollable container height for mobile */
            .scrollable-topics-container {
                max-height: 50vh;
            }
        }
        
        /* Tablet-specific styles */
        @media (min-width: 641px) and (max-width: 1023px) {
            .lg\:col-span-1, .lg\:col-span-2 {
                grid-column: span 2 !important;
            }
            
            .grid-cols-1 {
                grid-template-columns: 1fr !important;
            }
            
            /* Adjust scrollable container height for tablet */
            .scrollable-topics-container {
                max-height: 55vh;
            }
        }
        
        /* Touch-friendly improvements */
        @media (max-width: 1024px) {
            button, 
            .progress-checkbox, 
            .chapter-progress-toggle,
            a[href] {
                min-height: 44px;
                min-width: 44px;
            }
            
            .progress-checkbox,
            .chapter-progress-toggle {
                min-height: 44px;
                min-width: 44px;
            }
            
            .subtopic-checkbox-label {
                min-height: 44px;
            }
        }
        
        /* Optimize animations for mobile */
        @media (prefers-reduced-motion: reduce) {
            *, ::before, ::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        /* Custom animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out forwards;
        }
        
        .animate-slide-in {
            animation: slideIn 0.3s ease-out forwards;
        }
        
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        
        /* Smooth transitions for expanding rows */
        .subtopic-row {
            transition: all 0.3s ease-in-out;
            overflow: hidden;
        }
    

        /* ===== Enhanced My Courses UI v2 ===== */
        :root {
            --asdn-ink: #0f172a;
            --asdn-muted: #64748b;
            --asdn-soft: #EEF3F6;
            --asdn-line: rgba(148, 163, 184, 0.22);
            --asdn-shadow: 0 18px 45px rgba(15, 23, 42, 0.10);
            --asdn-shadow-soft: 0 10px 28px rgba(15, 23, 42, 0.08);
            --asdn-accent: linear-gradient(135deg, #1B3C53 0%, #234C6A 42%, #456882 100%);
            --asdn-cool: linear-gradient(135deg, #456882 0%, #234C6A 100%);
            --asdn-green: linear-gradient(135deg, #10b981 0%, #22c55e 100%);
            --asdn-orange: linear-gradient(135deg, #f97316 0%, #f59e0b 100%);
        }

        body {
            background:
                radial-gradient(circle at 18% 12%, rgba(27,60,83, 0.14), transparent 28%),
                radial-gradient(circle at 78% 2%, rgba(69,104,130, 0.13), transparent 28%),
                linear-gradient(135deg, #f8fbff 0%, #edf4ff 54%, #f7f3ff 100%) !important;
        }

        .main-page-shell {
            position: relative;
        }

        .main-page-shell::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(27,60,83, .06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(27,60,83, .06) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,.8), transparent 85%);
            z-index: -1;
        }

        .glass-card {
            border: 1px solid rgba(255,255,255,.75) !important;
            box-shadow: var(--asdn-shadow-soft) !important;
            border-radius: 22px !important;
            background: rgba(255, 255, 255, 0.86) !important;
        }

        .course-hero {
            position: relative;
            overflow: hidden;
            border-radius: 28px;
            padding: clamp(1.2rem, 2.4vw, 2rem);
            color: #fff;
            background:
                radial-gradient(circle at 14% 10%, rgba(255,255,255,.24), transparent 22%),
                radial-gradient(circle at 72% 92%, rgba(255,255,255,.18), transparent 28%),
                linear-gradient(135deg, #1B3C53 0%, #234C6A 45%, #456882 100%);
            box-shadow: 0 24px 60px rgba(79,70,229,.25);
        }

        .course-hero::after {
            content: "";
            position: absolute;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            right: -100px;
            bottom: -160px;
            background: rgba(255,255,255,.16);
            filter: blur(2px);
        }

        .hero-pill {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            border: 1px solid rgba(255,255,255,.28);
            background: rgba(255,255,255,.16);
            color: #fff;
            border-radius: 999px;
            padding: .4rem .72rem;
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .02em;
            backdrop-filter: blur(10px);
        }

        .hero-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            padding: .76rem 1rem;
            border-radius: 14px;
            font-weight: 800;
            font-size: .84rem;
            transition: all .22s ease;
            border: 1px solid rgba(255,255,255,.28);
        }

        .hero-action.primary {
            background: #fff;
            color: #1B3C53;
            box-shadow: 0 12px 28px rgba(255,255,255,.22);
        }

        .hero-action.secondary {
            background: rgba(255,255,255,.16);
            color: #fff;
        }

        .hero-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(15,23,42,.16);
        }

        .hero-metric {
            position: relative;
            z-index: 1;
            border-radius: 20px;
            padding: 1rem;
            background: rgba(255,255,255,.16);
            border: 1px solid rgba(255,255,255,.22);
            backdrop-filter: blur(12px);
        }

        .hero-metric .metric-label {
            color: rgba(255,255,255,.78);
            font-size: .68rem;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 900;
        }

        .hero-metric .metric-value {
            color: #fff;
            font-weight: 900;
            font-size: clamp(1.25rem, 2vw, 1.9rem);
            margin-top: .2rem;
            line-height: 1;
        }

        .section-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .32rem .68rem;
            border-radius: 999px;
            background: #EEF3F6;
            color: #1B3C53;
            font-size: .68rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .course-search-box {
            position: relative;
        }

        .course-search-box i {
            position: absolute;
            left: .95rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .course-search-box input {
            width: 100%;
            border: 1px solid var(--asdn-line);
            background: rgba(248,250,252,.88);
            border-radius: 16px;
            padding: .78rem .85rem .78rem 2.55rem;
            color: var(--asdn-ink);
            font-size: .86rem;
            outline: none;
            transition: all .22s ease;
        }

        .course-search-box input:focus {
            border-color: #234C6A;
            box-shadow: 0 0 0 4px rgba(139,92,246,.12);
            background: #fff;
        }

        .course-select-card {
            position: relative;
            overflow: hidden;
            display: block;
            border-radius: 20px;
            border: 1px solid rgba(226,232,240,.95);
            background: linear-gradient(180deg, #ffffff, #f8fafc);
            padding: 1rem;
            transition: all .22s ease;
        }

        .course-select-card:hover {
            transform: translateY(-3px);
            border-color: rgba(35,76,106,.35);
            box-shadow: 0 14px 30px rgba(15,23,42,.09);
        }

        .course-select-card.active {
            border-color: rgba(35,76,106,.7);
            background:
                radial-gradient(circle at 100% 0%, rgba(69,104,130,.12), transparent 40%),
                linear-gradient(180deg, #ffffff, #F6F1ED);
            box-shadow: 0 16px 34px rgba(35,76,106,.15);
        }

        .course-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            background: var(--asdn-accent);
            box-shadow: 0 10px 22px rgba(35,76,106,.22);
        }

        .mini-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            border-radius: 999px;
            padding: .28rem .56rem;
            background: rgba(241,245,249,.95);
            color: #475569;
            font-size: .68rem;
            font-weight: 800;
            border: 1px solid rgba(226,232,240,.85);
        }

        .stats-modern-card {
            border-radius: 18px;
            padding: .92rem;
            border: 1px solid rgba(226,232,240,.9);
            background: linear-gradient(180deg, #fff, #f8fafc);
            transition: all .2s ease;
        }

        .stats-modern-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--asdn-shadow-soft);
        }

        .stats-modern-icon {
            width: 40px;
            height: 40px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            margin-right: .75rem;
            flex: 0 0 auto;
        }

        .right-focus-card {
            position: relative;
            overflow: hidden;
        }

        .right-focus-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 5px;
            background: var(--asdn-accent);
        }

        .topic-card {
            border-left: 0 !important;
            border: 1px solid rgba(226,232,240,.95) !important;
            box-shadow: 0 8px 22px rgba(15,23,42,.05) !important;
            border-radius: 20px !important;
            position: relative;
            overflow: hidden;
        }

        .topic-card::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 5px;
            background: #1B3C53;
        }

        .topic-card.covered::before {
            background: #10b981;
        }

        .subtopic-item {
            border: 1px solid rgba(226,232,240,.82);
            background: rgba(248,250,252,.88) !important;
        }

        .empty-state-pro {
            border: 1px dashed rgba(148,163,184,.55);
            background:
                radial-gradient(circle at 50% 0%, rgba(27,60,83,.10), transparent 40%),
                linear-gradient(180deg, #fff, #f8fafc);
            border-radius: 24px;
            padding: 2rem;
            text-align: center;
        }

        .empty-state-orb {
            width: 86px;
            height: 86px;
            border-radius: 28px;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1B3C53;
            background: linear-gradient(135deg, #EEF3F6, #fae8ff);
            box-shadow: inset 0 0 0 1px rgba(27,60,83,.12);
        }

        .status-badge {
            border: 1px solid currentColor;
            font-size: .66rem !important;
            letter-spacing: .06em !important;
        }

        .scrollable-topics-container {
            max-height: 68vh !important;
            padding-right: .65rem;
        }

        .progress-bar-container {
            background: #eef2f7 !important;
            height: 8px !important;
        }

        .progress-bar-fill {
            box-shadow: 0 8px 16px rgba(27,60,83,.18);
        }

        @media (max-width: 768px) {
            .course-hero {
                border-radius: 22px;
            }

            .hero-action {
                width: 100%;
            }

            .hero-metric {
                padding: .85rem;
            }
        }

    

        /* ===== Same Dashboard Theme Polish: visual-only, no new workflow ===== */
        .main-page-shell {
            animation: pageRise .45s ease both;
        }

        @keyframes pageRise {
            from { opacity: .86; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        header.hidden.lg\:flex,
        header.bg-gradient-to-r {
            background: rgba(255,255,255,.78) !important;
            backdrop-filter: blur(18px);
            border-bottom: 1px solid rgba(226,232,240,.72);
            box-shadow: 0 10px 28px rgba(15,23,42,.05) !important;
        }

        .course-hero {
            min-height: 252px;
            display: flex;
            align-items: center;
            border: 1px solid rgba(255,255,255,.24);
        }

        .course-hero::before {
            content: "";
            position: absolute;
            inset: -2px;
            background:
                linear-gradient(90deg, rgba(255,255,255,.16), transparent 30%, rgba(255,255,255,.12) 62%, transparent),
                radial-gradient(circle at 22% 78%, rgba(255,255,255,.16), transparent 30%);
            pointer-events: none;
        }

        .course-hero h2 {
            text-shadow: 0 14px 34px rgba(15,23,42,.18);
        }

        .hero-pill {
            box-shadow: inset 0 1px 0 rgba(255,255,255,.28), 0 8px 18px rgba(15,23,42,.08);
        }

        .hero-metric {
            transition: transform .22s ease, background .22s ease, box-shadow .22s ease;
        }

        .hero-metric:hover {
            transform: translateY(-4px);
            background: rgba(255,255,255,.22);
            box-shadow: 0 18px 38px rgba(15,23,42,.16);
        }

        .stats-modern-card,
        .course-select-card,
        .topic-card,
        .right-focus-card,
        .glass-card {
            backdrop-filter: blur(18px);
        }

        .stats-modern-card {
            box-shadow: 0 12px 30px rgba(15,23,42,.06);
        }

        .stats-modern-card:hover .stats-modern-icon,
        .course-select-card:hover .course-icon-box {
            transform: scale(1.06) rotate(-2deg);
        }

        .stats-modern-icon,
        .course-icon-box {
            transition: transform .22s ease, box-shadow .22s ease;
        }

        #courseList::-webkit-scrollbar,
        .scrollable-topics-container::-webkit-scrollbar {
            width: 7px;
        }

        #courseList::-webkit-scrollbar-thumb,
        .scrollable-topics-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #234C6A, #456882) !important;
            border-radius: 999px;
        }

        .right-focus-card {
            box-shadow: 0 20px 48px rgba(79,70,229,.10) !important;
        }

        .chapter-progress-toggle {
            box-shadow: inset 0 0 0 1px rgba(148,163,184,.30), 0 8px 18px rgba(15,23,42,.06);
        }

        .chapter-progress-toggle.active {
            background: linear-gradient(135deg, #10b981, #22c55e) !important;
            box-shadow: 0 10px 24px rgba(16,185,129,.22);
        }

        .progress-checkbox.checked {
            box-shadow: 0 8px 18px rgba(16,185,129,.20);
        }

        .topic-type-badge,
        .mini-chip,
        .status-badge {
            box-shadow: 0 6px 14px rgba(15,23,42,.04);
        }

        .subtopic-item:hover {
            border-color: rgba(35,76,106,.28);
            background: #fff !important;
            box-shadow: 0 10px 22px rgba(15,23,42,.06);
        }

        @media (max-width: 640px) {
            .course-hero {
                min-height: auto;
            }
            .course-hero h2 {
                font-size: 1.9rem !important;
            }
        }

/* ===== Brand palette update: #1B3C53, #234C6A, #456882, #D2C1B6 ===== */
:root {
    --dash-main: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --primary-gradient: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    --trainer-primary: #234C6A !important;
    --trainer-violet: #1B3C53 !important;
    --brand-dark: #1B3C53;
    --brand-primary: #234C6A;
    --brand-secondary: #456882;
    --brand-soft: #D2C1B6;
}
body {
    background:
        radial-gradient(circle at 14% 10%, rgba(27,60,83,.13), transparent 28%),
        radial-gradient(circle at 86% 8%, rgba(69,104,130,.13), transparent 30%),
        linear-gradient(135deg, #F6F1ED 0%, #EEF3F6 48%, #F8FBFF 100%) !important;
}
.bg-gradient-to-r.from-purple-500.to-pink-500,
.bg-gradient-to-r.from-indigo-500.to-purple-500,
.bg-gradient-to-r.from-indigo-600.to-purple-600,
.bg-gradient-to-r.from-blue-500.to-cyan-500,
.bg-gradient-to-r.from-blue-500.to-indigo-500,
.bg-gradient-to-r.from-purple-600.to-pink-600,
.bg-gradient-to-br.from-purple-500.to-pink-500,
.bg-gradient-to-br.from-blue-500.to-indigo-500,
.bg-gradient-to-br.from-indigo-500.to-purple-500,
.avatar-gradient,.avatar-gradient-2,.avatar-gradient-3,.avatar-gradient-4,.avatar-gradient-5 {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.text-purple-500,.text-purple-600,.text-indigo-500,.text-indigo-600,.text-blue-500,.text-blue-600,.text-cyan-500,.text-cyan-600 {
    color: #234C6A !important;
}
.bg-purple-50,.bg-indigo-50,.bg-blue-50,.from-purple-50,.from-indigo-50,.from-blue-50,.to-pink-50,.to-cyan-50,.to-indigo-50 {
    background-color: rgba(210,193,182,.22) !important;
}
.border-purple-200,.border-indigo-200,.border-blue-200 {
    border-color: rgba(69,104,130,.25) !important;
}
button[style*="--primary-gradient"],.btn-primary,.tab-button.active,.view-toggle.active,.page-link.active {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
}
.gradient-text {
    background: linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    -webkit-background-clip: text !important;
    background-clip: text !important;
    color: transparent !important;
}
.hero-chip,.section-kicker {
    border-color: rgba(210,193,182,.45) !important;
}

    </style>
<style>

/* ===== Company Source Safe UI Patch: My Courses theme like approved reference ===== */
/* CSS-only patch. PHP queries, POST handlers, AJAX, form names, IDs, links and DB logic are untouched. */

body {
    background:
        radial-gradient(circle at 12% 8%, rgba(27,60,83,.10), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(69,104,130,.10), transparent 30%),
        linear-gradient(135deg, #F7F2EE 0%, #EEF3F6 46%, #FBFAF8 100%) !important;
}

/* Hero: clean navy theme, right metrics readable */
.course-hero {
    min-height: 245px !important;
    border-radius: 28px !important;
    background:
        radial-gradient(circle at 92% 20%, rgba(255,255,255,.18), transparent 26%),
        radial-gradient(circle at 70% 110%, rgba(210,193,182,.20), transparent 30%),
        linear-gradient(135deg, #1B3C53 0%, #234C6A 48%, #456882 100%) !important;
    box-shadow:
        0 24px 64px rgba(27,60,83,.25),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    border: 1.6px solid rgba(255,255,255,.24) !important;
}

.course-hero::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    background:
        radial-gradient(circle at 98% 12%, rgba(255,255,255,.10), transparent 28%),
        linear-gradient(90deg, rgba(255,255,255,.10), transparent 34%, rgba(255,255,255,.08) 70%, transparent) !important;
    pointer-events: none !important;
}

.course-hero::after {
    width: 330px !important;
    height: 330px !important;
    right: -92px !important;
    bottom: -170px !important;
    background: rgba(255,255,255,.15) !important;
}

.course-hero h2,
.course-hero p,
.course-hero span,
.course-hero .hero-pill,
.course-hero .metric-label,
.course-hero .metric-value {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.course-hero h2 {
    text-shadow: 0 12px 30px rgba(0,0,0,.20) !important;
}

.hero-pill {
    background: rgba(255,255,255,.15) !important;
    border: 1.3px solid rgba(255,255,255,.28) !important;
    box-shadow:
        0 9px 22px rgba(15,23,42,.12),
        inset 0 1px 0 rgba(255,255,255,.24) !important;
}

.hero-metric {
    background: rgba(255,255,255,.15) !important;
    border: 1.4px solid rgba(255,255,255,.26) !important;
    border-radius: 19px !important;
    box-shadow:
        0 14px 30px rgba(15,23,42,.12),
        inset 0 1px 0 rgba(255,255,255,.22) !important;
    transition: transform .22s ease, box-shadow .22s ease, background .22s ease !important;
}

.hero-metric:hover {
    transform: translateY(-4px) !important;
    background: rgba(255,255,255,.21) !important;
    box-shadow:
        0 20px 42px rgba(15,23,42,.18),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

.hero-metric .metric-label {
    font-size: .70rem !important;
    font-weight: 1000 !important;
    letter-spacing: .07em !important;
    opacity: .86 !important;
}

.hero-metric .metric-value {
    font-weight: 1000 !important;
    font-size: clamp(1.45rem, 2.3vw, 2.1rem) !important;
    line-height: 1 !important;
}

/* Top status boxes exactly like approved visual: green, purple, orange, blue */
.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card {
    position: relative !important;
    overflow: hidden !important;
    min-height: 88px !important;
    color: #ffffff !important;
    border-radius: 18px !important;
    border: 1.5px solid rgba(255,255,255,.38) !important;
    box-shadow:
        0 18px 38px rgba(27,60,83,.16),
        inset 0 1px 0 rgba(255,255,255,.20) !important;
    transition: transform .22s ease, box-shadow .22s ease, filter .22s ease !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card::before {
    content: "" !important;
    position: absolute !important;
    inset: 0 !important;
    background:
        radial-gradient(circle at 92% 10%, rgba(255,255,255,.20), transparent 34%),
        radial-gradient(circle at 4% 100%, rgba(255,255,255,.10), transparent 32%) !important;
    pointer-events: none !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card::after {
    content: "" !important;
    position: absolute !important;
    right: -34px !important;
    top: -42px !important;
    width: 112px !important;
    height: 112px !important;
    border-radius: 999px !important;
    background: rgba(255,255,255,.14) !important;
    pointer-events: none !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card > * {
    position: relative !important;
    z-index: 2 !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card:nth-child(1) {
    background: linear-gradient(135deg, #047857 0%, #059669 54%, #10b981 100%) !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card:nth-child(2) {
    background: linear-gradient(135deg, #6d28d9 0%, #7c3aed 54%, #a78bfa 100%) !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card:nth-child(3) {
    background: linear-gradient(135deg, #b45309 0%, #d97706 54%, #f59e0b 100%) !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card:nth-child(4) {
    background: linear-gradient(135deg, #1d4ed8 0%, #2563eb 54%, #60a5fa 100%) !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card:hover {
    transform: translateY(-4px) !important;
    filter: brightness(1.06) !important;
    box-shadow:
        0 26px 55px rgba(27,60,83,.24),
        inset 0 1px 0 rgba(255,255,255,.28) !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card p,
.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card span {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    text-shadow: 0 1px 6px rgba(0,0,0,.16) !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card .stats-modern-icon {
    width: 46px !important;
    height: 46px !important;
    min-width: 46px !important;
    min-height: 46px !important;
    border-radius: 999px !important;
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.38), transparent 34%),
        rgba(255,255,255,.18) !important;
    border: 1.4px solid rgba(255,255,255,.46) !important;
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    box-shadow:
        0 12px 24px rgba(0,0,0,.18),
        inset 0 1px 0 rgba(255,255,255,.25) !important;
    transition: transform .22s ease, box-shadow .22s ease !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card .stats-modern-icon i {
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
}

.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card:hover .stats-modern-icon {
    transform: translateY(-2px) scale(1.10) rotate(3deg) !important;
    box-shadow:
        0 16px 34px rgba(0,0,0,.25),
        0 0 0 8px rgba(255,255,255,.14),
        inset 0 1px 0 rgba(255,255,255,.30) !important;
}

/* Main widgets / empty states shade like reference */
.glass-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.09), transparent 34%),
        radial-gradient(circle at 6% 96%, rgba(210,193,182,.22), transparent 30%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.88)) !important;
    border: 1.5px solid rgba(210,193,182,.64) !important;
    box-shadow:
        0 18px 44px rgba(27,60,83,.11),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
}

.glass-card:hover {
    box-shadow:
        0 26px 58px rgba(27,60,83,.15),
        inset 0 1px 0 rgba(255,255,255,.92) !important;
}

.right-focus-card {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.08), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border: 1.5px solid rgba(210,193,182,.68) !important;
    box-shadow:
        0 20px 48px rgba(27,60,83,.12),
        inset 0 1px 0 rgba(255,255,255,.86) !important;
}

.right-focus-card::before {
    background: linear-gradient(90deg, #1B3C53, #234C6A, #456882) !important;
    height: 5px !important;
}

.empty-state-pro {
    background:
        radial-gradient(circle at 50% 0%, rgba(69,104,130,.09), transparent 40%),
        linear-gradient(135deg, rgba(255,253,250,.97), rgba(238,243,246,.88)) !important;
    border: 1.6px dashed rgba(69,104,130,.34) !important;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.86),
        0 12px 30px rgba(27,60,83,.055) !important;
}

.empty-state-orb {
    background:
        radial-gradient(circle at 30% 20%, rgba(255,255,255,.60), transparent 36%),
        linear-gradient(135deg, #EEF3F6, #f3e8ff) !important;
    color: #1B3C53 !important;
    box-shadow:
        inset 0 0 0 1px rgba(27,60,83,.12),
        0 12px 28px rgba(27,60,83,.10) !important;
}

/* Course picker and topic cards */
.course-select-card,
.topic-card,
.subtopic-item,
.stats-modern-card:not(.main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card) {
    background:
        radial-gradient(circle at 96% 4%, rgba(69,104,130,.06), transparent 34%),
        linear-gradient(135deg, rgba(255,253,250,.98), rgba(246,241,237,.90)) !important;
    border-color: rgba(210,193,182,.62) !important;
}

.course-select-card:hover,
.topic-card:hover,
.subtopic-item:hover {
    border-color: rgba(35,76,106,.38) !important;
    box-shadow: 0 18px 38px rgba(27,60,83,.12) !important;
}

/* Progress bars readable and theme matched */
.progress-bar-container {
    background: rgba(27,60,83,.10) !important;
    height: 8px !important;
    border-radius: 999px !important;
}

.progress-bar-fill {
    background: linear-gradient(90deg, #1B3C53, #456882) !important;
    box-shadow: 0 6px 14px rgba(27,60,83,.18) !important;
}

/* Topic/subtopic controls visible but no functionality changes */
.progress-checkbox.theory,
.progress-checkbox.practical {
    border-color: #1B3C53 !important;
    background: rgba(27,60,83,.08) !important;
}

.progress-checkbox.theory.checked,
.progress-checkbox.practical.checked {
    background: linear-gradient(135deg, #1B3C53, #234C6A) !important;
    border-color: #1B3C53 !important;
    color: #ffffff !important;
    box-shadow: 0 8px 18px rgba(27,60,83,.22) !important;
}

.chapter-progress-toggle.active {
    background: linear-gradient(135deg, #10b981, #22c55e) !important;
}

@media (max-width: 768px) {
    .course-hero {
        min-height: auto !important;
        border-radius: 22px !important;
    }

    .main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card {
        min-height: 82px !important;
    }

    .main-page-shell > .grid.grid-cols-2.lg\:grid-cols-4 > .stats-modern-card .stats-modern-icon {
        width: 42px !important;
        height: 42px !important;
        min-width: 42px !important;
        min-height: 42px !important;
    }
}

</style>

<style>
/* ===== DOM-safe topbar avatar sync ===== */
/* Visual-only patch. It copies the already-working sidebar profile photo into top-right header avatar. */
.topbar-synced-profile-img {
    width: 42px !important;
    height: 42px !important;
    min-width: 42px !important;
    min-height: 42px !important;
    border-radius: 999px !important;
    object-fit: cover !important;
    border: 2px solid rgba(255,255,255,.82) !important;
    box-shadow: 0 10px 22px rgba(27,60,83,.18) !important;
    background: rgba(255,255,255,.22) !important;
    display: block !important;
}

.topbar-synced-profile-img.mobile {
    width: 34px !important;
    height: 34px !important;
    min-width: 34px !important;
    min-height: 34px !important;
}
</style>

</head>
<body class="relative overflow-x-hidden">
    <!-- Include Sidebar -->
    <?php include '../t_sidebar.php'; ?>
    
    <!-- Main Content Area -->
    <div class="flex-1 ml-0 lg:ml-64 min-h-screen transition-all duration-300 ease-in-out">
        <!-- Mobile Header -->
        <header class="bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-4 py-3 flex justify-between items-center sticky top-0 z-30 lg:hidden">
            <!-- Mobile Menu Button -->
            <button class="text-xl text-indigo-600 hover:text-indigo-800 transition-colors" onclick="toggleMobileMenu()">
                <i class="fas fa-bars"></i>
            </button>
            
            <h1 class="text-lg font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-layer-group text-indigo-600 text-sm"></i>
                </div>
                <span>My Courses</span>
            </h1>
            
            <div class="flex items-center space-x-3">
                <!-- Notification Bell -->
                <div class="relative">
                    <i class="fas fa-bell text-gray-600 hover:text-purple-600 cursor-pointer transition-colors"></i>
                    <span class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">3</span>
                </div>
                
                <!-- User Profile/Indicator -->
                <div class="relative">
                    <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold">
                        <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- Desktop Header -->
        <header class="hidden lg:flex bg-gradient-to-r from-blue-50 to-indigo-50 shadow-md px-6 py-4 justify-between items-center sticky top-0 z-30">
            <div class="flex-1"></div> <!-- Spacer for centering -->
            
            <h1 class="text-2xl font-bold text-gray-800 flex items-center space-x-2">
                <div class="bg-indigo-100 p-2 rounded-lg">
                    <i class="fas fa-layer-group text-indigo-600 text-xl"></i>
                </div>
                <span>My Courses</span>
            </h1>
            
            <div class="flex-1 flex justify-end items-center space-x-4">
                <!-- Notification Bell -->
                <?php include '../trainer_notification_bell.php'; ?>
                
                <!-- User Profile -->
                <div class="flex items-center space-x-2">
                    <div class="w-10 h-10 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white font-bold">
                        <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                    </div>
                    <div>
                        <p class="font-semibold"><?php echo htmlspecialchars($trainer['name']); ?></p>
                        <p class="text-sm text-gray-500">Trainer</p>
                    </div>
                </div>
            </div>
        </header>

        <!-- Mobile Navigation Menu -->
        <div id="mobileMenu" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden lg:hidden">
            <div class="absolute left-0 top-0 h-full w-4/5 max-w-xs bg-gradient-to-b from-gray-900 to-black shadow-xl transform transition-transform duration-300 -translate-x-full">
                <!-- Mobile Menu Header -->
                <div class="p-4 border-b border-gray-800 bg-gradient-to-r from-gray-800 to-black">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <img src="../../logo2.png" alt="ASD Academy Logo" class="h-8 w-auto object-contain">
                        </div>
                        <button onclick="toggleMobileMenu()" class="text-gray-400 hover:text-white text-xl">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    
                    <!-- User Info -->
                    <div class="mt-4 flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold">
                            <?php echo strtoupper(substr($trainer['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <p class="font-medium text-white"><?php echo htmlspecialchars($trainer['name']); ?></p>
                            <p class="text-xs text-gray-400">Trainer</p>
                        </div>
                    </div>
                </div>
                
                <!-- Mobile Navigation Links -->
                <nav class="flex-1 overflow-y-auto p-4 space-y-1">
                    <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                    
                    <a href="../dashboard/dashboard.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'dashboard.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-blue-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-blue-900 to-blue-700 flex items-center justify-center">
                            <i class="fas fa-tachometer-alt text-blue-300 text-sm"></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>

                    <a href="../courses/my_courses.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'my_courses.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-purple-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-purple-900 to-purple-700 flex items-center justify-center">
                            <i class="fas fa-layer-group text-purple-300 text-sm"></i>
                        </div>
                        <span class="font-medium">My Courses</span>
                        <?php if (isset($all_batches) && count($all_batches) > 0): ?>
                            <span class="ml-auto bg-purple-600 text-white text-xs font-bold px-2 py-1 rounded-full">
                                <?php echo count($all_batches); ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <a href="../students/students.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'students.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-blue-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-blue-900 to-blue-700 flex items-center justify-center">
                            <i class="fas fa-users text-blue-300 text-sm"></i>
                        </div>
                        <span class="font-medium">My Students</span>
                    </a>

                    <a href="../schedule/schedule.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'schedule.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-green-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-green-900 to-green-700 flex items-center justify-center">
                            <i class="fas fa-calendar-alt text-green-300 text-sm"></i>
                        </div>
                        <span class="font-medium">Schedule</span>
                    </a>

                    <a href="../attendance/trainer_attendance.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'trainer_attendance.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-amber-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-amber-900 to-amber-700 flex items-center justify-center">
                            <i class="fas fa-clipboard-check text-amber-300 text-sm"></i>
                        </div>
                        <span class="font-medium">Attendance</span>
                    </a>
                    
                    <a href="../feedback/weekly_feedback.php" 
                       class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'weekly_feedback.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-amber-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                       onclick="toggleMobileMenu()">
                        <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-amber-900 to-amber-700 flex items-center justify-center">
                            <i class="fas fa-comment-dots text-amber-300 text-sm"></i>
                        </div>
                        <span class="font-medium">Feedback</span>
                    </a>

                    <!-- Assessments Section -->
                    <div class="pt-3">
                        <p class="text-xs uppercase text-gray-500 font-bold tracking-wider px-3 mb-2">Assessments</p>
                        
                        <a href="../exam/trainer_dashboard.php" 
                           class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'trainer_dashboard.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-red-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                           onclick="toggleMobileMenu()">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-red-900 to-red-700 flex items-center justify-center">
                                <i class="fas fa-file-alt text-red-300 text-sm"></i>
                            </div>
                            <span class="font-medium">Exams</span>
                        </a>

                        <a href="../content/trainer_content.php" 
                           class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'trainer_content.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-indigo-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                           onclick="toggleMobileMenu()">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-indigo-900 to-indigo-700 flex items-center justify-center">
                                <i class="fas fa-tasks text-indigo-300 text-sm"></i>
                            </div>
                            <span class="font-medium">Study Materials</span>
                        </a>
                        
                        <a href="../profile.php" 
                           class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 <?= $current_page == 'profile.php' ? 'bg-gray-800 bg-opacity-50 border-l-4 border-indigo-500' : 'hover:bg-gray-800 hover:bg-opacity-50 text-gray-300' ?>"
                           onclick="toggleMobileMenu()">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-indigo-900 to-indigo-700 flex items-center justify-center">
                                <i class="fas fa-user text-indigo-300 text-sm"></i>
                            </div>
                            <span class="font-medium">Profile</span>
                        </a>

                        <!-- Logout Button - Moved to be just below Profile -->
                        <a href="../logout.php" 
                           class="mobile-nav-link flex items-center space-x-3 p-3 rounded-lg transition-all duration-300 hover:bg-red-900 hover:text-red-400 text-gray-300 mt-2"
                           onclick="toggleMobileMenu()">
                            <div class="w-8 h-8 rounded-lg bg-gradient-to-r from-red-900 to-red-700 flex items-center justify-center">
                                <i class="fas fa-sign-out-alt text-red-300 text-sm"></i>
                            </div>
                            <span class="font-medium">Logout</span>
                        </a>
                    </div>
                </nav>
            </div>
        </div>

        <main class="main-page-shell p-3 sm:p-4 md:p-6">
            <!-- Welcome Hero -->
            <section class="course-hero mb-4 sm:mb-6" data-aos="fade-up">
                <div class="relative z-10 grid grid-cols-1 xl:grid-cols-12 gap-6 items-center">
                    <div class="xl:col-span-7">
                        <div class="flex flex-wrap gap-2 mb-4">
                            <span class="hero-pill"><i class="fas fa-layer-group"></i> Trainer Courses</span>
                            <span class="hero-pill"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($trainer['name']); ?></span>
                            <span class="hero-pill"><i class="fas fa-calendar-day"></i> <?php echo date('l, d M Y'); ?></span>
                        </div>
                        <h2 class="text-3xl sm:text-4xl font-black leading-tight mb-3">
                            Course Control Center
                        </h2>
                        <p class="text-white/85 text-sm sm:text-base max-w-2xl">
                            Track assigned batches, monitor topic coverage, update theory/practical completion, and keep student verification flowing from one clean workspace.
                        </p>
                        <!-- Navigation quick actions removed as requested: existing course workflow remains unchanged. -->
                    </div>

                    <div class="xl:col-span-5 grid grid-cols-2 gap-3">
                        <div class="hero-metric">
                            <div class="metric-label">Assigned batches</div>
                            <div class="metric-value"><?php echo count($all_batches); ?></div>
                        </div>
                        <div class="hero-metric">
                            <div class="metric-label">Unique courses</div>
                            <div class="metric-value"><?php echo $total_unique_courses; ?></div>
                        </div>
                        <div class="hero-metric">
                            <div class="metric-label">Linked students</div>
                            <div class="metric-value"><?php echo $total_course_students; ?></div>
                        </div>
                        <div class="hero-metric">
                            <div class="metric-label">Selected progress</div>
                            <div class="metric-value"><?php echo $batch_progress['topic_progress'] ?? 0; ?>%</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Course Status Snapshot -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4 sm:mb-6" data-aos="fade-up" data-aos-delay="50">
                <div class="stats-modern-card flex items-center">
                    <div class="stats-modern-icon" style="background: var(--asdn-green);"><i class="fas fa-play"></i></div>
                    <div>
                        <p class="text-xs font-black text-slate-500 uppercase tracking-wide">Ongoing</p>
                        <p class="text-2xl font-black text-slate-900"><?php echo $course_status_counts['ongoing']; ?></p>
                    </div>
                </div>
                <div class="stats-modern-card flex items-center">
                    <div class="stats-modern-icon" style="background: var(--asdn-cool);"><i class="fas fa-clock"></i></div>
                    <div>
                        <p class="text-xs font-black text-slate-500 uppercase tracking-wide">Upcoming</p>
                        <p class="text-2xl font-black text-slate-900"><?php echo $course_status_counts['upcoming']; ?></p>
                    </div>
                </div>
                <div class="stats-modern-card flex items-center">
                    <div class="stats-modern-icon" style="background: var(--asdn-orange);"><i class="fas fa-check-double"></i></div>
                    <div>
                        <p class="text-xs font-black text-slate-500 uppercase tracking-wide">Completed</p>
                        <p class="text-2xl font-black text-slate-900"><?php echo $course_status_counts['completed']; ?></p>
                    </div>
                </div>
                <div class="stats-modern-card flex items-center">
                    <div class="stats-modern-icon" style="background: var(--asdn-accent);"><i class="fas fa-chart-line"></i></div>
                    <div>
                        <p class="text-xs font-black text-slate-500 uppercase tracking-wide">Overall</p>
                        <p class="text-2xl font-black text-slate-900"><?php echo $batch_progress['topic_progress'] ?? 0; ?>%</p>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
                <!-- Left Column: Batch List & Stats -->
                <div class="lg:col-span-1 space-y-4 sm:space-y-6">
                    <!-- Batch Selection Card -->
                    <div class="glass-card p-4 sm:p-6" data-aos="fade-right" data-aos-delay="100">
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div>
                                <span class="section-eyebrow"><i class="fas fa-list-check"></i> Course Picker</span>
                                <h3 class="text-xl font-black text-slate-900 mt-3">Select Course</h3>
                                <p class="text-xs text-slate-500 mt-1">Choose a batch to update curriculum progress.</p>
                            </div>
                            <span class="mini-chip"><i class="fas fa-layer-group"></i><?php echo count($all_batches); ?> assigned</span>
                        </div>

                        <div class="course-search-box mb-4">
                            <i class="fas fa-search"></i>
                            <input id="courseSearchInput" type="text" placeholder="Search course, batch or status...">
                        </div>

                        <div id="courseList" class="space-y-3 max-h-[68vh] overflow-y-auto pr-1">
                            <?php if (empty($all_batches)): ?>
                                <div class="empty-state-pro">
                                    <div class="empty-state-orb">
                                        <i class="fas fa-box-open text-3xl"></i>
                                    </div>
                                    <h4 class="text-base font-black text-slate-800">No courses assigned yet</h4>
                                    <p class="text-xs text-slate-500 mt-2">Once admin assigns a batch, it will appear here automatically.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($all_batches as $batch): 
                                    $is_active_course = ($selected_batch_id == $batch['batch_id'] && $selected_course_id == $batch['course_id']);
                                    $search_text = strtolower(($batch['course_name'] ?? '') . ' ' . ($batch['batch_name'] ?? '') . ' ' . ($batch['batch_id'] ?? '') . ' ' . ($batch['status'] ?? ''));
                                ?>
                                    <a href="?batch_id=<?php echo urlencode($batch['batch_id']); ?>&course_id=<?php echo urlencode($batch['course_id']); ?>" 
                                       data-course-card
                                       data-search="<?php echo htmlspecialchars($search_text); ?>"
                                       class="course-select-card <?php echo $is_active_course ? 'active' : ''; ?>">
                                        <div class="flex gap-3">
                                            <div class="course-icon-box">
                                                <i class="fas fa-book-open"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="min-w-0">
                                                        <h4 class="font-black text-slate-900 text-sm sm:text-base truncate"><?php echo htmlspecialchars($batch['course_name'] ?? 'UNKNOWN COURSE'); ?></h4>
                                                        <p class="text-xs text-indigo-600 mt-1 font-extrabold truncate"><?php echo htmlspecialchars($batch['batch_name']); ?></p>
                                                    </div>
                                                    <?php if ($is_active_course): ?>
                                                        <span class="w-3 h-3 bg-indigo-500 rounded-full animate-pulse flex-shrink-0 mt-1"></span>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="flex flex-wrap gap-1.5 mt-3">
                                                    <span class="status-badge status-<?php echo $batch['status']; ?>">
                                                        <?php echo ucfirst($batch['status']); ?>
                                                    </span>
                                                    <span class="mini-chip">
                                                        <i class="fas fa-users"></i><?php echo $batch['student_count']; ?> students
                                                    </span>
                                                    <span class="mini-chip">
                                                        <i class="fas fa-hashtag"></i><?php echo htmlspecialchars($batch['batch_id']); ?>
                                                    </span>
                                                </div>

                                                <div class="mt-3 text-[11px] text-slate-500 flex items-center gap-1">
                                                    <i class="fas fa-calendar"></i>
                                                    <span class="truncate"><?php echo date('M j, Y', strtotime($batch['start_date'])); ?> - <?php echo date('M j, Y', strtotime($batch['end_date'])); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>

                                <div id="courseSearchEmpty" class="empty-state-pro hidden">
                                    <div class="empty-state-orb">
                                        <i class="fas fa-search text-3xl"></i>
                                    </div>
                                    <h4 class="text-base font-black text-slate-800">No matching course found</h4>
                                    <p class="text-xs text-slate-500 mt-2">Try another course name, batch ID, or status.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($selected_batch): ?>
                    <!-- Progress Statistics Card -->
                    <div class="glass-card p-4 sm:p-6" data-aos="fade-right" data-aos-delay="200">
                        <h3 class="text-lg sm:text-xl font-bold text-gray-800 mb-3 sm:mb-4 flex items-center">
                            <i class="fas fa-chart-pie mr-2 text-blue-500"></i>
                            Progress Statistics
                        </h3>
                        
                        <div class="space-y-3 sm:space-y-4">
                            <div class="flex items-center justify-between p-3 bg-gradient-to-r from-blue-50 to-cyan-50 rounded-xl">
                                <div class="flex items-center min-w-0">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 bg-blue-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                        <i class="fas fa-book-open text-blue-600 text-sm sm:text-base"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs sm:text-sm text-gray-600 truncate">Topics Covered</p>
                                        <p class="text-lg sm:text-xl font-bold truncate"><?php echo $batch_progress['covered_topics']; ?>/<?php echo $batch_progress['total_topics']; ?></p>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="text-xl sm:text-2xl font-bold text-blue-600"><?php echo $batch_progress['topic_progress']; ?>%</div>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl">
                                <div class="flex items-center min-w-0">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 bg-green-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                        <i class="fas fa-brain text-green-600 text-sm sm:text-base"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs sm:text-sm text-gray-600 truncate">Theory Completed</p>
                                        <p class="text-lg sm:text-xl font-bold truncate"><?php echo $batch_progress['theory_completed_total']; ?>/<?php echo $batch_progress['total_sub_topics']; ?></p>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="text-xl sm:text-2xl font-bold text-green-600"><?php echo $batch_progress['theory_progress']; ?>%</div>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-gradient-to-r from-cyan-50 to-blue-50 rounded-xl">
                                <div class="flex items-center min-w-0">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 bg-cyan-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                        <i class="fas fa-laptop-code text-cyan-600 text-sm sm:text-base"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs sm:text-sm text-gray-600 truncate">Practical Completed</p>
                                        <p class="text-lg sm:text-xl font-bold truncate"><?php echo $batch_progress['practical_completed_total']; ?>/<?php echo $batch_progress['total_sub_topics']; ?></p>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="text-xl sm:text-2xl font-bold text-cyan-600"><?php echo $batch_progress['practical_progress']; ?>%</div>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl">
                                <div class="flex items-center min-w-0">
                                    <div class="w-8 h-8 sm:w-10 sm:h-10 bg-purple-100 rounded-lg flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
                                        <i class="fas fa-tasks text-purple-600 text-sm sm:text-base"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-xs sm:text-sm text-gray-600 truncate">Sub-topics Completed</p>
                                        <p class="text-lg sm:text-xl font-bold truncate"><?php echo $batch_progress['completed_sub_topics']; ?>/<?php echo $batch_progress['total_sub_topics']; ?></p>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="text-xl sm:text-2xl font-bold text-purple-600"><?php echo $batch_progress['sub_topic_progress']; ?>%</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right Column: Batch Details & Topics -->
                <div class="lg:col-span-2 space-y-4 sm:space-y-6">
                    <?php if ($selected_batch): ?>
                        <!-- Batch Details Card -->
                        <div class="glass-card right-focus-card p-4 sm:p-6" data-aos="fade-left">
                            <div class="flex flex-col md:flex-row md:items-center justify-between mb-4 sm:mb-6">
                                <div class="mb-4 md:mb-0">
                                    <div class="flex flex-col sm:flex-row sm:items-center mb-2 gap-2">
                                        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 truncate"><?php echo htmlspecialchars($selected_batch['course_name'] ?? 'UNKNOWN COURSE'); ?> <span class="text-base text-gray-500 font-normal ml-2">(<?php echo htmlspecialchars($selected_batch['batch_name'] ?? 'UNKNOWN BATCH'); ?>)</span></h2>
                                        <span class="status-badge status-<?php echo $selected_batch['status'] ?? 'pending'; ?> self-start sm:self-center">
                                            <?php echo ucfirst($selected_batch['status']); ?>
                                        </span>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm text-gray-600">
                                        <div class="flex items-center bg-gray-100 px-2 py-1 rounded">
                                            <i class="fas fa-hashtag mr-1"></i>
                                            <span class="truncate max-w-[100px] sm:max-w-none"><?php echo htmlspecialchars($selected_batch['batch_id']); ?></span>
                                        </div>
                                        <div class="flex items-center bg-gray-100 px-2 py-1 rounded">
                                            <i class="fas fa-calendar-alt mr-1"></i>
                                            <span class="truncate max-w-[150px] sm:max-w-none"><?php echo date('M j, Y', strtotime($selected_batch['start_date'])); ?> - <?php echo date('M j, Y', strtotime($selected_batch['end_date'])); ?></span>
                                        </div>
                                        <div class="flex items-center bg-gray-100 px-2 py-1 rounded">
                                            <i class="fas fa-clock mr-1"></i>
                                            <span><?php echo htmlspecialchars($selected_batch['time_slot']); ?></span>
                                        </div>
                                        <div class="flex items-center bg-gray-100 px-2 py-1 rounded">
                                            <i class="fas fa-video mr-1"></i>
                                            <span><?php echo ucfirst($selected_batch['mode']); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex-shrink-0">
                                    <div class="relative w-16 h-16 sm:w-20 sm:h-20 md:w-24 md:h-24 mx-auto">
                                        <svg class="progress-ring w-full h-full" viewBox="0 0 100 100">
                                            <circle class="progress-ring-circle" stroke="#e5e7eb" stroke-width="6" fill="transparent" r="42" cx="50" cy="50"/>
                                            <circle class="progress-ring-circle" stroke="url(#gradient)" stroke-width="6" fill="transparent" r="42" cx="50" cy="50" 
                                                    stroke-dasharray="264" 
                                                    stroke-dashoffset="<?php echo 264 - (264 * $batch_progress['topic_progress'] / 100); ?>"
                                                    stroke-linecap="round"/>
                                            <defs>
                                                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                                    <stop offset="0%" stop-color="#1B3C53" />
                                                    <stop offset="100%" stop-color="#234C6A" />
                                                </linearGradient>
                                            </defs>
                                        </svg>
                                        <div class="absolute inset-0 flex items-center justify-center">
                                            <span class="text-lg sm:text-xl md:text-2xl font-bold gradient-text"><?php echo $batch_progress['topic_progress']; ?>%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="space-y-3 sm:space-y-4">
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-xs sm:text-sm font-medium text-gray-700">Topic Completion</span>
                                        <span class="text-xs sm:text-sm font-bold text-purple-600"><?php echo $batch_progress['topic_progress']; ?>%</span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-fill bg-gradient-to-r from-purple-500 to-pink-500" style="width: <?php echo $batch_progress['topic_progress']; ?>%"></div>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="flex justify-between mb-1">
                                        <span class="text-xs sm:text-sm font-medium text-gray-700">Sub-topic Completion</span>
                                        <span class="text-xs sm:text-sm font-bold text-green-600"><?php echo $batch_progress['sub_topic_progress']; ?>%</span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-fill bg-gradient-to-r from-green-400 to-blue-500" style="width: <?php echo $batch_progress['sub_topic_progress']; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Topics & Progress Card with Scrollbar -->
                        <div class="glass-card right-focus-card p-4 sm:p-6" data-aos="fade-left" data-aos-delay="100">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 sm:mb-6 gap-2">
                                <h3 class="text-lg sm:text-xl font-bold text-gray-800 flex items-center">
                                    <i class="fas fa-graduation-cap mr-2 text-indigo-500"></i>
                                    Topics & Progress
                                </h3>
                                <div class="text-xs sm:text-sm text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
                                    <?php echo $batch_progress['covered_topics']; ?> of <?php echo $batch_progress['total_topics']; ?> topics covered
                                </div>
                            </div>
                            
                            <?php if (empty($batch_topics) || $batch_topics[0]['id'] === null): ?>
                                <div class="text-center py-8 sm:py-10">
                                    <i class="fas fa-book-open text-4xl sm:text-5xl text-gray-300 mb-3 sm:mb-4"></i>
                                    <p class="text-gray-500 mb-4 text-sm sm:text-base">No topics have been added to this batch yet</p>
                                </div>
                            <?php else: ?>
                                <!-- Scrollable Topics Container -->
                                <div class="scrollable-topics-container">
                                    <div class="space-y-4 sm:space-y-6">
                                        <?php foreach ($batch_topics as $index => $topic): ?>
                                            <div class="topic-card p-3 sm:p-5 bg-white rounded-xl shadow-sm <?php echo $topic['covered_by_trainer'] ? 'covered' : ''; ?> fade-in"
                                                 style="animation-delay: <?php echo $index * 0.05; ?>s"
                                                 id="topic-<?php echo $topic['id']; ?>">
                                                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-3 sm:gap-4">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex flex-col sm:flex-row sm:items-center mb-2 sm:mb-3 gap-2">
                                                            <span class="px-2 py-1 sm:px-3 sm:py-1 bg-indigo-100 text-indigo-800 rounded-full text-xs sm:text-sm font-semibold self-start">
                                                                Ch. <?php echo $topic['chapter']; ?>
                                                            </span>
                                                            <h4 class="text-base sm:text-lg font-bold text-gray-800 truncate"><?php echo htmlspecialchars($topic['topic_name']); ?></h4>
                                                            <div class="flex flex-wrap gap-2">
                                                                <span class="topic-type-badge topic-type-<?php echo $topic['topic_type'] ?? 'both'; ?>">
                                                                    <?php echo ucfirst($topic['topic_type'] ?? 'both'); ?>
                                                                </span>
                                                                <?php if ($topic['covered_by_trainer']): ?>
                                                                    <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs font-semibold flex items-center">
                                                                        <i class="fas fa-check-circle mr-1"></i> Covered
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        
                                                        <?php 
                                                        if ($topic['total_sub_topics'] > 0 && $topic['sub_topic_details']): 
                                                            $sub_topic_details = explode('||', $topic['sub_topic_details']);
                                                            $sub_topic_details = array_filter($sub_topic_details); // Remove empty entries
                                                        ?>
                                                            <div class="ml-0 sm:ml-8 mt-3 sm:mt-4">
                                                                <div class="space-y-2 sm:space-y-3">
                                                                    <?php foreach ($sub_topic_details as $detail): ?>
                                                                        <?php 
                                                                        list($sub_id, $sub_name, $theory_completed, $practical_completed) = explode(':', $detail);
                                                                        $theory_completed = (int)$theory_completed;
                                                                        $practical_completed = (int)$practical_completed;
                                                                        ?>
                                                                        
                                                                        <div class="subtopic-item p-2 sm:p-3 bg-gray-50 rounded-lg">
                                                                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-2 sm:gap-0">
                                                                                <span class="text-xs sm:text-sm font-medium text-gray-700 truncate flex-1"><?php echo htmlspecialchars($sub_name); ?></span>
                                                                                <div class="flex items-center justify-between sm:justify-end space-x-2 sm:space-x-3">
                                                                                    <?php if ($topic['topic_type'] !== 'practical'): ?>
                                                                                    <div class="subtopic-checkbox-label" data-sub-id="<?php echo $sub_id; ?>" data-type="theory">
                                                                                        <span class="hidden xs:inline">Theory</span>
                                                                                        <span class="xs:hidden">T</span>
                                                                                        <div class="progress-checkbox theory <?php echo $theory_completed ? 'checked' : ''; ?>" 
                                                                                             onclick="toggleSubTopicProgress(<?php echo $sub_id; ?>, 'theory', this, '<?php echo $topic['topic_type']; ?>')">
                                                                                        </div>
                                                                                    </div>
                                                                                    <?php endif; ?>
                                                                                    
                                                                                    <?php if ($topic['topic_type'] !== 'theory'): ?>
                                                                                    <div class="subtopic-checkbox-label" data-sub-id="<?php echo $sub_id; ?>" data-type="practical">
                                                                                        <span class="hidden xs:inline">Practical</span>
                                                                                        <span class="xs:hidden">P</span>
                                                                                        <div class="progress-checkbox practical <?php echo $practical_completed ? 'checked' : ''; ?>" 
                                                                                             onclick="toggleSubTopicProgress(<?php echo $sub_id; ?>, 'practical', this, '<?php echo $topic['topic_type']; ?>')">
                                                                                        </div>
                                                                                    </div>
                                                                                    <?php endif; ?>
                                                                                </div>
                                                                            </div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <div class="flex flex-col items-start sm:items-end space-y-2 sm:space-y-3 mt-2 sm:mt-0">
                                                        <div class="flex items-center w-full sm:w-auto justify-between sm:justify-start">
                                                            <span class="text-xs text-gray-600 mr-2 sm:mr-2">Mark as Covered:</span>
                                                            <div class="chapter-progress-toggle <?php echo $topic['covered_by_trainer'] ? 'active' : ''; ?>" 
                                                                 onclick="toggleMainTopicCovered(<?php echo $topic['id']; ?>, this)">
                                                                <div class="toggle-slider"></div>
                                                            </div>
                                                        </div>
                                                        
                                                        <?php if ($topic['covered_date']): ?>
                                                            <div class="text-xs text-gray-500 text-left sm:text-right w-full">
                                                                Covered on: <?php echo date('M j, Y', strtotime($topic['covered_date'])); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($topic['total_sub_topics'] > 0): ?>
                                                            <div class="text-xs text-gray-500 text-left sm:text-right w-full">
                                                                Sub-topics: <?php echo $topic['fully_completed_sub_topics']; ?>/<?php echo $topic['total_sub_topics']; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($topic['total_sub_topics'] > 0): ?>
                                                    <div class="mt-3 sm:mt-4 ml-0 sm:ml-8">
                                                        <div class="flex flex-col xs:flex-row justify-between text-xs text-gray-600 mb-1 gap-1">
                                                            <span>Sub-topic progress</span>
                                                            <span class="text-right">
                                                                <span class="text-green-600"><?php echo $topic['fully_completed_sub_topics']; ?></span>/<?php echo $topic['total_sub_topics']; ?> completed
                                                                <span class="hidden sm:inline">
                                                                    (T: <span class="text-blue-600"><?php echo $topic['theory_completed_count']; ?></span>,
                                                                    P: <span class="text-cyan-600"><?php echo $topic['practical_completed_count']; ?></span>)
                                                                </span>
                                                            </span>
                                                        </div>
                                                        <div class="progress-bar-container">
                                                            <div class="progress-bar-fill bg-gradient-to-r from-cyan-400 to-blue-500" 
                                                                 style="width: <?php echo $topic['total_sub_topics'] > 0 ? round(($topic['fully_completed_sub_topics'] / $topic['total_sub_topics']) * 100) : 0; ?>%"></div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <!-- End Scrollable Topics Container -->
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- No Course Selected Card -->
                        <div class="glass-card right-focus-card p-6 sm:p-8 md:p-12" data-aos="zoom-in">
                            <div class="empty-state-pro max-w-2xl mx-auto">
                                <div class="empty-state-orb">
                                    <i class="fas fa-layer-group text-4xl"></i>
                                </div>
                                <h3 class="text-2xl sm:text-3xl font-black text-slate-900 mb-2">No Course Selected</h3>
                                <p class="text-slate-500 mb-5 max-w-md mx-auto text-sm sm:text-base">
                                    Pick a course from the left panel to open the full batch workspace, progress rings, topic checklist, and theory/practical tracking.
                                </p>
                                <?php if (!empty($all_batches)): ?>
                                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-indigo-50 text-indigo-700 font-extrabold text-sm">
                                        <i class="fas fa-circle-check"></i>
                                        <?php echo count($all_batches); ?> courses assigned to you
                                    </div>
                                <?php else: ?>
                                    <div class="inline-flex items-center gap-2 px-4 py-3 rounded-2xl bg-amber-50 text-amber-700 font-bold text-sm border border-amber-100">
                                        <i class="fas fa-info-circle"></i>
                                        No courses assigned yet. Contact the administrator.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        
        <!-- Footer -->
        <footer class="mt-8 py-4 text-center text-gray-500 text-sm border-t border-gray-200">
            <p>ASD Academy Trainer Portal © <?php echo date('Y'); ?>. All rights reserved.</p>
        </footer>
    </div>

    <!-- Report Modal -->
    <div id="reportModal" class="fixed inset-0 z-50 hidden">
        <div class="modal-overlay fixed inset-0" onclick="hideBatchReport()"></div>
        <div class="fixed inset-0 flex items-center justify-center p-3 sm:p-4">
            <div class="modal-content bg-white rounded-xl sm:rounded-2xl shadow-2xl w-full max-w-sm sm:max-w-md">
                <div class="p-4 sm:p-6">
                    <div class="flex justify-between items-center mb-4 sm:mb-6">
                        <h3 class="text-lg sm:text-xl font-bold text-gray-800">Generate Batch Report</h3>
                        <button onclick="hideBatchReport()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-lg sm:text-xl"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-3 sm:space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 sm:mb-2">Report Type</label>
                            <select class="w-full p-2 sm:p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm sm:text-base">
                                <option value="progress">Progress Report</option>
                                <option value="attendance">Attendance Report</option>
                                <option value="comprehensive">Comprehensive Report</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 sm:mb-2">Date Range</label>
                            <div class="flex flex-col sm:flex-row sm:space-x-2 space-y-2 sm:space-y-0">
                                <div class="flex-1">
                                    <input type="date" class="w-full p-2 sm:p-3 border border-gray-300 rounded-lg text-sm sm:text-base" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <span class="self-center hidden sm:block">to</span>
                                <div class="flex-1">
                                    <input type="date" class="w-full p-2 sm:p-3 border border-gray-300 rounded-lg text-sm sm:text-base" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1 sm:mb-2">Format</label>
                            <div class="flex space-x-2">
                                <button class="flex-1 p-2 sm:p-3 border-2 border-purple-500 bg-purple-50 text-purple-700 rounded-lg font-semibold text-sm sm:text-base">
                                    <i class="fas fa-file-pdf mr-1 sm:mr-2"></i> PDF
                                </button>
                                <button class="flex-1 p-2 sm:p-3 border border-gray-300 rounded-lg hover:bg-gray-50 text-sm sm:text-base">
                                    <i class="fas fa-file-excel mr-1 sm:mr-2"></i> Excel
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6 sm:mt-8 flex justify-end space-x-2 sm:space-x-3">
                        <button onclick="hideBatchReport()" class="px-3 py-2 sm:px-4 sm:py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 text-sm sm:text-base">
                            Cancel
                        </button>
                        <button onclick="generateReport()" class="px-3 py-2 sm:px-4 sm:py-2 bg-gradient-to-r from-purple-600 to-pink-600 text-white rounded-lg font-semibold hover:opacity-90 text-sm sm:text-base">
                            <i class="fas fa-download mr-1 sm:mr-2"></i> Generate
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Initialize AOS animations
        document.addEventListener('DOMContentLoaded', function() {
            AOS.init({
                duration: 600,
                once: true,
                offset: 50,
                disable: window.innerWidth < 640
            });
            
            // Add staggered animations for table rows
            const rows = document.querySelectorAll('.topic-card');
            rows.forEach((row, index) => {
                row.style.animationDelay = `${index * 0.05}s`;
            });
            
            // Animate cards on page load
            const cards = document.querySelectorAll('.glass-card');
            cards.forEach((card, index) => {
                card.classList.add('animate-fade-in');
                card.classList.add(`delay-${(index % 3) + 1}00`);
            });
            
            // Animate progress bars
            const progressBars = document.querySelectorAll('.progress-bar-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
        
        // Function to toggle mobile menu
        function toggleMobileMenu() {
            const mobileMenu = document.getElementById('mobileMenu');
            const mobileMenuContent = mobileMenu.querySelector('div');
            
            if (mobileMenu.classList.contains('hidden')) {
                mobileMenu.classList.remove('hidden');
                setTimeout(() => {
                    mobileMenuContent.classList.remove('-translate-x-full');
                }, 10);
            } else {
                mobileMenuContent.classList.add('-translate-x-full');
                setTimeout(() => {
                    mobileMenu.classList.add('hidden');
                }, 300);
            }
        }
        
        // Close mobile menu when clicking outside
        document.getElementById('mobileMenu').addEventListener('click', function(e) {
            if (e.target.id === 'mobileMenu') {
                toggleMobileMenu();
            }
        });
        
        // Handle ESC key to close mobile menu
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const mobileMenu = document.getElementById('mobileMenu');
                if (!mobileMenu.classList.contains('hidden')) {
                    toggleMobileMenu();
                }
            }
        });
        
        // Toggle main topic covered status
        function toggleMainTopicCovered(topicId, toggleElement) {
            // Prevent multiple clicks
            if (toggleElement.classList.contains('processing')) return;
            
            const isCurrentlyCovered = toggleElement.classList.contains('active');
            const newIsCovered = !isCurrentlyCovered;
            
            // Show confirmation
            let confirmMessage = newIsCovered 
                ? 'Marking this main topic as covered will automatically mark ALL its sub-topics (Theory & Practical) as completed. Continue?'
                : 'Marking this main topic as UNCOVERED will automatically mark ALL its sub-topics as UNCOMPLETED. Continue?';
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Show loading state
            toggleElement.classList.add('processing');
            const originalBg = toggleElement.style.background;
            toggleElement.style.background = '#e5e7eb';
            toggleElement.style.cursor = 'wait';
            const slider = toggleElement.querySelector('.toggle-slider');
            const originalSlider = slider.innerHTML;
            slider.innerHTML = '<div class="loading-spinner"></div>';
            
            // Send AJAX request
            const formData = new FormData();
            formData.append('mark_covered', '1');
            formData.append('topic_id', topicId);
            formData.append('is_covered', newIsCovered ? '1' : '0');
            formData.append('ajax', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update toggle appearance
                    if (newIsCovered) {
                        toggleElement.classList.add('active');
                        toggleElement.style.background = '';
                    } else {
                        toggleElement.classList.remove('active');
                        toggleElement.style.background = originalBg;
                    }
                    
                    slider.innerHTML = originalSlider;
                    toggleElement.style.cursor = '';
                    toggleElement.classList.remove('processing');
                    
                    // Show success message
                    showToast(newIsCovered ? 'Topic marked as covered!' : 'Topic marked as uncovered!', 'success');
                    
                    // Reload page to update progress statistics
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                toggleElement.style.background = originalBg;
                toggleElement.style.cursor = '';
                slider.innerHTML = originalSlider;
                toggleElement.classList.remove('processing');
                showToast('Failed to update topic status. Please try again.', 'error');
            });
        }
        
        // Toggle sub-topic progress
        function toggleSubTopicProgress(subTopicId, progressType, checkboxElement, topicType) {
            // Prevent multiple clicks
            if (checkboxElement.classList.contains('processing')) return;
            
            const isCurrentlyChecked = checkboxElement.classList.contains('checked');
            const newIsChecked = !isCurrentlyChecked;
            
            // Determine companion checkbox (theory/practical)
            const companionType = progressType === 'theory' ? 'practical' : 'theory';
            const companionCheckbox = checkboxElement.closest('.subtopic-item')
                .querySelector(`.progress-checkbox.${companionType}`);
            
            // Get current state of companion checkbox
            const companionChecked = companionCheckbox ? companionCheckbox.classList.contains('checked') : false;
            
            // Determine final values based on topic type
            let theoryCompleted = progressType === 'theory' ? newIsChecked : companionChecked;
            let practicalCompleted = progressType === 'practical' ? newIsChecked : companionChecked;
            
            // Adjust based on topic type restrictions
            if (topicType === 'theory') {
                practicalCompleted = false;
            } else if (topicType === 'practical') {
                theoryCompleted = false;
            }
            
            // Show loading state
            checkboxElement.classList.add('processing');
            const originalClass = checkboxElement.className;
            checkboxElement.className = 'progress-checkbox processing';
            checkboxElement.style.cursor = 'wait';
            checkboxElement.innerHTML = '<div class="loading-spinner"></div>';
            
            // Send AJAX request
            const formData = new FormData();
            formData.append('update_sub_topic', '1');
            formData.append('sub_topic_id', subTopicId);
            formData.append('theory_completed_value', theoryCompleted ? '1' : '0');
            formData.append('practical_completed_value', practicalCompleted ? '1' : '0');
            formData.append('ajax', '1');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update checkbox appearance
                    checkboxElement.className = originalClass;
                    checkboxElement.classList.toggle('checked', newIsChecked);
                    checkboxElement.style.cursor = '';
                    checkboxElement.innerHTML = '';
                    checkboxElement.classList.remove('processing');
                    
                    // Update companion checkbox if needed
                    if (companionCheckbox && progressType === 'theory' && topicType === 'both') {
                        companionCheckbox.classList.toggle('checked', data.practical_completed);
                    } else if (companionCheckbox && progressType === 'practical' && topicType === 'both') {
                        companionCheckbox.classList.toggle('checked', data.theory_completed);
                    }
                    
                    showToast('Progress updated!', 'success');
                    
                    // Reload page to update progress statistics
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                checkboxElement.className = originalClass;
                checkboxElement.style.cursor = '';
                checkboxElement.innerHTML = '';
                checkboxElement.classList.remove('processing');
                showToast('Failed to update progress. Please try again.', 'error');
            });
        }
        
        // Show/hide report modal
        function showBatchReport() {
            const modal = document.getElementById('reportModal');
            modal.classList.remove('hidden');
            setTimeout(() => {
                modal.querySelector('.modal-content').classList.add('active');
            }, 10);
        }
        
        function hideBatchReport() {
            const modal = document.getElementById('reportModal');
            modal.querySelector('.modal-content').classList.remove('active');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }
        
        // Simulate report generation
        function generateReport() {
            const button = event.target.closest('button');
            const originalText = button.innerHTML;
            
            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1 sm:mr-2"></i> Generating...';
            button.disabled = true;
            
            setTimeout(() => {
                button.innerHTML = '<i class="fas fa-check mr-1 sm:mr-2"></i> Generated!';
                button.classList.remove('from-purple-600', 'to-pink-600');
                button.classList.add('from-green-500', 'to-emerald-500');
                
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                    button.classList.remove('from-green-500', 'to-emerald-500');
                    button.classList.add('from-purple-600', 'to-pink-600');
                    hideBatchReport();
                    
                    // Show success toast
                    showToast('Report generated successfully!', 'success');
                }, 1500);
            }, 2000);
        }
        
        // Toast notification function
        function showToast(message, type = 'info') {
            // Remove existing toasts
            document.querySelectorAll('.custom-toast').forEach(toast => toast.remove());
            
            const toast = document.createElement('div');
            toast.className = `custom-toast fixed top-4 right-4 px-4 py-3 sm:px-6 sm:py-3 rounded-lg shadow-lg text-white font-semibold z-50 transform translate-x-full opacity-0 transition-all duration-300 text-sm sm:text-base ${
                type === 'success' ? 'bg-gradient-to-r from-green-500 to-emerald-500' :
                type === 'error' ? 'bg-gradient-to-r from-red-500 to-pink-500' :
                'bg-gradient-to-r from-blue-500 to-cyan-500'
            }`;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-full', 'opacity-0');
                toast.classList.add('translate-x-0', 'opacity-100');
            }, 10);
            
            setTimeout(() => {
                toast.classList.remove('translate-x-0', 'opacity-100');
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Smooth scroll to top when switching batches
        document.querySelectorAll('a[href*="batch_id="]').forEach(link => {
            link.addEventListener('click', function(e) {
                if (this.getAttribute('href').startsWith('?')) {
                    e.preventDefault();
                    const url = this.getAttribute('href');
                    
                    // Add loading indicator
                    const originalText = this.innerHTML;
                    this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Loading...';
                    this.disabled = true;
                    
                    // Scroll to top with animation
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                    
                    // Navigate after scroll
                    setTimeout(() => {
                        window.location.href = url;
                    }, 300);
                }
            });
        });
        
        // Handle orientation change
        window.addEventListener('orientationchange', function() {
            setTimeout(() => {
                location.reload();
            }, 100);
        });
    

        // Enhanced course search/filter
        const courseSearchInput = document.getElementById('courseSearchInput');
        if (courseSearchInput) {
            courseSearchInput.addEventListener('input', function () {
                const term = this.value.trim().toLowerCase();
                const cards = document.querySelectorAll('[data-course-card]');
                const empty = document.getElementById('courseSearchEmpty');
                let visible = 0;

                cards.forEach(card => {
                    const haystack = card.getAttribute('data-search') || '';
                    const show = haystack.includes(term);
                    card.style.display = show ? 'block' : 'none';
                    if (show) visible++;
                });

                if (empty) {
                    empty.classList.toggle('hidden', visible !== 0 || term.length === 0);
                }
            });
        }

    </script>

<script>
/* ===== Topbar avatar DOM sync =====
   PHP untouched. Since sidebar profile image is already working, this copies its src
   into the hardcoded A icon in the page header. Tiny miracle, no database drama. */
(function () {
    function getSidebarProfileSrc() {
        const img = document.querySelector('.sidebar-profile-photo');
        if (!img) return '';
        const src = img.getAttribute('src') || '';
        if (!src || img.style.display === 'none') return '';
        return src;
    }

    function replaceBoxWithImage(box, src, isMobile) {
        if (!box || !src) return;

        // Do not replace notification badge or unrelated icons, because chaos is not a feature.
        if (box.closest('#trainer-notif-container')) return;

        const existingImg = box.querySelector('img.topbar-synced-profile-img');
        if (existingImg) {
            existingImg.src = src;
            return;
        }

        box.innerHTML = '';
        box.className = '';
        box.style.cssText = '';

        const img = document.createElement('img');
        img.src = src;
        img.alt = 'Profile Picture';
        img.className = 'topbar-synced-profile-img' + (isMobile ? ' mobile' : '');
        img.onerror = function () {
            this.remove();
        };
        box.appendChild(img);
    }

    function syncTopbarAvatar() {
        const src = getSidebarProfileSrc();
        if (!src) return false;

        // Desktop header: right-side user profile area after notification bell.
        document.querySelectorAll('header.hidden.lg\\:flex .flex.items-center.space-x-2').forEach(function (profileWrap) {
            const nameText = profileWrap.textContent || '';
            if (!nameText.includes('Trainer')) return;

            const firstBox = profileWrap.querySelector('div:first-child');
            replaceBoxWithImage(firstBox, src, false);
        });

        // Mobile header: profile icon is usually the last circle in right cluster.
        document.querySelectorAll('header.lg\\:hidden .relative > div').forEach(function (box) {
            const txt = (box.textContent || '').trim();
            if (txt.length <= 2 && !box.querySelector('i')) {
                replaceBoxWithImage(box, src, true);
            }
        });

        return true;
    }

    function runWithRetries() {
        let tries = 0;
        const timer = setInterval(function () {
            tries++;
            const done = syncTopbarAvatar();
            if (done || tries >= 20) {
                clearInterval(timer);
            }
        }, 150);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', runWithRetries);
    } else {
        runWithRetries();
    }

    window.addEventListener('load', syncTopbarAvatar);
    window.addEventListener('pageshow', syncTopbarAvatar);
})();
</script>

</body>
</html>